<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Runs commands with Symfony Process: no shell, no timeout, output streamed to the console. */
final class ProcessShell implements Shell
{
    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function has(string $tool): bool
    {
        return (new ExecutableFinder())->find($tool) !== null;
    }

    public function run(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
        if (!$process->isSuccessful()) {
            throw new CommandFailed($command, $process->getExitCode() ?? 1, $process->getErrorOutput());
        }
    }

    public function capture(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new CommandFailed($command, $process->getExitCode() ?? 1, $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    public function succeeds(array $command, string $cwd): bool
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run();

        return $process->isSuccessful();
    }
}
