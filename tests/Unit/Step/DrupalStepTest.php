<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\DrupalStep;
use Manifesto\Mfd\Tests\Support\FakeShell;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

final class DrupalStepTest extends NewCommandTestCase
{
    /** @var list<string> Directories whose permissions a test broke and must be restored. */
    private array $directoriesToRestore = [];

    protected function tearDown(): void
    {
        foreach ($this->directoriesToRestore as $dir) {
            @chmod($dir, 0755);
        }
        $this->directoriesToRestore = [];

        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    private function drupal(array $input = ['name' => 'acme']): CommandTester
    {
        return $this->newProject($input, [new DrupalStep()]);
    }

    public function testRunsDdevComposerAndInstallInOrder(): void
    {
        $tester = $this->drupal(['name' => 'acme-corp', '--site-name' => "O'Brien & Co"]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $calls = $this->shell->calls;
        self::assertContains('ddev config --project-name=acme-corp --project-type=drupal11 --docroot=web', $calls);
        self::assertContains('ddev composer create-project drupal/recommended-project:^11 --no-interaction', $calls);
        self::assertContains(
            'ddev composer require --dev --no-interaction --with-all-dependencies drupal/core-dev '
                . 'weitzman/drupal-test-traits mglaman/phpstan-drupal phpstan/extension-installer drupal/coder',
            $calls,
        );
        self::assertContains("ddev drush site:install standard --site-name=O'Brien & Co -y", $calls);
        foreach ($calls as $call) {
            self::assertStringNotContainsString('--db-url', $call);
        }
        self::assertLessThan(
            array_search(
                'ddev composer require --dev --no-interaction --with-all-dependencies drupal/core-dev '
                    . 'weitzman/drupal-test-traits mglaman/phpstan-drupal phpstan/extension-installer drupal/coder',
                $calls,
                true,
            ),
            array_search(
                'ddev composer config --no-interaction allow-plugins.dealerdirect/phpcodesniffer-composer-installer '
                    . 'true',
                $calls,
                true,
            ),
        );
    }

    /**
     * Fix round 4: twig/twig 3.30.0 breaks Drupal 11.4.7 rendering. The conflict entry blocks
     * it so composer resolves a fixed 3.30.x automatically.
     */
    public function testBlocksTheBrokenTwigVersionInComposerJson(): void
    {
        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $composer = json_decode(
            (string) file_get_contents($this->project('acme') . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(['twig/twig' => '3.30.0'], $composer['conflict']);
        self::assertSame('^11', $composer['require']['drupal/core-recommended']);
    }

    public function testRunsTheTwigUpdateAfterCreateProjectAndBeforeDrushRequire(): void
    {
        $this->drupal();

        $calls = $this->shell->calls;
        $createProjectIndex = array_search(
            'ddev composer create-project drupal/recommended-project:^11 --no-interaction',
            $calls,
            true,
        );
        $twigUpdateIndex = array_search('ddev composer update --no-interaction twig/twig', $calls, true);
        $drushRequireIndex = array_search('ddev composer require --no-interaction drush/drush', $calls, true);

        self::assertNotFalse($createProjectIndex, 'create-project call not found');
        self::assertNotFalse($twigUpdateIndex, 'twig update call not found');
        self::assertNotFalse($drushRequireIndex, 'drush require call not found');
        self::assertLessThan($twigUpdateIndex, $createProjectIndex);
        self::assertLessThan($drushRequireIndex, $twigUpdateIndex);
    }

    public function testRerunDoesNotDuplicateTheTwigConflictEntry(): void
    {
        $this->drupal();
        $this->drupal();

        $composerFile = $this->project('acme') . '/composer.json';
        $composerJson = (string) file_get_contents($composerFile);
        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['twig/twig' => '3.30.0'], $composer['conflict']);
        self::assertSame(
            1,
            substr_count($composerJson, '"twig/twig"'),
            'the conflict entry must not be duplicated on rerun',
        );
    }

    public function testKeepsExistingConflictEntriesWhenAddingTheTwigConflict(): void
    {
        $this->drupal();

        $composerFile = $this->project('acme') . '/composer.json';
        $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        $composer['conflict']['some/package'] = '1.0.0';
        file_put_contents(
            $composerFile,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0.0', $composer['conflict']['some/package']);
        self::assertSame('3.30.0', $composer['conflict']['twig/twig']);
    }

    /**
     * Fix wave R22: decoding composer.json to a PHP array (rather than stdClass, as
     * PackageMerger does) turns an empty JSON object such as `"require-dev": {}` into an empty
     * PHP array, which json_encode() then writes back out as `[]`, silently corrupting the file.
     * Non-ASCII values must also round-trip unescaped (JSON_UNESCAPED_UNICODE).
     *
     * StepRunner/State skip a step already marked done, so blockBrokenPackages() cannot be
     * re-exercised by simply calling drupal() twice (see testRerunDoesNotDuplicateTheTwigConflictEntry,
     * which the "done" skip makes a no-op on the second call). This simulates a genuine resume
     * instead: composer.json is rewritten with empty objects and a non-ASCII value, and the
     * `drupal` step's own "done" marker is removed, exactly as it would be missing after an
     * interrupted run, so the second call actually reruns blockBrokenPackages().
     */
    public function testKeepsEmptyObjectsAndNonAsciiValuesWhenBlockingBrokenPackages(): void
    {
        $this->drupal();

        $projectDir = $this->project('acme');
        $composerFile = $projectDir . '/composer.json';
        file_put_contents(
            $composerFile,
            json_encode(
                (object) [
                    'require' => (object) ['drupal/core-recommended' => '^11'],
                    'conflict' => (object) ['twig/twig' => '3.30.0'],
                    'require-dev' => new \stdClass(),
                    'extra' => (object) ['patches' => new \stdClass()],
                    'description' => 'Café gateway',
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
        unlink($projectDir . '/.ddev/mfd/done/drupal');

        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $composerJson = (string) file_get_contents($composerFile);
        self::assertStringContainsString('"require-dev": {}', $composerJson);
        self::assertStringContainsString('"patches": {}', $composerJson);
        self::assertStringContainsString('Café gateway', $composerJson);
        // Written as chr(92) . 'u00e9' (rather than the literal escape sequence in source) so this
        // assertion unambiguously checks for the *escaped* form, not the raw "é" character, which
        // legitimately appears elsewhere in this same string via 'Café gateway' above.
        self::assertStringNotContainsString(chr(92) . 'u00e9', $composerJson);
    }

    public function testNeverPinsPhpunitDirectly(): void
    {
        $this->drupal();

        foreach ($this->shell->calls as $call) {
            self::assertStringNotContainsString('phpunit/phpunit', $call);
        }
    }

    public function testIgnoresDependenciesAndDdevSecretsButCommitsSettingsPhp(): void
    {
        $this->drupal();
        $lines = file($this->project('acme') . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];

        $rules = [
            '/vendor/', '/web/core/', '/node_modules/', '/web/sites/*/settings.ddev.php', '/web/sites/*/files/',
            '*.sql', '*.sql.*', '*.mysql', '/.env', '/.env.*', '!/.env.example', '/.ddev/.env', '/private/',
            '/storybook-static/',
        ];
        foreach ($rules as $rule) {
            self::assertContains($rule, $lines);
        }
        self::assertNotContains('/web/sites/*/settings.php', $lines);
        self::assertNotContains('/web/sites/*/settings*.php', $lines);
    }

    public function testConfigSyncDirectoryIsSetOnce(): void
    {
        $this->drupal();

        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame(1, substr_count($settings, "\$settings['config_sync_directory'] = '../config/sync';"));
    }

    public function testAFailureAfterCreateProjectResumesWithoutRedoingIt(): void
    {
        $this->shell->failOn('composer require --dev');
        self::assertSame(1, $this->drupal()->getStatusCode());

        $this->shell->clearFailures();
        $this->shell->calls = [];
        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->shell->callsStartingWith('ddev composer create-project'));
        self::assertSame([], $this->shell->callsStartingWith('ddev config '));
        self::assertNotSame([], $this->shell->callsStartingWith('ddev composer require --dev'));
        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame(1, substr_count($settings, 'config_sync_directory'));
    }

    public function testSkipsSiteInstallWhenTheSiteIsAlreadyInstalled(): void
    {
        $this->shell->failOn('drush site:install');
        $this->drupal();
        touch($this->project('acme') . '/.fake-installed');
        $this->shell->clearFailures();
        $this->shell->calls = [];

        self::assertSame(0, $this->drupal()->getStatusCode());
        self::assertSame([], $this->shell->callsStartingWith('ddev drush site:install'));
    }

    public function testFailsClearlyWhenDdevDidNotIncludeItsSettings(): void
    {
        $this->drupal();
        file_put_contents($this->project('acme') . '/web/sites/default/settings.php', "<?php\n");
        unlink($this->project('acme') . '/.ddev/mfd/done/drupal');
        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('settings.ddev.php', $tester->getDisplay());
    }

    public function testCreatesConfigSyncBeforeSiteInstall(): void
    {
        $this->drupal();

        self::assertDirectoryExists($this->project('acme') . '/config/sync');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function leakShapes(): iterable
    {
        yield 'in-place salt plus appended $databases block' => ['siteInstallLeaksSecrets'];
        yield 'no trailing newline before the append' => ['siteInstallLeaksWithoutTrailingNewline'];
        yield 'a salt line appended after the DDEV include' => ['siteInstallLeaksSaltAfterInclude'];
    }

    #[DataProvider('leakShapes')]
    public function testRestoresSettingsPhpByteIdenticalToTheSnapshotWhateverShapeTheLeakTakes(string $toggle): void
    {
        $this->shell->{$toggle} = true;

        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame($this->shell->settingsPhpAtSiteInstall, $settings);
        self::assertFileDoesNotExist($this->project('acme') . '/.ddev/mfd/settings.php.snapshot');
    }

    public function testRestoresSettingsPhpEvenWhenSiteInstallLeaksThenFails(): void
    {
        $this->shell->siteInstallLeaksThenFails = true;

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame($this->shell->settingsPhpAtSiteInstall, $settings);
        // The install failed, so nothing downstream of it (the secrets check, the snapshot delete)
        // ever ran; the snapshot is kept, not silently cleaned up alongside a failed run.
        self::assertFileExists($this->project('acme') . '/.ddev/mfd/settings.php.snapshot');
    }

    public function testThrowsWhenASecretSurvivesRestore(): void
    {
        $this->shell->secretSurvivesRestore = true;

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('settings.php', $tester->getDisplay());
    }

    public function testFailsWithAMessageNamingSettingsPhpWhenTheRestoreCannotWriteTheFile(): void
    {
        $this->shell->restoreCannotWriteSettingsPhp = true;
        // The fake makes web/sites/default unsearchable to simulate the write failing; restore it
        // afterwards so the temp-directory cleanup can still reach and delete the project tree.
        $this->directoriesToRestore[] = $this->project('acme') . '/web/sites/default';

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('settings.php', $tester->getDisplay());
    }

    /**
     * F4: when site:install fails AND the restore that follows it also fails, both messages must
     * reach the user, not just the restore's (which would silently discard the reason site:install
     * itself failed) or just the install's (which would silently discard why the restore, the
     * user's one remaining safety net, also failed).
     */
    public function testShowsBothMessagesWhenTheRestoreAlsoFailsAfterAFailedInstall(): void
    {
        $this->shell->siteInstallLeaksThenFails = true;
        $this->shell->restoreCannotWriteSettingsPhp = true;
        $this->directoriesToRestore[] = $this->project('acme') . '/web/sites/default';

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('settings.php restore failed', $display);
        self::assertStringContainsString('after site:install failed', $display);
        self::assertStringContainsString('fake failure: site:install leaked secrets then failed', $display);
        self::assertStringContainsString('Could not write', $display);
    }

    public function testFailsWhenTheRestoredContentDiffersFromTheSnapshotCapturedBeforeInstall(): void
    {
        $this->shell->corruptsSnapshotDuringInstall = true;

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('settings.php', $tester->getDisplay());
    }

    /**
     * Fix round 4 (L3): settings.php must end up byte-identical to the pre-install snapshot even
     * when the snapshot *repair* write (triggered because the on-disk snapshot was tampered with)
     * itself fails, here because its directory has lost write permission. Before this fix,
     * restoreSettingsFromSnapshot() attempted the snapshot repair before rewriting settings.php
     * from $expected, so a repair failure left settings.php holding the leaked bytes.
     */
    public function testSettingsPhpIsRestoredEvenWhenTheSnapshotRepairCannotWriteItsDirectory(): void
    {
        $this->shell->corruptsSnapshotAndLocksDirectoryDuringInstall = true;
        $this->directoriesToRestore[] = dirname($this->project('acme') . '/.ddev/mfd/settings.php.snapshot');

        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame($this->shell->settingsPhpAtSiteInstall, $settings);
    }

    /**
     * F3: the reviewer's LaunderProbeTest scenario turned into a unit test. Run 1's on-disk
     * snapshot is tampered with mid-install; the restore has the correct bytes in memory
     * ($originalSettings) the whole time, so it must write those, not the tampered on-disk
     * snapshot, and must repair the on-disk snapshot file from them before failing closed. A
     * rerun (run 2, no in-memory value of its own) then finds an already-clean on-disk snapshot
     * and restores settings.php byte-identical to the pre-install content, not `// corrupted`.
     */
    public function testRerunAfterSnapshotTamperingDuringInstallEndsWithThePreInstallBytes(): void
    {
        $this->shell->corruptsSnapshotDuringInstall = true;

        $run1 = $this->drupal();

        self::assertSame(1, $run1->getStatusCode(), $run1->getDisplay());
        $settingsFile = $this->project('acme') . '/web/sites/default/settings.php';
        $snapshotFile = $this->project('acme') . '/.ddev/mfd/settings.php.snapshot';
        $before = $this->shell->settingsPhpAtSiteInstall;
        self::assertFileExists($snapshotFile, 'run 1 must not clean up after failing closed');

        $this->shell->corruptsSnapshotDuringInstall = false;
        $run2 = $this->drupal();

        self::assertSame(0, $run2->getStatusCode(), $run2->getDisplay());
        self::assertSame($before, (string) file_get_contents($settingsFile));
        self::assertFileDoesNotExist($snapshotFile);
        self::assertSame(
            [],
            glob(dirname($snapshotFile) . '/settings.php.snapshot.tmp-*') ?: [],
            'the atomic snapshot write must not leave a stray temp file behind',
        );
    }

    /**
     * F1: `containsLeakedSecret()` now allows only known-safe shapes (`$databases = [];` /
     * `$databases = array();`, and a `hash_salt` value that is exactly `''` or `""`) instead of
     * listing bad ones. Cases below marked "probe" come from the security-analyst's
     * `probe.php`/round-2 re-review, reused per the fix-round-3 brief.
     *
     * Three probe cases are deliberately NOT reused with the probe's own "want" value:
     * `isset($databases['default'])`, `getenv(...)` and `file_get_contents(...)` as a hash_salt
     * value. The probe labelled all three "legit"/clean, but the brief itself overrides the
     * first explicitly ("isset($databases['default'])), flagged" in its own new-case list) and
     * states the general rule for the other two just as explicitly ("A value that is ... a
     * function call is a leak"). An allow-list of "safe-looking" function names would be exactly
     * the kind of guessable shape this fix is replacing, so all three are asserted here as leaks,
     * per the brief's text, not the probe's original annotation.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function tokenizerSecretCases(): iterable
    {
        yield 'in-place salt' => ["<?php\n\$settings['hash_salt'] = 'abcdef1234567890';\n", true];
        yield 'indented salt' => ["<?php\n    \$settings['hash_salt'] = 'abcdef1234567890';\n", true];
        yield '$databases glued onto the previous line with no newline' => [
            "<?php\n\$x = 1;\$databases['default']['default'] = array (\n  'database' => 'db',\n);\n",
            true,
        ];
        yield 'CRLF line endings' => ["<?php\r\n\$settings['hash_salt'] = 'abcdef1234567890';\r\n", true];
        yield 'double-quoted salt' => ["<?php\n\$settings[\"hash_salt\"] = \"abcdef1234567890\";\n", true];
        yield "core's default.settings.php doc-comment prose" => [FakeShell::DDEV_SETTINGS, false];
        yield 'empty $databases array' => ["<?php\n\$databases = [];\n", false];
        yield 'empty hash_salt' => ["<?php\n\$settings['hash_salt'] = '';\n", false];

        // New cases, fix round 3 (F1).
        yield 'heredoc salt' => ["<?php\n\$settings['hash_salt'] = <<<EOT\nabc\nEOT;\n", true];
        yield 'nowdoc salt' => ["<?php\n\$settings['hash_salt'] = <<<'EOT'\nabc\nEOT;\n", true];
        yield 'interpolated salt' => ["<?php\n\$x = 'a'; \$settings['hash_salt'] = \"abc{\$x}\";\n", true];
        yield 'concatenated salt, empty first operand' => ["<?php\n\$settings['hash_salt'] = '' . 'abc';\n", true];
        yield '$databases assigned a non-empty array literal' => [
            "<?php\n\$databases = ['default' => ['default' => ['password' => 'p']]];\n",
            true,
        ];
        yield '$databases assigned a non-empty array() call' => [
            "<?php\n\$databases = array('default' => array('default' => array('password' => 'p')));\n",
            true,
        ];
        yield '$databases = array(); (the array() form of the empty assignment)' => [
            "<?php\n\$databases = array();\n",
            false,
        ];
        yield 'hash_salt set via an array literal merged into $settings' => [
            "<?php\n\$settings = ['hash_salt' => 'x'] + \$settings;\n",
            true,
        ];
        yield 'hash_salt in inline HTML after the closing tag' => [
            "<?php \$a = 1; ?>\n\$settings['hash_salt'] = 'abc';\n",
            true,
        ];
        yield 'no opening <?php tag at all' => ["\$settings['hash_salt'] = 'abc';\n", true];
        yield 'salt value is the two-character string \'""\', not empty' => [
            "<?php\n\$settings['hash_salt'] = '\"\"';\n",
            true,
        ];
        yield 'double-quoted empty hash_salt' => ["<?php\n\$settings[\"hash_salt\"] = \"\";\n", false];
        yield 'whitespace and comments between every token of the assignment (probe)' => [
            "<?php \$settings /*a*/ [ // b\n 'hash_salt' # c\n ] /* d */ = /* e */ 'abc' ;",
            true,
        ];

        // Fix round 4 (L2): token_get_all(..., TOKEN_PARSE) throws \CompileError for some
        // invalid code, not just \ParseError (\ParseError extends \CompileError). This snippet
        // has no hash_salt/$databases text at all, so it would tokenize clean and read false if
        // the exception were not caught; it must still fail closed to true.
        yield 'duplicate modifier throws CompileError, not ParseError (fails closed)' => [
            "<?php\nclass A { public public \$x; }\n",
            true,
        ];

        // Deliberately overridden probe cases (see the method docblock).
        yield 'isset($databases[...]) is a read, still flagged (probe, want overridden)' => [
            "<?php\nif (isset(\$databases['default'])) {\n}\n",
            true,
        ];
        yield 'hash_salt from getenv() is a function call, so a leak (probe, want overridden)' => [
            "<?php\n\$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');\n",
            true,
        ];
        yield 'hash_salt from file_get_contents() is a function call, so a leak (probe, want overridden)' => [
            "<?php\n\$settings['hash_salt'] = file_get_contents('/x/salt.txt');\n",
            true,
        ];
    }

    #[DataProvider('tokenizerSecretCases')]
    public function testContainsLeakedSecretReadsTokensNotText(string $php, bool $expectLeak): void
    {
        self::assertSame($expectLeak, DrupalStep::containsLeakedSecret($php));
    }

    public function testResumeRestoresALeftoverSnapshotBeforeAnyComposerCall(): void
    {
        $first = $this->drupal();
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());

        $settingsFile = $this->project('acme') . '/web/sites/default/settings.php';
        $snapshotFile = $this->project('acme') . '/.ddev/mfd/settings.php.snapshot';
        $clean = (string) file_get_contents($settingsFile);

        // Simulate a run that was interrupted mid-install: a snapshot left behind and a
        // dirty, read-only settings.php, exactly what a killed `mfd new` could leave.
        file_put_contents($snapshotFile, $clean);
        file_put_contents(
            $settingsFile,
            $clean . "\$databases['default']['default'] = array (\n  'database' => 'db',\n);\n",
        );
        chmod($settingsFile, 0444);
        unlink($this->project('acme') . '/.ddev/mfd/done/drupal');
        $this->shell->calls = [];
        $this->shell->settingsPhpAtFirstDdevCall = null;

        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        // The file was already clean by the time the very first `ddev` command ran, proving the
        // restore happened before any shell call, including the composer ones.
        self::assertSame($clean, $this->shell->settingsPhpAtFirstDdevCall);
        self::assertNotSame([], $this->shell->callsStartingWith('ddev composer require --dev'));
        self::assertSame($clean, (string) file_get_contents($settingsFile));
        self::assertFileDoesNotExist($snapshotFile);
    }

    public function testKeepsAnExistingGitignoreAndAddsTheBlockOnce(): void
    {
        mkdir($this->project('acme'));
        $this->drupal();
        $this->drupal();

        $gitignore = (string) file_get_contents($this->project('acme') . '/.gitignore');
        self::assertSame(1, substr_count($gitignore, '# drupal-starter'));
    }
}
