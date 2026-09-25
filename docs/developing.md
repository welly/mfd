# Developing mfd

This guide is for you if you want to add a new command to `mfd`, or extend the `mfd new` scaffolder with a new step or a new sequence of steps.

## How it fits together

`mfd` is a Symfony Console application. When you run `mfd new "Acme Corp"`, this is the flow:

```
bin/mfd (builds a Symfony Console Application named "mfd", versioned from Mfd::VERSION)
  ↓
src/Command/NewCommand.php (configures the command)
  ↓
ProjectConfig::fromInput() (derives project and theme names)
  ↓
Preflight (checks Docker, DDEV, Node.js, python3, the harness)
  ↓
StepRunner (runs Steps::all() in order, resuming from failure)
  ↓
Each Step (DrupalStep, ThemeStep, HarnessStep, TemplatesStep, ToolkitStep, FinishStep)
  ├─ Context (project path, config, shell, notices)
  ├─ State (persists progress in .ddev/mfd/)
  └─ Notices (collects non-fatal warnings, repeated in the final summary)
  ↓
FinishStep (exports config, writes .mfd.json, reports harness setup state)
```

`src/Mfd.php` holds two package-wide facts: `Mfd::VERSION` and `Mfd::root()` (the package root,
used to find `template/` and `resources/` whether mfd is run from a checkout or from
`vendor/`).

### Exit codes

- Exit 0: success. All steps finished.
- Exit 1: a step failed. Rerun to resume.
- Exit 2: user error or pre-flight check failed (bad options, missing tools). Nothing was written.

To resume, rerun `mfd new` with the same arguments from the directory you first ran it in, or
pass the same `--dir` explicitly. `--dir` defaults to `./<project name>`, resolved against the
current directory: running the command again from inside the generated project computes a
different default, `./<name>/<name>`, nested one level too deep. Pre-flight then refuses, because
a DDEV project with that name already exists.

### State and resumption

When `mfd new` runs, it writes a `.ddev/mfd/` directory (see `src/State.php`), with:

- `.ddev/mfd/inputs`: a plain `KEY=VALUE` text file recording the resolved project, theme, theme label and site name (so rerunning with different options is detected as a conflict)
- `.ddev/mfd/done/<step>`: an empty marker file per finished step, one per step name (so rerunning skips a step whose marker exists)

`DrupalStep` also keeps its own snapshot at `.ddev/mfd/settings.php.snapshot`: the exact bytes of
`settings.php` before `site:install` runs, restored afterwards (or on the next resume, if the
run was interrupted mid-install) and deleted once the restore is verified.

Every step must be idempotent: if it runs twice, the second run should be a no-op. This is why `DrupalStep` snapshots `settings.php` before `site:install` and restores it afterwards, even if the install was interrupted.

### Context

Each step receives a `Context` object with:

- `Context::$projectDir`: the project root
- `Context::$config`: the `ProjectConfig` (project name, theme, site name, etc.)
- `Context::$shell`: the `Shell` (runs external commands safely)
- `Context::warn()`: logs a non-fatal problem (the step continues)
- `Context::log()`: logs a message

## Shell

Every external command runs through a `Shell` object, which takes command arguments as an array, not a string. This prevents shell injection. There are two implementations:

- `ProcessShell` (`src/Shell/ProcessShell.php`): the real thing; runs Symfony `Process` and streams its output to the console
- `FakeShell`: used in tests; records commands and returns canned output

When you run a command in a step, always use the array form:

```php
$context->shell->run(['ddev', 'composer', 'require', 'package/name'], $dir);
// NOT: $context->shell->run('ddev composer require package/name', $dir);
```

## Adding a command

Here is a worked example: adding `mfd make:block <name>` to create a block plugin.

### Step 1: Create the Command class

Create `src/Command/MakeBlockCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:block', description: 'Create a custom block plugin in the current project')]
final class MakeBlockCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Block machine name, e.g. hero_banner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $output->writeln("Creating block: $name");
        // implementation here
        return Command::SUCCESS;
    }
}
```

The `#[AsCommand]` attribute registers the command. The `name` is what you type (`mfd make:block`), and `description` is what `mfd --help` shows.

### Step 2: Register the command

In `bin/mfd`, add your command to the loop where commands are registered:

```php
foreach ([new NewCommand(), new MakeComponentCommand(), new MakeBlockCommand()] as $command) {
    $application->addCommand($command);
}
```

### Step 3: Write a test

