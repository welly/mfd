<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * F2: `setup:site`'s backup/restore must survive Task's errexit. `TaskfileTemplateTest`'s
 * `--dry` assertions only check the script's text, never runs it, so they cannot see a bare
 * command aborting the script mid-restore. This runs the real, rendered `Taskfile.yml` for real
 * (`task setup`, no `--dry`) against a fake `ddev` on PATH, covering every branch the grouped
 * restore command is meant to handle. `setup:site` itself is `internal: true` and cannot be
 * invoked directly from the CLI, so this goes through the public `setup` task, which reaches it
 * via `up` and `be:install` (both a no-op against the fake `ddev`) and `fe:install` (a no-op
 * against a fake `npm`); nothing here re-implements or duplicates the real script.
 *
 * Skipped if `task` is not on the host, since none of the rest of this suite runs it for real.
 */
final class SetupSiteRestoreTest extends RenderedTemplateTestCase
{
    private string $settingsFile;

    protected function setUp(): void
    {
        if ((new ExecutableFinder())->find('task') === null) {
            self::markTestSkipped('task is not installed on this host.');
        }
        parent::setUp();
        $this->settingsFile = $this->dir . '/web/sites/default/settings.php';
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "<?php\n\$settings['hash_salt'] = '';\n");
    }

    protected function tearDown(): void
    {
        // A test may have left web/sites/default unsearchable (FAKE_DDEV_BREAK_DIR) or
        // settings.php unreadable (the backup-fails scenario); restore both before the parent
        // TempDirectory trait's #[After] cleanup tries to remove the whole tree.
        @chmod(dirname($this->settingsFile), 0755);
        @chmod($this->settingsFile, 0644);
        parent::tearDown();
    }

    /** @param array<string, string> $env */
    private function runSetup(array $env): Process
    {
        $process = new Process(
            ['task', 'setup'],
            $this->dir,
            $env + ['PATH' => Mfd::root() . '/tests/fixtures/bin-setup-site:' . getenv('PATH')],
        );
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    public function testInstallSucceedsAndLeaksSettingsIsRestoredWithAWarningAndExitsZero(): void
    {
        $before = (string) file_get_contents($this->settingsFile);

        $process = $this->runSetup(['FAKE_DDEV_LEAK' => '1']);

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString(
            'warning: site:install changed web/sites/default/settings.php; restored the committed version',
            $process->getErrorOutput(),
        );
        self::assertSame($before, (string) file_get_contents($this->settingsFile));
        self::assertFileExists($this->dir . '/.fake-site-install-ran');
    }

    public function testInstallFailsWithExitSevenAndLeaksSettingsIsStillRestoredAndStatusSevenIsReported(): void
    {
        $before = (string) file_get_contents($this->settingsFile);

        $process = $this->runSetup(['FAKE_DDEV_LEAK' => '1', 'FAKE_DDEV_EXIT' => '7']);

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('exit status 7', $process->getErrorOutput());
        self::assertSame($before, (string) file_get_contents($this->settingsFile));
    }

    public function testRestoreFailureIsReportedAndKeepsTheBackup(): void
    {
        $process = $this->runSetup(['FAKE_DDEV_LEAK' => '1', 'FAKE_DDEV_BREAK_DIR' => '1']);
        // The fake ddev leaves web/sites/default unsearchable; put it back so the assertions
        // below (and the parent tearDown) can reach settings.php and the rest of the tree again.
        chmod(dirname($this->settingsFile), 0755);

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString(
            'error: could not restore web/sites/default/settings.php; it may contain secrets, do not commit it.',
            $process->getErrorOutput(),
        );
        self::assertStringContainsString('Clean copy kept at', $process->getErrorOutput());
        self::assertFileExists($this->dir . '/.fake-site-install-ran', 'the install itself must still have run');

        $backup = self::extractBackupPath($process->getErrorOutput());
        self::assertFileExists($backup, 'the backup must survive a failed restore, not be deleted by the EXIT trap');
        unlink($backup);
    }

    public function testBackupFailureStopsBeforeTheInstallEverRuns(): void
    {
        chmod($this->settingsFile, 0000);

        $process = $this->runSetup([]);

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString(
            'error: could not back up web/sites/default/settings.php',
            $process->getErrorOutput(),
        );
        self::assertFileDoesNotExist($this->dir . '/.fake-site-install-ran');
    }

    /**
     * Task echoes each multi-line command's own source text before running it, so the literal
     * string "Clean copy kept at $backup_file" (the unexpanded shell variable, from the script
     * listing) appears in the output before the real, expanded path from actually running it.
     * The last match is always the real one.
     */
    private static function extractBackupPath(string $output): string
    {
        self::assertMatchesRegularExpression('/Clean copy kept at (\S+)/', $output);
        preg_match_all('/Clean copy kept at (\S+)/', $output, $matches);

        return (string) end($matches[1]);
    }
}
