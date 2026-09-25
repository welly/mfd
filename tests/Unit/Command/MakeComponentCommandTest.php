<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Command;

use Manifesto\Mfd\Command\MakeComponentCommand;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeComponentCommandTest extends TestCase
{
    use TempDirectory;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->newTempDir();
        file_put_contents($this->project . '/.mfd.json', json_encode(['mfdVersion' => '0.1.0', 'theme' => 'acme']));
        mkdir($this->project . '/web/themes/custom/acme', 0777, true);
    }

    /** @param array<string, mixed> $input */
    private function make(array $input, ?string $cwd = null): CommandTester
    {
        $tester = new CommandTester(new MakeComponentCommand($cwd ?? $this->project));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    public function testCreatesTheComponentInTheProjectTheme(): void
    {
        $tester = $this->make(['name' => 'hero']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->project . '/web/themes/custom/acme/components/hero/hero.twig');
        self::assertStringContainsString('qa/stories.ts', $tester->getDisplay());
    }

    public function testFindsTheProjectRootFromASubdirectory(): void
    {
        mkdir($this->project . '/web/modules', 0777, true);
        $tester = $this->make(['name' => 'hero'], $this->project . '/web/modules');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->project . '/web/themes/custom/acme/components/hero/hero.twig');
    }

    public function testThemeOptionOverridesTheMarker(): void
    {
        mkdir($this->project . '/web/themes/custom/other', 0777, true);
        $this->make(['name' => 'hero', '--theme' => 'other']);

        self::assertFileExists($this->project . '/web/themes/custom/other/components/hero/hero.twig');
    }

    public function testOutsideAProjectExitsTwo(): void
    {
        $tester = $this->make(['name' => 'hero'], $this->newTempDir());

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('.mfd.json', $tester->getDisplay());
    }

    public function testMissingThemeDirectoryExitsTwo(): void
    {
        $tester = $this->make(['name' => 'hero', '--theme' => 'nope']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('web/themes/custom/nope', $tester->getDisplay());
    }

    public function testBadNameExitsTwoAndExistingComponentExitsOne(): void
    {
        self::assertSame(2, $this->make(['name' => 'Bad Name'])->getStatusCode());
        $this->make(['name' => 'card']);
        self::assertSame(1, $this->make(['name' => 'card'])->getStatusCode());
    }

    public function testUnreadableMarkerExitsTwo(): void
    {
        file_put_contents($this->project . '/.mfd.json', '{not json');

        self::assertSame(2, $this->make(['name' => 'hero'])->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidThemeOptions(): iterable
    {
        yield 'path traversal' => ['../../etc'];
        yield 'uppercase' => ['Nope'];
    }

    #[DataProvider('invalidThemeOptions')]
    public function testInvalidThemeOptionExitsTwoAndWritesNothing(string $theme): void
    {
        $tester = $this->make(['name' => 'hero', '--theme' => $theme]);

        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('--theme', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/web/themes/custom/acme/components');
        self::assertFileDoesNotExist($this->project . '/web/themes/custom/hero');
    }
}
