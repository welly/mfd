<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Template\Renderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** template/ rendered for project "acme", theme "acme", into a temporary directory. */
abstract class RenderedTemplateTestCase extends TestCase
{
    use TempDirectory;

    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->newTempDir();
        (new Renderer())->render(Mfd::root() . '/template', $this->dir, [
            'PROJECT' => 'acme', 'THEME' => 'acme', 'THEME_LABEL' => 'Acme', 'SITE_NAME' => 'Acme',
        ]);
    }

    /**
     * Run a command in the rendered project, with the fake ddev first on PATH.
     *
     * @param list<string> $command
     */
    protected function runIn(array $command): Process
    {
        $process = new Process($command, $this->dir, ['PATH' => Mfd::root() . '/tests/fixtures/bin:' . getenv('PATH')]);
        $process->run();

        return $process;
    }

    protected function read(string $relative): string
    {
        return (string) file_get_contents($this->dir . '/' . $relative);
    }
}
