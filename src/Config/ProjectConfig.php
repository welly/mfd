<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Config;

use Manifesto\Mfd\Exception\UserError;

/** The validated inputs for `mfd new`, with every default filled in. */
final readonly class ProjectConfig
{
    private function __construct(
        public string $project,
        public string $theme,
        public string $themeLabel,
        public string $siteName,
        public string $targetDir,
    ) {
    }

    /** @throws UserError when a value breaks the naming rules or no project name can be derived */
    public static function fromInput(
        ?string $name,
        ?string $projectName = null,
        ?string $theme = null,
        ?string $themeLabel = null,
        ?string $siteName = null,
        ?string $targetDir = null,
    ): self {
        $name = (string) $name;
        if ($name === '') {
            throw new UserError(
                'a project name is required. Usage: mfd new <name> [options], e.g. mfd new "Acme Corp" '
                    . '(see mfd new --help)',
            );
        }
        $nameIsMachineName = Naming::isValidProjectName($name);
        if (!$nameIsMachineName && !Naming::isValidLabel($name)) {
            if (!Naming::isAscii($name)) {
                throw new UserError(sprintf(
                    "'%s' has non-ASCII characters, which mfd's naming rules do not support. Choose an ASCII "
                        . "--project-name (3-63 lowercase letters, digits and '-') and, if you want a custom "
                        . 'label, an ASCII --theme-label.',
                    $name,
                ));
            }
            throw new UserError(sprintf("'%s' may use letters, digits, spaces and . , & ' ( ) - only", $name));
        }

        if ($projectName === null || $projectName === '') {
            $projectName = $nameIsMachineName ? $name : Naming::projectNameFromLabel($name);
            if (!Naming::isValidProjectName($projectName)) {
                throw new UserError(sprintf(
                    "could not derive a DDEV project name from '%s' (got '%s'); choose one with --project-name "
                        . "(3-63 lowercase letters, digits and '-', starting with a letter)",
                    $name,
                    $projectName,
                ));
            }
        }
        if (!Naming::isValidProjectName($projectName)) {
            throw new UserError(sprintf(
                "project name '%s' must be 3-63 characters of lowercase letters, digits and '-', "
                    . "starting with a letter and not ending with '-' (it becomes the DDEV name)",
                $projectName,
            ));
        }

        if ($themeLabel === null || $themeLabel === '') {
            $themeLabel = $nameIsMachineName ? Naming::titleCase($name) : $name;
        }

        if ($theme === null || $theme === '') {
            $theme = Naming::defaultThemeName($projectName);
            if (!Naming::isValidThemeName($theme)) {
                throw new UserError(sprintf(
                    "the default theme name '%s' is reserved or too long; choose one with --theme",
                    $theme,
                ));
            }
        }
        if (!Naming::isValidThemeName($theme)) {
            throw new UserError(sprintf(
                "theme name '%s' must be lowercase letters, digits and '_', start with a letter, "
                    . "be at most 50 characters, and not be a core machine name",
                $theme,
            ));
        }

        if (!Naming::isValidLabel($themeLabel)) {
            throw new UserError(sprintf(
                "theme label '%s' may use letters, digits, spaces and . , & ' ( ) - only",
                $themeLabel,
            ));
        }

        $siteName = ($siteName === null || $siteName === '') ? $themeLabel : $siteName;
        if (!Naming::isValidLabel($siteName)) {
            throw new UserError(sprintf(
                "site name '%s' may use letters, digits, spaces and . , & ' ( ) - only",
                $siteName,
            ));
        }

        $targetDir = ($targetDir === null || $targetDir === '') ? './' . $projectName : $targetDir;

        return new self($projectName, $theme, $themeLabel, $siteName, $targetDir);
    }

    public function siteUrl(): string
    {
        return sprintf('http://%s.ddev.site', $this->project);
    }

    /** The inputs recorded for resume; a rerun must match them exactly. */
    public function inputsRecord(): string
    {
        return sprintf(
            "PROJECT=%s\nTHEME=%s\nTHEME_LABEL=%s\nSITE_NAME=%s\n",
            $this->project,
            $this->theme,
            $this->themeLabel,
            $this->siteName,
        );
    }

    /** @return array<string, string> Template placeholder values, keyed by placeholder name. */
    public function placeholders(): array
    {
        return [
            'PROJECT' => $this->project,
            'THEME' => $this->theme,
            'THEME_LABEL' => $this->themeLabel,
            'SITE_NAME' => $this->siteName,
        ];
    }
}
