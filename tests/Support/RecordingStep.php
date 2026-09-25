<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Step\Context;
use Manifesto\Mfd\Step\Step;

/** A step that records when it starts and ends, and can be told to fail between the two. */
final class RecordingStep implements Step
{
    public bool $fail = false;

    /** @param \ArrayObject<int, string> $journal */
    public function __construct(private readonly string $name, private readonly \ArrayObject $journal)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function plan(Context $context): array
    {
        return ['would run ' . $this->name];
    }

    public function run(Context $context): void
    {
        $this->journal[] = $this->name . '-start';
        if ($this->fail) {
            throw new \RuntimeException('forced failure in ' . $this->name);
        }
        $this->journal[] = $this->name . '-end';
    }
}
