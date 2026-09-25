<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Harness\QaPatcher;

/** The mf-harness design-review tooling with the Storybook lane, pointed at DDEV. */
final class HarnessStep implements Step
{
    public function name(): string
    {
        return 'harness';
    }

    public function plan(Context $context): array
    {
        return [
            sprintf(
                'bash %s --yes --storybook   (Storybook, qa/ tooling, Playwright browsers)',
                $context->harness->qaInit,
            ),
            sprintf(
                'patch qa/gate.config.ts (%s, ddev start), qa/stories.ts (front-page card), qa/figma-map.json ({})',
                $context->config->siteUrl(),
            ),
        ];
    }

    public function run(Context $context): void
    {
        $context->shell->run(['bash', $context->harness->qaInit, '--yes', '--storybook'], $context->projectDir);
        (new QaPatcher($context->projectDir))->apply($context->config->project, $context->config->theme);
    }
}
