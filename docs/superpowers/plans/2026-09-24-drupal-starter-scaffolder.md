# mfd (Manifesto Drupal toolkit) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `mfd new <name>` takes an empty folder to a Drupal 11 project on DDEV with a named SDC theme, Storybook, three PHPUnit suites, the mf-harness review tooling and a daily-use Taskfile. `mfd make:component <name>` works inside that project.

**Architecture:**
- A Symfony Console application, `bin/mfd`, has two commands.
- `new` validates input into a `ProjectConfig`, runs read-only pre-flight checks, then runs ordered `Step` classes. Each step records completion under `.ddev/mfd/done/`, so a rerun resumes.
- Every external command goes through a `Shell` interface as an argv array. Tests swap in a `FakeShell`.
- Files that land in a project come from `template/`. A small renderer fills in four placeholders.
- `new` also adds `manifesto/mfd` to the project's `require-dev`, so teammates run `mfd` inside DDEV.

**Tech Stack:**
- The tool: PHP 8.3 or later, `symfony/console`, `symfony/process` and `symfony/filesystem` (`^7.4 || ^8.0`), PHPUnit 12, phpstan 2, PHP_CodeSniffer.
- A generated project:
  - Drupal 11, DDEV 1.25 or later, Drush
  - `drupal/core-dev` and Drupal Test Traits
  - phpcs with `drupal/coder`, phpstan with `mglaman/phpstan-drupal`
  - Storybook 10, installed by the harness
  - ESLint 10 and Stylelint 17

**Spec:** `docs/superpowers/specs/2026-09-24-drupal-starter-scaffolder-design.md`

**Dispatch notes:**
- Tasks 1–3, 8, 11, 12, 13 and 15 are PHP tool code: `backend-engineer`. Task 15 runs straight after Task 6.
- Tasks 5, 9 and 10 touch Drupal: `backend-engineer`. The brief includes `~/.claude/references/drupal-pitfalls.md` and the `drupal-testing` skill.
- Tasks 4, 6 and 7 are templates: `frontend-engineer` for 4 and 6, `backend-engineer` for 7.
- Task 14 is docs: `content-strategist`.
- Task review: `qa-reviewer`.
- Nobody runs `git add` or `git commit`: the human stages and commits the finished branch.

## Global Constraints

- PHP 8.3 or later.
  - `composer.json` sets `config.platform.php` to `8.3.0`.
  - Every PHP file starts with `declare(strict_types=1);`.
  - PSR-12 style; phpstan level 8 clean.
  - Classes are `final` unless they are an interface or a trait; `readonly` wherever it fits.
  - Tests are analysed at level 8 too: narrow decoded JSON and other `mixed` values with `assertIsArray()`, `assertIsString()` or `@var` annotations. Never lower the level or add a baseline.
  - Never name a test-case method `run()`: PHPUnit's `TestCase::run()` is public.
- Runtime dependencies are only `symfony/console`, `symfony/process` and `symfony/filesystem`, each `^7.4 || ^8.0`. This lets `mfd` install inside Drupal 11 projects. Dev dependencies: `phpunit/phpunit` `^12.0`, `phpstan/phpstan` `^2.1`, `squizlabs/php_codesniffer` `^3.13 || ^4.0`.
- Namespaces: `Manifesto\Mfd\` maps to `src/`; `Manifesto\Mfd\Tests\` maps to `tests/`. The package is `manifesto/mfd`, and its binary is `bin/mfd`.
- Every external command runs through `Shell` as an argv array (`list<string>`), never as a shell string. The one exception is the end-to-end test's own helper.
- Exit codes:
  - 0 success (`Command::SUCCESS`)
  - 1 a step failed (rerun to resume) (`Command::FAILURE`)
  - 2 bad input or failed pre-flight, with nothing written (`Command::INVALID`)
- Nothing in `mfd` runs `git commit`. Implementers run neither `git add` nor `git commit`.
- Project name: `^[a-z][a-z0-9-]{1,61}[a-z0-9]$`. It is the DDEV project name; the URL is `http://<project>.ddev.site`.
- Theme machine name:
  - `^[a-z][a-z0-9_]*$`, at most 50 characters, not a reserved core machine name
  - default: project name with `-` replaced by `_`
- Labels (`--theme-label`, `--site-name`): `^[A-Za-z0-9][A-Za-z0-9 .,&'()-]{0,98}$`. Default theme label: project name in title case. Default site name: the theme label. An empty option value means "use the default".
- **Every `preg_match` pattern anchored with `$` uses the `D` modifier.** Without it, `$` also matches before a trailing newline, so `"acme\n"` would pass.
- Template placeholders are exactly `{{PROJECT}}`, `{{THEME}}`, `{{THEME_LABEL}}` and `{{SITE_NAME}}`. In paths, `__THEME__` is replaced. Task's own `{{.NAME}}` templates must pass through untouched.
- Names on disk:
  - project marker: `.mfd.json`, with keys `mfdVersion`, `created`, `project`, `theme`, `themeLabel`, `siteName`
  - resume state: `.ddev/mfd/` (`inputs`, `done/<step>`, and a `.gitignore` containing `*`)
- Toolkit source: repository `https://github.com/welly/drupal-starter`, version `dev-main`. The environment variables `MFD_REPOSITORY` and `MFD_VERSION` override them.
- Harness paths: `QA_INIT`, default `$HOME/.claude/skills/design-review/scaffold/qa-init.sh`; `HARNESS_PROBE`, default `$HOME/.claude/lib/setup_project_probe.py`. Environment variables of the same names override them.
- Versions:
  - DDEV at least 1.25.0, Node at least 20
  - `drupal/recommended-project:^11`
  - PHPUnit is **not** pinned in generated projects; `drupal/core-dev` picks it
  - Exact front-end pins: `eslint` 10.11.0, `@eslint/js` 10.0.1, `globals` 17.12.0, `stylelint` 17.15.0, `stylelint-config-standard` 40.0.0
- Storybook is always installed and enabled.

## Review Focus

1. **Rerun after a failure partway through a step** (for example, `composer require` fails after `create-project` succeeded). Expected: the rerun resumes without redoing or breaking the completed sub-commands. Pinned in Task 8.
2. **A DDEV project with the same name already exists elsewhere on the machine.** Expected: exit 2 before anything is written, with instructions. Pinned in Task 2.
3. **Labels with punctuation** (`O'Brien & Co`) reach the Drush argument and the files intact. Labels with `:`, `#` or a trailing newline are rejected with exit 2. Pinned in Tasks 1 and 12.
4. **`package.json` round-trips through the merger without damage:** empty objects stay `{}`, scoped names like `@eslint/js` survive, and indentation stays at two spaces. Pinned in Task 6.
5. **`mfd make:component` run from a subdirectory of the project, or outside any project.** Expected: from a subdirectory it finds the root; outside a project it exits 2 with a clear message. Pinned in Task 9.

---
### Task 1: Package skeleton, naming rules, and `mfd new` input handling

**Files:**
- Delete (bash leftovers from an earlier plan, never committed): `bin/scaffold`, `lib/`, `tests/`, `Taskfile.yml`
- Create: `composer.json` (then run `composer install`, which creates `composer.lock`), `.gitignore`, `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `Taskfile.yml`
- Create: `bin/mfd`, `src/Mfd.php`, `src/Exception/UserError.php`, `src/Config/Naming.php`, `src/Config/ProjectConfig.php`, `src/Command/NewCommand.php`
- Test: `tests/Unit/Config/NamingTest.php`, `tests/Unit/Config/ProjectConfigTest.php`, `tests/Unit/BinMfdTest.php`

**Interfaces:**
- Produces:
  - `Mfd::VERSION` (string) and `Mfd::root(): string` (the package root directory)
  - `UserError extends \RuntimeException`
  - `Naming::RESERVED` and the static functions `Naming::isValidProjectName`, `isReserved`, `isValidThemeName`, `isValidLabel`, `defaultThemeName`, `titleCase`
  - `ProjectConfig::fromInput(?string $project, ?string $theme = null, ?string $themeLabel = null, ?string $siteName = null, ?string $targetDir = null): ProjectConfig`, which throws `UserError`. Its public readonly properties are `project`, `theme`, `themeLabel`, `siteName` and `targetDir`. Its methods are `siteUrl(): string`, `inputsRecord(): string` and `placeholders(): array<string,string>`.
  - The `new` command. In this task it validates input and prints the resolved values; Task 2 replaces `execute()`.
  - `bin/mfd` maps Symfony Console input exceptions to exit 2.

- [ ] **Step 1: Remove the bash leftovers and create the Composer package**

Run: `rm -rf bin lib tests Taskfile.yml`

`composer.json`:

```json
{
    "name": "manifesto/mfd",
    "description": "Manifesto Drupal toolkit: scaffold Drupal 11 projects with SDC, Storybook, PHPUnit and the mf-harness review tooling, and work in them day to day.",
    "type": "library",
    "license": "proprietary",
    "bin": ["bin/mfd"],
    "require": {
        "php": ">=8.3",
        "symfony/console": "^7.4 || ^8.0",
        "symfony/filesystem": "^7.4 || ^8.0",
        "symfony/process": "^7.4 || ^8.0"
    },
    "require-dev": {
        "phpstan/phpstan": "^2.1",
        "phpunit/phpunit": "^12.0",
        "squizlabs/php_codesniffer": "^3.13 || ^4.0"
    },
    "autoload": {
        "psr-4": { "Manifesto\\Mfd\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Manifesto\\Mfd\\Tests\\": "tests/" }
    },
    "config": {
        "platform": { "php": "8.3.0" },
        "sort-packages": true
    }
}
```

`.gitignore`:

```
/vendor/
/.phpunit.cache/
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         defaultTestSuite="unit"
         failOnRisky="true"
         failOnWarning="true"
         beStrictAboutOutputDuringTests="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="e2e">
      <directory>tests/E2e</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory>src</directory>
    </include>
  </source>
</phpunit>
```

`phpcs.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ruleset name="mfd">
  <description>PSR-12 for the mfd tool. template/ holds generated-project files with placeholders and is not linted here.</description>
  <file>bin/mfd</file>
  <file>src</file>
  <file>tests</file>
  <exclude-pattern>tests/fixtures/*</exclude-pattern>
  <arg name="colors"/>
  <arg value="s"/>
  <rule ref="PSR12"/>
</ruleset>
```

`phpstan.neon.dist`:

```neon
parameters:
  level: 8
  paths:
    - bin/mfd
    - src
    - tests
  excludePaths:
    - tests/fixtures
```

`Taskfile.yml` (this repository's own tasks):

```yaml
# Tasks for developing mfd itself. Run `task` to list them.
version: '3'

tasks:
  default:
    silent: true
    cmds:
      - task --list

  install:
    desc: Install dependencies and put mfd on your PATH (~/.local/bin/mfd)
    cmds:
      - composer install
      - mkdir -p "$HOME/.local/bin"
      - ln -sf "$PWD/bin/mfd" "$HOME/.local/bin/mfd"
      - echo "mfd linked to ~/.local/bin/mfd. Make sure ~/.local/bin is on your PATH."

  test:
    desc: Fast tests (no Docker needed)
    cmds:
      - vendor/bin/phpunit

  lint:
    desc: Coding standards (PSR-12) and static analysis (phpstan level 8)
    cmds:
      - vendor/bin/phpcs
      - vendor/bin/phpstan analyse --no-progress

  e2e:
    desc: Slow end-to-end run against real DDEV (needs Docker and the harness)
    cmds:
      - vendor/bin/phpunit --testsuite e2e
```

Run: `composer install`. Expected: it resolves `symfony/*` 7.4.x (platform PHP 8.3), PHPUnit 12, phpstan 2 and phpcs.

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Config/NamingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Config;

use Manifesto\Mfd\Config\Naming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamingTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function projectNames(): iterable
    {
        yield 'simple' => ['acme', true];
        yield 'hyphenated' => ['acme-corp', true];
        yield 'digits' => ['acme2', true];
        yield 'uppercase' => ['Acme', false];
        yield 'underscore' => ['acme_corp', false];
        yield 'leading hyphen' => ['-acme', false];
        yield 'trailing hyphen' => ['acme-', false];
        yield 'leading digit' => ['1acme', false];
        yield 'too short' => ['a', false];
        yield 'dot' => ['ab.', false];
        yield 'trailing newline' => ["acme\n", false];
        yield '63 characters' => ['a' . str_repeat('b', 62), true];
        yield '64 characters' => ['a' . str_repeat('b', 63), false];
    }

    #[DataProvider('projectNames')]
    public function testProjectNames(string $name, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidProjectName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function themeNames(): iterable
    {
        yield 'simple' => ['acme', true];
        yield 'underscore' => ['acme_ui', true];
        yield 'hyphen' => ['acme-ui', false];
        yield 'uppercase' => ['Acme', false];
        yield 'leading digit' => ['1acme', false];
        yield 'core module' => ['node', false];
        yield 'core theme' => ['olivero', false];
        yield 'system' => ['system', false];
        yield '50 characters' => [str_repeat('a', 50), true];
        yield '51 characters' => [str_repeat('a', 51), false];
        yield 'trailing newline' => ["acme\n", false];
    }

    #[DataProvider('themeNames')]
    public function testThemeNames(string $name, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidThemeName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function labels(): iterable
    {
        yield 'words' => ['Acme Corp', true];
        yield 'punctuation' => ["O'Brien & Co (UK), Ltd.", true];
        yield 'colon' => ['Acme: Corp', false];
        yield 'hash' => ['Acme #1', false];
        yield 'leading space' => [' Acme', false];
        yield 'empty' => ['', false];
        yield 'trailing newline' => ["Acme\n", false];
    }

    #[DataProvider('labels')]
    public function testLabels(string $label, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidLabel($label));
    }

    public function testDefaults(): void
    {
        self::assertSame('acme_corp', Naming::defaultThemeName('acme-corp'));
        self::assertSame('Acme Corp', Naming::titleCase('acme-corp'));
        self::assertSame('Acme2', Naming::titleCase('acme2'));
    }
}
```

`tests/Unit/Config/ProjectConfigTest.php`:

```php
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
        $config = ProjectConfig::fromInput('acme-corp', 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x');

        self::assertSame(['acme_ui', 'Acme UI', 'Acme Group', '/tmp/x'], [$config->theme, $config->themeLabel, $config->siteName, $config->targetDir]);
    }

    public function testSiteNameDefaultsToACustomThemeLabel(): void
    {
        $config = ProjectConfig::fromInput('acme', null, "O'Brien & Co");

        self::assertSame("O'Brien & Co", $config->siteName);
    }

    public function testEmptyOptionValuesMeanDefault(): void
    {
        $config = ProjectConfig::fromInput('acme', '', '', '', '');

        self::assertSame(['acme', 'Acme', 'Acme', './acme'], [$config->theme, $config->themeLabel, $config->siteName, $config->targetDir]);
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

    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function invalidInputs(): iterable
    {
        yield 'bad project' => ['Acme', null, null, null];
        yield 'bad theme' => ['acme', 'acme-ui', null, null];
        yield 'reserved theme' => ['acme', 'views', null, null];
        yield 'long theme' => ['acme', str_repeat('a', 51), null, null];
        yield 'bad label' => ['acme', null, 'Acme: Corp', null];
        yield 'bad site name' => ['acme', null, null, 'Acme #1'];
        yield 'newline label' => ['acme', null, "Acme\n", null];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreUserErrors(?string $project, ?string $theme, ?string $label, ?string $site): void
    {
        $this->expectException(UserError::class);
        ProjectConfig::fromInput($project, $theme, $label, $site);
    }

    public function testInputsRecordAndPlaceholders(): void
    {
        $config = ProjectConfig::fromInput('acme-corp', null, "O'Brien & Co");

        self::assertSame("PROJECT=acme-corp\nTHEME=acme_corp\nTHEME_LABEL=O'Brien & Co\nSITE_NAME=O'Brien & Co\n", $config->inputsRecord());
        self::assertSame(
            ['PROJECT' => 'acme-corp', 'THEME' => 'acme_corp', 'THEME_LABEL' => "O'Brien & Co", 'SITE_NAME' => "O'Brien & Co"],
            $config->placeholders(),
        );
    }
}
```

`tests/Unit/BinMfdTest.php` runs the real entry point:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Mfd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BinMfdTest extends TestCase
{
    /** @param list<string> $args */
    private function mfd(array $args): Process
    {
        $process = new Process([PHP_BINARY, Mfd::root() . '/bin/mfd', ...$args]);
        $process->run();

        return $process;
    }

    public function testNoArgumentsListsTheCommands(): void
    {
        $process = $this->mfd([]);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('new', $process->getOutput());
    }

    public function testHelpForNewExitsZero(): void
    {
        $process = $this->mfd(['new', '--help']);

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('--theme-label', $process->getOutput());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badInvocations(): iterable
    {
        yield 'missing project name' => [['new']];
        yield 'unknown option' => [['new', 'acme', '--nope']];
        yield 'option without value' => [['new', 'acme', '--theme']];
        yield 'extra argument' => [['new', 'acme', 'other']];
        yield 'invalid project name' => [['new', 'Acme']];
    }

    /** @param list<string> $args */
    #[DataProvider('badInvocations')]
    public function testBadInputExitsTwo(array $args): void
    {
        $process = $this->mfd($args);

        self::assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('error:', $process->getOutput() . $process->getErrorOutput());
    }
}
```

- [ ] **Step 3: Run the tests and confirm they fail.** Run `vendor/bin/phpunit`. Expected: errors such as `Class "Manifesto\Mfd\Config\Naming" not found`.

- [ ] **Step 4: Implement**

`src/Mfd.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

/** Package-wide facts. */
final class Mfd
{
    public const VERSION = '0.1.0';

    /** The package root: where template/ and resources/ live, in a checkout or in vendor/. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }
}
```

`src/Exception/UserError.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Exception;

/** Bad input or a failed pre-flight check. Nothing has been written. Exit code 2. */
final class UserError extends \RuntimeException
{
}
```

`src/Config/Naming.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Config;

/** The naming rules for projects, themes and labels. */
final class Naming
{
    /** Machine names Drupal 11 core uses (modules, themes, profiles), plus names that would confuse Drupal. */
    public const RESERVED = [
        'core', 'drupal', 'system', 'stark', 'olivero', 'claro', 'starterkit_theme', 'standard', 'minimal',
        'demo_umami', 'testing', 'action', 'announcements_feed', 'automated_cron', 'ban', 'basic_auth',
        'big_pipe', 'block', 'block_content', 'book', 'breakpoint', 'ckeditor5', 'comment', 'config',
        'config_translation', 'contact', 'content_moderation', 'content_translation', 'contextual', 'datetime',
        'datetime_range', 'dblog', 'dynamic_page_cache', 'editor', 'field', 'field_layout', 'field_ui', 'file',
        'filter', 'forum', 'help', 'history', 'image', 'inline_form_errors', 'jsonapi', 'language',
        'layout_builder', 'layout_discovery', 'link', 'locale', 'media', 'media_library', 'menu_link_content',
        'menu_ui', 'migrate', 'migrate_drupal', 'migrate_drupal_ui', 'mysql', 'navigation', 'node', 'options',
        'package_manager', 'page_cache', 'path', 'path_alias', 'pgsql', 'phpass', 'responsive_image', 'rest',
        'sdc', 'search', 'serialization', 'settings_tray', 'shortcut', 'sqlite', 'statistics', 'syslog',
        'taxonomy', 'telephone', 'text', 'toolbar', 'tour', 'tracker', 'update', 'user', 'views', 'views_ui',
        'workflows', 'workspaces', 'workspaces_ui',
    ];

