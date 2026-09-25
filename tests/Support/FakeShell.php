<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\Shell;

/**
 * Test double for Shell. Records every command and fakes the files DDEV, Composer,
 * the harness installer and git would create, so steps are tested without Docker.
 */
final class FakeShell implements Shell
{
    /** @var list<string> Every command, its arguments joined by single spaces. */
    public array $calls = [];
    public string $ddevVersion = 'v1.25.4';
    public int $nodeMajor = 24;
    public bool $dockerRunning = true;
    public bool $ddevProjectExists = false;
    /** Simulates Drupal's installer occasionally running install_settings_form and writing real secrets. */
    public bool $siteInstallLeaksSecrets = false;
    /** Same leak, but the file has no trailing newline before the append. */
    public bool $siteInstallLeaksWithoutTrailingNewline = false;
    /** A different leak shape: a fresh hash_salt line appended after the DDEV include, not rewritten in place. */
    public bool $siteInstallLeaksSaltAfterInclude = false;
    /** site:install leaks secrets into settings.php and then fails (exit 1). */
    public bool $siteInstallLeaksThenFails = false;
    /** The leak also corrupts the on-disk snapshot, so a secret survives even a correct restore. */
    public bool $secretSurvivesRestore = false;
    /**
     * Something rewrites the on-disk snapshot file (with clean, non-leaking content) during
     * site:install, so it no longer matches the bytes captured in memory before the install ran.
     */
    public bool $corruptsSnapshotDuringInstall = false;
    /**
     * Same tampering as corruptsSnapshotDuringInstall, but the snapshot's own directory also
     * loses write permission, so the restore's repair write to the on-disk snapshot fails too.
     * Proves settings.php itself is still restored correctly even when that repair throws.
     */
    public bool $corruptsSnapshotAndLocksDirectoryDuringInstall = false;
    /**
     * Simulates the restore's own write failing: after site:install, the directory holding
     * settings.php loses even the execute (search) bit, so nothing inside it, including the file
     * itself, can be looked up any more. This survives DrupalStep's own defensive `chmod` (which
     * needs only file ownership, not directory permissions) the way a merely read-only settings.php
     * would not, so it is the one permission change that genuinely fails the restore's write.
     */
    public bool $restoreCannotWriteSettingsPhp = false;
    public string $probeOutput = '{"primaryStack":"drupal","nextStep":"init: run /init to create CLAUDE.md"}';
    /** Content of settings.php the moment the first `ddev` command ran, for order assertions. */
    public ?string $settingsPhpAtFirstDdevCall = null;
    /** Content of settings.php the moment `site:install` was called, before any leak side effect. */
    public ?string $settingsPhpAtSiteInstall = null;

    /** @var list<string> */
    private array $failures = [];
    /** @var list<string> */
    private array $missingTools = [];

