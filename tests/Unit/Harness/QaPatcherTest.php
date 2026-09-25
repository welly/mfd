<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Harness;

use Manifesto\Mfd\Harness\QaPatcher;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class QaPatcherTest extends TestCase
{
    use TempDirectory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->newTempDir();
        mkdir($this->dir . '/qa');
        file_put_contents($this->dir . '/qa/gate.config.ts', "export const gateConfig = {\n"
            . "  baseUrl: process.env.QA_BASE_URL ?? 'http://localhost:3000',\n"
            . "  devServerCommand: process.env.QA_DEV_CMD ?? 'npm run dev',\n};\n");
        file_put_contents($this->dir . '/qa/stories.ts', "export const stories = [{ id: 'resource-card' }];\n");
        file_put_contents($this->dir . '/qa/figma-map.json', "{\"example\": {\"node\": \"1:2\"}}\n");
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->dir . '/' . $file);
    }

    public function testPointsTheReviewAtDdevAndTheFrontPageCard(): void
    {
        (new QaPatcher($this->dir))->apply('acme-corp', 'acme_ui');

        self::assertStringContainsString("'http://acme-corp.ddev.site'", $this->read('qa/gate.config.ts'));
        self::assertStringContainsString("'ddev start'", $this->read('qa/gate.config.ts'));
        self::assertStringNotContainsString('localhost:3000', $this->read('qa/gate.config.ts'));
        self::assertStringNotContainsString('npm run dev', $this->read('qa/gate.config.ts'));
        self::assertStringContainsString("component: 'acme_ui:card'", $this->read('qa/stories.ts'));
        self::assertStringContainsString('[data-qa="card"]', $this->read('qa/stories.ts'));
        self::assertStringContainsString("import type { Story } from './story';", $this->read('qa/stories.ts'));
        self::assertSame("{}\n", $this->read('qa/figma-map.json'));
    }

    public function testApplyingTwiceIsHarmless(): void
    {
        (new QaPatcher($this->dir))->apply('acme', 'acme');
        (new QaPatcher($this->dir))->apply('acme', 'acme');

        self::assertSame(1, substr_count($this->read('qa/gate.config.ts'), 'acme.ddev.site'));
    }

    public function testFailsClearlyWhenTheHarnessTemplateChanged(): void
    {
        file_put_contents(
            $this->dir . '/qa/gate.config.ts',
            "export const gateConfig = { baseUrl: 'http://elsewhere' };\n",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('harness template changed');
        (new QaPatcher($this->dir))->apply('acme', 'acme');
    }

    public function testFailsClearlyWhenGateConfigIsMissing(): void
    {
        unlink($this->dir . '/qa/gate.config.ts');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('qa/gate.config.ts');
        (new QaPatcher($this->dir))->apply('acme', 'acme');
    }
}
