<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Steps;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;

final class NewCommandFullRunTest extends NewCommandTestCase
{
    private const STEPS = ['drupal', 'theme', 'harness', 'templates', 'toolkit', 'finish'];

    public function testStepsAreRegisteredInOrder(): void
    {
        self::assertSame(self::STEPS, array_map(static fn ($step): string => $step->name(), Steps::all()));
    }

    public function testAFullRunProducesACompleteProject(): void
    {
        $tester = $this->newProject(['name' => 'acme-corp', '--theme-label' => "O'Brien & Co"]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $p = $this->project('acme-corp');
        $expectedFiles = [
            'Taskfile.yml',
            '.taskfiles/backend.yml',
            '.taskfiles/frontend.yml',
            '.taskfiles/qa.yml',
            'phpunit.xml.dist',
            'phpcs.xml.dist',
            'phpstan.neon.dist',
            'eslint.config.mjs',
            '.stylelintrc.json',
            'CLAUDE.md',
            'README.md',
            '.ddev/config.testing.yaml',
            'web/modules/custom/acme_corp_tests/tests/src/ExistingSite/FrontPageTest.php',
            'web/themes/custom/acme_corp/components/card/card.twig',
            'qa/stories.ts',
            '.mfd.json',
            'config/sync/system.site.yml',
        ];
        foreach ($expectedFiles as $file) {
            self::assertFileExists($p . '/' . $file);
        }
        self::assertDirectoryExists($p . '/.git');
        self::assertContains('git init -q', $this->shell->calls);
        foreach ($this->shell->calls as $call) {
            self::assertStringNotContainsString('git commit', $call);
            self::assertStringNotContainsString('git add', $call);
        }
    }

    public function testNoPlaceholderSurvivesAnywhere(): void
    {
        $this->newProject(['name' => 'acme']);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project('acme'), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            self::assertStringNotContainsString('__THEME__', $file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/\{\{(PROJECT|THEME|THEME_LABEL|SITE_NAME)\}\}|__THEME__/',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname(),
            );
        }
    }

    public function testMarkerRecordsTheInputs(): void
    {
        $this->newProject(['name' => 'acme-corp', '--theme-label' => "O'Brien & Co"]);
        $markerFile = $this->project('acme-corp') . '/.mfd.json';
        $marker = json_decode((string) file_get_contents($markerFile), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($marker);
        self::assertSame('0.1.0', $marker['mfdVersion']);
        self::assertSame('acme-corp', $marker['project']);
        self::assertSame('acme_corp', $marker['theme']);
        self::assertSame("O'Brien & Co", $marker['themeLabel']);
        self::assertSame("O'Brien & Co", $marker['siteName']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/D', $marker['created']);
    }

    public function testPackageJsonKeepsQaInitScriptsAndGainsLint(): void
    {
        $this->newProject(['name' => 'acme']);
        $packageFile = $this->project('acme') . '/package.json';
        $pkg = json_decode((string) file_get_contents($packageFile), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($pkg);
        self::assertArrayHasKey('qa:loop', $pkg['scripts']);
        self::assertArrayHasKey('lint', $pkg['scripts']);
    }

    public function testExportsConfigRestartsDdevAndReportsTheHarnessNextStep(): void
    {
        $tester = $this->newProject(['name' => 'acme']);

        self::assertContains('ddev drush config:export -y', $this->shell->calls);
        self::assertContains('npm install', $this->shell->calls);
        self::assertStringContainsString('next step is init: run /init to create CLAUDE.md', $tester->getDisplay());
    }

    public function testASecondRunSkipsEveryStep(): void
    {
        $this->newProject(['name' => 'acme']);
        $this->shell->calls = [];
        $tester = $this->newProject(['name' => 'acme']);

        self::assertSame(0, $tester->getStatusCode());
        foreach (self::STEPS as $step) {
            self::assertStringContainsString($step . ': skipped (already done)', $tester->getDisplay());
        }
        self::assertSame([], $this->shell->callsStartingWith('ddev composer'));
        self::assertSame([], $this->shell->callsStartingWith('bash'));
    }

    public function testABrokenProbeWarnsButDoesNotFail(): void
    {
        $this->shell->probeOutput = 'not json';
        $tester = $this->newProject(['name' => 'acme']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('probe failed', $tester->getDisplay());
    }

    public function testDryRunListsAllSixStepsAndWritesNothing(): void
    {
        $tester = $this->newProject(['name' => 'acme', '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        foreach (self::STEPS as $step) {
            self::assertStringContainsString('==> ' . $step, $tester->getDisplay());
        }
        self::assertDirectoryDoesNotExist($this->project('acme'));
        self::assertSame([], $this->shell->callsStartingWith('ddev config'));
        self::assertSame([], $this->shell->callsStartingWith('bash'));
    }
}
