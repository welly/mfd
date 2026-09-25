<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Mfd;
use PHPUnit\Framework\TestCase;

/**
 * Checks on mfd's own composer.json and .gitattributes: what ships in the distributed
 * package, and which dependency needs to be present at runtime (`require`) versus only
 * for the test suite (`require-dev`).
 */
final class PackagingTest extends TestCase
{
    /**
     * symfony/filesystem is only used by tests (TempDirectory, the e2e suite); mfd's own
     * runtime code (src/, bin/) never references Symfony\Component\Filesystem. Requiring it
     * in production forces every consumer of the package to install a dependency they never
     * exercise.
     */
    public function testSymfonyFilesystemIsADevDependencyOnly(): void
    {
        /** @var array{require: array<string, string>, 'require-dev': array<string, string>} $composer */
        $composer = json_decode(
            (string) file_get_contents(Mfd::root() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey('symfony/filesystem', $composer['require']);
        self::assertArrayHasKey('symfony/filesystem', $composer['require-dev']);
    }

    /**
     * Test-only and tooling-config files add nothing to a consumer installing mfd via Composer,
     * so they are export-ignored from the distributed archive. template/, resources/, src/ and
     * bin/ must stay in: Mfd::root() and Steps read them at runtime (see TemplatesStep,
     * DrupalStep and bin/mfd), so export-ignoring any of them would break every real `mfd new`.
     */
    public function testGitattributesExportIgnoresTestOnlyFilesButKeepsRuntimeDirectories(): void
    {
        $attributes = (string) file_get_contents(Mfd::root() . '/.gitattributes');

        $exportIgnored = [
            '/tests',
            '/docs/superpowers',
            '/.superpowers',
            '/phpunit.xml.dist',
            '/phpcs.xml.dist',
            '/phpstan.neon.dist',
            '/Taskfile.yml',
        ];
        foreach ($exportIgnored as $path) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($path, '/') . '\s+export-ignore$/m',
                $attributes,
                $path . ' should be export-ignored',
            );
        }

        foreach (['/template', '/resources', '/src', '/bin'] as $runtimePath) {
            self::assertDoesNotMatchRegularExpression(
                '/^' . preg_quote($runtimePath, '/') . '\b.*export-ignore$/m',
                $attributes,
                $runtimePath . ' is needed at runtime and must not be export-ignored',
            );
        }
    }
}
