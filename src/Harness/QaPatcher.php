<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Harness;

/**
 * Points the mf-harness design review at a DDEV project. qa/gate.config.ts and
 * qa/stories.ts are project-owned in the harness (qa-scaffold.py PROJECT_OWNED),
 * so these edits survive harness updates. qa/figma-map.json is not PROJECT_OWNED;
 * it survives updates only because the harness installer copies it in solely when
 * it is absent, never overwriting an existing one.
 */
final class QaPatcher
{
    private const GATE = 'qa/gate.config.ts';

    private const STORIES = <<<'TS'
        // qa/stories.ts - site stories: real Drupal pages the review checks in the running site.
        // Use each component's plugin ID, provider:machine-name, as `component`. A component
        // with a Storybook story but no entry here is reported as QA-SBONLY.
        import type { Story } from './story';

        export const stories: Story[] = [
          {
            id: 'card-front',
            component: '__THEME__:card',
            path: '/',
            contract: { requiredSelectors: ['[data-qa="card"]'] },
          },
        ];

        TS;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function apply(string $project, string $theme): void
    {
        $this->patchGate($project);
        file_put_contents($this->projectDir . '/qa/stories.ts', str_replace('__THEME__', $theme, self::STORIES));
        // The harness's example entries stop the review before it checks anything.
        file_put_contents($this->projectDir . '/qa/figma-map.json', "{}\n");
    }

    private function patchGate(string $project): void
    {
        $file = $this->projectDir . '/' . self::GATE;
        if (!is_file($file)) {
            throw new \RuntimeException(self::GATE . ' not found; the harness installer should have written it');
        }
        $text = (string) file_get_contents($file);
        $replacements = [
            "'http://localhost:3000'" => sprintf("'http://%s.ddev.site'", $project),
            "'npm run dev'" => "'ddev start'",
        ];
        foreach ($replacements as $old => $new) {
            if (str_contains($text, $new)) {
                continue;
            }
            $position = strpos($text, $old);
            if ($position === false) {
                throw new \RuntimeException(sprintf(
                    "%s: %s not found, so the harness template changed. Set baseUrl to 'http://%s.ddev.site' and "
                        . "devServerCommand to 'ddev start' by hand.",
                    self::GATE,
                    $old,
                    $project,
                ));
            }
            $text = substr_replace($text, $new, $position, strlen($old));
        }
        file_put_contents($file, $text);
    }
}
