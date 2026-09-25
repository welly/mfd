<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;
use Manifesto\Mfd\Tests\Support\RecordingStep;

final class NewCommandRunTest extends NewCommandTestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $journal;
    private RecordingStep $alpha;
    private RecordingStep $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = new \ArrayObject();
        $this->alpha = new RecordingStep('alpha', $this->journal);
        $this->beta = new RecordingStep('beta', $this->journal);
    }

    /** @param array<string, mixed> $input */
    private function newAcme(array $input = ['name' => 'acme']): \Symfony\Component\Console\Tester\CommandTester
    {
        return $this->newProject($input, [$this->alpha, $this->beta]);
    }

    public function testRunsStepsInOrderAndMarksThemDone(): void
    {
        $tester = $this->newAcme();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['alpha-start', 'alpha-end', 'beta-start', 'beta-end'], $this->journal->getArrayCopy());
        self::assertFileExists($this->project('acme') . '/.ddev/mfd/done/alpha');
        self::assertFileExists($this->project('acme') . '/.ddev/mfd/done/beta');
        self::assertStringContainsString('/setup-project', $tester->getDisplay());
    }

    public function testStateDirectoryIgnoresItselfAndRecordsInputs(): void
    {
        $this->newAcme();

        self::assertSame("*\n", file_get_contents($this->project('acme') . '/.ddev/mfd/.gitignore'));
        self::assertSame(
            "PROJECT=acme\nTHEME=acme\nTHEME_LABEL=Acme\nSITE_NAME=Acme\n",
            file_get_contents($this->project('acme') . '/.ddev/mfd/inputs'),
        );
    }

    public function testARerunSkipsCompletedSteps(): void
    {
        $this->newAcme();
        $this->journal->exchangeArray([]);
        $tester = $this->newAcme();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->journal->getArrayCopy());
        self::assertStringContainsString('alpha: skipped (already done)', $tester->getDisplay());
    }

    public function testAFailingStepExitsOneAndIsNotMarkedDone(): void
    {
        $this->beta->fail = true;
        $tester = $this->newAcme();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['alpha-start', 'alpha-end', 'beta-start'], $this->journal->getArrayCopy());
        self::assertFileExists($this->project('acme') . '/.ddev/mfd/done/alpha');
        self::assertFileDoesNotExist($this->project('acme') . '/.ddev/mfd/done/beta');
        self::assertStringContainsString("step 'beta' failed: forced failure in beta", $tester->getDisplay());
        self::assertStringContainsString('rerun the same command to resume', $tester->getDisplay());
    }

    public function testARerunAfterAFailureResumesAtTheFailedStep(): void
    {
        $this->beta->fail = true;
        $this->newAcme();
        $this->beta->fail = false;
        $this->journal->exchangeArray([]);
        $tester = $this->newAcme();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['beta-start', 'beta-end'], $this->journal->getArrayCopy());
    }

    public function testRerunWithDifferentOptionsExitsTwo(): void
    {
        $this->newAcme();
        $tester = $this->newAcme(['name' => 'acme', '--theme' => 'other_theme']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('different options', $tester->getDisplay());
        self::assertStringContainsString(
            'THEME=acme',
            (string) file_get_contents($this->project('acme') . '/.ddev/mfd/inputs'),
        );
    }

    public function testDryRunPrintsEveryStepAndWritesNothing(): void
    {
        $tester = $this->newAcme(['name' => 'acme', '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('==> alpha', $tester->getDisplay());
        self::assertStringContainsString('would run beta', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project('acme'));
        self::assertSame([], $this->journal->getArrayCopy());
    }

    public function testANonEmptyDirectoryWithoutStateExitsTwo(): void
    {
        mkdir($this->project('acme'));
        touch($this->project('acme') . '/file');
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('not empty', $tester->getDisplay());
    }

    public function testMissingHarnessInstallerExitsTwoBeforeWriting(): void
    {
        $this->harness = new HarnessPaths('/nonexistent/qa-init.sh', $this->harness->probe);
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('mf-harness', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project('acme'));
    }

    public function testMissingHarnessProbeExitsTwo(): void
    {
        $this->harness = new HarnessPaths($this->harness->qaInit, '/nonexistent/probe.py');

        self::assertSame(2, $this->newAcme()->getStatusCode());
    }

    public function testHarnessInstallerWithoutStorybookSupportExitsTwoBeforeWriting(): void
    {
        file_put_contents($this->harness->qaInit, "#!/usr/bin/env bash\necho 'Unknown argument' >&2\n");
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Storybook', $tester->getDisplay());
        self::assertStringContainsString($this->harness->qaInit, $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project('acme'));
    }

    public function testMissingToolExitsTwo(): void
    {
        $this->shell->withoutTool('task');
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('missing required tools: task', $tester->getDisplay());
    }

    public function testDockerNotRunningExitsTwo(): void
    {
        $this->shell->dockerRunning = false;
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Docker', $tester->getDisplay());
    }

    public function testOldDdevExitsTwo(): void
    {
        $this->shell->ddevVersion = 'v1.24.9';
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('DDEV 1.25.0', $tester->getDisplay());
    }

    public function testOldNodeExitsTwo(): void
    {
        $this->shell->nodeMajor = 18;

        self::assertSame(2, $this->newAcme()->getStatusCode());
    }

    public function testAnExistingDdevProjectWithTheSameNameExitsTwoBeforeWriting(): void
    {
        $this->shell->ddevProjectExists = true;
        $tester = $this->newAcme();

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('ddev delete -Oy acme', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->project('acme'));
    }

    public function testResumingOurOwnProjectDoesNotTripTheDdevNameCheck(): void
    {
        $this->beta->fail = true;
        $this->newAcme();
        $this->beta->fail = false;
        $this->shell->ddevProjectExists = true;

        self::assertSame(0, $this->newAcme()->getStatusCode());
    }

    public function testNoticesAreRepeatedInTheSummary(): void
    {
        $step = new class ('noisy') implements \Manifesto\Mfd\Step\Step {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function plan(\Manifesto\Mfd\Step\Context $context): array
            {
                return [];
            }

            public function run(\Manifesto\Mfd\Step\Context $context): void
            {
                $context->warn('something to do later');
            }
        };
        $tester = $this->newProject(['name' => 'acme'], [$step]);

        self::assertSame(2, substr_count($tester->getDisplay(), 'something to do later'));
    }
}
