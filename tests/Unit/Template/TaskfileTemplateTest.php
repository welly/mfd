<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;

final class TaskfileTemplateTest extends RenderedTemplateTestCase
{
    public function testTaskfileParsesAndListsBackEndFrontEndAndQaTasks(): void
    {
        $process = $this->runIn(['task', '--list']);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $tasks = [
            'up', 'down', 'setup', 'dev', 'check', 'drush', 'be:install', 'be:require', 'be:update', 'be:cr',
            'be:deploy', 'be:cex', 'be:cim', 'be:login', 'be:db:export', 'be:db:import', 'be:logs', 'be:xdebug',
            'be:test', 'be:test:unit', 'be:test:kernel', 'be:test:site', 'be:lint', 'be:lint:fix', 'fe:install',
            'fe:build', 'fe:watch', 'fe:lint', 'fe:lint:fix', 'fe:test', 'fe:storybook', 'fe:storybook:build',
            'fe:component', 'qa:review', 'qa:review:storybook', 'qa:review:scoped', 'qa:probe',
        ];
        foreach ($tasks as $task) {
            self::assertStringContainsString('* ' . $task . ':', $process->getOutput(), 'missing task ' . $task);
        }
    }

    public function testBeTestForwardsArgumentsToPhpunitInsideDdev(): void
    {
        $out = $this->runIn(['task', '--dry', 'be:test', '--', '--filter', 'FrontPage'])->getErrorOutput();

        self::assertStringContainsString('ddev exec vendor/bin/phpunit --filter FrontPage', $out);
    }

    public function testSuiteTasksSelectTheirSuite(): void
    {
        $site = $this->runIn(['task', '--dry', 'be:test:site'])->getErrorOutput();
        $kernel = $this->runIn(['task', '--dry', 'be:test:kernel'])->getErrorOutput();
        $unit = $this->runIn(['task', '--dry', 'be:test:unit'])->getErrorOutput();

        self::assertStringContainsString('--testsuite existing-site', $site);
        self::assertStringContainsString('--testsuite kernel', $kernel);
        self::assertStringContainsString('--testsuite unit', $unit);
    }

    public function testFrontEndTasksGoThroughNpmScriptsIfPresent(): void
    {
        $build = $this->runIn(['task', '--dry', 'fe:build'])->getErrorOutput();
        $lint = $this->runIn(['task', '--dry', 'fe:lint'])->getErrorOutput();
        $test = $this->runIn(['task', '--dry', 'fe:test'])->getErrorOutput();

        self::assertStringContainsString('npm run --if-present build', $build);
        self::assertStringContainsString('npm run --if-present lint', $lint);
        self::assertStringContainsString('npm run --if-present test:js', $test);
    }

    public function testComponentTaskRunsMfdInsideDdev(): void
    {
        $out = $this->runIn(['task', '--dry', 'fe:component', '--', 'hero'])->getErrorOutput();

        self::assertStringContainsString('ddev exec vendor/bin/mfd make:component hero', $out);
        self::assertStringContainsString('ddev drush cr', $out);
    }

    /**
     * mfd itself lets HARNESS_PROBE override the probe script location (HarnessPaths). qa:probe
     * must honour the same override instead of hard-coding the default path, so a project set up
     * against a non-default harness checkout (or under test) probes the right script.
     */
    public function testProbeTaskHonoursTheHarnessProbeOverride(): void
    {
        $out = $this->runIn(['task', '--dry', 'qa:probe'])->getErrorOutput();

        self::assertStringContainsString(
            'python3 "${HARNESS_PROBE:-$HOME/.claude/lib/setup_project_probe.py}" probe --root .',
            $out,
        );
    }

    public function testMissingBuildScriptIsANoOp(): void
    {
        file_put_contents($this->dir . '/package.json', '{"scripts":{}}');
        $process = $this->runIn(['task', 'fe:build']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testADefinedBuildScriptRunsWithItsArguments(): void
    {
        file_put_contents($this->dir . '/package.json', '{"scripts":{"build":"echo built"}}');
        $process = $this->runIn(['task', 'fe:build', '--', '--mode', 'prod']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('built --mode prod', $process->getOutput());
    }

    public function testDbImportWithoutAFileFailsWithUsage(): void
    {
        $process = $this->runIn(['task', 'be:db:import']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('task be:db:import -- ', $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * qa:review is advisory (qa.yml: "nothing here blocks a merge"), including inside `task check`.
     * A design review with non-critical findings exits non-zero; `check` must not fail because of it.
     */
    /**
     * Every task depends on `up`. Without a status check, each one runs `ddev start`, which
     * rebuilds the images (seconds per task) even when the project is already running.
     */
    public function testUpIsSkippedWhenTheProjectIsAlreadyRunning(): void
    {
        self::assertStringContainsString(
            "      - ddev start\n    status:\n      - ddev exec true >/dev/null 2>&1\n",
            $this->read('Taskfile.yml'),
        );
    }

    public function testCheckDoesNotFailWhenTheDesignReviewFindsNonCriticalIssues(): void
    {
        self::assertStringContainsString(
            "      - task: qa:review\n        ignore_error: true\n",
            $this->read('Taskfile.yml'),
        );
    }

    /**
     * The no-dump branch of setup:site runs `drush site:install --existing-config` against an
     * already-committed settings.php; that installer can rewrite real secrets into it exactly as
     * DrupalStep's own site:install can, so the same backup/restore discipline applies here.
     */
    public function testSetupSiteBacksUpAndRestoresSettingsPhpAroundSiteInstall(): void
    {
        $out = $this->runIn(['task', '--dry', 'setup'])->getErrorOutput();

        self::assertStringContainsString('mktemp', $out);
        self::assertStringContainsString('cp "$settings_file" "$backup_file"', $out);
        self::assertStringContainsString('ddev drush site:install --existing-config -y', $out);
        self::assertStringContainsString('chmod u+w "$settings_file"', $out);
        self::assertStringContainsString('cp "$backup_file" "$settings_file"', $out);
        self::assertStringContainsString('rm -f "$backup_file"', $out);
        self::assertStringContainsString('exit "$status"', $out);
    }

    /**
     * The backup must fail closed (never run the install against a file it then can't restore).
     * The restore itself runs as one grouped `{ ... } || { ... }` command (F2, fix round 3): a
     * bare failing command in the restore would abort the whole script under Task's errexit and
     * let the `trap` delete the backup on its way out, exactly when it is needed most (see
     * `SetupSiteRestoreTest` for the real-`task` proof of that). Grouping keeps any failure
     * inside it, so it is caught here rather than the script aborting underneath it, and the
     * failure branch explicitly disables the trap so the backup survives.
     */
    public function testSetupSiteBackupFailsClosedAndTheRestoreChecksItself(): void
    {
        $out = $this->runIn(['task', '--dry', 'setup'])->getErrorOutput();

        self::assertStringContainsString('cp "$settings_file" "$backup_file" || {', $out);
        self::assertStringContainsString('could not back up $settings_file', $out);
        self::assertStringContainsString("trap 'rm -f \"\$backup_file\"' EXIT", $out);
        self::assertStringContainsString(
            '{ chmod u+w "$settings_file" && cp "$backup_file" "$settings_file" && chmod 0644 "$settings_file"',
            $out,
        );
        self::assertStringContainsString('&& cmp -s "$settings_file" "$backup_file"; } || {', $out);
        self::assertStringContainsString('trap - EXIT', $out);
        self::assertStringContainsString('could not restore $settings_file', $out);
        self::assertStringContainsString('Clean copy kept at $backup_file', $out);
    }
}
