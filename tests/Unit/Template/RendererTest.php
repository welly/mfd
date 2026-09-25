<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Template\Renderer;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    use TempDirectory;

    private const VALUES = [
        'PROJECT' => 'acme-corp',
        'THEME' => 'acme_corp',
        'THEME_LABEL' => "O'Brien & Co",
        'SITE_NAME' => 'Acme {{THEME}}',
    ];

    private string $out;

    protected function setUp(): void
    {
        $this->out = $this->newTempDir();
    }

    /**
     * @param array<string, string> $values
     * @return list<string>
     */
    private function render(array $values = self::VALUES): array
    {
        return (new Renderer())->render(Mfd::root() . '/tests/fixtures/template', $this->out, $values);
    }

    public function testReplacesTheFourTokensAndLeavesTaskTemplatesAlone(): void
    {
        $this->render();
        $taskfile = (string) file_get_contents($this->out . '/Taskfile.yml');

        self::assertStringContainsString("  THEME: acme_corp\n", $taskfile);
        self::assertStringContainsString('echo {{.CLI_ARGS}} acme-corp "Acme {{THEME}}" "O\'Brien & Co"', $taskfile);
    }

    public function testValuesAreInsertedLiterallyNeverReExpanded(): void
    {
        $this->render();

        self::assertStringContainsString('Acme {{THEME}}', (string) file_get_contents($this->out . '/Taskfile.yml'));
    }

    public function testThemeTokenInPathsBecomesTheThemeName(): void
    {
        $this->render();
        $info = $this->out . '/web/modules/custom/acme_corp_tests/acme_corp_tests.info.yml';

        self::assertFileExists($info);
        self::assertSame("name: acme_corp tests\n", file_get_contents($info));
    }

    public function testCopiesDotfilesKeepsModesAndBinaries(): void
    {
        $this->render();

        self::assertSame("acme-corp\n", file_get_contents($this->out . '/.hidden/config.txt'));
        self::assertTrue(is_executable($this->out . '/scripts/run.sh'));
        self::assertFileEquals(Mfd::root() . '/tests/fixtures/template/logo.bin', $this->out . '/logo.bin');
    }

    public function testNeverOverwritesAnExistingFile(): void
    {
        file_put_contents($this->out . '/Taskfile.yml', 'mine');
        $lines = $this->render();

        self::assertSame('mine', file_get_contents($this->out . '/Taskfile.yml'));
        self::assertContains('kept existing Taskfile.yml', $lines);
        self::assertContains('wrote logo.bin', $lines);
    }

    public function testMissingValueIsRejected(): void
    {
        $values = self::VALUES;
        unset($values['SITE_NAME']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SITE_NAME');
        $this->render($values);
    }
}
