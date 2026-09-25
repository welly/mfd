<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\E2e;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Step\DrupalStep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Slow: scaffolds a real project on DDEV and runs its daily tasks. Run with `task e2e`. */
final class NewProjectE2eTest extends TestCase
{
    private const THEME = 'e2e_theme';

    private string $work;
    private string $name;
    private string $project;

    protected function setUp(): void
    {
        $this->name = 'mfde2e-' . getmypid();
        $this->work = sys_get_temp_dir() . '/' . $this->name . '-work';
        $this->project = $this->work . '/' . $this->name;
        mkdir($this->work, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->project . '/.ddev')) {
            $this->sh('ddev delete -Oy ' . escapeshellarg($this->name), $this->project, false);
        }
        (new Filesystem())->remove($this->work);
    }

    public function testANewProjectPassesItsOwnChecks(): void
    {
        $this->sh(sprintf(
            '%s new %s --theme %s --theme-label %s',
            escapeshellarg(Mfd::root() . '/bin/mfd'),
            escapeshellarg($this->name),
            self::THEME,
            escapeshellarg('E2E & Co'),
        ), $this->work);

        // The installer generates its own salt, so the DDEV-salt search below can't find an
        // installer leak on its own; run the same tokenizer check DrupalStep itself uses (N3)
        // directly against the real, generated settings.php, calling into src/ rather than
        // reimplementing the check.
        self::assertFalse(
            DrupalStep::containsLeakedSecret(
                (string) file_get_contents($this->project . '/web/sites/default/settings.php'),
            ),
            'settings.php contains a leaked secret right after `mfd new`',
        );

        // Each PHPUnit suite runs at least one test and passes (catches zero-test passes).
        foreach (['unit', 'kernel', 'existing-site'] as $suite) {
            $out = $this->sh('ddev exec vendor/bin/phpunit --testsuite ' . $suite);
            self::assertMatchesRegularExpression('/OK \([1-9]\d* tests?/', $out, "suite $suite ran zero tests");
        }

        // settings.php holds no secrets; configuration is exported; the real salt is committable
        // nowhere and settings.ddev.php (where it actually lives) is git-ignored.
        $this->assertNoSecretsAreCommittable();
        self::assertFileExists($this->project . '/config/sync/system.site.yml');

        // Linters pass on generated code.
        $this->sh('task be:lint');
        $this->sh('task fe:lint');

        // fe:build is a no-op until a build script exists, then runs it with no Taskfile change.
        $this->sh('task fe:build');
        $original = (string) file_get_contents($this->project . '/package.json');
        $package = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $package);
        $package->scripts->build = 'echo BUILT';
        file_put_contents(
            $this->project . '/package.json',
            json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        self::assertStringContainsString('BUILT', $this->sh('task fe:build'));
        file_put_contents($this->project . '/package.json', $original);

        // A new component through mfd; Storybook and the linters accept it.
        $this->makeComponent('hero');
        $this->sh('task fe:lint');
        $this->sh('task fe:storybook:build');
        $index = json_decode(
            (string) file_get_contents($this->project . '/storybook-static/index.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($index);
        $titles = array_map(static fn (array $e): string => (string) $e['title'], $index['entries']);
        self::assertContains(self::THEME . '/SDC/Card', $titles);
        self::assertContains(self::THEME . '/SDC/Hero', $titles);

        // The design review reports no critical findings.
        $this->sh('task qa:review', null, false);
        $reports = glob($this->project . '/qa/reports/runs/*/report.json') ?: [];
        self::assertNotSame([], $reports, 'the review wrote no report');
        usort($reports, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $report = json_decode((string) file_get_contents($reports[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $critical = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['severity'] === 'critical',
        ));
        self::assertSame([], $critical, json_encode($critical, JSON_PRETTY_PRINT) ?: '');

        // task setup rebuilds the site from config/sync. FinishStep only runs `git init`, never a
        // commit (see src/Step/FinishStep.php), so settings.php is never actually tracked at this
        // point in the e2e; `git diff` against a commit that doesn't exist would prove nothing.
        // Compare the raw bytes taken just before `task setup` instead, which is the same
        // guarantee template/Taskfile.yml's own backup/restore is meant to provide.
        $settingsBeforeSetup = (string) file_get_contents($this->project . '/web/sites/default/settings.php');
        $this->sh('ddev drush sql:drop -y');
        $this->sh('task setup');
        self::assertStringContainsString(
            'data-qa="card"',
            $this->sh('curl -fsS ' . escapeshellarg("http://{$this->name}.ddev.site/")),
        );
        // task setup:site's own site:install runs through the same backup/restore as `mfd new`
        // (template/Taskfile.yml); confirm settings.php is still clean and nothing committable
        // holds the real salt after it too.
        $this->assertNoSecretsAreCommittable();
        self::assertFalse(
            DrupalStep::containsLeakedSecret(
                (string) file_get_contents($this->project . '/web/sites/default/settings.php'),
            ),
            'settings.php contains a leaked secret after `task setup`',
        );
        self::assertSame(
            $settingsBeforeSetup,
            (string) file_get_contents($this->project . '/web/sites/default/settings.php'),
            'task setup:site did not restore settings.php byte-for-byte',
        );

        // The full local check passes.
        $this->sh('task check');
    }

    /**
     * settings.php holds no secrets, the real hash_salt lives only in the git-ignored
     * settings.ddev.php, and that salt appears in no file `git ls-files -co --exclude-standard`
     * would put in the repository.
     */
    private function assertNoSecretsAreCommittable(): void
    {
        // Anchored to the start of a line: Drupal core's own default.settings.php documentation
        // comments contain this exact text as indented example prose (e.g. the line
        // " * $databases['default']['default'] = ["), which every generated settings.php carries
        // unconditionally and an unanchored check would misflag as a leak.
        $settings = (string) file_get_contents($this->project . '/web/sites/default/settings.php');
        self::assertDoesNotMatchRegularExpression("/^\\\$settings\\['hash_salt'\\] = '[^']+';/m", $settings);
        self::assertDoesNotMatchRegularExpression("/^\\\$databases\\['default'\\]/m", $settings);

        $ddevSettings = (string) file_get_contents($this->project . '/web/sites/default/settings.ddev.php');
        self::assertMatchesRegularExpression("/hash_salt'\\] = '([^']+)'/", $ddevSettings);
        preg_match("/hash_salt'\\] = '([^']+)'/", $ddevSettings, $matches);
        $salt = $matches[1] ?? null;
        self::assertIsString($salt, 'settings.ddev.php has no real salt to check the rest of the tree against');
        self::assertNotSame('', $salt);

        $listed = trim($this->sh('git ls-files -co --exclude-standard'));
        $committable = $listed === '' ? [] : explode("\n", $listed);
        foreach ($committable as $relative) {
            $path = $this->project . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            self::assertStringNotContainsString(
                $salt,
                (string) file_get_contents($path),
                $relative . ' would commit the real hash_salt',
            );
        }

        $this->sh('git check-ignore -q web/sites/default/settings.ddev.php');
    }

    private function makeComponent(string $name): void
    {
        $published = getenv('MFD_E2E_PUBLISHED') === '1';
        if ($published || is_file($this->project . '/vendor/bin/mfd')) {
            self::assertFileExists(
                $this->project . '/vendor/bin/mfd',
                'manifesto/mfd was not installed in the project',
            );
            $this->sh('task fe:component -- ' . $name);

            return;
        }
        $this->sh(escapeshellarg(Mfd::root() . '/bin/mfd') . ' make:component ' . $name);
        $this->sh('ddev drush cr');
    }

    private function sh(string $command, ?string $cwd = null, bool $mustPass = true): string
    {
        $process = Process::fromShellCommandline($command, $cwd ?? $this->project, null, null, null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite(STDERR, $buffer);
        });
        $output = $process->getOutput() . $process->getErrorOutput();
        if ($mustPass && !$process->isSuccessful()) {
            self::fail(sprintf("`%s` failed (exit %s):\n%s", $command, (string) $process->getExitCode(), $output));
        }

        return $output;
    }
}
