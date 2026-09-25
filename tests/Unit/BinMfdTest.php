<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Mfd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BinMfdTest extends TestCase
{
    /** @param list<string> $args */
    private function mfd(array $args): Process
    {
        $process = new Process([PHP_BINARY, Mfd::root() . '/bin/mfd', ...$args]);
        $process->run();

        return $process;
    }

    public function testNoArgumentsListsTheCommands(): void
    {
        $process = $this->mfd([]);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('new', $process->getOutput());
    }

    public function testHelpForNewExitsZero(): void
    {
        $process = $this->mfd(['new', '--help']);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('--theme-label', $process->getOutput());
    }

    public function testHelpForNewShowsTheProjectNameOverride(): void
    {
        $process = $this->mfd(['new', '--help']);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('--project-name', $process->getOutput());
        self::assertStringContainsString('optional', strtolower($process->getOutput()));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badInvocations(): iterable
    {
        yield 'missing project name' => [['new']];
        yield 'unknown option' => [['new', 'acme', '--nope']];
        yield 'option without value' => [['new', 'acme', '--theme']];
        yield 'extra argument' => [['new', 'acme', 'other']];
        yield 'underivable name' => [['new', '3M Company']];
        yield 'label with colon' => [['new', 'Acme: Corp']];
        yield 'make:component without a name' => [['make:component']];
    }

    /** @param list<string> $args */
    #[DataProvider('badInvocations')]
    public function testBadInputExitsTwo(array $args): void
    {
        $process = $this->mfd($args);

        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('error:', $process->getOutput() . $process->getErrorOutput());
    }
}
