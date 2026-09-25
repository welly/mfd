<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Shell;

use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\ProcessShell;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProcessShellTest extends TestCase
{
    public function testRunStreamsOutput(): void
    {
        $output = new BufferedOutput();
        (new ProcessShell($output))->run(['echo', 'hello'], sys_get_temp_dir());

        self::assertStringContainsString('hello', $output->fetch());
    }

    public function testRunThrowsOnFailureWithTheExitCode(): void
    {
        try {
            (new ProcessShell(new BufferedOutput()))->run(['sh', '-c', 'echo boom >&2; exit 3'], sys_get_temp_dir());
            self::fail('expected CommandFailed');
        } catch (CommandFailed $e) {
            self::assertSame(3, $e->exitCode);
            self::assertSame(['sh', '-c', 'echo boom >&2; exit 3'], $e->command);
            self::assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function testCaptureReturnsTrimmedStdout(): void
    {
        self::assertSame(
            'a b',
            (new ProcessShell(new BufferedOutput()))->capture(['echo', ' a b '], sys_get_temp_dir()),
        );
    }

    public function testArgumentsAreNeverShellInterpreted(): void
    {
        $shell = new ProcessShell(new BufferedOutput());

        self::assertSame(
            "O'Brien & Co; \$HOME",
            $shell->capture(['printf', '%s', "O'Brien & Co; \$HOME"], sys_get_temp_dir()),
        );
    }

    public function testSucceedsAndHas(): void
    {
        $shell = new ProcessShell(new BufferedOutput());

        self::assertTrue($shell->succeeds(['true'], sys_get_temp_dir()));
        self::assertFalse($shell->succeeds(['false'], sys_get_temp_dir()));
        self::assertTrue($shell->has('sh'));
        self::assertFalse($shell->has('definitely-not-a-real-tool-mfd'));
    }
}
