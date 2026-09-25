<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Config;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Exception\UserError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    public function testDefaultsDeriveFromTheProjectName(): void
    {
        $config = ProjectConfig::fromInput('acme-corp');

        self::assertSame('acme-corp', $config->project);
        self::assertSame('acme_corp', $config->theme);
        self::assertSame('Acme Corp', $config->themeLabel);
        self::assertSame('Acme Corp', $config->siteName);
        self::assertSame('./acme-corp', $config->targetDir);
        self::assertSame('http://acme-corp.ddev.site', $config->siteUrl());
    }

    public function testOptionsOverrideEveryDefault(): void
    {
        $config = ProjectConfig::fromInput('acme-corp', null, 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x');

        self::assertSame(
            ['acme_ui', 'Acme UI', 'Acme Group', '/tmp/x'],
            [$config->theme, $config->themeLabel, $config->siteName, $config->targetDir],
        );
    }

    public function testSiteNameDefaultsToACustomThemeLabel(): void
    {
        $config = ProjectConfig::fromInput('acme', null, null, "O'Brien & Co");

        self::assertSame("O'Brien & Co", $config->siteName);
    }

    public function testEmptyOptionValuesMeanDefault(): void
    {
        $config = ProjectConfig::fromInput('acme', '', '', '', '', '');

        self::assertSame(
            ['acme', 'Acme', 'Acme', './acme'],
            [$config->theme, $config->themeLabel, $config->siteName, $config->targetDir],
        );
    }

    public function testMissingProjectNameIsAUserError(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('project name is required');
        ProjectConfig::fromInput(null);
    }

    public function testReservedDefaultThemeAsksForTheme(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('--theme');
        ProjectConfig::fromInput('node');
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string, ?string}> */
    public static function invalidInputs(): iterable
    {
        yield 'bad name' => ['Acme: Corp', null, null, null, null];
        yield 'bad theme' => ['acme', null, 'acme-ui', null, null];
        yield 'reserved theme' => ['acme', null, 'views', null, null];
        yield 'long theme' => ['acme', null, str_repeat('a', 51), null, null];
        yield 'bad label' => ['acme', null, null, 'Acme: Corp', null];
        yield 'bad site name' => ['acme', null, null, null, 'Acme #1'];
        yield 'newline label' => ['acme', null, null, "Acme\n", null];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreUserErrors(
        ?string $name,
        ?string $projectName,
        ?string $theme,
        ?string $label,
        ?string $site,
    ): void {
        $this->expectException(UserError::class);
        ProjectConfig::fromInput($name, $projectName, $theme, $label, $site);
    }

    public function testInputsRecordAndPlaceholders(): void
    {
        $config = ProjectConfig::fromInput('acme-corp', null, null, "O'Brien & Co");

        self::assertSame(
            "PROJECT=acme-corp\nTHEME=acme_corp\nTHEME_LABEL=O'Brien & Co\nSITE_NAME=O'Brien & Co\n",
            $config->inputsRecord(),
        );
        self::assertSame(
            [
                'PROJECT' => 'acme-corp',
                'THEME' => 'acme_corp',
                'THEME_LABEL' => "O'Brien & Co",
                'SITE_NAME' => "O'Brien & Co",
            ],
            $config->placeholders(),
        );
    }

    public function testALabelIsEnoughAndEverythingDerivesFromIt(): void
    {
        $config = ProjectConfig::fromInput('Acme Corp');

        self::assertSame('acme-corp', $config->project);
        self::assertSame('acme_corp', $config->theme);
        self::assertSame('Acme Corp', $config->themeLabel);
        self::assertSame('Acme Corp', $config->siteName);
        self::assertSame('./acme-corp', $config->targetDir);
    }

    public function testASingleWordLabel(): void
    {
        $config = ProjectConfig::fromInput('Something');

        self::assertSame(
            ['something', 'something', 'Something', 'Something'],
            [$config->project, $config->theme, $config->themeLabel, $config->siteName],
        );
    }

    public function testPunctuatedLabelKeepsItsLabelAndGetsACleanMachineName(): void
    {
        $config = ProjectConfig::fromInput("O'Brien & Co");

        self::assertSame(
            ['obrien-co', 'obrien_co', "O'Brien & Co"],
            [$config->project, $config->theme, $config->themeLabel],
        );
    }

    public function testAMachineStyleNameIsUsedAsIsAndTitleCasedForTheLabel(): void
    {
        $config = ProjectConfig::fromInput('acme-corp');

        self::assertSame(['acme-corp', 'Acme Corp'], [$config->project, $config->themeLabel]);
    }

    public function testEveryDerivedValueCanBeOverridden(): void
    {
        $config = ProjectConfig::fromInput('Acme Corp', 'acme', 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x');

        self::assertSame(
            ['acme', 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x'],
            [$config->project, $config->theme, $config->themeLabel, $config->siteName, $config->targetDir],
        );
    }

    public function testAnUnderivableProjectNameAsksForProjectName(): void
    {
        $this->expectException(UserError::class);
        $this->expectExceptionMessage('--project-name');
        ProjectConfig::fromInput('3M Company');
    }

    public function testAnInvalidProjectNameOverrideIsAUserError(): void
    {
        $this->expectException(UserError::class);
        ProjectConfig::fromInput('Acme Corp', 'Acme_Corp');
    }

    public function testANameThatIsNeitherAMachineNameNorALabelIsAUserError(): void
    {
        $this->expectException(UserError::class);
        ProjectConfig::fromInput('Acme: Corp');
    }

    /**
     * Naming::isValidLabel rejects any non-ASCII character, so a label such as 'Café Ltd' is
     * rejected before Naming::projectNameFromLabel (which used to special-case a curly apostrophe
     * that could never actually reach it) ever runs. ASCII-only labels are the documented,
     * pinned behaviour, so the error must point at the ASCII workaround rather than just listing
     * the allowed punctuation.
     */
    public function testANonAsciiNameIsAUserErrorSuggestingAsciiOverrides(): void
    {
        try {
            ProjectConfig::fromInput('Café Ltd');
            self::fail('expected a UserError');
        } catch (UserError $e) {
            self::assertStringContainsString('--project-name', $e->getMessage());
            self::assertStringContainsString('--theme-label', $e->getMessage());
        }
    }
}
