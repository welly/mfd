<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\ToolkitStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;

final class ToolkitStepTest extends NewCommandTestCase
{
    protected function tearDown(): void
    {
        putenv('MFD_REPOSITORY');
        putenv('MFD_VERSION');
    }

    public function testAddsMfdFromTheDefaultRepository(): void
    {
        $tester = $this->newProject(['name' => 'acme'], [new ToolkitStep()]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains(
            'ddev composer config --no-interaction repositories.mfd vcs https://github.com/welly/drupal-starter',
            $this->shell->calls,
        );
        self::assertContains(
            'ddev composer require --dev --no-interaction manifesto/mfd:dev-main',
            $this->shell->calls,
        );
    }

    public function testEnvironmentOverridesTheSource(): void
    {
        putenv('MFD_REPOSITORY=https://github.com/example/fork');
        putenv('MFD_VERSION=dev-feature/x');
        $this->newProject(['name' => 'acme'], [new ToolkitStep()]);

        self::assertContains(
            'ddev composer config --no-interaction repositories.mfd vcs https://github.com/example/fork',
            $this->shell->calls,
        );
        self::assertContains(
            'ddev composer require --dev --no-interaction manifesto/mfd:dev-feature/x',
            $this->shell->calls,
        );
    }

    public function testAnUnavailablePackageWarnsWithTheCommandToRunLaterInsteadOfFailing(): void
    {
        $this->shell->failOn('manifesto/mfd');
        $tester = $this->newProject(['name' => 'acme'], [new ToolkitStep()]);
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        self::assertStringContainsString('ddev composer require --dev manifesto/mfd:dev-main', $display);
        self::assertSame(2, substr_count($display, 'could not add manifesto/mfd'));
    }

    public function testTheWarningKeepsTheReasonTheCommandFailed(): void
    {
        $this->shell->failOn('manifesto/mfd');
        $tester = $this->newProject(['name' => 'acme'], [new ToolkitStep()]);

        self::assertStringContainsString(
            'fake failure for: ddev composer require --dev --no-interaction manifesto/mfd:dev-main',
            $tester->getDisplay(),
        );
    }

    public function testAFailingComposerConfigCallAlsoWarnsWithTheReason(): void
    {
        $this->shell->failOn('repositories.mfd');
        $tester = $this->newProject(['name' => 'acme'], [new ToolkitStep()]);
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        self::assertStringContainsString('ddev composer require --dev manifesto/mfd:dev-main', $display);
        self::assertStringContainsString(
            'fake failure for: ddev composer config --no-interaction repositories.mfd vcs '
                . 'https://github.com/welly/drupal-starter',
            $display,
        );
    }
}
