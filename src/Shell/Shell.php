<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

/**
 * Every external command mfd runs goes through here, as an argv array: never a
 * shell string, so values are never quoted or interpreted by a shell.
 */
interface Shell
{
    /** Whether an executable is on PATH. */
    public function has(string $tool): bool;

    /**
     * Run a command in $cwd, streaming its output.
     *
     * @param list<string> $command
     * @throws CommandFailed on a non-zero exit code
     */
    public function run(array $command, string $cwd): void;

    /**
     * Run a command in $cwd and return its trimmed standard output.
     *
     * @param list<string> $command
     * @throws CommandFailed on a non-zero exit code
     */
    public function capture(array $command, string $cwd): string;

    /**
     * Run a command in $cwd quietly; true when it exits 0. Never throws.
     *
     * @param list<string> $command
     */
    public function succeeds(array $command, string $cwd): bool;
}
