<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Exception;

/** A step of `mfd new` failed. Rerunning resumes at that step. Exit code 1. */
final class StepFailed extends \RuntimeException
{
    public function __construct(public readonly string $step, \Throwable $previous)
    {
        parent::__construct(sprintf("step '%s' failed: %s", $step, $previous->getMessage()), 0, $previous);
    }
}
