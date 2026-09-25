<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Component\ComponentGenerator;

/**
 * Custom theme from core's starterkit, the example card component, and the card
 * placed on the front page so the site story and the ExistingSite test have a
 * real Drupal render to check.
 */
final class ThemeStep implements Step
{
    private const CONTENT = '{{ page.content }}';

    public function name(): string
    {
        return 'theme';
    }

    public function plan(Context $context): array
    {
        $c = $context->config;

        return [
            sprintf(
                'ddev exec vendor/bin/dr generate-theme %s --name "%s" --path themes/custom',
                $c->theme,
                $c->themeLabel,
            ),
            sprintf('write web/themes/custom/%s/components/card/ (the example component)', $c->theme),
            sprintf(
                'write web/themes/custom/%s/templates/layout/page--front.html.twig (includes the card)',
                $c->theme,
            ),
            sprintf('ddev drush theme:enable %s; set it as the default theme; clear caches', $c->theme),
        ];
    }

    public function run(Context $context): void
    {
        $theme = $context->config->theme;
        $themeDir = $context->path('web/themes/custom/' . $theme);
        $dir = $context->projectDir;

        // Drupal 11.4: vendor/bin/dr is the generator; core/scripts/drupal is deprecated.
        if (!is_file("$themeDir/$theme.info.yml")) {
            $context->shell->run(
                [
                    'ddev', 'exec', 'vendor/bin/dr', 'generate-theme', $theme,
                    '--name', $context->config->themeLabel, '--path', 'themes/custom',
                ],
                $dir,
            );
        }
        if (!is_file("$themeDir/$theme.info.yml")) {
            throw new \RuntimeException("the theme generator did not create $themeDir/$theme.info.yml");
        }
        if (!is_dir("$themeDir/components/card")) {
            (new ComponentGenerator())->generate($themeDir, 'card');
        }
        $this->placeCardOnFrontPage($themeDir . '/templates/layout', $theme);
        $context->shell->run(['ddev', 'drush', 'theme:enable', $theme, '-y'], $dir);
        $context->shell->run(['ddev', 'drush', 'config:set', 'system.theme', 'default', $theme, '-y'], $dir);
        $context->shell->run(['ddev', 'drush', 'cr'], $dir);
    }

    /** Copy the generated page template to page--front and include the card just before the main content. */
    private function placeCardOnFrontPage(string $layoutDir, string $theme): void
    {
        $front = $layoutDir . '/page--front.html.twig';
        if (is_file($front)) {
            return;
        }
        $page = $layoutDir . '/page.html.twig';
        $text = is_file($page) ? (string) file_get_contents($page) : '';
        $position = strpos($text, self::CONTENT);
        if ($position === false) {
            throw new \RuntimeException(
                $page . ' has no ' . self::CONTENT . '; cannot place the example card on the front page',
            );
        }
        $card = sprintf("{{ include('%s:card', { heading: 'Welcome' }, with_context = false) }}\n      ", $theme);
        file_put_contents($front, substr_replace($text, $card . self::CONTENT, $position, strlen(self::CONTENT)));
    }
}
