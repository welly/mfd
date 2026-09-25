<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Component;

use Manifesto\Mfd\Component\ComponentGenerator;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComponentGeneratorTest extends TestCase
{
    use TempDirectory;

    private string $theme;

    protected function setUp(): void
    {
        $this->theme = $this->newTempDir();
    }

    public function testCreatesAnSdcWithExamplesAndDataQa(): void
    {
        $files = (new ComponentGenerator())->generate($this->theme, 'hero-banner');
        $dir = $this->theme . '/components/hero-banner';

        self::assertSame([
            'components/hero-banner/hero-banner.component.yml',
            'components/hero-banner/hero-banner.twig',
            'components/hero-banner/hero-banner.css',
        ], $files);
        $yml = (string) file_get_contents($dir . '/hero-banner.component.yml');
        self::assertStringContainsString("name: Hero Banner\n", $yml);
        self::assertStringContainsString('type: Drupal\Core\Template\Attribute', $yml);
        self::assertStringContainsString("examples: ['Hero Banner heading']", $yml);
        self::assertStringContainsString(
            '$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json',
            $yml,
        );
        $twig = (string) file_get_contents($dir . '/hero-banner.twig');
        self::assertStringContainsString('data-qa="hero-banner"', $twig);
        self::assertStringContainsString("attributes.addClass('hero-banner')", $twig);
        self::assertStringContainsString('class="hero-banner__heading"', $twig);
        self::assertStringContainsString('{% block content %}{% endblock %}', $twig);
        self::assertSame("/* Styles for the Hero Banner component. */\n", file_get_contents($dir . '/hero-banner.css'));
    }

    public function testCardMatchesWhatTheExampleTestsExpect(): void
    {
        (new ComponentGenerator())->generate($this->theme, 'card');

        self::assertStringContainsString(
            "name: Card\n",
            (string) file_get_contents($this->theme . '/components/card/card.component.yml'),
        );
        self::assertStringContainsString(
            'data-qa="card"',
            (string) file_get_contents($this->theme . '/components/card/card.twig'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'space' => ['Bad Name'];
        yield 'uppercase' => ['Hero'];
        yield 'leading digit' => ['1hero'];
        yield 'path' => ['../hero'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["hero\n"];
    }

    #[DataProvider('badNames')]
    public function testRejectsBadNames(string $name): void
    {
        $this->expectException(UserError::class);
        (new ComponentGenerator())->generate($this->theme, $name);
    }

    public function testRefusesAnExistingComponent(): void
    {
        (new ComponentGenerator())->generate($this->theme, 'card');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        (new ComponentGenerator())->generate($this->theme, 'card');
    }
}
