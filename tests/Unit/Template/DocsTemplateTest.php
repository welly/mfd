<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;

final class DocsTemplateTest extends RenderedTemplateTestCase
{
    public function testClaudeMdAndReadmeNameTheProjectThemeAndTasks(): void
    {
        foreach (['CLAUDE.md', 'README.md'] as $file) {
            $text = $this->read($file);
            $needles = ['http://acme.ddev.site', 'web/themes/custom/acme', 'task be:test', 'task fe:component'];
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $text, "$file lacks $needle");
            }
            self::assertDoesNotMatchRegularExpression('/\{\{(PROJECT|THEME|THEME_LABEL|SITE_NAME)\}\}/', $text, $file);
            self::assertStringNotContainsString('drupal-starter', $text, $file);
        }
        self::assertStringContainsString('drupal-pitfalls.md', $this->read('CLAUDE.md'));
        self::assertStringContainsString('/setup-project', $this->read('CLAUDE.md'));
        self::assertStringContainsString('vendor/bin/mfd', $this->read('CLAUDE.md'));
    }
}
