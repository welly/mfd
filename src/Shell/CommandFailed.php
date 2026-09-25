<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

/** An external command exited non-zero. */
final class CommandFailed extends \RuntimeException
{
    /** @param list<string> $command */
    public function __construct(public readonly array $command, public readonly int $exitCode, string $errorOutput)
    {
        $tail = trim(implode("\n", array_slice(explode("\n", trim($errorOutput)), -20)));
        parent::__construct(sprintf(
            '`%s` exited %d%s',
            implode(' ', $command),
            $exitCode,
            $tail === '' ? '' : ":\n" . $tail,
        ));
    }
}