    /** The DDEV project name, so it must be a valid hostname label. */
    public static function isValidProjectName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,61}[a-z0-9]$/D', $name) === 1;
    }

    public static function isReserved(string $name): bool
    {
        return in_array($name, self::RESERVED, true);
    }

    public static function isValidThemeName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/D', $name) === 1
            && strlen($name) <= 50
            && !self::isReserved($name);
    }

    /** Labels reach YAML (theme .info.yml) and command arguments: only characters safe in a plain YAML scalar. */
    public static function isValidLabel(string $label): bool
    {
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9 .,&'()-]{0,98}$/D", $label) === 1;
    }

    public static function defaultThemeName(string $project): string
    {
        return str_replace('-', '_', $project);
    }

    public static function titleCase(string $project): string
    {
        return ucwords(str_replace('-', ' ', $project));
    }
}
```

`src/Config/ProjectConfig.php`:

```php
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

    /** @throws UserError when any value breaks the naming rules */
    public static function fromInput(
        ?string $project,
        ?string $theme = null,
        ?string $themeLabel = null,
        ?string $siteName = null,
        ?string $targetDir = null,
    ): self {
        $project = (string) $project;
        if ($project === '') {
            throw new UserError('a project name is required. Usage: mfd new <project-name> [options] (see mfd new --help)');
        }
        if (!Naming::isValidProjectName($project)) {
            throw new UserError(sprintf(
                "project name '%s' must be 3-63 characters of lowercase letters, digits and '-', starting with a letter and not ending with '-' (it becomes the DDEV name)",
                $project,
            ));
        }

        if ($theme === null || $theme === '') {
            $theme = Naming::defaultThemeName($project);
            if (!Naming::isValidThemeName($theme)) {
                throw new UserError(sprintf("the default theme name '%s' is reserved or too long; choose one with --theme", $theme));
            }
        }
        if (!Naming::isValidThemeName($theme)) {
            throw new UserError(sprintf(
                "theme name '%s' must be lowercase letters, digits and '_', start with a letter, be at most 50 characters, and not be a core machine name",
                $theme,
            ));
        }

        $themeLabel = ($themeLabel === null || $themeLabel === '') ? Naming::titleCase($project) : $themeLabel;
        if (!Naming::isValidLabel($themeLabel)) {
            throw new UserError(sprintf("theme label '%s' may use letters, digits, spaces and . , & ' ( ) - only", $themeLabel));
        }

        $siteName = ($siteName === null || $siteName === '') ? $themeLabel : $siteName;
        if (!Naming::isValidLabel($siteName)) {
            throw new UserError(sprintf("site name '%s' may use letters, digits, spaces and . , & ' ( ) - only", $siteName));
        }

        $targetDir = ($targetDir === null || $targetDir === '') ? './' . $project : $targetDir;

        return new self($project, $theme, $themeLabel, $siteName, $targetDir);
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
```

`src/Command/NewCommand.php`, the input-only version (Task 2 replaces `execute()`):

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Command;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Exception\UserError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'new',
    description: 'Create a Drupal 11 project with SDC, Storybook, PHPUnit and the mf-harness review tooling',
)]
final class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('project-name', InputArgument::OPTIONAL, 'Project name; also the DDEV name (lowercase letters, digits and -)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, "Theme machine name (default: project name with '-' as '_')")
            ->addOption('theme-label', null, InputOption::VALUE_REQUIRED, 'Theme human name (default: project name in title case)')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Drupal site name (default: the theme label)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory (default: ./<project-name>)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the steps and change nothing')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Never prompt (mfd new does not prompt; kept for harness parity)')
            ->setHelp(<<<'HELP'
                Exit codes: 0 success, 1 a step failed (rerun the same command to resume),
                2 bad input or failed pre-flight (nothing written).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configFrom($input);
        } catch (UserError $e) {
            $this->error($output, $e->getMessage());

            return self::INVALID;
        }

        $output->writeln(sprintf('Project:    %s (%s)', $config->project, $config->siteUrl()));
        $output->writeln(sprintf('Theme:      %s (%s)', $config->theme, $config->themeLabel));
        $output->writeln(sprintf('Site name:  %s', $config->siteName));
        $output->writeln(sprintf('Directory:  %s', $config->targetDir));

        return self::SUCCESS;
    }

    private function configFrom(InputInterface $input): ProjectConfig
    {
        return ProjectConfig::fromInput(
            self::stringOrNull($input->getArgument('project-name')),
            self::stringOrNull($input->getOption('theme')),
            self::stringOrNull($input->getOption('theme-label')),
            self::stringOrNull($input->getOption('site-name')),
            self::stringOrNull($input->getOption('dir')),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream->writeln('<error>error:</error> ' . $message);
    }
}
```

`bin/mfd` (make it executable with `chmod +x bin/mfd`):

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Manifesto\Mfd\Command\NewCommand;
use Manifesto\Mfd\Mfd;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// When mfd is a project dependency, Composer's vendor/bin proxy sets
// $_composer_autoload_path. From a checkout, use this package's own vendor/.
$autoload = $GLOBALS['_composer_autoload_path'] ?? dirname(__DIR__) . '/vendor/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) {
    fwrite(STDERR, 'error: dependencies are missing. Run `composer install` in ' . dirname(__DIR__) . " first.\n");
    exit(2);
}
require $autoload;

$application = new Application('mfd', Mfd::VERSION);
foreach ([new NewCommand()] as $command) {
    // Symfony 7.4 adds addCommand() and deprecates add(); use whichever the installed version prefers.
    method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);
}
$application->setAutoExit(false);
$application->setCatchExceptions(false);

$output = new ConsoleOutput();
try {
    $exitCode = $application->run(new ArgvInput(), $output);
} catch (ConsoleException $e) {
    // Unknown options, missing option values and extra arguments are bad input: exit 2.
    $output->getErrorOutput()->writeln('<error>error:</error> ' . $e->getMessage() . ' (see --help)');
    $exitCode = 2;
}
exit($exitCode);
```

- [ ] **Step 5: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes, with no deprecations or warnings. If `addCommand()` / `add()` triggers a deprecation on the installed Symfony version, keep the `method_exists` switch and confirm the output is clean.

- [ ] **Step 6: Lint.** Run `task lint`. Expected: phpcs reports no errors, and phpstan reports no errors at level 8. Fix any findings; do not lower the level.

---

### Task 2: Shell, FakeShell, state, pre-flight checks and the step runner

**Files:**
- Create: `src/Shell/Shell.php`, `src/Shell/ProcessShell.php`, `src/Shell/CommandFailed.php`
- Create: `src/Harness/HarnessPaths.php`, `src/State.php`, `src/Preflight.php`
- Create: `src/Step/Step.php`, `src/Step/Context.php`, `src/Step/Notices.php`, `src/Step/StepRunner.php`, `src/Exception/StepFailed.php`, `src/Steps.php`
- Modify: `src/Command/NewCommand.php` (the full flow)
- Test support: `tests/Support/FakeShell.php`, `tests/Support/TempDirectory.php`, `tests/Support/NewCommandTestCase.php`, `tests/Support/RecordingStep.php`
- Test: `tests/Unit/Shell/ProcessShellTest.php`, `tests/Unit/NewCommandRunTest.php`

**Interfaces:**
- Consumes: Task 1's `ProjectConfig`, `UserError`, `Mfd`, and `NewCommand::configFrom()` / `error()`.
- Produces (later tasks rely on these exact names):
  - `Shell`:
    - `has(string $tool): bool`
    - `run(list<string> $command, string $cwd): void` (streams output; throws `CommandFailed`)
    - `capture(list<string> $command, string $cwd): string` (trimmed stdout; throws `CommandFailed`)
    - `succeeds(list<string> $command, string $cwd): bool`
  - `CommandFailed extends \RuntimeException`, with public readonly `command` (a `list<string>`) and `exitCode` (an int)
  - `HarnessPaths` (readonly `qaInit` and `probe`; `fromEnvironment()`)
  - `State(string $projectDir)`: `DIR = '.ddev/mfd'`, `recordedInputs(): ?string`, `init(ProjectConfig)`, `isDone(string)`, `markDone(string)`
  - `Preflight(Shell, HarnessPaths)`: `check(ProjectConfig $config, string $targetDir): void`, which throws `UserError`
  - `Step`: `name(): string`, `plan(Context): list<string>`, `run(Context): void`
  - `Context` (readonly):
    - properties `config`, `shell`, `output`, `projectDir`, `packageRoot`, `harness`, `notices`
    - methods `path(string): string`, `log(string): void`, `warn(string): void`
    - `warn()` also adds the message to `notices`
  - `Notices`: `add(string)`, `all(): list<string>`
  - `StepRunner(list<Step>)`: `plan(Context)`, `run(Context, State)`, which throws `StepFailed`
  - `StepFailed(string $step, \Throwable $previous)`
  - `Steps::all(): list<Step>` (empty in this task; Tasks 8–12 add to it)
  - `NewCommand` constructor: `(?Shell $shell = null, ?array $steps = null, ?HarnessPaths $harness = null)`
  - Test support: `FakeShell`, the `TempDirectory` trait, `NewCommandTestCase::newProject(array $input, ?array $steps = null): CommandTester`, and `RecordingStep`

- [ ] **Step 1: Write the test support classes**

`tests/Support/TempDirectory.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

/** Temporary directories that are removed after each test. */
trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function newTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mfd-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $real = (string) realpath($dir);
        $this->tempDirectories[] = $real;

        return $real;
    }

    #[After]
    protected function removeTempDirs(): void
    {
        (new Filesystem())->remove($this->tempDirectories);
        $this->tempDirectories = [];
    }
}
```

`tests/Support/FakeShell.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\Shell;

/**
 * Test double for Shell. Records every command and fakes the files DDEV, Composer,
 * the harness installer and git would create, so steps are tested without Docker.
 */
final class FakeShell implements Shell
{
    /** @var list<string> Every command, its arguments joined by single spaces. */
    public array $calls = [];
    public string $ddevVersion = 'v1.25.4';
    public int $nodeMajor = 24;
    public bool $dockerRunning = true;
    public bool $ddevProjectExists = false;
    public string $probeOutput = '{"primaryStack":"drupal","nextStep":"init: run /init to create CLAUDE.md"}';

    /** @var list<string> */
    private array $failures = [];
    /** @var list<string> */
    private array $missingTools = [];

    private const DDEV_SETTINGS = <<<'PHP'
        <?php
        $settings['hash_salt'] = '';
        if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
          include __DIR__ . '/settings.ddev.php';
        }

        PHP;

    /** Any command whose joined line contains $needle fails with exit code 1. */
    public function failOn(string $needle): void
    {
        $this->failures[] = $needle;
    }

    public function clearFailures(): void
    {
        $this->failures = [];
    }

    public function withoutTool(string $tool): void
    {
        $this->missingTools[] = $tool;
    }

    /** @return list<string> */
    public function callsStartingWith(string $prefix): array
    {
        return array_values(array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, $prefix)));
    }

    public function has(string $tool): bool
    {
        return !in_array($tool, $this->missingTools, true);
    }

    public function run(array $command, string $cwd): void
    {
        $this->execute($command, $cwd);
    }

    public function capture(array $command, string $cwd): string
    {
        return trim($this->execute($command, $cwd));
    }

    public function succeeds(array $command, string $cwd): bool
    {
        try {
            $this->execute($command, $cwd);

            return true;
        } catch (CommandFailed) {
            return false;
        }
    }

    /** @param list<string> $command */
    private function execute(array $command, string $cwd): string
    {
        $line = implode(' ', $command);
        $this->calls[] = $line;
        foreach ($this->failures as $needle) {
            if (str_contains($line, $needle)) {
                throw new CommandFailed($command, 1, 'fake failure for: ' . $line);
            }
        }

        return match ($command[0]) {
            'ddev' => $this->ddev($command, $cwd),
            'docker' => $this->dockerRunning ? '' : throw new CommandFailed($command, 1, 'Cannot connect to the Docker daemon'),
            'node' => (string) $this->nodeMajor,
            'bash' => $this->qaInit($cwd),
            'python3' => $this->probeOutput,
            'git' => $this->git($command, $cwd),
            default => '',
        };
    }

    /** @param list<string> $command */
    private function ddev(array $command, string $cwd): string
    {
        $args = array_slice($command, 1);
        $line = implode(' ', $args);

        if ($line === '--version') {
            return 'ddev version ' . $this->ddevVersion;
        }
        if (str_starts_with($line, 'describe ')) {
            return $this->ddevProjectExists ? '' : throw new CommandFailed($command, 1, 'no such project');
        }
        if (str_starts_with($line, 'config ')) {
            $this->write($cwd, '.ddev/config.yaml', "name: fake\n");
        } elseif ($line === 'start' || $line === 'restart') {
            if (is_dir($cwd . '/web/sites/default') && !is_file($cwd . '/web/sites/default/settings.php')) {
                $this->write($cwd, 'web/sites/default/settings.php', self::DDEV_SETTINGS);
            }
        } elseif (str_starts_with($line, 'composer create-project')) {
            $this->write($cwd, 'composer.json', "{\"require\":{\"drupal/core-recommended\":\"^11\"}}\n");
            $this->write($cwd, 'web/core/lib/Drupal.php', "<?php\n");
            $this->write($cwd, 'web/sites/default/default.settings.php', "<?php\n");
        } elseif ($line === 'drush status --field=bootstrap') {
            return is_file($cwd . '/.fake-installed') ? 'Successful' : '';
        } elseif (str_starts_with($line, 'drush site:install')) {
            $this->write($cwd, '.fake-installed', '');
        } elseif (str_starts_with($line, 'drush config:export')) {
            $this->write($cwd, 'config/sync/system.site.yml', "name: fake\n");
        } elseif (str_starts_with($line, 'exec vendor/bin/dr generate-theme ')) {
            $theme = $args[3];
            $this->write($cwd, "web/themes/custom/$theme/templates/layout/page.html.twig", "<main>\n  {{ page.content }}\n</main>\n");
            $this->write($cwd, "web/themes/custom/$theme/$theme.info.yml", "name: fake\ntype: theme\n");
        }

        return '';
    }

    /** What the real harness qa-init.sh writes, reduced to what mfd reads. */
    private function qaInit(string $cwd): string
    {
        $this->write($cwd, 'qa/gate.config.ts', "export const gateConfig = {\n"
            . "  baseUrl: process.env.QA_BASE_URL ?? 'http://localhost:3000',\n"
            . "  devServerCommand: process.env.QA_DEV_CMD ?? 'npm run dev',\n};\n");
        $this->write($cwd, 'qa/stories.ts', "export const stories = [{ id: 'resource-card' }];\n");
        $this->write($cwd, 'qa/figma-map.json', "{\"example\": {\"node\": \"1:2\"}}\n");
        if (!is_file($cwd . '/package.json')) {
            $this->write($cwd, 'package.json', "{\n  \"scripts\": {\n    \"qa:loop\": \"echo loop\",\n    \"storybook\": \"echo sb\"\n  },\n"
                . "  \"devDependencies\": {\n    \"storybook\": \"10.6.0\"\n  }\n}\n");
        }
        file_put_contents($cwd . '/.gitignore', "\n# mf-harness design-review\nqa/reports/\n", FILE_APPEND);

        return '';
    }

    /** @param list<string> $command */
    private function git(array $command, string $cwd): string
    {
        if (($command[1] ?? '') === 'init' && !is_dir($cwd . '/.git')) {
            mkdir($cwd . '/.git');
        }

        return '';
    }

    private function write(string $cwd, string $relative, string $content): void
    {
        $path = $cwd . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
}
```

`tests/Support/RecordingStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use Manifesto\Mfd\Step\Context;
use Manifesto\Mfd\Step\Step;

/** A step that records when it starts and ends, and can be told to fail between the two. */
final class RecordingStep implements Step
{
    public bool $fail = false;

    /** @param \ArrayObject<int, string> $journal */
    public function __construct(private readonly string $name, private readonly \ArrayObject $journal)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function plan(Context $context): array
    {
        return ['would run ' . $this->name];
    }

