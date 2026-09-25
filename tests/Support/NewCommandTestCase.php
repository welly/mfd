<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Command\NewCommand;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Step\Step;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Runs `mfd new` with a FakeShell, fake harness files and a temporary working directory. */
abstract class NewCommandTestCase extends TestCase
{
    use TempDirectory;

    protected FakeShell $shell;
    protected HarnessPaths $harness;
    protected string $work;

    protected function setUp(): void
    {
        $this->shell = new FakeShell();
        $this->work = $this->newTempDir();
        $harnessDir = $this->newTempDir();
        file_put_contents($harnessDir . '/qa-init.sh', "#!/usr/bin/env bash\n# supports --yes --storybook\n");
        touch($harnessDir . '/probe.py');
        $this->harness = new HarnessPaths($harnessDir . '/qa-init.sh', $harnessDir . '/probe.py');
    }

    /**
     * @param array<string, mixed> $input CommandTester input; --dir defaults to <work>/<derived project name>.
     * @param list<Step>|null $steps null runs the real Steps::all()
     */
    protected function newProject(array $input, ?array $steps = null): CommandTester
    {
        $name = $input['--project-name'] ?? $input['name'] ?? 'unnamed';
        $input += ['--dir' => $this->work . '/' . (is_string($name) ? $name : 'unnamed')];
        $tester = new CommandTester(new NewCommand($this->shell, $steps, $this->harness));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    protected function project(string $name): string
    {
        return $this->work . '/' . $name;
    }
}
