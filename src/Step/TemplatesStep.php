<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Npm\PackageMerger;
use Manifesto\Mfd\Template\Renderer;

/**
 * mfd's own project files (Taskfile, PHPUnit, linters, docs, example tests), the
 * lint scripts merged into package.json, and a DDEV restart so the test
 * environment variables take effect.
 */
final class TemplatesStep implements Step
{
    public function name(): string
    {
        return 'templates';
    }

    public function plan(Context $context): array
    {
        return [
            sprintf(
                'copy template/ (Taskfile, .taskfiles/, phpunit.xml.dist, phpcs, phpstan, ESLint, Stylelint, '
                . 'CLAUDE.md, README.md, %s_tests module)',
                $context->config->theme,
            ),
            'merge lint scripts and tools into package.json; npm install',
            'ddev restart (loads .ddev/config.testing.yaml)',
        ];
    }

    public function run(Context $context): void
    {
        $lines = (new Renderer())->render(
            $context->packageRoot . '/template',
            $context->projectDir,
            $context->config->placeholders(),
        );
        foreach ($lines as $line) {
            $context->log($line);
        }
        (new PackageMerger())->merge(
            $context->path('package.json'),
            $context->packageRoot . '/resources/package-additions.json',
        );
        $context->shell->run(['npm', 'install'], $context->projectDir);
        $context->shell->run(['ddev', 'restart'], $context->projectDir);
    }
}