    public function run(Context $context): void
    {
        $this->journal[] = $this->name . '-start';
        if ($this->fail) {
            throw new \RuntimeException('forced failure in ' . $this->name);
        }
        $this->journal[] = $this->name . '-end';
    }
}
```

`tests/Support/NewCommandTestCase.php`:

```php
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
        touch($harnessDir . '/qa-init.sh');
        touch($harnessDir . '/probe.py');
        $this->harness = new HarnessPaths($harnessDir . '/qa-init.sh', $harnessDir . '/probe.py');
    }

    /**
     * @param array<string, mixed> $input CommandTester input; --dir defaults to <work>/<project-name>.
     * @param list<Step>|null $steps null runs the real Steps::all()
     */
    protected function newProject(array $input, ?array $steps = null): CommandTester
    {
        $name = $input['project-name'] ?? 'unnamed';
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
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Shell/ProcessShellTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Shell;

use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\ProcessShell;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProcessShellTest extends TestCase
{
    public function testRunStreamsOutput(): void
    {
        $output = new BufferedOutput();
        (new ProcessShell($output))->run(['echo', 'hello'], sys_get_temp_dir());

        self::assertStringContainsString('hello', $output->fetch());
    }

    public function testRunThrowsOnFailureWithTheExitCode(): void
    {
        try {
            (new ProcessShell(new BufferedOutput()))->run(['sh', '-c', 'echo boom >&2; exit 3'], sys_get_temp_dir());
            self::fail('expected CommandFailed');
        } catch (CommandFailed $e) {
            self::assertSame(3, $e->exitCode);
            self::assertSame(['sh', '-c', 'echo boom >&2; exit 3'], $e->command);
            self::assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function testCaptureReturnsTrimmedStdout(): void
    {
        self::assertSame('a b', (new ProcessShell(new BufferedOutput()))->capture(['echo', ' a b '], sys_get_temp_dir()));
    }

    public function testArgumentsAreNeverShellInterpreted(): void
    {
        $shell = new ProcessShell(new BufferedOutput());

        self::assertSame("O'Brien & Co; \$HOME", $shell->capture(['printf', '%s', "O'Brien & Co; \$HOME"], sys_get_temp_dir()));
    }

    public function testSucceedsAndHas(): void
    {
        $shell = new ProcessShell(new BufferedOutput());

        self::assertTrue($shell->succeeds(['true'], sys_get_temp_dir()));
        self::assertFalse($shell->succeeds(['false'], sys_get_temp_dir()));
        self::assertTrue($shell->has('sh'));
        self::assertFalse($shell->has('definitely-not-a-real-tool-mfd'));
    }
}
```

`tests/Unit/NewCommandRunTest.php`:

```php
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
    private function newAcme(array $input = ['project-name' => 'acme']): \Symfony\Component\Console\Tester\CommandTester
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
        self::assertSame("PROJECT=acme\nTHEME=acme\nTHEME_LABEL=Acme\nSITE_NAME=Acme\n", file_get_contents($this->project('acme') . '/.ddev/mfd/inputs'));
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
        $tester = $this->newAcme(['project-name' => 'acme', '--theme' => 'other_theme']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('different options', $tester->getDisplay());
        self::assertStringContainsString('THEME=acme', (string) file_get_contents($this->project('acme') . '/.ddev/mfd/inputs'));
    }

    public function testDryRunPrintsEveryStepAndWritesNothing(): void
    {
        $tester = $this->newAcme(['project-name' => 'acme', '--dry-run' => true]);

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
        $tester = $this->newProject(['project-name' => 'acme'], [$step]);

        self::assertSame(2, substr_count($tester->getDisplay(), 'something to do later'));
    }
}
```

- [ ] **Step 3: Run the tests and confirm they fail.** Run `vendor/bin/phpunit`. Expected: errors such as `Interface "Manifesto\Mfd\Shell\Shell" not found`.

- [ ] **Step 4: Implement**

`src/Shell/Shell.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

/**
 * Every external command mfd runs goes through here, as an argv array: never a
 * shell string, so values are never quoted or interpreted by a shell.
 */
interface Shell
{
    /** Whether an executable is on PATH. */
    public function has(string $tool): bool;

    /**
     * Run a command in $cwd, streaming its output.
     *
     * @param list<string> $command
     * @throws CommandFailed on a non-zero exit code
     */
    public function run(array $command, string $cwd): void;

    /**
     * Run a command in $cwd and return its trimmed standard output.
     *
     * @param list<string> $command
     * @throws CommandFailed on a non-zero exit code
     */
    public function capture(array $command, string $cwd): string;

    /**
     * Run a command in $cwd quietly; true when it exits 0. Never throws.
     *
     * @param list<string> $command
     */
    public function succeeds(array $command, string $cwd): bool;
}
```

`src/Shell/CommandFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

/** An external command exited non-zero. */
final class CommandFailed extends \RuntimeException
{
    /** @param list<string> $command */
    public function __construct(public readonly array $command, public readonly int $exitCode, string $errorOutput)
    {
        $tail = trim(implode("\n", array_slice(explode("\n", trim($errorOutput)), -20)));
        parent::__construct(sprintf('`%s` exited %d%s', implode(' ', $command), $exitCode, $tail === '' ? '' : ":\n" . $tail));
    }
}
```

`src/Shell/ProcessShell.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Shell;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Runs commands with Symfony Process: no shell, no timeout, output streamed to the console. */
final class ProcessShell implements Shell
{
    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function has(string $tool): bool
    {
        return (new ExecutableFinder())->find($tool) !== null;
    }

    public function run(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
        if (!$process->isSuccessful()) {
            throw new CommandFailed($command, $process->getExitCode() ?? 1, $process->getErrorOutput());
        }
    }

    public function capture(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new CommandFailed($command, $process->getExitCode() ?? 1, $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    public function succeeds(array $command, string $cwd): bool
    {
        $process = new Process($command, $cwd, null, null, null);
        $process->run();

        return $process->isSuccessful();
    }
}
```

`src/Harness/HarnessPaths.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Harness;

/** Where the mf-harness pieces mfd calls are installed. */
final readonly class HarnessPaths
{
    public function __construct(public string $qaInit, public string $probe)
    {
    }

    /** The installed harness under ~/.claude; QA_INIT and HARNESS_PROBE override. */
    public static function fromEnvironment(): self
    {
        $home = (string) getenv('HOME');

        return new self(
            self::env('QA_INIT') ?? $home . '/.claude/skills/design-review/scaffold/qa-init.sh',
            self::env('HARNESS_PROBE') ?? $home . '/.claude/lib/setup_project_probe.py',
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? null : $value;
    }
}
```

`src/State.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Config\ProjectConfig;

/** Resume state for `mfd new`, in .ddev/mfd/ under the project. The directory ignores itself. */
final class State
{
    public const DIR = '.ddev/mfd';

    public function __construct(private readonly string $projectDir)
    {
    }

    /** The inputs an earlier run recorded, or null when there was no earlier run. */
    public function recordedInputs(): ?string
    {
        $file = $this->file('inputs');

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function init(ProjectConfig $config): void
    {
        if (!is_dir($this->file('done'))) {
            mkdir($this->file('done'), 0777, true);
        }
        file_put_contents($this->file('.gitignore'), "*\n");
        if ($this->recordedInputs() === null) {
            file_put_contents($this->file('inputs'), $config->inputsRecord());
        }
    }

    public function isDone(string $step): bool
    {
        return is_file($this->file('done/' . $step));
    }

    public function markDone(string $step): void
    {
        touch($this->file('done/' . $step));
    }

    private function file(string $relative): string
    {
        return $this->projectDir . '/' . self::DIR . '/' . $relative;
    }
}
```

`src/Preflight.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Shell\CommandFailed;
use Manifesto\Mfd\Shell\Shell;

/** Read-only checks before `mfd new` writes anything. Every failure is a UserError (exit 2). */
final class Preflight
{
    public const MIN_DDEV = '1.25.0';
    public const MIN_NODE = 20;
    private const TOOLS = ['ddev', 'docker', 'node', 'npm', 'git', 'task', 'python3'];

    public function __construct(private readonly Shell $shell, private readonly HarnessPaths $harness)
    {
    }

    public function check(ProjectConfig $config, string $targetDir): void
    {
        $this->checkTools();
        $this->checkHarness();
        $this->checkTarget($config, $targetDir);
        $this->checkDdevName($config, $targetDir);
    }

    private function checkTools(): void
    {
        $missing = array_values(array_filter(self::TOOLS, fn (string $tool): bool => !$this->shell->has($tool)));
        if ($missing !== []) {
            throw new UserError('missing required tools: ' . implode(' ', $missing));
        }
        $cwd = (string) getcwd();
        try {
            $ddevOutput = $this->shell->capture(['ddev', '--version'], $cwd);
            $nodeMajor = (int) $this->shell->capture(['node', '-p', 'process.versions.node.split(".")[0]'], $cwd);
        } catch (CommandFailed $e) {
            throw new UserError('could not read tool versions: ' . $e->getMessage(), 0, $e);
        }
        $ddev = preg_match('/v(\d+\.\d+\.\d+)/', $ddevOutput, $m) === 1 ? $m[1] : '0.0.0';
        if (version_compare($ddev, self::MIN_DDEV, '<')) {
            throw new UserError(sprintf('DDEV %s or later is required (found %s)', self::MIN_DDEV, $ddev));
        }
        if (!$this->shell->succeeds(['docker', 'info'], $cwd)) {
            throw new UserError('Docker is not running. Start it and retry.');
        }
        if ($nodeMajor < self::MIN_NODE) {
            throw new UserError(sprintf('Node.js %d or later is required (found %d)', self::MIN_NODE, $nodeMajor));
        }
    }

    private function checkHarness(): void
    {
        if (!is_file($this->harness->qaInit)) {
            throw new UserError(sprintf(
                'mf-harness design-review installer not found at %s. Install or update the harness (mf-harness ./update.sh), or set QA_INIT.',
                $this->harness->qaInit,
            ));
        }
        if (!is_file($this->harness->probe)) {
            throw new UserError(sprintf(
                'mf-harness setup probe not found at %s. Install or update the harness, or set HARNESS_PROBE.',
                $this->harness->probe,
            ));
        }
    }

    private function checkTarget(ProjectConfig $config, string $targetDir): void
    {
        if (!file_exists($targetDir)) {
            return;
        }
        if (!is_dir($targetDir)) {
            throw new UserError($targetDir . ' exists and is not a directory');
        }
        $recorded = (new State($targetDir))->recordedInputs();
        if ($recorded !== null) {
            if ($recorded !== $config->inputsRecord()) {
                throw new UserError(sprintf(
                    "%s was created with different options:\n%sRerun with the same options to resume, or choose another --dir.",
                    $targetDir,
                    $recorded,
                ));
            }

            return;
        }
        if (array_diff((array) scandir($targetDir), ['.', '..']) !== []) {
            throw new UserError($targetDir . ' is not empty. Choose an empty or new directory with --dir.');
        }
    }

    private function checkDdevName(ProjectConfig $config, string $targetDir): void
    {
        if ((new State($targetDir))->recordedInputs() !== null) {
            return;
        }
        if ($this->shell->succeeds(['ddev', 'describe', $config->project], (string) getcwd())) {
            throw new UserError(sprintf(
                "a DDEV project named '%s' already exists. Choose another project name, or remove it with: ddev delete -Oy %s",
                $config->project,
                $config->project,
            ));
        }
    }
}
```

`src/Step/Step.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

/**
 * One stage of `mfd new`. To add a step: implement this, then list it in Steps::all().
 * run() must be safe to call again after it failed part-way through.
 */
interface Step
{
    /** Machine name used in output and resume state, e.g. "drupal". */
    public function name(): string;

    /** @return list<string> What run() will do, for --dry-run. */
    public function plan(Context $context): array;

    /** Do the work. Throw to fail; the run can then be resumed. */
    public function run(Context $context): void;
}
```

`src/Step/Notices.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

/** Warnings collected during a run, repeated in the final summary. */
final class Notices
{
    /** @var list<string> */
    private array $items = [];

    public function add(string $notice): void
    {
        $this->items[] = $notice;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->items;
    }
}
```

`src/Step/Context.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Shell\Shell;
use Symfony\Component\Console\Output\OutputInterface;

/** Everything a step needs. */
final readonly class Context
{
    public function __construct(
        public ProjectConfig $config,
        public Shell $shell,
        public OutputInterface $output,
        public string $projectDir,
        public string $packageRoot,
        public HarnessPaths $harness,
        public Notices $notices = new Notices(),
    ) {
    }

    public function path(string $relative): string
    {
        return $this->projectDir . '/' . $relative;
    }

    public function log(string $line): void
    {
        $this->output->writeln('    ' . $line);
    }

    /** Print a warning now and repeat it in the summary. */
    public function warn(string $message): void
    {
        $this->output->writeln('<comment>warning:</comment> ' . $message);
        $this->notices->add($message);
    }
}
```

`src/Exception/StepFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Exception;

/** A step of `mfd new` failed. Rerunning resumes at that step. Exit code 1. */
final class StepFailed extends \RuntimeException
{
    public function __construct(public readonly string $step, \Throwable $previous)
    {
        parent::__construct(sprintf("step '%s' failed: %s", $step, $previous->getMessage()), 0, $previous);
    }
}
```

`src/Step/StepRunner.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Exception\StepFailed;
use Manifesto\Mfd\State;

/** Runs the steps in order, skipping those already done. */
final class StepRunner
{
    /** @param list<Step> $steps */
    public function __construct(private readonly array $steps)
    {
    }

    public function plan(Context $context): void
    {
        foreach ($this->steps as $step) {
            $context->output->writeln('==> ' . $step->name());
            foreach ($step->plan($context) as $line) {
                $context->log($line);
            }
        }
    }

    /** @throws StepFailed */
    public function run(Context $context, State $state): void
    {
        foreach ($this->steps as $step) {
            $name = $step->name();
            if ($state->isDone($name)) {
                $context->output->writeln(sprintf('==> %s: skipped (already done)', $name));
                continue;
            }
            $context->output->writeln('==> ' . $name);
            try {
                $step->run($context);
            } catch (\Throwable $e) {
                throw new StepFailed($name, $e);
            }
            $state->markDone($name);
            $context->log('done');
        }
    }
}
```

`src/Steps.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Step\Step;

/** The steps of `mfd new`, in order. Register a new step here. */
final class Steps
{
    /** @return list<Step> */
    public static function all(): array
    {
        return [];
    }
}
```

Replace `src/Command/NewCommand.php`'s constructor and `execute()`. Keep `configure()`, `configFrom()`, `stringOrNull()` and `error()` from Task 1, and add the `use` lines this needs:

```php
    /** @param list<Step>|null $steps null means Steps::all() */
    public function __construct(
        private readonly ?Shell $shell = null,
        private readonly ?array $steps = null,
        private readonly ?HarnessPaths $harness = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shell = $this->shell ?? new ProcessShell($output);
        $harness = $this->harness ?? HarnessPaths::fromEnvironment();
        try {
            $config = $this->configFrom($input);
            $targetDir = self::absolute($config->targetDir);
            (new Preflight($shell, $harness))->check($config, $targetDir);
        } catch (UserError $e) {
            $this->error($output, $e->getMessage());

            return self::INVALID;
        }

        $runner = new StepRunner($this->steps ?? Steps::all());
        $context = new Context($config, $shell, $output, $targetDir, Mfd::root(), $harness);

        if ($input->getOption('dry-run') === true) {
            $output->writeln('Dry run: nothing will be changed.');
            $this->printConfig($output, $config, $targetDir);
            $runner->plan($context);

            return self::SUCCESS;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $state = new State($targetDir);
        $state->init($config);
        try {
            $runner->run($context, $state);
        } catch (StepFailed $e) {
            $this->error($output, $e->getMessage());
            $output->writeln('Fix the problem above, then rerun the same command to resume.');

            return self::FAILURE;
        }

        $this->printSummary($output, $context);

        return self::SUCCESS;
    }

    private function printConfig(OutputInterface $output, ProjectConfig $config, string $targetDir): void
    {
        $output->writeln(sprintf('Project:    %s (%s)', $config->project, $config->siteUrl()));
        $output->writeln(sprintf('Theme:      %s (%s)', $config->theme, $config->themeLabel));
        $output->writeln(sprintf('Site name:  %s', $config->siteName));
        $output->writeln(sprintf('Directory:  %s', $targetDir));
    }

    private function printSummary(OutputInterface $output, Context $context): void
    {
        $config = $context->config;
        $output->writeln('');
        $output->writeln('Project ready in ' . $context->projectDir);
        $output->writeln(sprintf('  Site:     %s   (task be:login for an admin link)', $config->siteUrl()));
        $output->writeln('  Theme:    web/themes/custom/' . $config->theme);
        $output->writeln("  Tasks:    run 'task' in the project to list them");
        foreach ($context->notices->all() as $notice) {
            $output->writeln('<comment>warning:</comment> ' . $notice);
        }
        $output->writeln('');
        $output->writeln('Next:');
        $output->writeln('  1. Review the files, then commit them yourself (git add -A && git commit).');
        $output->writeln('  2. Open Claude Code in the project and run /setup-project to finish the harness');
        $output->writeln('     setup (MCP, Figma, enrolment). It skips what is already done.');
    }

    /** An absolute path for $dir, relative to the current directory when not absolute. */
    private static function absolute(string $dir): string
    {
        $path = str_starts_with($dir, '/') ? $dir : getcwd() . '/' . $dir;
        $path = (string) preg_replace('#/(\./)+#', '/', $path);

        return rtrim($path, '/');
    }
```

- [ ] **Step 5: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: all tests pass, with pristine output.

- [ ] **Step 6: Lint.** Run `task lint`. Expected: clean.

---

### Task 3: Template renderer

**Files:**
- Create: `src/Template/Renderer.php`
- Create: the `tests/fixtures/template/` fixture tree
- Test: `tests/Unit/Template/RendererTest.php`

**Interfaces:**
- Produces: `Renderer::KEYS` and `Renderer::render(string $source, string $target, array<string,string> $values): list<string>`.
  - Returns one line per file: `wrote <path>` or `kept existing <path>`.
  - Throws `\InvalidArgumentException` when a key is missing.

- [ ] **Step 1: Create the fixtures**

`tests/fixtures/template/Taskfile.yml`:

```yaml
vars:
  THEME: {{THEME}}
tasks:
  t:
    cmds:
      - echo {{.CLI_ARGS}} {{PROJECT}} "{{SITE_NAME}}" "{{THEME_LABEL}}"
```

`tests/fixtures/template/web/modules/custom/__THEME___tests/__THEME___tests.info.yml` contains the single line `name: {{THEME}} tests`.

`tests/fixtures/template/scripts/run.sh` contains `#!/bin/sh` and `echo {{PROJECT}}`. Mark it executable: `chmod +x tests/fixtures/template/scripts/run.sh`.

`tests/fixtures/template/.hidden/config.txt` contains `{{PROJECT}}`. It proves dotfiles and dot-directories are copied.

`tests/fixtures/template/logo.bin` is 3 bytes of invalid UTF-8: `php -r 'file_put_contents("tests/fixtures/template/logo.bin", "\xff\xfe\x00");'`.

- [ ] **Step 2: Write the failing test** `tests/Unit/Template/RendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Template\Renderer;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    use TempDirectory;

    private const VALUES = [
        'PROJECT' => 'acme-corp',
        'THEME' => 'acme_corp',
        'THEME_LABEL' => "O'Brien & Co",
        'SITE_NAME' => 'Acme {{THEME}}',
    ];

    private string $out;

    protected function setUp(): void
    {
        $this->out = $this->newTempDir();
    }

    /** @param array<string, string> $values @return list<string> */
    private function render(array $values = self::VALUES): array
    {
        return (new Renderer())->render(Mfd::root() . '/tests/fixtures/template', $this->out, $values);
    }

    public function testReplacesTheFourTokensAndLeavesTaskTemplatesAlone(): void
    {
        $this->render();
        $taskfile = (string) file_get_contents($this->out . '/Taskfile.yml');

        self::assertStringContainsString("  THEME: acme_corp\n", $taskfile);
        self::assertStringContainsString('echo {{.CLI_ARGS}} acme-corp "Acme {{THEME}}" "O\'Brien & Co"', $taskfile);
    }

    public function testValuesAreInsertedLiterallyNeverReExpanded(): void
    {
        $this->render();

        self::assertStringContainsString('Acme {{THEME}}', (string) file_get_contents($this->out . '/Taskfile.yml'));
    }

    public function testThemeTokenInPathsBecomesTheThemeName(): void
    {
        $this->render();
        $info = $this->out . '/web/modules/custom/acme_corp_tests/acme_corp_tests.info.yml';

        self::assertFileExists($info);
        self::assertSame("name: acme_corp tests\n", file_get_contents($info));
    }

    public function testCopiesDotfilesKeepsModesAndBinaries(): void
    {
        $this->render();

        self::assertSame("acme-corp\n", file_get_contents($this->out . '/.hidden/config.txt'));
        self::assertTrue(is_executable($this->out . '/scripts/run.sh'));
        self::assertFileEquals(Mfd::root() . '/tests/fixtures/template/logo.bin', $this->out . '/logo.bin');
    }

    public function testNeverOverwritesAnExistingFile(): void
    {
        file_put_contents($this->out . '/Taskfile.yml', 'mine');
        $lines = $this->render();

        self::assertSame('mine', file_get_contents($this->out . '/Taskfile.yml'));
        self::assertContains('kept existing Taskfile.yml', $lines);
        self::assertContains('wrote logo.bin', $lines);
    }

    public function testMissingValueIsRejected(): void
    {
        $values = self::VALUES;
        unset($values['SITE_NAME']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SITE_NAME');
        $this->render($values);
    }
}
```

The fixture text files need a trailing newline. `.hidden/config.txt` is `{{PROJECT}}` plus a newline, and the info file ends with a newline.

- [ ] **Step 3: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Template`. Expected: `Class "Manifesto\Mfd\Template\Renderer" not found`.

- [ ] **Step 4: Implement** `src/Template/Renderer.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Template;

/**
 * Copies a template tree into a project, filling in its placeholders.
 *
 * {{PROJECT}}, {{THEME}}, {{THEME_LABEL}} and {{SITE_NAME}} are replaced in UTF-8
 * files in one pass, so values are never re-expanded; __THEME__ is replaced in
 * paths. Task's own {{.NAME}} templates do not match and pass through. Files
 * that already exist are kept, never overwritten.
 */
final class Renderer
{
    public const KEYS = ['PROJECT', 'THEME', 'THEME_LABEL', 'SITE_NAME'];

    /**
     * @param array<string, string> $values
     * @return list<string> "wrote <path>" or "kept existing <path>" for each file
     */
    public function render(string $source, string $target, array $values): array
    {
        foreach (self::KEYS as $key) {
            if (!isset($values[$key])) {
                throw new \InvalidArgumentException('missing template value ' . $key);
            }
        }
        $pattern = '/\{\{(' . implode('|', self::KEYS) . ')\}\}/';

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $lines = [];
        foreach ($files as $path) {
            $relative = str_replace('__THEME__', $values['THEME'], substr($path, strlen($source) + 1));
            $destination = $target . '/' . $relative;
            if (file_exists($destination)) {
                $lines[] = 'kept existing ' . $relative;
                continue;
            }
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            $data = (string) file_get_contents($path);
            if (preg_match('//u', $data) === 1) {
                $data = (string) preg_replace_callback($pattern, static fn (array $m): string => $values[$m[1]], $data);
            }
            file_put_contents($destination, $data);
            chmod($destination, fileperms($path) & 0777);
            $lines[] = 'wrote ' . $relative;
        }

        return $lines;
    }
}
```

- [ ] **Step 5: Run the test and confirm it passes.** Run `vendor/bin/phpunit tests/Unit/Template`. Expected: PASS.

- [ ] **Step 6: Lint.** Run `task lint`. Expected: clean.

---

### Task 4: The generated project's Taskfile

**Files:**
- Create: `template/Taskfile.yml`, `template/.taskfiles/backend.yml`, `template/.taskfiles/frontend.yml`, `template/.taskfiles/qa.yml`
- Create: `tests/fixtures/bin/ddev` (executable), `tests/Support/RenderedTemplateTestCase.php`
- Test: `tests/Unit/Template/TaskfileTemplateTest.php`

**Interfaces:**
- Consumes: `Renderer` (Task 3).
- Produces:
  - the task names in the spec's "Generated Taskfile" section
  - `fe:component` runs `ddev exec vendor/bin/mfd make:component <name>` (Task 9 builds that command)
  - `RenderedTemplateTestCase`, with `$this->dir` holding a freshly rendered `template/` (project `acme`, theme `acme`) and a `runIn(list<string>): Process` helper that runs in that directory with `tests/fixtures/bin` first on `PATH`

- [ ] **Step 1: Write the test support**

`tests/fixtures/bin/ddev` (run `chmod +x`):

```sh
#!/bin/sh
# Stand-in for ddev in template tests: tasks depend on `ddev start`. Never starts anything.
exit 0
```

`tests/Support/RenderedTemplateTestCase.php`:

```php
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

    /** Run a command in the rendered project, with the fake ddev first on PATH. @param list<string> $command */
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
```

- [ ] **Step 2: Write the failing test** `tests/Unit/Template/TaskfileTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;

final class TaskfileTemplateTest extends RenderedTemplateTestCase
{
    public function testTaskfileParsesAndListsBackEndFrontEndAndQaTasks(): void
    {
        $process = $this->runIn(['task', '--list']);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        foreach ([
            'up', 'down', 'setup', 'dev', 'check', 'drush', 'be:install', 'be:require', 'be:update', 'be:cr',
            'be:deploy', 'be:cex', 'be:cim', 'be:login', 'be:db:export', 'be:db:import', 'be:logs', 'be:xdebug',
            'be:test', 'be:test:unit', 'be:test:kernel', 'be:test:site', 'be:lint', 'be:lint:fix', 'fe:install',
            'fe:build', 'fe:watch', 'fe:lint', 'fe:lint:fix', 'fe:test', 'fe:storybook', 'fe:storybook:build',
            'fe:component', 'qa:review', 'qa:review:storybook', 'qa:review:scoped', 'qa:probe',
        ] as $task) {
            self::assertStringContainsString('* ' . $task . ':', $process->getOutput(), 'missing task ' . $task);
        }
    }

    public function testBeTestForwardsArgumentsToPhpunitInsideDdev(): void
    {
        $out = $this->runIn(['task', '--dry', 'be:test', '--', '--filter', 'FrontPage'])->getErrorOutput();

        self::assertStringContainsString('ddev exec vendor/bin/phpunit --filter FrontPage', $out);
    }

    public function testSuiteTasksSelectTheirSuite(): void
    {
        self::assertStringContainsString('--testsuite existing-site', $this->runIn(['task', '--dry', 'be:test:site'])->getErrorOutput());
        self::assertStringContainsString('--testsuite kernel', $this->runIn(['task', '--dry', 'be:test:kernel'])->getErrorOutput());
        self::assertStringContainsString('--testsuite unit', $this->runIn(['task', '--dry', 'be:test:unit'])->getErrorOutput());
    }

    public function testFrontEndTasksGoThroughNpmScriptsIfPresent(): void
    {
        self::assertStringContainsString('npm run --if-present build', $this->runIn(['task', '--dry', 'fe:build'])->getErrorOutput());
        self::assertStringContainsString('npm run --if-present lint', $this->runIn(['task', '--dry', 'fe:lint'])->getErrorOutput());
        self::assertStringContainsString('npm run --if-present test:js', $this->runIn(['task', '--dry', 'fe:test'])->getErrorOutput());
    }

    public function testComponentTaskRunsMfdInsideDdev(): void
    {
        $out = $this->runIn(['task', '--dry', 'fe:component', '--', 'hero'])->getErrorOutput();

        self::assertStringContainsString('ddev exec vendor/bin/mfd make:component hero', $out);
        self::assertStringContainsString('ddev drush cr', $out);
    }

    public function testMissingBuildScriptIsANoOp(): void
    {
        file_put_contents($this->dir . '/package.json', '{"scripts":{}}');
        $process = $this->runIn(['task', 'fe:build']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testADefinedBuildScriptRunsWithItsArguments(): void
    {
        file_put_contents($this->dir . '/package.json', '{"scripts":{"build":"echo built"}}');
        $process = $this->runIn(['task', 'fe:build', '--', '--mode', 'prod']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('built --mode prod', $process->getOutput());
    }

    public function testDbImportWithoutAFileFailsWithUsage(): void
    {
        $process = $this->runIn(['task', 'be:db:import']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('task be:db:import -- ', $process->getOutput() . $process->getErrorOutput());
    }
}
```

`task --dry` prints the commands it would run on stderr. If your Task version prints them on stdout, assert on the combined output instead (`getOutput() . getErrorOutput()`), and do it the same way in every test.

- [ ] **Step 3: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Template/TaskfileTemplateTest.php`. Expected: FAIL (no Taskfile to list).

- [ ] **Step 4: Implement the templates**

`template/Taskfile.yml`:

```yaml
# Daily tasks for {{SITE_NAME}}. Run `task` to list them.
# Back end: be:*   Front end: fe:*   Review: qa:*
version: '3'

vars:
  THEME: {{THEME}}

includes:
  be: ./.taskfiles/backend.yml
  fe: ./.taskfiles/frontend.yml
  qa: ./.taskfiles/qa.yml

tasks:
  default:
    silent: true
    cmds:
      - task --list

  up:
    desc: Start the DDEV site (http://{{PROJECT}}.ddev.site)
    cmds:
      - ddev start

  down:
    desc: Stop the DDEV site
    cmds:
      - ddev stop

  setup:
    desc: 'Get a fresh clone running. Optional database dump: DB=path/to/dump.sql.gz task setup'
    cmds:
      - task: up
      - task: be:install
      - task: fe:install
      - task: setup:site

  setup:site:
    internal: true
    cmds:
      - |
        if [ -n "{{.DB}}" ]; then
          ddev import-db --file="{{.DB}}"
          ddev drush deploy -y
        else
          ddev drush site:install --existing-config -y
        fi

  dev:
    desc: Start the site, then the front-end watcher and Storybook together
    cmds:
      - task: up
      - task: dev:watch

  dev:watch:
    internal: true
    deps: [fe:watch, fe:storybook]

  check:
    desc: The full local check before a pull request
    cmds:
      - task: be:lint
      - task: fe:lint
      - task: be:test
      - task: fe:build
      - task: qa:review

  drush:
    desc: 'Run Drush in DDEV, e.g. task drush -- status'
    cmds:
      - ddev drush {{.CLI_ARGS}}
```

`template/.taskfiles/backend.yml`:

```yaml
# Back-end tasks (be:*). PHP, Composer and Drush always run inside DDEV.
version: '3'

tasks:
  install:
    desc: Install PHP dependencies
    deps: [':up']
    cmds:
      - ddev composer install

  require:
    desc: 'Add a Composer package, e.g. task be:require -- drupal/pathauto'
    deps: [':up']
    cmds:
      - ddev composer require {{.CLI_ARGS}}

  update:
    desc: 'Update Composer packages, e.g. task be:update -- drupal/core-*'
    deps: [':up']
    cmds:
      - ddev composer update {{.CLI_ARGS}} --with-all-dependencies

  drush:
    desc: 'Run Drush, e.g. task be:drush -- status'
    deps: [':up']
    cmds:
      - ddev drush {{.CLI_ARGS}}

  cr:
    desc: Clear Drupal caches
    deps: [':up']
    cmds:
      - ddev drush cr

  deploy:
    desc: Run database updates, config import and deploy hooks in core's order
    deps: [':up']
    cmds:
      - ddev drush deploy -y

  cex:
    desc: Export configuration to config/sync
    deps: [':up']
    cmds:
      - ddev drush config:export -y

  cim:
    desc: Import configuration from config/sync
    deps: [':up']
    cmds:
      - ddev drush config:import -y

  login:
    desc: One-time admin login link
    deps: [':up']
    cmds:
      - ddev drush uli

  db:export:
    desc: 'Export the database (default db.sql.gz, ignored by git), e.g. task be:db:export -- backup.sql.gz'
    deps: [':up']
    cmds:
      - ddev export-db --file={{if .CLI_ARGS}}{{.CLI_ARGS}}{{else}}db.sql.gz{{end}}

  db:import:
    desc: 'Import a database dump, e.g. task be:db:import -- db.sql.gz'
    deps: [':up']
    preconditions:
      - sh: test -n "{{.CLI_ARGS}}"
        msg: 'Usage: task be:db:import -- path/to/dump.sql.gz'
    cmds:
      - ddev import-db --file={{.CLI_ARGS}}

  logs:
    desc: Recent Drupal log messages
    deps: [':up']
    cmds:
      - ddev drush watchdog:show

  xdebug:
    desc: 'Turn Xdebug on or off, e.g. task be:xdebug -- on'
    preconditions:
      - sh: test -n "{{.CLI_ARGS}}"
        msg: 'Usage: task be:xdebug -- on|off|status'
    cmds:
      - ddev xdebug {{.CLI_ARGS}}

  test:
    desc: 'All PHPUnit suites; extra args go to PHPUnit, e.g. task be:test -- --filter FrontPage'
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/phpunit {{.CLI_ARGS}}

  test:unit:
    desc: Unit suite
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/phpunit --testsuite unit {{.CLI_ARGS}}

  test:kernel:
    desc: Kernel suite
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/phpunit --testsuite kernel {{.CLI_ARGS}}

  test:site:
    desc: ExistingSite suite (Drupal Test Traits, runs against the installed site)
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/phpunit --testsuite existing-site {{.CLI_ARGS}}

  lint:
    desc: PHP coding standards (phpcs) and static analysis (phpstan)
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/phpcs
      - ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G

  lint:fix:
    desc: Fix what phpcbf can fix automatically
    deps: [':up']
    cmds:
      # phpcbf exits 1 when it fixed files; that is success here.
      - ddev exec vendor/bin/phpcbf || [ $? -eq 1 ]
```

`template/.taskfiles/frontend.yml`:

```yaml
# Front-end tasks (fe:*). Each runs an npm script with --if-present, so adding or
# changing a tool (Vite, PostCSS, Tailwind, TypeScript...) means editing
# package.json scripts, never this file. A script that does not exist yet is a no-op.
version: '3'

tasks:
  install:
    desc: Install Node dependencies from package-lock.json
    cmds:
      - npm ci

  build:
    desc: Build front-end assets (npm script "build")
    cmds:
      - npm run --if-present build -- {{.CLI_ARGS}}

  watch:
    desc: Rebuild front-end assets on change (npm script "watch")
    cmds:
      - npm run --if-present watch -- {{.CLI_ARGS}}

  lint:
    desc: Lint component and custom-module JS and CSS (npm script "lint")
    cmds:
      - npm run --if-present lint -- {{.CLI_ARGS}}

  lint:fix:
    desc: Fix lint problems where possible (npm script "lint:fix")
    cmds:
      - npm run --if-present lint:fix -- {{.CLI_ARGS}}

  test:
    desc: JavaScript tests (npm script "test:js")
    cmds:
      - npm run --if-present test:js -- {{.CLI_ARGS}}

  storybook:
    desc: Storybook dev server on http://localhost:6006 (npm script "storybook")
    cmds:
      - npm run --if-present storybook -- {{.CLI_ARGS}}

  storybook:build:
    desc: Static Storybook build (npm script "storybook:build")
    cmds:
      - npm run --if-present storybook:build -- {{.CLI_ARGS}}

  component:
    desc: 'Create a Single Directory Component in the theme, e.g. task fe:component -- hero'
    deps: [':up']
    cmds:
      - ddev exec vendor/bin/mfd make:component {{.CLI_ARGS}}
      - task: ':be:cr'
```

`template/.taskfiles/qa.yml`:

```yaml
# Design review and harness tasks (qa:*). Advisory: nothing here blocks a merge;
# merge checks run in CI once the repository is enrolled.
version: '3'

tasks:
  review:
    desc: Full design review (Storybook check, then the site check against DDEV)
    cmds:
      - npm run qa:loop

  review:storybook:
    desc: Storybook check only; no DDEV needed
    cmds:
      - QA_DEV_CMD= npm run qa:loop

  review:scoped:
    desc: Review only what changed on this branch
    cmds:
      - npm run qa:loop:scoped

  probe:
    desc: Show what is left of the harness setup (then run /setup-project in Claude Code)
    cmds:
      - python3 "$HOME/.claude/lib/setup_project_probe.py" probe --root .
```

- [ ] **Step 5: Run the test and confirm it passes.** Run `vendor/bin/phpunit tests/Unit/Template/TaskfileTemplateTest.php`. Expected: PASS. If your installed Task version rejects `deps: [':up']` (3.53.1 here), check the Task docs on "calling a root task from an included Taskfile" and use the form they give, everywhere.

- [ ] **Step 6: Lint.** Run `task lint`. Expected: clean.

---

### Task 5: PHPUnit, the test environment, PHP quality configuration and example tests (generated project)

**Owner:** `backend-engineer`. The brief includes `~/.claude/references/drupal-pitfalls.md` (all Critical and Security items, plus the TE items) and the `drupal-testing` skill.

**Files:**
- Create: `template/phpunit.xml.dist`, `template/.ddev/config.testing.yaml`, `template/phpcs.xml.dist`, `template/phpstan.neon.dist`
- Create: `template/web/modules/custom/__THEME___tests/__THEME___tests.info.yml`
- Create: `template/web/modules/custom/__THEME___tests/tests/src/Unit/ExampleTest.php`
- Create: `template/web/modules/custom/__THEME___tests/tests/src/Kernel/ComponentDiscoveryTest.php`
- Create: `template/web/modules/custom/__THEME___tests/tests/src/ExistingSite/FrontPageTest.php`
- Test: `tests/Unit/Template/PhpTemplatesTest.php`

**Interfaces:**
- Consumes: `RenderedTemplateTestCase` (Task 4), and the card component `<theme>:card` with `data-qa="card"` on the front page (Task 10 produces it).
- Produces: the PHPUnit suite names `unit`, `kernel` and `existing-site` (Task 4's tasks use them).

- [ ] **Step 1: Write the failing test** `tests/Unit/Template/PhpTemplatesTest.php`. These tests check structure only; the end-to-end run in Task 13 proves behaviour.

```php
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

        $names = array_map(static fn (\SimpleXMLElement $s): string => (string) $s['name'], $xml->xpath('//testsuite') ?: []);
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
```

- [ ] **Step 2: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Template/PhpTemplatesTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement the templates**

`template/phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
  PHPUnit for {{THEME}}. Run inside DDEV: `task be:test` (all), or
  be:test:unit / be:test:kernel / be:test:site. PHPUnit comes from
  drupal/core-dev, which picks the version core supports: never pin it here.
  Environment (SIMPLETEST_DB, DTT_BASE_URL) comes from .ddev/config.testing.yaml.
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="web/core/tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnRisky="true"
         displayDetailsOnTestsThatTriggerDeprecations="true">
  <php>
    <ini name="error_reporting" value="32767"/>
    <ini name="memory_limit" value="-1"/>
  </php>
  <testsuites>
    <testsuite name="unit">
      <directory>web/modules/custom/*/tests/src/Unit</directory>
    </testsuite>
    <testsuite name="kernel">
      <directory>web/modules/custom/*/tests/src/Kernel</directory>
    </testsuite>
    <testsuite name="existing-site">
      <directory>web/modules/custom/*/tests/src/ExistingSite</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

`template/.ddev/config.testing.yaml`:

```yaml
# Environment for PHPUnit inside the web container (task be:test).
# Kernel tests use the DDEV database with a table prefix; ExistingSite tests
# (Drupal Test Traits) browse the installed site through the web container.
web_environment:
  - SIMPLETEST_DB=mysql://db:db@db/db
  - SIMPLETEST_BASE_URL=http://web
  - DTT_BASE_URL=http://web
  - BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest/browser_output
```

`template/phpcs.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ruleset name="{{THEME}}">
  <description>Drupal coding standards for custom code (task be:lint).</description>
  <file>web/modules/custom</file>
  <file>web/themes/custom</file>
  <arg name="extensions" value="php,module,inc,install,test,profile,theme,info,yml"/>
  <arg name="colors"/>
  <arg value="s"/>
  <!-- Report warnings, but fail only on errors. -->
  <config name="ignore_warnings_on_exit" value="1"/>
  <exclude-pattern>*/node_modules/*</exclude-pattern>
  <rule ref="Drupal"/>
  <rule ref="DrupalPractice"/>
</ruleset>
```

`template/phpstan.neon.dist`:

```neon
# Static analysis for custom code (task be:lint). phpstan-drupal is loaded by
# phpstan/extension-installer. Raise the level as the codebase grows.
parameters:
  level: 1
  paths:
    - web/modules/custom
    - web/themes/custom
```

`template/web/modules/custom/__THEME___tests/__THEME___tests.info.yml`:

```yaml
name: {{THEME}} tests
type: module
description: 'Holds the project example tests. No runtime code; never needs enabling.'
package: Testing
core_version_requirement: ^11
hidden: true
```

`.../tests/src/Unit/ExampleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\Unit;

use Drupal\Component\Utility\Html;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Example unit test: no Drupal bootstrap. Replace with tests of your classes.
 */
#[Group('{{THEME}}')]
final class ExampleTest extends UnitTestCase {

  /**
   * Html::getClass() turns a label into the CSS class a template expects.
   */
  public function testClassNameFromLabel(): void {
    $this->assertSame('card-heading', Html::getClass('Card heading'));
  }

}
```

`.../tests/src/Kernel/ComponentDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Example kernel test: the theme's Single Directory Components are discovered.
 */
#[Group('{{THEME}}')]
final class ComponentDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The example card component is registered under the theme's namespace.
   */
  public function testCardComponentIsDiscovered(): void {
    $this->container->get('theme_installer')->install(['{{THEME}}']);
    $this->config('system.theme')->set('default', '{{THEME}}')->save();

    $component = $this->container->get('plugin.manager.sdc')->find('{{THEME}}:card');

    $this->assertSame('Card', $component->metadata->name);
  }

}
```

`.../tests/src/ExistingSite/FrontPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\{{THEME}}_tests\ExistingSite;

use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Example ExistingSite test: runs against the installed DDEV site.
 */
#[Group('{{THEME}}')]
final class FrontPageTest extends ExistingSiteBase {

  /**
   * The front page renders the example card component.
   */
  public function testFrontPageRendersCard(): void {
    $this->drupalGet('/');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-qa="card"]');
  }

}
```

- [ ] **Step 4: Run the test and confirm it passes.** Run `vendor/bin/phpunit tests/Unit/Template/PhpTemplatesTest.php`. Expected: PASS.

Behaviour inside Drupal is verified in Task 13. If the kernel test fails there because the container is stale after the theme install, fetch the manager with `\Drupal::service('plugin.manager.sdc')` after the install, and follow the `drupal-testing` skill before changing anything else.

---

### Task 6: Front-end lint configuration and the package.json merger

**Owner:** `frontend-engineer`.

**Files:**
- Create: `template/eslint.config.mjs`, `template/.stylelintrc.json`
- Create: `resources/package-additions.json`, `src/Npm/PackageMerger.php`
- Test: `tests/Unit/Npm/PackageMergerTest.php`

**Interfaces:**
- Produces: `PackageMerger::merge(string $packageFile, string $additionsFile): void`.
  - For each of `scripts`, `devDependencies` and `overrides`, it adds missing keys and never changes existing ones.
  - It keeps empty objects as `{}` and writes 2-space indentation with a trailing newline.
  - It throws `\RuntimeException` when `package.json` is missing or is not a JSON object.

- [ ] **Step 1: Write the failing test** `tests/Unit/Npm/PackageMergerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Npm;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Npm\PackageMerger;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class PackageMergerTest extends TestCase
{
    use TempDirectory;

    private string $package;

    protected function setUp(): void
    {
        $this->package = $this->newTempDir() . '/package.json';
    }

    private function merge(): void
    {
        (new PackageMerger())->merge($this->package, Mfd::root() . '/resources/package-additions.json');
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $data = json_decode((string) file_get_contents($this->package), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testAddsLintScriptsAndExactPinnedTools(): void
    {
        file_put_contents($this->package, '{"scripts":{"storybook":"storybook dev -p 6006"},"devDependencies":{"storybook":"10.6.0"}}');
        $this->merge();
        $pkg = $this->decoded();

        self::assertSame(
            ['@eslint/js' => '10.0.1', 'eslint' => '10.11.0', 'globals' => '17.12.0', 'stylelint' => '17.15.0', 'stylelint-config-standard' => '40.0.0'],
            array_diff_key($pkg['devDependencies'], ['storybook' => true]),
        );
        self::assertArrayHasKey('lint', $pkg['scripts']);
        self::assertArrayHasKey('lint:fix', $pkg['scripts']);
        self::assertSame('storybook dev -p 6006', $pkg['scripts']['storybook']);
        self::assertSame('10.6.0', $pkg['devDependencies']['storybook']);
    }

    public function testNeverOverwritesExistingKeys(): void
    {
        file_put_contents($this->package, '{"scripts":{"lint":"mine"},"devDependencies":{"eslint":"9.0.0"}}');
        $this->merge();
        $pkg = $this->decoded();

        self::assertSame('mine', $pkg['scripts']['lint']);
        self::assertSame('9.0.0', $pkg['devDependencies']['eslint']);
    }

    public function testKeepsEmptyObjectsListsAndTwoSpaceIndentation(): void
    {
        file_put_contents($this->package, "{\n  \"name\": \"x\",\n  \"config\": {},\n  \"files\": [\"a\"]\n}\n");
        $this->merge();
        $json = (string) file_get_contents($this->package);

        self::assertStringContainsString("\n  \"config\": {},\n", $json);
        self::assertStringContainsString('"files": [', $json);
        self::assertStringContainsString("\n    \"@eslint/js\": \"10.0.1\"", $json);
        self::assertStringEndsWith("}\n", $json);
    }

    public function testMissingPackageJsonFailsClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('package.json');
        $this->merge();
    }

    public function testLintScopeIsComponentsAndCustomModulesNotTheStarterkitCss(): void
    {
        $additions = json_decode((string) file_get_contents(Mfd::root() . '/resources/package-additions.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($additions);
        $lint = $additions['scripts']['lint'];

        self::assertStringContainsString('web/themes/custom/*/components', $lint);
        self::assertStringNotContainsString('web/themes/custom/**/*.css', $lint);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Npm`. Expected: FAIL.

- [ ] **Step 3: Implement**

`resources/package-additions.json`:

```json
{
  "scripts": {
    "lint": "eslint --no-error-on-unmatched-pattern web/themes/custom/*/components web/modules/custom && stylelint --allow-empty-input \"web/themes/custom/*/components/**/*.css\" \"web/modules/custom/**/*.css\"",
    "lint:fix": "eslint --fix --no-error-on-unmatched-pattern web/themes/custom/*/components web/modules/custom && stylelint --fix --allow-empty-input \"web/themes/custom/*/components/**/*.css\" \"web/modules/custom/**/*.css\""
  },
  "devDependencies": {
    "@eslint/js": "10.0.1",
    "eslint": "10.11.0",
    "globals": "17.12.0",
    "stylelint": "17.15.0",
    "stylelint-config-standard": "40.0.0"
  }
}
```

`src/Npm/PackageMerger.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Npm;

/**
 * Adds mfd's scripts and devDependencies to a project's package.json without
 * changing anything already there. Works on JSON objects (not PHP arrays) so
 * empty objects stay {} and key order is kept.
 */
final class PackageMerger
{
    private const SECTIONS = ['scripts', 'devDependencies', 'overrides'];

    public function merge(string $packageFile, string $additionsFile): void
    {
        $package = $this->read($packageFile);
        $additions = $this->read($additionsFile);

        foreach (self::SECTIONS as $section) {
            if (!isset($additions->{$section}) || !$additions->{$section} instanceof \stdClass) {
                continue;
            }
            if (!isset($package->{$section}) || !$package->{$section} instanceof \stdClass) {
                $package->{$section} = new \stdClass();
            }
            foreach (get_object_vars($additions->{$section}) as $name => $value) {
                if (!property_exists($package->{$section}, $name)) {
                    $package->{$section}->{$name} = $value;
                }
            }
        }

        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // npm writes 2-space indentation; PHP's pretty print uses 4.
        $json = (string) preg_replace_callback('/^ +/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
        file_put_contents($packageFile, $json . "\n");
    }

    private function read(string $file): \stdClass
    {
        if (!is_file($file)) {
            throw new \RuntimeException($file . ' not found (package.json is written by the harness step)');
        }
        $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException($file . ' is not a JSON object');
        }

        return $data;
    }
}
```

The empty-object case: `json_encode` writes an empty `stdClass` as `{}`, which is why the merger works on `stdClass` objects rather than arrays.

`template/eslint.config.mjs`:

```js
// ESLint for component and custom-module JavaScript (task fe:lint).
// Drupal behaviours use the Drupal, drupalSettings and once globals.
import js from '@eslint/js';
import globals from 'globals';

export default [
  {
    ignores: ['node_modules/**', 'vendor/**', 'web/core/**', 'qa/**', '.storybook/**', 'storybook-static/**'],
  },
  js.configs.recommended,
  {
    files: ['web/themes/custom/**/*.js', 'web/modules/custom/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'script',
      globals: {
        ...globals.browser,
        Drupal: 'readonly',
        drupalSettings: 'readonly',
        once: 'readonly',
      },
    },
  },
];
```

`template/.stylelintrc.json`:

```json
{
  "extends": ["stylelint-config-standard"],
  "rules": {
    "selector-class-pattern": null
  }
}
```

`selector-class-pattern` is turned off so that BEM names such as `.card__heading`, which Drupal themes use, are allowed.

- [ ] **Step 4: Run the test and confirm it passes.** Run `vendor/bin/phpunit tests/Unit/Npm`. Expected: PASS.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 15: Project naming that starts from the label (runs straight after Task 6)

Added on 2026-09-24 at the user's request: `mfd new Something` must be enough on its own. Every other name comes from the one argument, and each can still be overridden with an optional flag.

**Owner:** `backend-engineer`.

**Files:**
- Modify: `src/Config/Naming.php` (add `projectNameFromLabel`)
- Modify: `src/Config/ProjectConfig.php` (`fromInput` signature and derivation)
- Modify: `src/Command/NewCommand.php` (argument `name`, new option `--project-name`, help text)
- Modify tests: `tests/Unit/Config/NamingTest.php`, `tests/Unit/Config/ProjectConfigTest.php`, `tests/Unit/BinMfdTest.php`, `tests/Support/NewCommandTestCase.php`, `tests/Unit/NewCommandRunTest.php`

**Interfaces:**
- Produces (Tasks 8–13 use these; their test inputs use the key `'name'`):
  - The `new` command's argument is now `name` (was `project-name`): the project's human name, for example `"Acme Corp"`, or an already machine-style name, for example `acme-corp`.
  - A new optional flag, `--project-name`, overrides the derived DDEV/project name.
  - `Naming::projectNameFromLabel(string $label): string`
  - `ProjectConfig::fromInput(?string $name, ?string $projectName = null, ?string $theme = null, ?string $themeLabel = null, ?string $siteName = null, ?string $targetDir = null): ProjectConfig`. Its properties and methods are unchanged.
  - `NewCommandTestCase::newProject()` defaults `--dir` to `<work>/<derived project name>`. It reads `$input['--project-name'] ?? $input['name']`, and callers in this plan always pass a name that is already a valid project name, such as `acme` or `acme-corp`.

**Derivation rules** (only `name` is needed; every flag is optional):

| Value | Default |
| --- | --- |
| project (DDEV) name | `--project-name`. Otherwise, if `name` is already a valid project name, `name` itself. Otherwise `Naming::projectNameFromLabel(name)`. |
| theme label | `--theme-label`. Otherwise, if `name` was already a valid project name, `Naming::titleCase(name)`. Otherwise `name` itself. |
| theme machine name | `--theme`, or the project name with `-` replaced by `_` |
| site name | `--site-name`, or the theme label |
| directory | `--dir`, or `./<project name>` |

`projectNameFromLabel`:
- lowercase the label
- remove apostrophes
- replace every run of characters other than `[a-z0-9]` with `-`
- trim `-` from both ends
- cut to 63 characters, then trim a trailing `-` again

The result is validated as a project name. If it is invalid (too short, or starting with a digit), `fromInput` throws `UserError` naming `--project-name`.

| `mfd new …` | project | theme | theme label = site name |
| --- | --- | --- | --- |
| `Something` | `something` | `something` | `Something` |
| `"Acme Corp"` | `acme-corp` | `acme_corp` | `Acme Corp` |
| `"O'Brien & Co"` | `obrien-co` | `obrien_co` | `O'Brien & Co` |
| `acme-corp` | `acme-corp` | `acme_corp` | `Acme Corp` |
| `"Acme Corp" --project-name acme --theme acme_ui` | `acme` | `acme_ui` | `Acme Corp` |

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Config/NamingTest.php`:

```php
    /** @return iterable<string, array{string, string}> */
    public static function labelsToProjectNames(): iterable
    {
        yield 'single word' => ['Something', 'something'];
        yield 'two words' => ['Acme Corp', 'acme-corp'];
        yield 'apostrophe and ampersand' => ["O'Brien & Co", 'obrien-co'];
        yield 'punctuation runs' => ['Acme, Inc. (UK)', 'acme-inc-uk'];
        yield 'already machine style' => ['acme-corp', 'acme-corp'];
        yield 'leading digit kept' => ['3M Company', '3m-company'];
        yield 'long label is cut to 63' => [str_repeat('Abc ', 30), rtrim(substr(str_repeat('abc-', 30), 0, 63), '-')];
    }

    #[DataProvider('labelsToProjectNames')]
    public function testProjectNameFromLabel(string $label, string $expected): void
    {
        self::assertSame($expected, Naming::projectNameFromLabel($label));
    }
```

Rewrite `tests/Unit/Config/ProjectConfigTest.php` for the new signature. Keep every existing case, moving each argument one position to the right after the new `$projectName` parameter. One exception: `invalidInputs()`'s `'bad project' => ['Acme', …]` is valid now (`Acme` is a label, and becomes project `acme`), so replace it with `'bad name' => ['Acme: Corp', …]`. Then add these:

```php
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

        self::assertSame(['something', 'something', 'Something', 'Something'], [$config->project, $config->theme, $config->themeLabel, $config->siteName]);
    }

    public function testPunctuatedLabelKeepsItsLabelAndGetsACleanMachineName(): void
    {
        $config = ProjectConfig::fromInput("O'Brien & Co");

        self::assertSame(['obrien-co', 'obrien_co', "O'Brien & Co"], [$config->project, $config->theme, $config->themeLabel]);
    }

    public function testAMachineStyleNameIsUsedAsIsAndTitleCasedForTheLabel(): void
    {
        $config = ProjectConfig::fromInput('acme-corp');

        self::assertSame(['acme-corp', 'Acme Corp'], [$config->project, $config->themeLabel]);
    }

    public function testEveryDerivedValueCanBeOverridden(): void
    {
        $config = ProjectConfig::fromInput('Acme Corp', 'acme', 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x');

        self::assertSame(['acme', 'acme_ui', 'Acme UI', 'Acme Group', '/tmp/x'], [$config->project, $config->theme, $config->themeLabel, $config->siteName, $config->targetDir]);
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
```

In `tests/Unit/BinMfdTest.php`, add these to `badInvocations()`:
- `yield 'underivable name' => [['new', '3M Company']];`
- `yield 'label with colon' => [['new', 'Acme: Corp']];`

Replace the existing `'invalid project name' => [['new', 'Acme']]` case. `Acme` is now a valid label, which becomes project `acme`. Add a test that `new --help` shows `--project-name` and the word `optional`.

In `tests/Support/NewCommandTestCase.php` and `tests/Unit/NewCommandRunTest.php`, rename the input key `'project-name'` to `'name'`. The default `--dir` in `newProject()` becomes `<work>/<$input['--project-name'] ?? $input['name']>`.

- [ ] **Step 2: Run the tests and confirm they fail.** Run `vendor/bin/phpunit`. Expected: the new cases FAIL (for example `Call to undefined method Naming::projectNameFromLabel()`).

- [ ] **Step 3: Implement**

`src/Config/Naming.php`: add

```php
    /** A DDEV project name from a human label: "O'Brien & Co" -> "obrien-co". May still be invalid; validate it. */
    public static function projectNameFromLabel(string $label): string
    {
        $slug = str_replace(["'", '’'], '', strtolower($label));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, 63), '-');
    }
```

`src/Config/ProjectConfig.php`: `fromInput()` becomes

```php
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
            throw new UserError('a project name is required. Usage: mfd new <name> [options], e.g. mfd new "Acme Corp" (see mfd new --help)');
        }
        $nameIsMachineName = Naming::isValidProjectName($name);
        if (!$nameIsMachineName && !Naming::isValidLabel($name)) {
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
                "project name '%s' must be 3-63 characters of lowercase letters, digits and '-', starting with a letter and not ending with '-' (it becomes the DDEV name)",
                $projectName,
            ));
        }

        if ($themeLabel === null || $themeLabel === '') {
            $themeLabel = $nameIsMachineName ? Naming::titleCase($name) : $name;
        }
        // …then the theme, the theme-label check, the site name and the target directory, exactly as
        // before, with $project renamed $projectName.
    }
```

Keep the existing theme, theme-label, site-name and target-dir code and messages unchanged. The only difference is that it now works from `$projectName`.

`src/Command/NewCommand.php`:
- `configure()`: the argument becomes `addArgument('name', InputArgument::OPTIONAL, 'The project\'s name, e.g. "Acme Corp". Every other name is derived from it')`.
- Add `->addOption('project-name', null, InputOption::VALUE_REQUIRED, 'Optional. DDEV/project machine name (default: derived from the name, e.g. acme-corp)')`.
- Start every other option's description with `Optional.`, and keep its default text, for example `'Optional. Theme machine name (default: the project name with \'-\' as \'_\')'`.
- Add to the help text: `Only the name is required, e.g. mfd new "Acme Corp" gives DDEV project acme-corp, theme acme_corp, and label and site name "Acme Corp".`
- `configFrom()` passes `name`, `--project-name`, `--theme`, `--theme-label`, `--site-name` and `--dir` in the new order.

- [ ] **Step 4: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes. Then run by hand: `bin/mfd new "Acme Corp" --dry-run` should print `Project: acme-corp`, `Theme: acme_corp (Acme Corp)` and `Site name: Acme Corp`, or exit 2 with a pre-flight message if this machine lacks a tool. `bin/mfd new --help` should show `--project-name` and `Optional.`.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 7: Generated CLAUDE.md and README

**Files:**
- Create: `template/CLAUDE.md`, `template/README.md`
- Test: `tests/Unit/Template/DocsTemplateTest.php`

**Interfaces:**
- Consumes: `RenderedTemplateTestCase` (Task 4) and the task names from Task 4.

- [ ] **Step 1: Write the failing test** `tests/Unit/Template/DocsTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Template;

use Manifesto\Mfd\Tests\Support\RenderedTemplateTestCase;

final class DocsTemplateTest extends RenderedTemplateTestCase
{
    public function testClaudeMdAndReadmeNameTheProjectThemeAndTasks(): void
    {
        foreach (['CLAUDE.md', 'README.md'] as $file) {
            $text = $this->read($file);
            foreach (['http://acme.ddev.site', 'web/themes/custom/acme', 'task be:test', 'task fe:component'] as $needle) {
                self::assertStringContainsString($needle, $text, "$file lacks $needle");
            }
            self::assertDoesNotMatchRegularExpression('/\{\{(PROJECT|THEME|THEME_LABEL|SITE_NAME)\}\}/', $text, $file);
        }
        self::assertStringContainsString('drupal-pitfalls.md', $this->read('CLAUDE.md'));
        self::assertStringContainsString('/setup-project', $this->read('CLAUDE.md'));
        self::assertStringContainsString('mfd', $this->read('CLAUDE.md'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Template/DocsTemplateTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** `template/CLAUDE.md`:

````markdown
# {{SITE_NAME}}

Drupal 11 site with Single Directory Components (SDC), Storybook and the mf-harness
design-review tooling. Scaffolded by drupal-starter (see `.drupal-starter.json`).

## Stack

- Drupal 11 on DDEV: http://{{PROJECT}}.ddev.site. PHP, Composer and Drush run in the DDEV
  web container; never use host PHP or host Composer.
- Custom theme `{{THEME}}` at `web/themes/custom/{{THEME}}`, generated from core's starterkit.
- Components: `web/themes/custom/{{THEME}}/components/<name>/` with `<name>.component.yml`,
  `<name>.twig` and `<name>.css`.
- Storybook 10 with `storybook-addon-sdc`, rendering Twig with Twig.js. Stories come from each
  component's `examples`; there are no hand-written story files.
- PHPUnit suites `unit`, `kernel` and `existing-site` (Drupal Test Traits), in
  `web/modules/custom/*/tests/src/`. Example tests live in `{{THEME}}_tests`.
- Configuration is exported to `config/sync`.

## Commands

Run `task` to list every task. The common ones:

| Task | What it does |
| --- | --- |
| `task dev` | Start the site, the front-end watcher and Storybook |
| `task check` | Everything a pull request needs locally: lint, tests, build, review |
| `task be:test` | All PHPUnit suites (`be:test:unit`, `be:test:kernel`, `be:test:site` for one) |
| `task be:test -- --filter Name` | One test |
| `task be:lint` | phpcs (Drupal standards) and phpstan |
| `task be:cex` / `task be:cim` | Export / import configuration |
| `task fe:component -- <name>` | New SDC in the theme |
| `task fe:build` / `task fe:lint` | Front-end build and lint (npm scripts `build`, `lint`) |
| `task qa:review` | Design review (advisory) |
| `task drush -- <args>` | Any Drush command |

## Conventions

- Write the failing test first. Use PHPUnit attributes (`#[Group('{{THEME}}')]`), never
  annotations. A suite that reports zero tests has not passed.
- New components: `task fe:component -- <name>`, then give every prop a realistic `examples`
  value, place the component on a real page, and add a site story to `qa/stories.ts`. Until then
  the review reports it as `QA-SBONLY` (checked in Storybook only).
- Front-end tooling is added through npm scripts (`build`, `watch`, `lint`, `test:js`). The
  `fe:` tasks call them; do not edit the Taskfile to add a tool.
- After changing configuration in the UI, run `task be:cex` and commit `config/sync`.
- `web/sites/default/settings.php` is committed and holds no secrets; credentials and the hash
  salt come from DDEV's `settings.ddev.php`, which is ignored.

## Harness

- Load `~/.claude/references/drupal-pitfalls.md` when working on Drupal code.
- Harness setup state: `task qa:probe`. Finish setup with `/setup-project` in Claude Code.
- The design review (`task qa:review`) is advisory. Merge checks run in CI once the repository
  is enrolled.

````

Then add this line to the end of CLAUDE.md's "Conventions" list:

```markdown
- Project tooling comes from `mfd` (a dev dependency; run it inside DDEV as `ddev exec vendor/bin/mfd`).
  `mfd list` shows its commands; `task fe:component` wraps `mfd make:component`.
```

`template/README.md`:

````markdown
# {{SITE_NAME}}

Drupal 11 on DDEV with the `{{THEME}}` theme, Single Directory Components and Storybook.

- Site: http://{{PROJECT}}.ddev.site
- Theme: `web/themes/custom/{{THEME}}`
- Storybook: http://localhost:6006 (`task fe:storybook`)

## Requirements

DDEV 1.25 or later with Docker running, Node.js 20 or later, [Task](https://taskfile.dev), git,
and the team's Claude Code harness.

## First run after cloning

```bash
task setup                        # site from config/sync
DB=path/to/dump.sql.gz task setup # or from a database dump
```

## Daily work

Run `task` for the full list.

| Task | What it does |
| --- | --- |
| `task dev` | Site, front-end watcher and Storybook |
| `task check` | Lint, tests, build and design review before a pull request |
| `task be:test` | All PHPUnit suites; `be:test:unit`, `be:test:kernel`, `be:test:site` for one |
| `task be:lint` | PHP coding standards and static analysis |
| `task be:cex` | Export configuration after changing it in the UI |
| `task be:login` | Admin login link |
| `task fe:component -- hero` | New component in the theme |
| `task fe:build`, `task fe:lint` | Front-end build and lint |
| `task qa:review` | Design review |

## Adding front-end tooling

The `fe:` tasks run npm scripts: `build`, `watch`, `lint`, `lint:fix`, `test:js`. To add Vite,
PostCSS, Tailwind or anything else, define or change those scripts in `package.json`. The
Taskfile does not change. A script that is not defined yet does nothing.

## Naming note

Drupal machine names are shared by modules and themes. The theme is `{{THEME}}`, so a custom
module cannot use that name.

````

- [ ] **Step 4: Run the test and confirm it passes.** Run `vendor/bin/phpunit tests/Unit/Template/DocsTemplateTest.php`. Expected: PASS.

---

### Task 8: Step `drupal` (DDEV, codebase, dev tooling, site install)

**Owner:** `backend-engineer`. The brief includes `~/.claude/references/drupal-pitfalls.md`.

**Files:**
- Create: `src/Step/DrupalStep.php`
- Modify: `src/Steps.php` (register the step)
- Test: `tests/Unit/Step/DrupalStepTest.php`

**Interfaces:**
- Consumes: `Step`, `Context`, `Shell`, `CommandFailed` and `NewCommandTestCase` (Task 2).
- Produces:
  - a DDEV project, composer.json and an installed site
  - a `.gitignore` with the `# drupal-starter` block
  - `web/sites/default/settings.php` with `config_sync_directory` set to `../config/sync`

- [ ] **Step 1: Write the failing test** `tests/Unit/Step/DrupalStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\DrupalStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DrupalStepTest extends NewCommandTestCase
{
    /** @param array<string, mixed> $input */
    private function drupal(array $input = ['name' => 'acme']): CommandTester
    {
        return $this->newProject($input, [new DrupalStep()]);
    }

    public function testRunsDdevComposerAndInstallInOrder(): void
    {
        $tester = $this->drupal(['name' => 'acme-corp', '--site-name' => "O'Brien & Co"]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $calls = $this->shell->calls;
        self::assertContains('ddev config --project-name=acme-corp --project-type=drupal11 --docroot=web', $calls);
        self::assertContains('ddev composer create-project drupal/recommended-project:^11 --no-interaction', $calls);
        self::assertContains(
            'ddev composer require --dev --no-interaction drupal/core-dev weitzman/drupal-test-traits mglaman/phpstan-drupal phpstan/extension-installer drupal/coder',
            $calls,
        );
        self::assertContains("ddev drush site:install standard --site-name=O'Brien & Co -y", $calls);
        self::assertLessThan(
            array_search('ddev composer require --dev --no-interaction drupal/core-dev weitzman/drupal-test-traits mglaman/phpstan-drupal phpstan/extension-installer drupal/coder', $calls, true),
            array_search('ddev composer config --no-interaction allow-plugins.dealerdirect/phpcodesniffer-composer-installer true', $calls, true),
        );
    }

    public function testNeverPinsPhpunitDirectly(): void
    {
        $this->drupal();

        foreach ($this->shell->calls as $call) {
            self::assertStringNotContainsString('phpunit/phpunit', $call);
        }
    }

    public function testIgnoresDependenciesAndDdevSecretsButCommitsSettingsPhp(): void
    {
        $this->drupal();
        $lines = file($this->project('acme') . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [];

        foreach (['/vendor/', '/web/core/', '/node_modules/', '/web/sites/*/settings.ddev.php', '/web/sites/*/files/', '*.sql.gz'] as $rule) {
            self::assertContains($rule, $lines);
        }
        self::assertNotContains('/web/sites/*/settings.php', $lines);
        self::assertNotContains('/web/sites/*/settings*.php', $lines);
    }

    public function testConfigSyncDirectoryIsSetOnce(): void
    {
        $this->drupal();

        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame(1, substr_count($settings, "\$settings['config_sync_directory'] = '../config/sync';"));
    }

    public function testAFailureAfterCreateProjectResumesWithoutRedoingIt(): void
    {
        $this->shell->failOn('composer require --dev');
        self::assertSame(1, $this->drupal()->getStatusCode());

        $this->shell->clearFailures();
        $this->shell->calls = [];
        $tester = $this->drupal();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->shell->callsStartingWith('ddev composer create-project'));
        self::assertSame([], $this->shell->callsStartingWith('ddev config '));
        self::assertNotSame([], $this->shell->callsStartingWith('ddev composer require --dev'));
        $settings = (string) file_get_contents($this->project('acme') . '/web/sites/default/settings.php');
        self::assertSame(1, substr_count($settings, 'config_sync_directory'));
    }

    public function testSkipsSiteInstallWhenTheSiteIsAlreadyInstalled(): void
    {
        $this->shell->failOn('drush site:install');
        $this->drupal();
        touch($this->project('acme') . '/.fake-installed');
        $this->shell->clearFailures();
        $this->shell->calls = [];

        self::assertSame(0, $this->drupal()->getStatusCode());
        self::assertSame([], $this->shell->callsStartingWith('ddev drush site:install'));
    }

    public function testFailsClearlyWhenDdevDidNotIncludeItsSettings(): void
    {
        $this->drupal();
        file_put_contents($this->project('acme') . '/web/sites/default/settings.php', "<?php\n");
        unlink($this->project('acme') . '/.ddev/mfd/done/drupal');
        $tester = $this->drupal();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('settings.ddev.php', $tester->getDisplay());
    }

    public function testKeepsAnExistingGitignoreAndAddsTheBlockOnce(): void
    {
        mkdir($this->project('acme'));
        $this->drupal();
        $this->drupal();

        self::assertSame(1, substr_count((string) file_get_contents($this->project('acme') . '/.gitignore'), '# drupal-starter'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Step/DrupalStepTest.php`. Expected: `Class "Manifesto\Mfd\Step\DrupalStep" not found`.

- [ ] **Step 3: Implement** `src/Step/DrupalStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Shell\CommandFailed;

/**
 * DDEV project, Drupal codebase, development tooling and site install. Every
 * command is guarded or idempotent, so rerunning after a failure resumes.
 */
final class DrupalStep implements Step
{
    /** Composer plugins drupal/coder and phpstan use; allowed first so --no-interaction never stops on the prompt. */
    private const ALLOWED_PLUGINS = ['dealerdirect/phpcodesniffer-composer-installer', 'phpstan/extension-installer'];

    /** PHPUnit comes from drupal/core-dev, which matches core. Never pin it directly. */
    private const DEV_PACKAGES = [
        'drupal/core-dev',
        'weitzman/drupal-test-traits',
        'mglaman/phpstan-drupal',
        'phpstan/extension-installer',
        'drupal/coder',
    ];

    private const GITIGNORE_MARKER = '# drupal-starter';

    private const GITIGNORE = <<<'TXT'
        # drupal-starter: dependencies, generated files and local secrets.
        # web/sites/default/settings.php is committed; DDEV keeps credentials and the
        # hash salt in settings.ddev.php.
        /vendor/
        /web/core/
        /web/modules/contrib/
        /web/themes/contrib/
        /web/profiles/contrib/
        /web/libraries/
        /web/sites/*/files/
        /web/sites/*/settings.ddev.php
        /web/sites/*/settings.local.php
        /web/sites/simpletest/
        /node_modules/
        /.phpunit.cache/
        *.sql.gz

        TXT;

    private const SETTINGS_MARKER = 'drupal-starter: config sync';

    private const SETTINGS_BLOCK = <<<'PHP'

        // drupal-starter: config sync directory, committed with the project.
        $settings['config_sync_directory'] = '../config/sync';

        PHP;

    public function name(): string
    {
        return 'drupal';
    }

    public function plan(Context $context): array
    {
        $c = $context->config;

        return [
            sprintf('ddev config --project-name=%s --project-type=drupal11 --docroot=web; ddev start', $c->project),
            'ddev composer create-project drupal/recommended-project:^11',
            'ddev composer require drush/drush',
            'ddev composer require --dev ' . implode(' ', self::DEV_PACKAGES),
            'write .gitignore (Drupal rules) and settings.php (config sync directory ../config/sync)',
            sprintf('ddev drush site:install standard --site-name="%s"', $c->siteName),
        ];
    }

    public function run(Context $context): void
    {
        $shell = $context->shell;
        $dir = $context->projectDir;
        $config = $context->config;

        if (!is_file($context->path('.ddev/config.yaml'))) {
            $shell->run(['ddev', 'config', '--project-name=' . $config->project, '--project-type=drupal11', '--docroot=web'], $dir);
        }
        $shell->run(['ddev', 'start'], $dir);
        if (!is_file($context->path('composer.json'))) {
            $shell->run(['ddev', 'composer', 'create-project', 'drupal/recommended-project:^11', '--no-interaction'], $dir);
        }
        foreach (self::ALLOWED_PLUGINS as $plugin) {
            $shell->run(['ddev', 'composer', 'config', '--no-interaction', 'allow-plugins.' . $plugin, 'true'], $dir);
        }
        $shell->run(['ddev', 'composer', 'require', '--no-interaction', 'drush/drush'], $dir);
        $shell->run(['ddev', 'composer', 'require', '--dev', '--no-interaction', ...self::DEV_PACKAGES], $dir);
        // Restart so DDEV's settings management writes settings.php for the new codebase.
        $shell->run(['ddev', 'restart'], $dir);
        $this->writeGitignore($context);
        $this->configureSettings($context);
        if (!$this->isInstalled($context)) {
            $shell->run(['ddev', 'drush', 'site:install', 'standard', '--site-name=' . $config->siteName, '-y'], $dir);
        }
    }

    private function writeGitignore(Context $context): void
    {
        $file = $context->path('.gitignore');
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        if (str_contains($current, self::GITIGNORE_MARKER)) {
            return;
        }
        $separator = ($current === '' || str_ends_with($current, "\n")) ? '' : "\n";
        file_put_contents($file, $current . $separator . self::GITIGNORE);
    }

    private function configureSettings(Context $context): void
    {
        $file = $context->path('web/sites/default/settings.php');
        if (!is_file($file)) {
            throw new \RuntimeException(
                $file . " was not created. Check that DDEV settings management is on (disable_settings_management: false in .ddev/config.yaml), run 'ddev restart', then rerun.",
            );
        }
        $settings = (string) file_get_contents($file);
        if (!str_contains($settings, 'settings.ddev.php')) {
            throw new \RuntimeException(
                $file . " does not include settings.ddev.php, so DDEV's database settings are not loaded. Check disable_settings_management in .ddev/config.yaml.",
            );
        }
        if (!str_contains($settings, self::SETTINGS_MARKER)) {
            file_put_contents($file, self::SETTINGS_BLOCK, FILE_APPEND);
        }
    }

    private function isInstalled(Context $context): bool
    {
        try {
            return $context->shell->capture(['ddev', 'drush', 'status', '--field=bootstrap'], $context->projectDir) === 'Successful';
        } catch (CommandFailed) {
            return false;
        }
    }
}
```

In `src/Steps.php`, return `[new DrupalStep()]` and add `use Manifesto\Mfd\Step\DrupalStep;`.

- [ ] **Step 4: Run the test and confirm it passes.** Run `vendor/bin/phpunit`. Expected: every test passes.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 9: `mfd make:component` (the in-project command)

**Owner:** `backend-engineer`, with `frontend-engineer` for the Twig. The brief includes the `twig-templating` skill.

**Files:**
- Create: `src/Project/ProjectRoot.php`, `src/Component/ComponentGenerator.php`, `src/Command/MakeComponentCommand.php`
- Modify: `bin/mfd` (register the command)
- Test: `tests/Unit/Component/ComponentGeneratorTest.php`, `tests/Unit/Command/MakeComponentCommandTest.php`

**Interfaces:**
- Consumes: `UserError` and `Naming` (Task 1), and `TempDirectory` (Task 2).
- Produces:
  - `ProjectRoot`:
    - `MARKER = '.mfd.json'`
    - `find(string $from): ProjectRoot`, which throws `UserError`
    - public readonly `dir`
    - `theme(): string`, `path(string): string`
  - `ComponentGenerator::generate(string $themeDir, string $name): list<string>`. It returns the paths it wrote, relative to `$themeDir`. It throws `UserError` for a bad name, and `\RuntimeException` when the component already exists. Task 10 uses it for the example card.
  - `MakeComponentCommand(?string $workingDir = null)`, the command `make:component <name> [--theme=]`. It exits 0 when created, 2 for bad input or when run outside a project, and 1 when the component exists.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Component/ComponentGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Component;

use Manifesto\Mfd\Component\ComponentGenerator;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComponentGeneratorTest extends TestCase
{
    use TempDirectory;

    private string $theme;

    protected function setUp(): void
    {
        $this->theme = $this->newTempDir();
    }

    public function testCreatesAnSdcWithExamplesAndDataQa(): void
    {
        $files = (new ComponentGenerator())->generate($this->theme, 'hero-banner');
        $dir = $this->theme . '/components/hero-banner';

        self::assertSame([
            'components/hero-banner/hero-banner.component.yml',
            'components/hero-banner/hero-banner.twig',
            'components/hero-banner/hero-banner.css',
        ], $files);
        $yml = (string) file_get_contents($dir . '/hero-banner.component.yml');
        self::assertStringContainsString("name: Hero Banner\n", $yml);
        self::assertStringContainsString("examples: ['Hero Banner heading']", $yml);
        self::assertStringContainsString('$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json', $yml);
        $twig = (string) file_get_contents($dir . '/hero-banner.twig');
        self::assertStringContainsString('data-qa="hero-banner"', $twig);
        self::assertStringContainsString("attributes.addClass('hero-banner')", $twig);
        self::assertStringContainsString('class="hero-banner__heading"', $twig);
        self::assertStringContainsString('{% block content %}{% endblock %}', $twig);
        self::assertSame("/* Styles for the Hero Banner component. */\n", file_get_contents($dir . '/hero-banner.css'));
    }

    public function testCardMatchesWhatTheExampleTestsExpect(): void
    {
        (new ComponentGenerator())->generate($this->theme, 'card');

        self::assertStringContainsString("name: Card\n", (string) file_get_contents($this->theme . '/components/card/card.component.yml'));
        self::assertStringContainsString('data-qa="card"', (string) file_get_contents($this->theme . '/components/card/card.twig'));
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'space' => ['Bad Name'];
        yield 'uppercase' => ['Hero'];
        yield 'leading digit' => ['1hero'];
        yield 'path' => ['../hero'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["hero\n"];
    }

    #[DataProvider('badNames')]
    public function testRejectsBadNames(string $name): void
    {
        $this->expectException(UserError::class);
        (new ComponentGenerator())->generate($this->theme, $name);
    }

    public function testRefusesAnExistingComponent(): void
    {
        (new ComponentGenerator())->generate($this->theme, 'card');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        (new ComponentGenerator())->generate($this->theme, 'card');
    }
}
```

`tests/Unit/Command/MakeComponentCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Command;

use Manifesto\Mfd\Command\MakeComponentCommand;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeComponentCommandTest extends TestCase
{
    use TempDirectory;

    private string $project;

    protected function setUp(): void
    {
        $this->project = $this->newTempDir();
        file_put_contents($this->project . '/.mfd.json', json_encode(['mfdVersion' => '0.1.0', 'theme' => 'acme']));
        mkdir($this->project . '/web/themes/custom/acme', 0777, true);
    }

    /** @param array<string, mixed> $input */
    private function make(array $input, ?string $cwd = null): CommandTester
    {
        $tester = new CommandTester(new MakeComponentCommand($cwd ?? $this->project));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    public function testCreatesTheComponentInTheProjectTheme(): void
    {
        $tester = $this->make(['name' => 'hero']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->project . '/web/themes/custom/acme/components/hero/hero.twig');
        self::assertStringContainsString('qa/stories.ts', $tester->getDisplay());
    }

    public function testFindsTheProjectRootFromASubdirectory(): void
    {
        mkdir($this->project . '/web/modules', 0777, true);
        $tester = $this->make(['name' => 'hero'], $this->project . '/web/modules');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->project . '/web/themes/custom/acme/components/hero/hero.twig');
    }

    public function testThemeOptionOverridesTheMarker(): void
    {
        mkdir($this->project . '/web/themes/custom/other', 0777, true);
        $this->make(['name' => 'hero', '--theme' => 'other']);

        self::assertFileExists($this->project . '/web/themes/custom/other/components/hero/hero.twig');
    }

    public function testOutsideAProjectExitsTwo(): void
    {
        $tester = $this->make(['name' => 'hero'], $this->newTempDir());

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('.mfd.json', $tester->getDisplay());
    }

    public function testMissingThemeDirectoryExitsTwo(): void
    {
        $tester = $this->make(['name' => 'hero', '--theme' => 'nope']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('web/themes/custom/nope', $tester->getDisplay());
    }

    public function testBadNameExitsTwoAndExistingComponentExitsOne(): void
    {
        self::assertSame(2, $this->make(['name' => 'Bad Name'])->getStatusCode());
        $this->make(['name' => 'card']);
        self::assertSame(1, $this->make(['name' => 'card'])->getStatusCode());
    }

    public function testUnreadableMarkerExitsTwo(): void
    {
        file_put_contents($this->project . '/.mfd.json', '{not json');

        self::assertSame(2, $this->make(['name' => 'hero'])->getStatusCode());
    }
}
```

Also add a case to `tests/Unit/BinMfdTest.php::badInvocations()`: `yield 'make:component without a name' => [['make:component']];`.

- [ ] **Step 2: Run the tests and confirm they fail.** Run `vendor/bin/phpunit`. Expected: the new tests FAIL (the classes don't exist yet).

- [ ] **Step 3: Implement**

`src/Project/ProjectRoot.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Project;

use Manifesto\Mfd\Config\Naming;
use Manifesto\Mfd\Exception\UserError;

/** A project made by `mfd new`: the directory holding .mfd.json, and what that file records. */
final readonly class ProjectRoot
{
    public const MARKER = '.mfd.json';

    /** @param array<string, mixed> $marker */
    private function __construct(public string $dir, private array $marker)
    {
    }

    /** Walk up from $from to the nearest directory containing .mfd.json. */
    public static function find(string $from): self
    {
        $dir = $from;
        while (true) {
            $file = $dir . '/' . self::MARKER;
            if (is_file($file)) {
                try {
                    $marker = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new UserError($file . ' is not valid JSON: ' . $e->getMessage(), 0, $e);
                }
                if (!is_array($marker)) {
                    throw new UserError($file . ' is not a JSON object');
                }

                return new self($dir, $marker);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                throw new UserError(sprintf('not inside an mfd project: no %s found in %s or above it', self::MARKER, $from));
            }
            $dir = $parent;
        }
    }

    public function theme(): string
    {
        $theme = $this->marker['theme'] ?? null;
        if (!is_string($theme) || !Naming::isValidThemeName($theme)) {
            throw new UserError(sprintf('%s/%s records no valid theme; pass --theme', $this->dir, self::MARKER));
        }

        return $theme;
    }

    public function path(string $relative): string
    {
        return $this->dir . '/' . $relative;
    }
}
```

`src/Component/ComponentGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Component;

use Manifesto\Mfd\Exception\UserError;

/** Writes a Single Directory Component: metadata with examples, a Twig template with data-qa, and a stylesheet. */
final class ComponentGenerator
{
    private const NAME = '/^[a-z][a-z0-9_-]*$/D';

    /** @return list<string> the files written, relative to $themeDir */
    public function generate(string $themeDir, string $name): array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new UserError(sprintf(
                "component name '%s' must be lowercase letters, digits, '-' or '_', starting with a letter",
                $name,
            ));
        }
        $relative = 'components/' . $name;
        $dir = $themeDir . '/' . $relative;
        if (file_exists($dir)) {
            throw new \RuntimeException($dir . ' already exists');
        }
        $label = ucwords(strtr($name, '-_', '  '));
        mkdir($dir, 0777, true);

        $files = [
            "$relative/$name.component.yml" => <<<YAML
                \$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
                name: $label
                props:
                  type: object
                  properties:
                    heading:
                      type: string
                      title: Heading
                      examples: ['$label heading']
                slots:
                  content:
                    title: Content

                YAML,
            "$relative/$name.twig" => <<<TWIG
                <div{{ attributes.addClass('$name') }} data-qa="$name">
                  <h2 class="{$name}__heading">{{ heading }}</h2>
                  {% block content %}{% endblock %}
                </div>

                TWIG,
            "$relative/$name.css" => "/* Styles for the $label component. */\n",
        ];
        foreach ($files as $path => $content) {
            file_put_contents($themeDir . '/' . $path, $content);
        }

        return array_keys($files);
    }
}
```

`src/Command/MakeComponentCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Command;

use Manifesto\Mfd\Component\ComponentGenerator;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:component', description: 'Create a Single Directory Component in the project theme')]
final class MakeComponentCommand extends Command
{
    /** @param string|null $workingDir where to start looking for the project; null means the current directory */
    public function __construct(private readonly ?string $workingDir = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, "Component machine name (lowercase letters, digits, '-' or '_')")
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme machine name (default: the theme recorded in .mfd.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        try {
            $root = ProjectRoot::find($this->workingDir ?? (string) getcwd());
            $themeOption = $input->getOption('theme');
            $theme = is_string($themeOption) && $themeOption !== '' ? $themeOption : $root->theme();
            $themeDir = $root->path('web/themes/custom/' . $theme);
            if (!is_dir($themeDir)) {
                throw new UserError(sprintf('theme directory web/themes/custom/%s not found in %s', $theme, $root->dir));
            }
            $files = (new ComponentGenerator())->generate($themeDir, $name);
        } catch (UserError $e) {
            $this->error($output, $e->getMessage());

            return self::INVALID;
        } catch (\RuntimeException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $output->writeln(sprintf('created web/themes/custom/%s/%s', $theme, $file));
        }
        $output->writeln('Give every prop realistic examples: Storybook builds the story from them.');
        $output->writeln('Then place the component on a real page and add a site story in qa/stories.ts.');

        return self::SUCCESS;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream->writeln('<error>error:</error> ' . $message);
    }
}
```

In `bin/mfd`, register it alongside `new`: `foreach ([new NewCommand(), new MakeComponentCommand()] as $command)`, and add the matching `use` line.

- [ ] **Step 4: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 10: Step `theme` (starterkit theme, example card, front-page placement)

**Owner:** `backend-engineer`. The brief includes `~/.claude/references/drupal-pitfalls.md` and the `twig-templating` skill.

**Files:**
- Create: `src/Step/ThemeStep.php`
- Modify: `src/Steps.php` (register it after `DrupalStep`)
- Test: `tests/Unit/Step/ThemeStepTest.php`

**Interfaces:**
- Consumes: `ComponentGenerator` (Task 9), `DrupalStep` (Task 8) and `NewCommandTestCase` (Task 2).
- Produces:
  - `web/themes/custom/<theme>/components/card/`, written by `ComponentGenerator`, with root `data-qa="card"`
  - `templates/layout/page--front.html.twig`, which includes `<theme>:card`
  - the theme enabled and set as the default

- [ ] **Step 1: Write the failing test** `tests/Unit/Step/ThemeStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\DrupalStep;
use Manifesto\Mfd\Step\ThemeStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ThemeStepTest extends NewCommandTestCase
{
    /** @param array<string, mixed> $input */
    private function theme(array $input = ['name' => 'acme']): CommandTester
    {
        return $this->newProject($input, [new DrupalStep(), new ThemeStep()]);
    }

    public function testGeneratesTheThemeAddsTheCardAndSetsTheDefault(): void
    {
        $tester = $this->theme(['name' => 'acme-corp', '--theme-label' => 'Acme & Co']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertContains('ddev exec vendor/bin/dr generate-theme acme_corp --name Acme & Co --path themes/custom', $this->shell->calls);
        $card = $this->project('acme-corp') . '/web/themes/custom/acme_corp/components/card';
        self::assertStringContainsString('examples:', (string) file_get_contents($card . '/card.component.yml'));
        self::assertStringContainsString('data-qa="card"', (string) file_get_contents($card . '/card.twig'));
        self::assertContains('ddev drush theme:enable acme_corp -y', $this->shell->calls);
        self::assertContains('ddev drush config:set system.theme default acme_corp -y', $this->shell->calls);
        self::assertContains('ddev drush cr', $this->shell->calls);
    }

    public function testFrontPageTemplateIncludesTheCardBeforeThePageContent(): void
    {
        $this->theme();
        $front = (string) file_get_contents($this->project('acme') . '/web/themes/custom/acme/templates/layout/page--front.html.twig');

        self::assertStringContainsString("{{ include('acme:card', { heading: 'Welcome' }, with_context = false) }}", $front);
        self::assertLessThan(strpos($front, '{{ page.content }}'), strpos($front, 'acme:card'));
    }

    public function testFailsClearlyWhenThePageTemplateHasNoContentRegion(): void
    {
        $this->shell->failOn('drush theme:enable');
        $this->theme();
        $layout = $this->project('acme') . '/web/themes/custom/acme/templates/layout';
        unlink($layout . '/page--front.html.twig');
        file_put_contents($layout . '/page.html.twig', "<main></main>\n");
        $this->shell->clearFailures();
        $tester = $this->theme();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('page.content', $tester->getDisplay());
    }

    public function testARerunKeepsAnEditedCardAndDoesNotRegenerateTheTheme(): void
    {
        $this->shell->failOn('drush theme:enable');
        $this->theme();
        $twig = $this->project('acme') . '/web/themes/custom/acme/components/card/card.twig';
        file_put_contents($twig, 'edited');
        $this->shell->clearFailures();
        $this->shell->calls = [];
        $tester = $this->theme();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('edited', file_get_contents($twig));
        self::assertSame([], $this->shell->callsStartingWith('ddev exec vendor/bin/dr generate-theme'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails.** Run `vendor/bin/phpunit tests/Unit/Step/ThemeStepTest.php`. Expected: FAIL (the class doesn't exist yet).

- [ ] **Step 3: Implement** `src/Step/ThemeStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Component\ComponentGenerator;

/**
 * Custom theme from core's starterkit, the example card component, and the card
 * placed on the front page so the site story and the ExistingSite test have a
 * real Drupal render to check.
 */
final class ThemeStep implements Step
{
    private const CONTENT = '{{ page.content }}';

    public function name(): string
    {
        return 'theme';
    }

    public function plan(Context $context): array
    {
        $c = $context->config;

        return [
            sprintf('ddev exec vendor/bin/dr generate-theme %s --name "%s" --path themes/custom', $c->theme, $c->themeLabel),
            sprintf('write web/themes/custom/%s/components/card/ (the example component)', $c->theme),
            sprintf('write web/themes/custom/%s/templates/layout/page--front.html.twig (includes the card)', $c->theme),
            sprintf('ddev drush theme:enable %s; set it as the default theme; clear caches', $c->theme),
        ];
    }

    public function run(Context $context): void
    {
        $theme = $context->config->theme;
        $themeDir = $context->path('web/themes/custom/' . $theme);
        $dir = $context->projectDir;

        // Drupal 11.4: vendor/bin/dr is the generator; core/scripts/drupal is deprecated.
        if (!is_file("$themeDir/$theme.info.yml")) {
            $context->shell->run(
                ['ddev', 'exec', 'vendor/bin/dr', 'generate-theme', $theme, '--name', $context->config->themeLabel, '--path', 'themes/custom'],
                $dir,
            );
        }
        if (!is_file("$themeDir/$theme.info.yml")) {
            throw new \RuntimeException("the theme generator did not create $themeDir/$theme.info.yml");
        }
        if (!is_dir("$themeDir/components/card")) {
            (new ComponentGenerator())->generate($themeDir, 'card');
        }
        $this->placeCardOnFrontPage($themeDir . '/templates/layout', $theme);
        $context->shell->run(['ddev', 'drush', 'theme:enable', $theme, '-y'], $dir);
        $context->shell->run(['ddev', 'drush', 'config:set', 'system.theme', 'default', $theme, '-y'], $dir);
        $context->shell->run(['ddev', 'drush', 'cr'], $dir);
    }

    /** Copy the generated page template to page--front and include the card just before the main content. */
    private function placeCardOnFrontPage(string $layoutDir, string $theme): void
    {
        $front = $layoutDir . '/page--front.html.twig';
        if (is_file($front)) {
            return;
        }
        $page = $layoutDir . '/page.html.twig';
        $text = is_file($page) ? (string) file_get_contents($page) : '';
        $position = strpos($text, self::CONTENT);
        if ($position === false) {
            throw new \RuntimeException($page . ' has no ' . self::CONTENT . '; cannot place the example card on the front page');
        }
        $card = sprintf("{{ include('%s:card', { heading: 'Welcome' }, with_context = false) }}\n      ", $theme);
        file_put_contents($front, substr_replace($text, $card . self::CONTENT, $position, strlen(self::CONTENT)));
    }
}
```

In `src/Steps.php`, return `[new DrupalStep(), new ThemeStep()]`.

- [ ] **Step 4: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 11: Step `harness` (qa-init with Storybook, then the patches)

**Owner:** `backend-engineer`.

**Files:**
- Create: `src/Harness/QaPatcher.php`, `src/Step/HarnessStep.php`
- Modify: `src/Steps.php` (register the step after `ThemeStep`)
- Test: `tests/Unit/Harness/QaPatcherTest.php`, `tests/Unit/Step/HarnessStepTest.php`

**Interfaces:**
- Consumes: `HarnessPaths` and `Context` (Task 2).
- Produces:
  - `QaPatcher(string $projectDir)` with `apply(string $project, string $theme): void`. It throws `\RuntimeException` when `qa/gate.config.ts` is missing or no longer contains the expected defaults.
  - `qa/gate.config.ts` pointed at `http://<project>.ddev.site` with `ddev start`
  - `qa/stories.ts` containing the `card-front` site story
  - `qa/figma-map.json` set to `{}`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Harness/QaPatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Harness;

use Manifesto\Mfd\Harness\QaPatcher;
use Manifesto\Mfd\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class QaPatcherTest extends TestCase
{
    use TempDirectory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->newTempDir();
        mkdir($this->dir . '/qa');
        file_put_contents($this->dir . '/qa/gate.config.ts', "export const gateConfig = {\n"
            . "  baseUrl: process.env.QA_BASE_URL ?? 'http://localhost:3000',\n"
            . "  devServerCommand: process.env.QA_DEV_CMD ?? 'npm run dev',\n};\n");
        file_put_contents($this->dir . '/qa/stories.ts', "export const stories = [{ id: 'resource-card' }];\n");
        file_put_contents($this->dir . '/qa/figma-map.json', "{\"example\": {\"node\": \"1:2\"}}\n");
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->dir . '/' . $file);
    }

    public function testPointsTheReviewAtDdevAndTheFrontPageCard(): void
    {
        (new QaPatcher($this->dir))->apply('acme-corp', 'acme_ui');

        self::assertStringContainsString("'http://acme-corp.ddev.site'", $this->read('qa/gate.config.ts'));
        self::assertStringContainsString("'ddev start'", $this->read('qa/gate.config.ts'));
        self::assertStringNotContainsString('localhost:3000', $this->read('qa/gate.config.ts'));
        self::assertStringNotContainsString('npm run dev', $this->read('qa/gate.config.ts'));
        self::assertStringContainsString("component: 'acme_ui:card'", $this->read('qa/stories.ts'));
        self::assertStringContainsString('[data-qa="card"]', $this->read('qa/stories.ts'));
        self::assertStringContainsString("import type { Story } from './story';", $this->read('qa/stories.ts'));
        self::assertSame("{}\n", $this->read('qa/figma-map.json'));
    }

    public function testApplyingTwiceIsHarmless(): void
    {
        (new QaPatcher($this->dir))->apply('acme', 'acme');
        (new QaPatcher($this->dir))->apply('acme', 'acme');

        self::assertSame(1, substr_count($this->read('qa/gate.config.ts'), 'acme.ddev.site'));
    }

    public function testFailsClearlyWhenTheHarnessTemplateChanged(): void
    {
        file_put_contents($this->dir . '/qa/gate.config.ts', "export const gateConfig = { baseUrl: 'http://elsewhere' };\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('harness template changed');
        (new QaPatcher($this->dir))->apply('acme', 'acme');
    }

    public function testFailsClearlyWhenGateConfigIsMissing(): void
    {
        unlink($this->dir . '/qa/gate.config.ts');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('qa/gate.config.ts');
        (new QaPatcher($this->dir))->apply('acme', 'acme');
    }
}
```

`tests/Unit/Step/HarnessStepTest.php`:

```php
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
        self::assertStringContainsString("'http://acme-corp.ddev.site'", (string) file_get_contents($this->project('acme-corp') . '/qa/gate.config.ts'));
        self::assertStringContainsString("component: 'acme_ui:card'", (string) file_get_contents($this->project('acme-corp') . '/qa/stories.ts'));
    }

    public function testAFailedInstallerFailsTheStep(): void
    {
        $this->shell->failOn('--storybook');

        self::assertSame(1, $this->newProject(['name' => 'acme'], [new HarnessStep()])->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail.** Run `vendor/bin/phpunit tests/Unit/Harness tests/Unit/Step/HarnessStepTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement**

`src/Harness/QaPatcher.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Harness;

/**
 * Points the mf-harness design review at a DDEV project. qa/gate.config.ts,
 * qa/stories.ts and qa/figma-map.json are project-owned in the harness
 * (qa-scaffold.py PROJECT_OWNED), so these edits survive harness updates.
 */
final class QaPatcher
{
    private const GATE = 'qa/gate.config.ts';

    private const STORIES = <<<'TS'
        // qa/stories.ts - site stories: real Drupal pages the review checks in the running site.
        // Use each component's plugin ID, provider:machine-name, as `component`. A component
        // with a Storybook story but no entry here is reported as QA-SBONLY.
        import type { Story } from './story';

        export const stories: Story[] = [
          {
            id: 'card-front',
            component: '__THEME__:card',
            path: '/',
            contract: { requiredSelectors: ['[data-qa="card"]'] },
          },
        ];

        TS;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function apply(string $project, string $theme): void
    {
        $this->patchGate($project);
        file_put_contents($this->projectDir . '/qa/stories.ts', str_replace('__THEME__', $theme, self::STORIES));
        // The harness's example entries stop the review before it checks anything.
        file_put_contents($this->projectDir . '/qa/figma-map.json', "{}\n");
    }

    private function patchGate(string $project): void
    {
        $file = $this->projectDir . '/' . self::GATE;
        if (!is_file($file)) {
            throw new \RuntimeException(self::GATE . ' not found; the harness installer should have written it');
        }
        $text = (string) file_get_contents($file);
        $replacements = [
            "'http://localhost:3000'" => sprintf("'http://%s.ddev.site'", $project),
            "'npm run dev'" => "'ddev start'",
        ];
        foreach ($replacements as $old => $new) {
            if (str_contains($text, $new)) {
                continue;
            }
            $position = strpos($text, $old);
            if ($position === false) {
                throw new \RuntimeException(sprintf(
                    "%s: %s not found, so the harness template changed. Set baseUrl to 'http://%s.ddev.site' and devServerCommand to 'ddev start' by hand.",
                    self::GATE,
                    $old,
                    $project,
                ));
            }
            $text = substr_replace($text, $new, $position, strlen($old));
        }
        file_put_contents($file, $text);
    }
}
```

`src/Step/HarnessStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Harness\QaPatcher;

/** The mf-harness design-review tooling with the Storybook lane, pointed at DDEV. */
final class HarnessStep implements Step
{
    public function name(): string
    {
        return 'harness';
    }

    public function plan(Context $context): array
    {
        return [
            sprintf('bash %s --yes --storybook   (Storybook, qa/ tooling, Playwright browsers)', $context->harness->qaInit),
            sprintf(
                'patch qa/gate.config.ts (%s, ddev start), qa/stories.ts (front-page card), qa/figma-map.json ({})',
                $context->config->siteUrl(),
            ),
        ];
    }

    public function run(Context $context): void
    {
        $context->shell->run(['bash', $context->harness->qaInit, '--yes', '--storybook'], $context->projectDir);
        (new QaPatcher($context->projectDir))->apply($context->config->project, $context->config->theme);
    }
}
```

In `src/Steps.php`, return `[new DrupalStep(), new ThemeStep(), new HarnessStep()]`.

- [ ] **Step 4: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes.

- [ ] **Step 5: Lint.** Run `task lint`. Expected: clean.

---

### Task 12: Steps `templates`, `toolkit` and `finish`, and the full `mfd new` run

**Owner:** `backend-engineer`.

**Files:**
- Create: `src/Step/TemplatesStep.php`, `src/Step/ToolkitStep.php`, `src/Step/FinishStep.php`
- Modify: `src/Steps.php` (all six steps, in order)
- Test: `tests/Unit/Step/ToolkitStepTest.php`, `tests/Unit/NewCommandFullRunTest.php`

**Interfaces:**
- Consumes:
  - `Renderer` (Task 3), `PackageMerger` and `resources/package-additions.json` (Task 6)
  - `ProjectRoot::MARKER` (Task 9)
  - `HarnessPaths`, `Context`, `Notices` and `Mfd::VERSION`
  - every earlier step
- Produces:
  - the finished project
  - `config/sync/`
  - a git repository with no commits
  - `.mfd.json`, with keys `mfdVersion`, `created`, `project`, `theme`, `themeLabel`, `siteName`
  - `manifesto/mfd` in the project's `require-dev`, or a notice saying how to add it later
  - `Steps::all()` returns six steps: drupal, theme, harness, templates, toolkit, finish

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Step/ToolkitStepTest.php`:

```php
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
        self::assertContains('ddev composer config --no-interaction repositories.mfd vcs https://github.com/welly/drupal-starter', $this->shell->calls);
        self::assertContains('ddev composer require --dev --no-interaction manifesto/mfd:dev-main', $this->shell->calls);
    }

    public function testEnvironmentOverridesTheSource(): void
    {
        putenv('MFD_REPOSITORY=https://github.com/example/fork');
        putenv('MFD_VERSION=dev-feature/x');
        $this->newProject(['name' => 'acme'], [new ToolkitStep()]);

        self::assertContains('ddev composer config --no-interaction repositories.mfd vcs https://github.com/example/fork', $this->shell->calls);
        self::assertContains('ddev composer require --dev --no-interaction manifesto/mfd:dev-feature/x', $this->shell->calls);
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
}
```

`tests/Unit/NewCommandFullRunTest.php`:

```php
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
        foreach ([
            'Taskfile.yml', '.taskfiles/backend.yml', '.taskfiles/frontend.yml', '.taskfiles/qa.yml', 'phpunit.xml.dist',
            'phpcs.xml.dist', 'phpstan.neon.dist', 'eslint.config.mjs', '.stylelintrc.json', 'CLAUDE.md', 'README.md',
            '.ddev/config.testing.yaml', 'web/modules/custom/acme_corp_tests/tests/src/ExistingSite/FrontPageTest.php',
            'web/themes/custom/acme_corp/components/card/card.twig', 'qa/stories.ts', '.mfd.json', 'config/sync/system.site.yml',
        ] as $file) {
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
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project('acme'), \FilesystemIterator::SKIP_DOTS));
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
        $marker = json_decode((string) file_get_contents($this->project('acme-corp') . '/.mfd.json'), true, 512, JSON_THROW_ON_ERROR);

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
        $pkg = json_decode((string) file_get_contents($this->project('acme') . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

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
```

- [ ] **Step 2: Run the tests and confirm they fail.** Run `vendor/bin/phpunit`. Expected: the new tests FAIL.

- [ ] **Step 3: Implement**

`src/Step/TemplatesStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Npm\PackageMerger;
use Manifesto\Mfd\Template\Renderer;

/**
 * mfd's own project files (Taskfile, PHPUnit, linters, docs, example tests), the
 * lint scripts merged into package.json, and a DDEV restart so the test
 * environment variables take effect.
 */
final class TemplatesStep implements Step
{
    public function name(): string
    {
        return 'templates';
    }

    public function plan(Context $context): array
    {
        return [
            sprintf(
                'copy template/ (Taskfile, .taskfiles/, phpunit.xml.dist, phpcs, phpstan, ESLint, Stylelint, CLAUDE.md, README.md, %s_tests module)',
                $context->config->theme,
            ),
            'merge lint scripts and tools into package.json; npm install',
            'ddev restart (loads .ddev/config.testing.yaml)',
        ];
    }

    public function run(Context $context): void
    {
        $lines = (new Renderer())->render($context->packageRoot . '/template', $context->projectDir, $context->config->placeholders());
        foreach ($lines as $line) {
            $context->log($line);
        }
        (new PackageMerger())->merge($context->path('package.json'), $context->packageRoot . '/resources/package-additions.json');
        $context->shell->run(['npm', 'install'], $context->projectDir);
        $context->shell->run(['ddev', 'restart'], $context->projectDir);
    }
}
```

`src/Step/ToolkitStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Shell\CommandFailed;

/**
 * Adds mfd itself to the project's require-dev, so every teammate gets the same
 * locked version through composer install and runs it inside DDEV. If Composer
 * cannot install it (offline, or the package not yet on that branch), this warns
 * with the exact commands to run later instead of failing a complete project.
 */
final class ToolkitStep implements Step
{
    public const PACKAGE = 'manifesto/mfd';
    public const DEFAULT_REPOSITORY = 'https://github.com/welly/drupal-starter';
    public const DEFAULT_VERSION = 'dev-main';

    public function name(): string
    {
        return 'toolkit';
    }

    public function plan(Context $context): array
    {
        [$repository, $version] = self::source();

        return [
            'ddev composer config repositories.mfd vcs ' . $repository,
            sprintf('ddev composer require --dev %s:%s', self::PACKAGE, $version),
        ];
    }

    public function run(Context $context): void
    {
        [$repository, $version] = self::source();
        $dir = $context->projectDir;
        try {
            $context->shell->run(['ddev', 'composer', 'config', '--no-interaction', 'repositories.mfd', 'vcs', $repository], $dir);
            $context->shell->run(['ddev', 'composer', 'require', '--dev', '--no-interaction', self::PACKAGE . ':' . $version], $dir);
        } catch (CommandFailed $e) {
            $context->warn(sprintf(
                "could not add %s to the project (%s). Once it is available, run in the project:\n"
                . "  ddev composer config repositories.mfd vcs %s\n"
                . '  ddev composer require --dev %s:%s',
                self::PACKAGE,
                strtok($e->getMessage(), "\n"),
                $repository,
                self::PACKAGE,
                $version,
            ));
        }
    }

    /** @return array{string, string} repository URL and version constraint; MFD_REPOSITORY and MFD_VERSION override */
    private static function source(): array
    {
        $repository = getenv('MFD_REPOSITORY');
        $version = getenv('MFD_VERSION');

        return [
            is_string($repository) && $repository !== '' ? $repository : self::DEFAULT_REPOSITORY,
            is_string($version) && $version !== '' ? $version : self::DEFAULT_VERSION,
        ];
    }
}
```

`src/Step/FinishStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Project\ProjectRoot;
use Manifesto\Mfd\Shell\CommandFailed;

/** Export configuration, create the git repository (never a commit), write .mfd.json, report the harness setup state. */
final class FinishStep implements Step
{
    public function name(): string
    {
        return 'finish';
    }

    public function plan(Context $context): array
    {
        return [
            'ddev drush config:export (config/sync)',
            'git init (no commit); write ' . ProjectRoot::MARKER,
            'run the harness setup probe and print its next step',
        ];
    }

    public function run(Context $context): void
    {
        if (!is_dir($context->path('config/sync'))) {
            mkdir($context->path('config/sync'), 0777, true);
        }
        $context->shell->run(['ddev', 'drush', 'config:export', '-y'], $context->projectDir);
        if (!is_dir($context->path('.git'))) {
            $context->shell->run(['git', 'init', '-q'], $context->projectDir);
        }
        $this->writeMarker($context);
        $this->reportProbe($context);
    }

    private function writeMarker(Context $context): void
    {
        $c = $context->config;
        $marker = [
            'mfdVersion' => Mfd::VERSION,
            'created' => date('Y-m-d'),
            'project' => $c->project,
            'theme' => $c->theme,
            'themeLabel' => $c->themeLabel,
            'siteName' => $c->siteName,
        ];
        file_put_contents(
            $context->path(ProjectRoot::MARKER),
            json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function reportProbe(Context $context): void
    {
        try {
            $json = $context->shell->capture(['python3', $context->harness->probe, 'probe', '--root', '.'], $context->projectDir);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $next = is_array($data) && is_string($data['nextStep'] ?? null) ? $data['nextStep'] : 'unknown';
            $context->log('harness setup: next step is ' . $next);
        } catch (CommandFailed | \JsonException) {
            $context->warn('the harness setup probe failed; run /setup-project in Claude Code to see the setup state');
        }
    }
}
```

`src/Steps.php`:

```php
    /** @return list<Step> */
    public static function all(): array
    {
        return [
            new DrupalStep(),
            new ThemeStep(),
            new HarnessStep(),
            new TemplatesStep(),
            new ToolkitStep(),
            new FinishStep(),
        ];
    }
```

Add the `use` lines for each step class.

- [ ] **Step 4: Run the tests and confirm they pass.** Run `vendor/bin/phpunit`. Expected: every test passes, with pristine output.

- [ ] **Step 5: Try the command by hand.** Run `bin/mfd new demo-site --dry-run`. Expected: it either lists all six steps, or exits 2 with a clear pre-flight message if a tool is missing on this machine. Then run `bin/mfd list`. Expected: it shows `new` and `make:component`.

- [ ] **Step 6: Lint.** Run `task lint`. Expected: clean.

---

### Task 13: End-to-end run against real DDEV

**Owner:** `backend-engineer`. This task needs Docker, DDEV and the installed harness, and takes 10–20 minutes. It is the only test allowed to use shell strings.

**Files:**
- Create: `tests/E2e/NewProjectE2eTest.php`

**Interfaces:**
- Consumes: everything.

The `manifesto/mfd` dependency installs from GitHub `main`. Until this branch is merged there, the toolkit step warns instead of installing it. So this test runs `make:component` from the working copy's `bin/mfd` (in the project directory) whenever `vendor/bin/mfd` is missing. When `MFD_E2E_PUBLISHED=1` is set, it requires `vendor/bin/mfd` and uses `task fe:component`.

- [ ] **Step 1: Write the test** `tests/E2e/NewProjectE2eTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\E2e;

use Manifesto\Mfd\Mfd;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Slow: scaffolds a real project on DDEV and runs its daily tasks. Run with `task e2e`. */
final class NewProjectE2eTest extends TestCase
{
    private const THEME = 'e2e_theme';

    private string $work;
    private string $name;
    private string $project;

    protected function setUp(): void
    {
        $this->name = 'mfde2e-' . getmypid();
        $this->work = sys_get_temp_dir() . '/' . $this->name . '-work';
        $this->project = $this->work . '/' . $this->name;
        mkdir($this->work, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->project . '/.ddev')) {
            $this->sh('ddev delete -Oy ' . escapeshellarg($this->name), $this->project, false);
        }
        (new Filesystem())->remove($this->work);
    }

    public function testANewProjectPassesItsOwnChecks(): void
    {
        $this->sh(sprintf(
            '%s new %s --theme %s --theme-label %s',
            escapeshellarg(Mfd::root() . '/bin/mfd'),
            escapeshellarg($this->name),
            self::THEME,
            escapeshellarg('E2E & Co'),
        ), $this->work);

        // Each PHPUnit suite runs at least one test and passes (catches zero-test passes).
        foreach (['unit', 'kernel', 'existing-site'] as $suite) {
            $out = $this->sh('ddev exec vendor/bin/phpunit --testsuite ' . $suite);
            self::assertMatchesRegularExpression('/OK \([1-9]\d* tests?/', $out, "suite $suite ran zero tests");
        }

        // settings.php holds no secrets; configuration is exported.
        $settings = (string) file_get_contents($this->project . '/web/sites/default/settings.php');
        self::assertDoesNotMatchRegularExpression("/hash_salt'\\] = '[^']+'/", $settings);
        self::assertDoesNotMatchRegularExpression("/^\\\$databases\\['default'\\]/m", $settings);
        self::assertFileExists($this->project . '/config/sync/system.site.yml');

        // Linters pass on generated code.
        $this->sh('task be:lint');
        $this->sh('task fe:lint');

        // fe:build is a no-op until a build script exists, then runs it with no Taskfile change.
        $this->sh('task fe:build');
        $original = (string) file_get_contents($this->project . '/package.json');
        $package = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $package);
        $package->scripts->build = 'echo BUILT';
        file_put_contents($this->project . '/package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertStringContainsString('BUILT', $this->sh('task fe:build'));
        file_put_contents($this->project . '/package.json', $original);

        // A new component through mfd; Storybook and the linters accept it.
        $this->makeComponent('hero');
        $this->sh('task fe:lint');
        $this->sh('task fe:storybook:build');
        $index = json_decode((string) file_get_contents($this->project . '/storybook-static/index.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($index);
        $titles = array_map(static fn (array $e): string => (string) $e['title'], $index['entries']);
        self::assertContains(self::THEME . '/SDC/Card', $titles);
        self::assertContains(self::THEME . '/SDC/Hero', $titles);

        // The design review reports no critical findings.
        $this->sh('task qa:review', null, false);
        $reports = glob($this->project . '/qa/reports/runs/*/report.json') ?: [];
        self::assertNotSame([], $reports, 'the review wrote no report');
        usort($reports, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $report = json_decode((string) file_get_contents($reports[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $critical = array_values(array_filter($report['findings'], static fn (array $f): bool => $f['severity'] === 'critical'));
        self::assertSame([], $critical, json_encode($critical, JSON_PRETTY_PRINT) ?: '');

        // task setup rebuilds the site from config/sync.
        $this->sh('ddev drush sql:drop -y');
        $this->sh('task setup');
        self::assertStringContainsString('data-qa="card"', $this->sh('curl -fsS ' . escapeshellarg("http://{$this->name}.ddev.site/")));

        // The full local check passes.
        $this->sh('task check');
    }

    private function makeComponent(string $name): void
    {
        $published = getenv('MFD_E2E_PUBLISHED') === '1';
        if ($published || is_file($this->project . '/vendor/bin/mfd')) {
            self::assertFileExists($this->project . '/vendor/bin/mfd', 'manifesto/mfd was not installed in the project');
            $this->sh('task fe:component -- ' . $name);

            return;
        }
        $this->sh(escapeshellarg(Mfd::root() . '/bin/mfd') . ' make:component ' . $name);
        $this->sh('ddev drush cr');
    }

    private function sh(string $command, ?string $cwd = null, bool $mustPass = true): string
    {
        $process = Process::fromShellCommandline($command, $cwd ?? $this->project, null, null, null);
        $process->run(static function (string $type, string $buffer): void {
            fwrite(STDERR, $buffer);
        });
        $output = $process->getOutput() . $process->getErrorOutput();
        if ($mustPass && !$process->isSuccessful()) {
            self::fail(sprintf("`%s` failed (exit %s):\n%s", $command, (string) $process->getExitCode(), $output));
        }

        return $output;
    }
}
```

Output goes to STDERR on purpose: `beStrictAboutOutputDuringTests` rejects stdout output, and a 15-minute silent run is hard to follow.

- [ ] **Step 2: Run it.** Run `task e2e`. Expected: 1 test, passing.

- [ ] **Step 3: Handle any failure with superpowers:systematic-debugging.** Find the root cause before changing code. Likely places to look:
  - the Kernel test container (see the Task 5 note)
  - `vendor/bin/dr` arguments on the installed core version
  - `site:install --existing-config` with the `standard` profile. If core refuses it, switch `DrupalStep` to the `minimal` profile; the ExistingSite and review checks still apply. Record the change in the spec.
  - starterkit PHP against phpcs. If the generated theme fails `be:lint`, have `ThemeStep` run `ddev exec vendor/bin/phpcbf web/themes/custom/<theme>`, accepting exit codes 0–2, and add a FakeShell test that expects the call.

  Each fix gets a failing fast test first where the behaviour can be faked.

---

### Task 14: Repository documentation

**Owner:** `content-strategist`. Run the `humanizer` skill on the result.

**Files:**
- Modify: `README.md`, `docs/new-project.md`, `docs/storybook.md`
- Create: `docs/developing.md` (developer guide, requested by the user on 2026-09-25)

- [ ] **Step 1: Rewrite `README.md`.** Make the quick start:

````markdown
## Install (once per machine)

```bash
git clone https://github.com/welly/drupal-starter.git ~/tools/mfd
cd ~/tools/mfd && task install     # composer install + symlink ~/.local/bin/mfd
```

## Start a project

```bash
mfd new "Acme Corp"                                 # DDEV acme-corp, theme acme_corp, label "Acme Corp"
mfd new "Acme Corp" --project-name acme --theme acme_ui   # every derived name can be overridden
```

## Inside a project

```bash
task fe:component -- hero      # = ddev exec vendor/bin/mfd make:component hero
```
````

Keep the "What you get", "Requirements" and versions sections. Add PHP 8.3 or later and Composer 2 (host, for `mfd new` only), Task and python3 to the requirements. Also add:

- an options table matching `mfd new --help`
- the exit codes
- "rerun the same command to resume"
- `MFD_REPOSITORY` and `MFD_VERSION`
- the naming derivation table from Task 15 (only the name is required)
- a "Developing mfd" section: `task test`, `task lint`, `task e2e`, and how to add a step (one `Step` class plus one line in `src/Steps.php`) or a command (one Command class, registered in `bin/mfd`)

- [ ] **Step 2: Add a note to `docs/new-project.md`.** Put this at the top: "`mfd new` does all of this. This page is the manual reference for what it runs." Add a line under step 2 saying `mfd` commits `settings.php` and uses `config/sync`, which differs from the manual `.gitignore` shown there.

- [ ] **Step 3: Update `docs/storybook.md`.** Add `task fe:component -- <name>` to "Add a component", and replace the `npm run` commands with the `task` equivalents (`task qa:review`, `task qa:review:storybook`).

- [ ] **Step 3b: Write `docs/developing.md`**, the developer guide, from the finished code. Read `src/` and `tests/Support/` first, and quote real class and method names. Link it from the README's "Developing mfd" section. Cover:
  - **How it fits together:** `bin/mfd` → Symfony Console `Application` → commands. How `mfd new` flows: `ProjectConfig::fromInput` (naming derivation) → `Preflight` → `StepRunner` over `Steps::all()`, with `State` (`.ddev/mfd/`) for resume, `Context` and `Notices`, and exit codes 0/1/2 (`UserError`, `StepFailed`). A small diagram of that flow.
  - **Shell:** why every external command goes through `Shell` as an argv array; `ProcessShell` against `FakeShell`.
  - **Adding a command:** a worked example (for example `mfd make:block`): a Command class with `#[AsCommand]`, registering it in `bin/mfd`, finding the project with `ProjectRoot`, exit codes, and a `CommandTester` test.
  - **Adding a step, or a sequence of steps, to `mfd new`:** a worked example step class implementing `Step` (`name`/`plan`/`run`), rules for re-running (every sub-command guarded or idempotent), registering and ordering in `Steps::all()`, using `Context::warn()` for non-fatal problems, and a `FakeShell` test (simulating a new external command in `FakeShell` when needed).
  - **Templates:** how `template/` and `Renderer` placeholders work (`{{PROJECT}}`, `__THEME__`, Task's own `{{.X}}`), `resources/package-additions.json` and `PackageMerger`, and adding a file to every new project.
  - **Tests and lint:** `task test`, `task lint`, `task e2e` (and `MFD_E2E_PUBLISHED`), the phpstan level 8 and PSR-12 rules, and the naming and `D`-modifier conventions.
  - **Releasing:** tagging, and `MFD_REPOSITORY`/`MFD_VERSION`.

- [ ] **Step 4: Verify.** Run `vendor/bin/phpunit` and `task lint`. Expected: both pass. Read the three files once for commands that no longer match the code.

---

## Follow-ups outside this plan

- Remove `DrupalStep::BROKEN_PACKAGES` (the temporary `twig/twig` 3.30.0 conflict, added in fix round 4) once Drupal or Twig ships a fix upstream.
- Tag a release (for example `v0.1.0`) and switch the default `MFD_VERSION` from `dev-main` to `^0.1`.
- Upgrade `locutus` to 3.x (3.0.36 at the time of writing), which clears harness finding SB-01. A separate piece of work.
- Raise against mf-harness:
  - `qa-scaffold.py` does not add `node_modules/` to `.gitignore` (ENOBUFS).
  - The placeholder `figma-map.json` stops the review.
  - `scaffold/drupal/phpunit.xml.dist` is never installed.
  - `qa:loop` does not run PHPUnit.
  - No CI job runs a Drupal PHP suite.
  - The preview adapter is not DDEV-aware.