    /**
     * Mirrors the shape of Drupal core's own default.settings.php closely enough to catch a real
     * bug found via the e2e run: its documentation comments contain literal, uncommented-looking
     * example text such as "$databases['default']['default'] = [", which a naive unanchored
     * "no secrets" check matches even though it is example prose, not a live assignment.
     */
    public const DDEV_SETTINGS = <<<'PHP'
        <?php
        /**
         * Example database configuration format:
         * $databases['default']['default'] = [
         *   'database' => 'databasename',
         * ];
         */
        $databases = [];
        $settings['hash_salt'] = '';
        if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
          include __DIR__ . '/settings.ddev.php';
        }

        PHP;

    /** Any command whose joined line contains $needle fails with exit code 1. */
    public function failOn(string $needle): void
    {
        $this->failures[] = $needle;
    }

    public function clearFailures(): void
    {
        $this->failures = [];
    }

    public function withoutTool(string $tool): void
    {
        $this->missingTools[] = $tool;
    }

    /** @return list<string> */
    public function callsStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => str_starts_with($call, $prefix),
        ));
    }

    public function has(string $tool): bool
    {
        return !in_array($tool, $this->missingTools, true);
    }

    public function run(array $command, string $cwd): void
    {
        $this->execute($command, $cwd);
    }

    public function capture(array $command, string $cwd): string
    {
        return trim($this->execute($command, $cwd));
    }

    public function succeeds(array $command, string $cwd): bool
    {
        try {
            $this->execute($command, $cwd);

            return true;
        } catch (CommandFailed) {
            return false;
        }
    }

    /** @param list<string> $command */
    private function execute(array $command, string $cwd): string
    {
        $line = implode(' ', $command);
        $this->calls[] = $line;
        // Preflight's own `ddev --version` check runs at getcwd(), before any step; it is not part
        // of DrupalStep's own command sequence, so it is excluded from the "first ddev call" mark.
        if ($command[0] === 'ddev' && $line !== 'ddev --version' && $this->settingsPhpAtFirstDdevCall === null) {
            $settingsFile = $cwd . '/web/sites/default/settings.php';
            $this->settingsPhpAtFirstDdevCall = is_file($settingsFile) ? (string) file_get_contents($settingsFile) : '';
        }
        foreach ($this->failures as $needle) {
            if (str_contains($line, $needle)) {
                throw new CommandFailed($command, 1, 'fake failure for: ' . $line);
            }
        }

        return match ($command[0]) {
            'ddev' => $this->ddev($command, $cwd),
            'docker' => $this->dockerRunning
                ? ''
                : throw new CommandFailed($command, 1, 'Cannot connect to the Docker daemon'),
            'node' => (string) $this->nodeMajor,
            'bash' => $this->qaInit($cwd),
            'python3' => $this->probeOutput,
            'git' => $this->git($command, $cwd),
            default => '',
        };
    }

    /** @param list<string> $command */
    private function ddev(array $command, string $cwd): string
    {
        $args = array_slice($command, 1);
        $line = implode(' ', $args);

        if ($line === '--version') {
            return 'ddev version ' . $this->ddevVersion;
        }
        if (str_starts_with($line, 'describe ')) {
            return $this->ddevProjectExists ? '' : throw new CommandFailed($command, 1, 'no such project');
        }
        if (str_starts_with($line, 'config ')) {
            $this->write($cwd, '.ddev/config.yaml', "name: fake\n");
        } elseif ($line === 'start' || $line === 'restart') {
            if (is_dir($cwd . '/web/sites/default') && !is_file($cwd . '/web/sites/default/settings.php')) {
                $this->write($cwd, 'web/sites/default/settings.php', self::DDEV_SETTINGS);
            }
        } elseif (str_starts_with($line, 'composer create-project')) {
            if (!is_file($cwd . '/composer.json')) {
                $this->write($cwd, 'composer.json', "{\"require\":{\"drupal/core-recommended\":\"^11\"}}\n");
            }
            $this->write($cwd, 'web/core/lib/Drupal.php', "<?php\n");
            $this->write($cwd, 'web/sites/default/default.settings.php', "<?php\n");
        } elseif ($line === 'drush status --field=bootstrap') {
            return is_file($cwd . '/.fake-installed') ? 'Successful' : '';
        } elseif (str_starts_with($line, 'drush site:install')) {
            $settingsFile = $cwd . '/web/sites/default/settings.php';
            $this->settingsPhpAtSiteInstall = is_file($settingsFile) ? (string) file_get_contents($settingsFile) : '';
            if ($this->siteInstallLeaksThenFails) {
                $this->leakSecretsIntoSettings($cwd);
                if ($this->restoreCannotWriteSettingsPhp) {
                    chmod(dirname($settingsFile), 0000);
                }
                throw new CommandFailed($command, 1, 'fake failure: site:install leaked secrets then failed');
            }
            $this->write($cwd, '.fake-installed', '');
            if ($this->secretSurvivesRestore) {
                $this->leakSecretsIntoSettings($cwd, alsoCorruptSnapshot: true);
            } elseif ($this->siteInstallLeaksSecrets) {
                $this->leakSecretsIntoSettings($cwd);
            } elseif ($this->siteInstallLeaksWithoutTrailingNewline) {
                $this->leakSecretsIntoSettings($cwd, trimTrailingNewline: true);
            } elseif ($this->siteInstallLeaksSaltAfterInclude) {
                $this->leakSecretsAfterInclude($cwd);
            } elseif ($this->corruptsSnapshotAndLocksDirectoryDuringInstall) {
                // Also leaks settings.php itself, so the scenario actually exercises the restore
                // writing $expected back, not just the (separate) snapshot-repair failure below.
                $this->leakSecretsIntoSettings($cwd);
            }
            if ($this->corruptsSnapshotDuringInstall || $this->corruptsSnapshotAndLocksDirectoryDuringInstall) {
                $snapshotFile = $cwd . '/.ddev/mfd/settings.php.snapshot';
                if (is_file($snapshotFile)) {
                    file_put_contents($snapshotFile, (string) file_get_contents($snapshotFile) . "// corrupted\n");
                }
                if ($this->corruptsSnapshotAndLocksDirectoryDuringInstall) {
                    chmod(dirname($snapshotFile), 0555);
                }
            }
            if ($this->restoreCannotWriteSettingsPhp) {
                chmod(dirname($settingsFile), 0000);
            }
        } elseif (str_starts_with($line, 'drush config:export')) {
            $this->write($cwd, 'config/sync/system.site.yml', "name: fake\n");
        } elseif (str_starts_with($line, 'exec vendor/bin/dr generate-theme ')) {
            $theme = $args[3];
            $this->write(
                $cwd,
                "web/themes/custom/$theme/templates/layout/page.html.twig",
                "<main>\n  {{ page.content }}\n</main>\n",
            );
            $this->write($cwd, "web/themes/custom/$theme/$theme.info.yml", "name: fake\ntype: theme\n");
        }

        return '';
    }

    /** What the real harness qa-init.sh writes, reduced to what mfd reads. */
    private function qaInit(string $cwd): string
    {
        $this->write($cwd, 'qa/gate.config.ts', "export const gateConfig = {\n"
            . "  baseUrl: process.env.QA_BASE_URL ?? 'http://localhost:3000',\n"
            . "  devServerCommand: process.env.QA_DEV_CMD ?? 'npm run dev',\n};\n");
        $this->write($cwd, 'qa/stories.ts', "export const stories = [{ id: 'resource-card' }];\n");
        $this->write($cwd, 'qa/figma-map.json', "{\"example\": {\"node\": \"1:2\"}}\n");
        if (!is_file($cwd . '/package.json')) {
            $this->write(
                $cwd,
                'package.json',
                "{\n  \"scripts\": {\n    \"qa:loop\": \"echo loop\",\n    \"storybook\": \"echo sb\"\n  },\n"
                    . "  \"devDependencies\": {\n    \"storybook\": \"10.6.0\"\n  }\n}\n",
            );
        }
        file_put_contents($cwd . '/.gitignore', "\n# mf-harness design-review\nqa/reports/\n", FILE_APPEND);

        return '';
    }

    /**
     * Mimics drupal_rewrite_settings(): rewrites the empty hash_salt in place and appends a
     * var_export()-style $databases block at the end of the file, exactly as Drupal's installer
     * does when it (sometimes, nondeterministically) runs install_settings_form.
     */
    private function leakSecretsIntoSettings(
        string $cwd,
        bool $trimTrailingNewline = false,
        bool $alsoCorruptSnapshot = false,
    ): void {
        $file = $cwd . '/web/sites/default/settings.php';
        if (!is_file($file)) {
            return;
        }
        $settings = (string) file_get_contents($file);
        $settings = str_replace(
            "\$settings['hash_salt'] = '';",
            "\$settings['hash_salt'] = 'leaked-real-hash-salt-value';",
            $settings,
        );
        if ($trimTrailingNewline) {
            $settings = rtrim($settings, "\n");
        }
        $settings .= "\$databases['default']['default'] = array (\n"
            . "  'database' => 'db',\n  'username' => 'db',\n  'password' => 'db',\n"
            . ");\n";
        file_put_contents($file, $settings);
        if ($alsoCorruptSnapshot) {
            $snapshotFile = $cwd . '/.ddev/mfd/settings.php.snapshot';
            if (is_file($snapshotFile)) {
                file_put_contents($snapshotFile, $settings);
            }
        }
    }

    /**
     * A different leak shape: instead of rewriting the existing (empty) hash_salt assignment in
     * place, a fresh one is appended after the DDEV include, plus the usual $databases block.
     */
    private function leakSecretsAfterInclude(string $cwd): void
    {
        $file = $cwd . '/web/sites/default/settings.php';
        if (!is_file($file)) {
            return;
        }
        $settings = (string) file_get_contents($file);
        $settings .= "\$settings['hash_salt'] = 'appended-after-include-salt-value';\n";
        $settings .= "\$databases['default']['default'] = array (\n"
            . "  'database' => 'db',\n  'username' => 'db',\n  'password' => 'db',\n"
            . ");\n";
        file_put_contents($file, $settings);
    }

    /** @param list<string> $command */
    private function git(array $command, string $cwd): string
    {
        if (($command[1] ?? '') === 'init' && !is_dir($cwd . '/.git')) {
            mkdir($cwd . '/.git');
        }

        return '';
    }

    private function write(string $cwd, string $relative, string $content): void
    {
        $path = $cwd . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
}
