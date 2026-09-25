<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\Shell;

/** Read-only checks before `mfd new` writes anything. Every failure is a UserError (exit 2). */
final class Preflight
{
    public const MIN_DDEV = '1.25.0';
    public const MIN_NODE = 20;
    private const TOOLS = ['ddev', 'docker', 'node', 'npm', 'git', 'task', 'python3'];

    public function __construct(private readonly Shell $shell, private readonly HarnessPaths $harness)
    {
    }

    public function check(ProjectConfig $config, string $targetDir): void
    {
        $this->checkTools();
        $this->checkHarness();
        $this->checkTarget($config, $targetDir);
        $this->checkDdevName($config, $targetDir);
    }

    private function checkTools(): void
    {
        $missing = array_values(array_filter(self::TOOLS, fn (string $tool): bool => !$this->shell->has($tool)));
        if ($missing !== []) {
            throw new UserError('missing required tools: ' . implode(' ', $missing));
        }
        $cwd = (string) getcwd();
        try {
            $ddevOutput = $this->shell->capture(['ddev', '--version'], $cwd);
            $nodeMajor = (int) $this->shell->capture(['node', '-p', 'process.versions.node.split(".")[0]'], $cwd);
        } catch (CommandFailed $e) {
            throw new UserError('could not read tool versions: ' . $e->getMessage(), 0, $e);
        }
        $ddev = preg_match('/v(\d+\.\d+\.\d+)/', $ddevOutput, $m) === 1 ? $m[1] : '0.0.0';
        if (version_compare($ddev, self::MIN_DDEV, '<')) {
            throw new UserError(sprintf('DDEV %s or later is required (found %s)', self::MIN_DDEV, $ddev));
        }
        if (!$this->shell->succeeds(['docker', 'info'], $cwd)) {
            throw new UserError('Docker is not running. Start it and retry.');
        }
        if ($nodeMajor < self::MIN_NODE) {
            throw new UserError(sprintf('Node.js %d or later is required (found %d)', self::MIN_NODE, $nodeMajor));
        }
    }

    private function checkHarness(): void
    {
        if (!is_file($this->harness->qaInit)) {
            throw new UserError(sprintf(
                'mf-harness design-review installer not found at %s. '
                    . 'Install or update the harness (mf-harness ./update.sh), or set QA_INIT.',
                $this->harness->qaInit,
            ));
        }
        if (!is_file($this->harness->probe)) {
            throw new UserError(sprintf(
                'mf-harness setup probe not found at %s. Install or update the harness, or set HARNESS_PROBE.',
                $this->harness->probe,
            ));
        }
        $installer = (string) file_get_contents($this->harness->qaInit);
        if (!str_contains($installer, '--storybook')) {
            throw new UserError(sprintf(
                'the installed mf-harness design-review installer at %s has no Storybook support. mfd needs '
                    . 'the Storybook SDC lane from mf-harness (branch feature/drupal-storybook-sdc, until it is '
                    . 'merged). Update the harness, or point QA_INIT at a qa-init.sh that has it.',
                $this->harness->qaInit,
            ));
        }
    }

    private function checkTarget(ProjectConfig $config, string $targetDir): void
    {
        if (!file_exists($targetDir)) {
            return;
        }
        if (!is_dir($targetDir)) {
            throw new UserError($targetDir . ' exists and is not a directory');
        }
        $recorded = (new State($targetDir))->recordedInputs();
        if ($recorded !== null) {
            if ($recorded !== $config->inputsRecord()) {
                throw new UserError(sprintf(
                    "%s was created with different options:\n%sRerun with the same options to resume, "
                        . 'or choose another --dir.',
                    $targetDir,
                    $recorded,
                ));
            }

            return;
        }
        if (array_diff((array) scandir($targetDir), ['.', '..']) !== []) {
            throw new UserError($targetDir . ' is not empty. Choose an empty or new directory with --dir.');
        }
    }

    private function checkDdevName(ProjectConfig $config, string $targetDir): void
    {
        if ((new State($targetDir))->recordedInputs() !== null) {
            return;
        }
        if ($this->shell->succeeds(['ddev', 'describe', $config->project], (string) getcwd())) {
            throw new UserError(sprintf(
                "a DDEV project named '%s' already exists. "
                    . 'Choose another project name, or remove it with: ddev delete -Oy %s',
                $config->project,
                $config->project,
            ));
        }
    }
}
