<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Npm;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Npm\PackageMerger;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class PackageMergerTest extends TestCase
{
    use TempDirectory;

    private string $package;

    protected function setUp(): void
    {
        $this->package = $this->newTempDir() . '/package.json';
    }

    private function merge(): void
    {
        (new PackageMerger())->merge($this->package, Mfd::root() . '/resources/package-additions.json');
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $data = json_decode((string) file_get_contents($this->package), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testAddsLintScriptsAndExactPinnedTools(): void
    {
        file_put_contents(
            $this->package,
            '{"scripts":{"storybook":"storybook dev -p 6006"},"devDependencies":{"storybook":"10.6.0"}}',
        );
        $this->merge();
        $pkg = $this->decoded();

        self::assertSame(
            [
                '@eslint/js' => '10.0.1',
                'eslint' => '10.11.0',
                'globals' => '17.12.0',
                'stylelint' => '17.15.0',
                'stylelint-config-standard' => '40.0.0',
            ],
            array_diff_key($pkg['devDependencies'], ['storybook' => true]),
        );
        self::assertArrayHasKey('lint', $pkg['scripts']);
        self::assertArrayHasKey('lint:fix', $pkg['scripts']);
        self::assertSame('storybook dev -p 6006', $pkg['scripts']['storybook']);
        self::assertSame('10.6.0', $pkg['devDependencies']['storybook']);
    }

    public function testNeverOverwritesExistingKeys(): void
    {
        file_put_contents($this->package, '{"scripts":{"lint":"mine"},"devDependencies":{"eslint":"9.0.0"}}');
        $this->merge();
        $pkg = $this->decoded();

        self::assertSame('mine', $pkg['scripts']['lint']);
        self::assertSame('9.0.0', $pkg['devDependencies']['eslint']);
    }

    public function testKeepsEmptyObjectsListsAndTwoSpaceIndentation(): void
    {
        file_put_contents($this->package, "{\n  \"name\": \"x\",\n  \"config\": {},\n  \"files\": [\"a\"]\n}\n");
        $this->merge();
        $json = (string) file_get_contents($this->package);

        self::assertStringContainsString("\n  \"config\": {},\n", $json);
        self::assertStringContainsString('"files": [', $json);
        self::assertStringContainsString("\n    \"@eslint/js\": \"10.0.1\"", $json);
        self::assertStringEndsWith("}\n", $json);
    }

    public function testMissingPackageJsonFailsClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('package.json');
        $this->merge();
    }

    public function testMalformedPackageJsonFailsClearly(): void
    {
        file_put_contents($this->package, '{"scripts": {,}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($this->package);
        $this->merge();
    }

    public function testLintScopeIsComponentsAndCustomModulesNotTheStarterkitCss(): void
    {
        $additionsFile = Mfd::root() . '/resources/package-additions.json';
        $additions = json_decode((string) file_get_contents($additionsFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($additions);
        $lint = $additions['scripts']['lint'];

        self::assertStringContainsString('web/themes/custom/*/components', $lint);
        self::assertStringNotContainsString('web/themes/custom/**/*.css', $lint);
    }
}
