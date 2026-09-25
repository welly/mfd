<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Component;

use Manifesto\Mfd\Exception\UserError;

/** Writes a Single Directory Component: metadata with examples, a Twig template with data-qa, and a stylesheet. */
final class ComponentGenerator
{
    private const NAME = '/^[a-z][a-z0-9_-]*$/D';

    /** @return list<string> the files written, relative to $themeDir */
    public function generate(string $themeDir, string $name): array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new UserError(sprintf(
                "component name '%s' must be lowercase letters, digits, '-' or '_', starting with a letter",
                $name,
            ));
        }
        $relative = 'components/' . $name;
        $dir = $themeDir . '/' . $relative;
        if (file_exists($dir)) {
            throw new \RuntimeException($dir . ' already exists');
        }
        $label = ucwords(strtr($name, '-_', '  '));
        mkdir($dir, 0777, true);

        $files = [
            // The metadata schema URL below is fixed content; phpcs:disable rather than reflow it.
            // phpcs:disable Generic.Files.LineLength.TooLong
            "$relative/$name.component.yml" => <<<YAML
                \$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
                name: $label
                props:
                  type: object
                  properties:
                    attributes:
                      type: Drupal\Core\Template\Attribute
                      title: Attributes
                    heading:
                      type: string
                      title: Heading
                      examples: ['$label heading']
                slots:
                  content:
                    title: Content

                YAML,
            // phpcs:enable Generic.Files.LineLength.TooLong
            "$relative/$name.twig" => <<<TWIG
                <div{{ attributes.addClass('$name') }} data-qa="$name">
                  <h2 class="{$name}__heading">{{ heading }}</h2>
                  {% block content %}{% endblock %}
                </div>

                TWIG,
            "$relative/$name.css" => "/* Styles for the $label component. */\n",
        ];
        foreach ($files as $path => $content) {
            file_put_contents($themeDir . '/' . $path, $content);
        }

        return array_keys($files);
    }
}