In `tests/Unit/MakeBlockCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit;

use Manifesto\Mfd\Command\MakeBlockCommand;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

final class MakeBlockCommandTest extends TestCase
{
    public function testCreatesABlock(): void
    {
        $command = new MakeBlockCommand();
        $tester = new CommandTester($command);

        $exit = $tester->execute(['name' => 'hero_banner']);

        self::assertSame(0, $exit);
    }
}
```

Run `task test` to verify.

## Adding a step to `mfd new`

Here is a worked example: adding a new step that installs accessibility testing.

### Step 1: Create the Step class

Create `src/Step/A11yStep.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

final class A11yStep implements Step
{
    public function name(): string
    {
        return 'a11y';
    }

    public function plan(Context $context): array
    {
        return [
            'npm install --save-dev @axe-core/playwright',
            'add a11y tests to qa/a11y.ts',
        ];
    }

    public function run(Context $context): void
    {
        $context->shell->run(['npm', 'install', '--save-dev', '@axe-core/playwright'], $context->projectDir);
        // Write a template file or other setup here
    }
}
```

Every step must implement `Step`, which has three methods:

- `name(): string` - the step's identifier (used in state files and logs). No spaces.
- `plan(Context): array` - an array of strings describing what the step will do (for `--dry-run`). Does not change anything.
- `run(Context): void` - actually performs the step. Throws an exception on failure (never returns a code).

### Step 2: Register the step

In `src/Steps.php`, add the new step to the array in the correct order:

```php
public static function all(): array
{
    return [
        new DrupalStep(),
        new ThemeStep(),
        new HarnessStep(),
        new TemplatesStep(),
        new A11yStep(),  // Add this
        new ToolkitStep(),
        new FinishStep(),
    ];
}
```

The order matters: steps run in the order listed. A new step that depends on Drupal being installed must come after `DrupalStep`. A step that depends on npm packages must come after `HarnessStep` (which sets up the harness installer). A step that creates files should come before `FinishStep`.

### Step 3: Make every command idempotent

If the step runs twice, the second run should be a no-op. In the example, `npm install` is idempotent (it installs nothing if the package is already there), but writing a template file is not. Guard it:

```php
public function run(Context $context): void
{
    if (!is_file($context->path('qa/a11y.ts'))) {
        $template = file_get_contents(__DIR__ . '/../../template/qa/a11y.ts');
        file_put_contents($context->path('qa/a11y.ts'), $template);
    }
    $context->shell->run(['npm', 'install', '--save-dev', '@axe-core/playwright'], $context->projectDir);
}
```

### Step 4: Write a test

In `tests/Unit/Step/A11yStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Step;

use Manifesto\Mfd\Step\A11yStep;
use Manifesto\Mfd\Tests\Support\NewCommandTestCase;

final class A11yStepTest extends NewCommandTestCase
{
    public function testRunsNpmInstall(): void
    {
        $tester = $this->newProject(['name' => 'acme'], [new A11yStep()]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertContains(
            'npm install --save-dev @axe-core/playwright',
            $this->shell->calls,
        );
    }
}
```

`NewCommandTestCase` provides `$this->newProject()` to run the command with a `FakeShell`. The `$this->shell->calls` array records every command that was run as a string (arguments joined by spaces).

## Templates

The `template/` directory holds files that are copied into every new project, with placeholders replaced:

- `{{PROJECT}}` becomes the DDEV project name, e.g. `acme-corp`
- `{{THEME}}` becomes the theme machine name, e.g. `acme_corp`
- `__THEME__` also becomes the theme machine name (Drupal and front-end tools use both)
- `{{SITE_NAME}}` becomes the site label, e.g. `Acme Corp`
- Task's own placeholders like `{{.X}}` are left as-is for the template to use later

### Copying template files

`TemplatesStep` uses `Renderer` to copy the template directory and replace placeholders:

```php
$lines = (new Renderer())->render(
    $context->packageRoot . '/template',
    $context->projectDir,
    $context->config->placeholders(),
);
foreach ($lines as $line) {
    $context->log($line);
}
```

The `render()` method returns an array of log lines such as "wrote Taskfile" or "kept existing .ddev/config.yaml". Existing files are left unchanged. In UTF-8 files, the placeholders `{{PROJECT}}`, `{{THEME}}`, `{{THEME_LABEL}}`, and `{{SITE_NAME}}` are replaced with their values. The path component `__THEME__` is also replaced. Task's own placeholders like `{{.X}}` pass through unchanged.

### Adding to every project

