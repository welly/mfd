<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Harness;

/** Where the mf-harness pieces mfd calls are installed. */
final readonly class HarnessPaths
{
    public function __construct(public string $qaInit, public string $probe)
    {
    }

    /** The installed harness under ~/.claude; QA_INIT and HARNESS_PROBE override. */
    public static function fromEnvironment(): self
    {
        $home = (string) getenv('HOME');

        return new self(
            self::env('QA_INIT') ?? $home . '/.claude/skills/design-review/scaffold/qa-init.sh',
            self::env('HARNESS_PROBE') ?? $home . '/.claude/lib/setup_project_probe.py',
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? null : $value;
    }
}
