<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\HarnessStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;

final class HarnessStepTest extends NewCommandTestCase
{
    public function testInstallsWithStorybookAndPatchesTheReview(): void
    {
        $tester = $this->newProject(['name' => 'acme-corp', '--theme' => 'acme_ui'], [new HarnessStep()]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('bash ' . $this->harness->qaInit . ' --yes --storybook', $this->shell->calls);
        self::assertStringContainsString(
            "'http://acme-corp.ddev.site'",
            (string) file_get_contents($this->project('acme-corp') . '/qa/gate.config.ts'),
        );
        self::assertStringContainsString(
            "component: 'acme_ui:card'",
            (string) file_get_contents($this->project('acme-corp') . '/qa/stories.ts'),
        );
    }

    public function testAFailedInstallerFailsTheStep(): void
    {
        $this->shell->failOn('--storybook');

        self::assertSame(1, $this->newProject(['name' => 'acme'], [new HarnessStep()])->getStatusCode());
    }
}
