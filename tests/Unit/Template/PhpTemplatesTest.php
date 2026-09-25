<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;

final class PhpTemplatesTest extends RenderedTemplateTestCase
{
    public function testPhpunitConfigDefinesTheThreeSuites(): void
    {
        $xml = simplexml_load_string($this->read('phpunit.xml.dist'));
        self::assertNotFalse($xml);

        $names = array_map(
            static fn (\SimpleXMLElement $s): string => (string) $s['name'],
            $xml->xpath('//testsuite') ?: [],
        );
        self::assertSame(['unit', 'kernel', 'existing-site'], $names);
    }

    public function testPhpunitIsNeverPinnedInTheGeneratedProject(): void
    {
        self::assertStringNotContainsString('phpunit/phpunit', $this->read('phpunit.xml.dist'));
    }

    public function testPhpcsConfigIsWellFormed(): void
    {
        self::assertNotFalse(simplexml_load_string($this->read('phpcs.xml.dist')));
    }

    public function testExampleTestsAreNamespacedToTheTestsModuleAndUseAttributes(): void
    {
        $files = glob($this->dir . '/web/modules/custom/acme_tests/tests/src/*/*.php') ?: [];
        self::assertCount(3, $files);
        foreach ($files as $file) {
            $php = (string) file_get_contents($file);
            self::assertStringContainsString('namespace Drupal\\Tests\\acme_tests\\', $php, $file);
            self::assertStringContainsString("#[Group('acme')]", $php, $file);
            self::assertStringNotContainsString('@group', $php, $file);
        }
    }

    public function testExamplePhpFilesAreSyntacticallyValid(): void
    {
        foreach (glob($this->dir . '/web/modules/custom/acme_tests/tests/src/*/*.php') ?: [] as $file) {
            $process = $this->runIn([PHP_BINARY, '-l', $file]);
            self::assertSame(0, $process->getExitCode(), $process->getOutput());
        }
    }

    public function testDdevTestEnvironmentPointsAtTheDdevDatabaseAndWebContainer(): void
    {
        $env = $this->read('.ddev/config.testing.yaml');

        self::assertStringContainsString('SIMPLETEST_DB=mysql://db:db@db/db', $env);
        self::assertStringContainsString('DTT_BASE_URL=http://web', $env);
    }
}
