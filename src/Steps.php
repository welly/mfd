<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Step\DrupalStep;
use Manifesto\Mfd\Step\FinishStep;
use Manifesto\Mfd\Step\HarnessStep;
use Manifesto\Mfd\Step\Step;
use Manifesto\Mfd\Step\TemplatesStep;
use Manifesto\Mfd\Step\ThemeStep;
use Manifesto\Mfd\Step\ToolkitStep;

/** The steps of `mfd new`, in order. Register a new step here. */
final class Steps
{
    /** @return list<Step> */
    public static function all(): array
    {
        return [
            new DrupalStep(),
            new ThemeStep(),
            new HarnessStep(),
            new TemplatesStep(),
            new ToolkitStep(),
            new FinishStep(),
        ];
    }
}
