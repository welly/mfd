<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Project\ProjectRoot;
use Manifesto\Mfd\Shell\CommandFailed;

/** Export configuration, create the git repository (never a commit), write .mfd.json, report the harness setup state. */
final class FinishStep implements Step
{
    public function name(): string
    {
        return 'finish';
    }

    public function plan(Context $context): array
    {
        return [
            'ddev drush config:export (config/sync)',
            'git init (no commit); write ' . ProjectRoot::MARKER,
            'run the harness setup probe and print its next step',
        ];
    }

    public function run(Context $context): void
    {
        if (!is_dir($context->path('config/sync'))) {
            mkdir($context->path('config/sync'), 0777, true);
        }
        $context->shell->run(['ddev', 'drush', 'config:export', '-y'], $context->projectDir);
        if (!is_dir($context->path('.git'))) {
            $context->shell->run(['git', 'init', '-q'], $context->projectDir);
        }
        $this->writeMarker($context);
        $this->reportProbe($context);
    }

    private function writeMarker(Context $context): void
    {
        $c = $context->config;
        $marker = [
            'mfdVersion' => Mfd::VERSION,
            'created' => date('Y-m-d'),
            'project' => $c->project,
            'theme' => $c->theme,
            'themeLabel' => $c->themeLabel,
            'siteName' => $c->siteName,
        ];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        file_put_contents(
            $context->path(ProjectRoot::MARKER),
            json_encode($marker, $flags) . "\n",
        );
    }

    private function reportProbe(Context $context): void
    {
        try {
            $json = $context->shell->capture(
                ['python3', $context->harness->probe, 'probe', '--root', '.'],
                $context->projectDir,
            );
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $next = is_array($data) && is_string($data['nextStep'] ?? null) ? $data['nextStep'] : 'unknown';
            $context->log('harness setup: next step is ' . $next);
        } catch (CommandFailed | \JsonException) {
            $context->warn(
                'the harness setup probe failed; run /setup-project in Claude Code to see the setup state',
            );
        }
    }
}
