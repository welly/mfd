<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Exception;

/** Bad input or a failed pre-flight check. Nothing has been written. Exit code 2. */
final class UserError extends \RuntimeException
{
}
