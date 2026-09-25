<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

/**
 * One stage of `mfd new`. To add a step: implement this, then list it in Steps::all().
 * run() must be safe to call again after it failed part-way through.
 */
interface Step
{
    /** Machine name used in output and resume state, e.g. "drupal". */
    public function name(): string;

    /** @return list<string> What run() will do, for --dry-run. */
    public function plan(Context $context): array;

    /** Do the work. Throw to fail; the run can then be resumed. */
    public function run(Context $context): void;
}