To add a file to every new project, create it in `template/` with the appropriate placeholders and trigger `TemplatesStep` to include it.

### Merging packages

`resources/package-additions.json` defines npm packages, scripts and overrides to merge into the generated `package.json`:

```json
{
  "scripts": {
    "lint": "eslint ... && stylelint ...",
    "lint:fix": "eslint --fix ... && stylelint --fix ..."
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

`package.json` itself is written by the harness installer (`qa-init.sh`, run by `HarnessStep`),
not by mfd. `PackageMerger` (run afterwards, by `TemplatesStep`) reads `package-additions.json`
and merges its `scripts`, `devDependencies`, and (optionally) `overrides` sections into that
`package.json`. Versions and scripts are added only if not already present. Storybook packages
and configuration also come from the harness installer, not from this file.

## Tests and lint

### Running tests

```bash
task test       # Fast unit and integration tests (no Docker)
task lint       # PSR-12 code standards and static analysis (phpstan level 8)
task e2e        # End-to-end tests against real DDEV (slow; requires Docker and the harness)
```

### Test naming conventions

Every test runs through PHPUnit against a `FakeShell` and a temporary directory
(`tests/Support/NewCommandTestCase`, `tests/Support/TempDirectory`), so nothing touches a real
shell or a real project. There is no separate naming prefix for tests that exercise a full run.
For example:

- `NewCommandRunTest`, `NewCommandFullRunTest` (drive a whole `mfd new` run against the fake shell)
- `NamingTest`, `ProjectConfigTest` (pure logic, no command involved)

### phpstan level 8 and PSR-12

All code must pass:

- `vendor/bin/phpstan analyse --no-progress` (level 8)
- `vendor/bin/phpcs` (PSR-12 standards)

Both run together as `task lint`. There is no `task lint:fix`; run `vendor/bin/phpcbf` directly
to auto-correct what phpcs can fix. phpstan findings need a manual fix.

## Releasing

Tags and version constraints:

- Tag releases as `v1.0.0`, `v1.1.0`, etc.
- The generated project's `composer.json` locks to `dev-main` by default (tracks the main branch).
- `MFD_VERSION` overrides the version constraint: set it to `v1.0.0` or `^1.0` to lock to a release.

When you release, the project's `ToolkitStep` adds `mfd` as a dev dependency with the version from `MFD_VERSION` or the default `dev-main`.

## Common patterns

### Logging

```php
$context->log('message here');      // Informational
$context->warn('non-fatal problem'); // Warning; step continues
throw new \RuntimeException('message');   // Fatal; step fails
```

When a step throws any exception, `StepRunner` catches it and turns it into a `StepFailed`
exception carrying the step's name. `NewCommand` turns that into exit code 1. The failed step is
never marked done, so a rerun resumes at the failed step and runs it again from the start (see
`NewCommandRunTest::testARerunAfterAFailureResumesAtTheFailedStep`). This is why every step must
be safe to run twice.

`CommandFailed` is thrown by `Shell` when a command exits non-zero; it is not for step code to throw directly.

### Finding the project root

```php
use Manifesto\Mfd\Project\ProjectRoot;

$root = ProjectRoot::find($from);  // walks up from $from to the nearest .mfd.json
$theme = $root->theme();           // the theme recorded in .mfd.json, validated
$path = $root->path('web/themes/custom/' . $theme);
```

### Environment variables

Used by the tool itself:

- `MFD_REPOSITORY`: Git repository URL for `mfd` (default: `https://github.com/welly/mfd`). Override to test a branch.
- `MFD_VERSION`: Version constraint for `mfd` in the generated project (default: `dev-main`). Override to pin a release.
- `QA_INIT`: Path to the mf-harness `qa-init.sh` installer (default: `~/.claude/skills/design-review/scaffold/qa-init.sh`). Override if your harness is elsewhere.
- `HARNESS_PROBE`: Path to the mf-harness setup probe (default: `~/.claude/lib/setup_project_probe.py`). Override if your harness is elsewhere.

## Further reading

- `src/Config/Naming.php`: naming rules and validation
- `src/Config/ProjectConfig.php`: project configuration from arguments
- `src/Step/DrupalStep.php`: a complex step showing snapshots and idempotence
- `src/Shell/Shell.php`: the Shell interface
- `src/Shell/ProcessShell.php`: the real implementation
- `tests/Support/FakeShell.php`: the mock shell for tests
- `tests/Support/NewCommandTestCase.php`: base class for command and step tests, runs mfd new with a FakeShell
