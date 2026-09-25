<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

/** Warnings collected during a run, repeated in the final summary. */
final class Notices
{
    /** @var list<string> */
    private array $items = [];

    public function add(string $notice): void
    {
        $this->items[] = $notice;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->items;
    }
}
