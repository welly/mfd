<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Exception\StepFailed;
use Manifesto\Mfd\State;

/** Runs the steps in order, skipping those already done. */
final class StepRunner
{
    /** @param list<Step> $steps */
    public function __construct(private readonly array $steps)
    {
    }

    public function plan(Context $context): void
    {
        foreach ($this->steps as $step) {
            $context->output->writeln('==> ' . $step->name());
            foreach ($step->plan($context) as $line) {
                $context->log($line);
            }
        }
    }

    /** @throws StepFailed */
    public function run(Context $context, State $state): void
    {
        foreach ($this->steps as $step) {
            $name = $step->name();
            if ($state->isDone($name)) {
                $context->output->writeln(sprintf('==> %s: skipped (already done)', $name));
                continue;
            }
            $context->output->writeln('==> ' . $name);
            try {
                $step->run($context);
            } catch (\Throwable $e) {
                throw new StepFailed($name, $e);
            }
            $state->markDone($name);
            $context->log('done');
        }
    }
}
