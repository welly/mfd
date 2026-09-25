<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

/** Package-wide facts. */
final class Mfd
{
    public const VERSION = '0.1.0';

    /** The package root: where template/ and resources/ live, in a checkout or in vendor/. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }
}
