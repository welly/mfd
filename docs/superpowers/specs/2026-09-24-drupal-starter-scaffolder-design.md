# mfd: Manifesto Drupal toolkit, design

Date: 2026-09-24
Status: Approved 2026-09-24

## Purpose

Manifesto engineers start client Drupal 11 projects by copying commands from
`docs/new-project.md`. This design replaces that with one command that takes an empty folder
to a working project: a DDEV site, a named custom theme with Single Directory Components (SDC),
Storybook, PHPUnit with three suites that pass, and the mf-harness design-review tooling, ready
for the engineer to commit and hand to `/setup-project`.

Success means:

- `mfd new <name>` finishes with a site at `http://<name>.ddev.site` running the new
  theme.
- In the generated project, `task check` passes, and `task dev` starts the site and Storybook,
  without further setup.
- The generated `Taskfile.yml` is the project's daily tool for back-end, front-end and QA
  work, and front-end tooling added later plugs in without changing it.
- Every generated project includes the harness. The scaffolder refuses to run without it.

## Decisions already made

| Topic | Decision |
| --- | --- |
| Delivery | This repository is `mfd`, a PHP command-line toolkit. `mfd new` scaffolds a project once; in-project commands such as `mfd make:component` are used daily. The generated project gets a `Taskfile.yml` for daily work, which calls `mfd` where it needs it. |
| Distribution | Both: engineers install `mfd` once per machine to run `mfd new`; `mfd new` also adds `manifesto/mfd` to each project's `require-dev` (VCS repository `https://github.com/welly/drupal-starter`), so every teammate gets the same locked version through `composer install` and runs it inside DDEV. |
| Harness depth | The scaffolder runs only the harness steps that touch local files. Everything else (MCP, Figma token, readiness audit, Figma map, enrolment, docs config) stays with `/setup-project` in Claude Code, which skips steps that are already done. |
| PHPUnit | Three suites: Unit, Kernel and ExistingSite (Drupal Test Traits). |
| Storybook | Always installed and enabled, with no opt-out. |
| Implementation | PHP 8.3+ with Symfony Console, plus a `template/` directory. PHP because the people who run and extend a Drupal scaffolder know it and already have it. Follows harness conventions: `--yes`, `--dry-run`, exit codes 0/1/2, and it never runs `git commit`. |

## Command-line interface

`mfd` is a Symfony Console application with these commands:

| Command | Where | What it does |
| --- | --- | --- |
| `mfd new <project-name>` | anywhere, from the machine-wide install | Scaffold a new project (below) |
| `mfd make:component <name>` | inside a project, normally `ddev exec vendor/bin/mfd …` via `task fe:component -- <name>` | New SDC in the project theme: `<name>.component.yml` with an `examples` stub, `<name>.twig` with `data-qa`, `<name>.css`. Finds the project root by walking up to `.mfd.json`, reads the theme from it; `--theme` overrides. Refuses a name that is not a machine name or a component that exists |

New commands are added the same way: one Command class, registered in `bin/mfd`.

### `mfd new`

```
mfd new <name> [options]

  <name>                   The project's name, e.g. "Acme Corp". The only thing required;
                           every other name below is derived from it.
  --project-name <name>    Optional. DDEV/project machine name. Default: derived from <name>.
  --theme <machine_name>   Optional. Theme machine name. Default: project name with '-' as '_'.
  --theme-label <label>    Optional. Theme human name. Default: <name> (title-cased if <name>
                           is already machine-style).
  --site-name <label>      Optional. Drupal site name. Default: the theme label.
  --dir <path>             Optional. Target directory. Default: ./<project name>.
  --dry-run                Run the full pre-flight, then print each step's plan lines; change
                           nothing else.
  --yes                    Non-interactive. Never prompts.
  -h, --help
```

### Naming rules

`mfd new "Acme Corp"` is enough on its own:

| `mfd new …` | project (DDEV) | theme | theme label = site name |
| --- | --- | --- | --- |
| `Something` | `something` | `something` | `Something` |
| `"Acme Corp"` | `acme-corp` | `acme_corp` | `Acme Corp` |
| `"O'Brien & Co"` | `obrien-co` | `obrien_co` | `O'Brien & Co` |
| `acme-corp` | `acme-corp` | `acme_corp` | `Acme Corp` |

The project name is derived by lowercasing, dropping apostrophes and turning every other run
of non-alphanumeric characters into `-`. If the result is not a valid project name (for example
`"3M Company"` gives `3m-company`, which starts with a digit), `mfd new` exits 2 and asks for
`--project-name`.


| Value | Rule | Example |
| --- | --- | --- |
| Project name | `^[a-z][a-z0-9-]{1,61}[a-z0-9]$`. It becomes the DDEV project name, so it must be a valid hostname label. | `acme-corp` |
| DDEV URL | `http://<project>.ddev.site` | `http://acme-corp.ddev.site` |
| Theme machine name | `^[a-z][a-z0-9_]*$`, at most 50 characters, and not a reserved name (`core`, `system`, `stark`, `olivero`, `claro`, `starterkit_theme`, or any core module name). Default: project name with `-` replaced by `_`. | `acme_corp` |
| Name / labels | `^[A-Za-z0-9][A-Za-z0-9 .,&'()-]{0,98}$` (safe in YAML and command arguments). | `Acme Corp` |

The scaffolder rejects invalid names before changing anything (exit 2).

Known risk with the default theme name: Drupal machine names must be unique across modules and
themes. A later custom module named after the project will clash with the theme. The README
explains this, and `--theme` avoids it.

## Scaffold steps

Each step checks whether its output already exists and skips if it does, so rerunning after a
failure resumes the run. Each step logs `==> <step>` and either `done` or `skipped`.

1. **Pre-flight** (exit 2 on failure, before any write):
   - DDEV 1.25 or later with Docker running
   - Node.js 20 or later
   - git
   - go-task (`task`)
   - python3 (the harness's setup probe is Python)
   - the harness installer at `${QA_INIT:-~/.claude/skills/design-review/scaffold/qa-init.sh}`,
     and it must support `--storybook` (the Storybook SDC lane, currently on the unmerged
     mf-harness branch `feature/drupal-storybook-sdc`; merging it is a prerequisite for `mfd`)
   - `python3 ~/.claude/lib/setup_project_probe.py` present

   A missing harness is a hard stop, with a message pointing at the mf-harness install
   instructions. Also stops if the target directory exists and is not empty (unless it
   holds resume state from an earlier run with the same options, in `.ddev/mfd/inputs`),
   or if a DDEV project with the same name already exists elsewhere.
2. **DDEV and Drupal:**

   ```
   ddev config --project-name=<project> --project-type=drupal11 --docroot=web
   ddev start
   ddev composer create-project "drupal/recommended-project:^11" --no-interaction
   block twig/twig 3.30.0 with a Composer conflict entry, then ddev composer update twig/twig
   ddev composer require drush/drush
   ddev composer require --dev --with-all-dependencies drupal/core-dev weitzman/drupal-test-traits \
     mglaman/phpstan-drupal phpstan/extension-installer drupal/coder
   ddev drush site:install standard --site-name="<site-name>" -y
   ```

   `--with-all-dependencies` is needed because `drupal/core-recommended` already locks packages
   (for example `sebastian/diff`) that `drupal/core-dev` must move. There is no `--db-url`: DDEV's
   settings management already supplies the database connection, and passing one did not stop the
   problem below. Drupal's installer nondeterministically writes a real `$databases` array and
   hash_salt straight into `settings.php` regardless of what is passed to `site:install`. To handle
   this, `settings.php` is snapshotted before `site:install` runs and restored to the exact
   original bytes afterwards, whether the install succeeds or fails. A tokenizer-based check then
   confirms the restored file holds no live secret before the step finishes
   (`DrupalStep::containsLeakedSecret()`). This replaces the earlier approach of stripping
   known-bad values out of the file after the fact. A temporary Composer `conflict` entry also pins
   away `twig/twig` 3.30.0, which breaks Drupal 11.4.7 rendering (`DrupalStep::BROKEN_PACKAGES`;
   remove once fixed upstream).

   PHPUnit is not pinned directly; `drupal/core-dev` chooses the version core supports. This
   avoids the pin that collects zero tests and still passes (see the `drupal-testing` skill).
3. **Theme:**

   ```
   ddev exec vendor/bin/dr generate-theme <theme> --name "<theme-label>" --path themes/custom
   ```

   Then add `components/` with the example `card` SDC (`card.component.yml`, `card.twig`,
   `card.css`), enable the theme, set it as the default, and clear the cache.
4. **Harness review tooling and Storybook:** `bash $QA_INIT --yes --storybook`. After that, patch the known
   gaps in the harness scaffold:
   - `.gitignore`: add the Drupal ignore block and `node_modules/`. Without `node_modules/`, the
     review run crashes with `ENOBUFS`.
   - `qa/figma-map.json`: set to `{}`. The shipped example entries otherwise stop the review
     before either check runs.
   - `qa/gate.config.ts`: set `baseUrl` to `http://<project>.ddev.site` and `devServerCommand`
     to `ddev start`.
   - `qa/stories.ts`: add a front-page site story for `<theme>:card`.
5. **Starter templates:** copy `template/` into the project, filling in the placeholders
   `{{PROJECT}}`, `{{THEME}}`, `{{THEME_LABEL}}` and `{{SITE_NAME}}`. The card is already on the
   front page by this point: `ThemeStep` (step 3) writes a `page--front.html.twig` template
   suggestion that includes the card just before the main content, so the site story checks a
   real render.
6. **Toolkit:** add the VCS repository and `require-dev manifesto/mfd` to the project with
   `ddev composer`. Source defaults to `https://github.com/welly/drupal-starter` at `dev-main`;
   `MFD_REPOSITORY` and `MFD_VERSION` override it (forks, branches). If Composer cannot install
   it (offline, or the package not yet on that branch), the step warns and prints the exact
   command to run later instead of failing an otherwise complete project; the summary repeats
   the warning.
7. **Finish:** export configuration to `config/sync`, `git init` if needed, write the
   `.mfd.json` marker (starter version and inputs), run `setup_project_probe.py probe --root .` and print its `nextStep`. Then print
   what to do next:
   1. Review and commit.
   2. Open Claude Code and run `/setup-project`.

   The scaffolder never runs `git commit`.

## Files in `template/`

| Path in the generated project | Purpose |
| --- | --- |
| `Taskfile.yml`, `.taskfiles/{backend,frontend,qa}.yml` | The project's daily tasks (next section) |
| `package.json` additions | `lint` and `lint:fix` scripts; Stylelint and ESLint as exact-pinned devDependencies. Merged into the `package.json` that `qa-init` writes, never overwriting its keys |
| `.stylelintrc.json`, `eslint.config.mjs` | Standard configs, scoped to components and custom modules (see the `fe:lint` row) |
| `phpunit.xml.dist` | Suites: `unit`, `kernel`, `existing-site`. Kernel and ExistingSite read `SIMPLETEST_DB` and `DTT_BASE_URL` from the environment |
| `.ddev/config.testing.yaml` | Sets `SIMPLETEST_DB`, `SIMPLETEST_BASE_URL=http://web`, `DTT_BASE_URL=http://web`, `BROWSERTEST_OUTPUT_DIRECTORY` inside the web container |
| `web/modules/custom/<theme>_tests/` | Tiny custom module whose only job is to hold the example tests; no runtime code |
| `.../tests/src/Unit/ExampleTest.php` | Passing Unit test, using PHPUnit attributes (`#[Group]`), not annotations |
| `.../tests/src/Kernel/ComponentDiscoveryTest.php` | Passing Kernel test that boots `system` and asserts the theme's SDC plugin is discovered |
| `.../tests/src/ExistingSite/FrontPageTest.php` | Passing ExistingSite test: the front page returns 200 and contains `[data-qa="card"]` |
| `phpcs.xml.dist` | Drupal and DrupalPractice standards on `web/modules/custom` and `web/themes/custom` |
| `phpstan.neon.dist` | Level 1 with phpstan-drupal, custom code only |
| `CLAUDE.md` | Drupal-specific seed: stack, commands (`task …`), where components and tests live, a pointer to the harness's `drupal-pitfalls.md`. `/setup-project` treats an existing CLAUDE.md as done |
| `README.md` | Project README: requirements, `task` commands, naming caveat |

## Generated Taskfile: the daily tool

The Taskfile is how engineers work on the project every day, not just how it gets installed.
It is split by concern so each file stays small and a project can extend one area without
touching the others:

```
Taskfile.yml             root: includes the files below; top-level shortcuts; `task` lists everything
.taskfiles/backend.yml   namespace be:
.taskfiles/frontend.yml  namespace fe:
.taskfiles/qa.yml        namespace qa:
```

Every task has a `desc`, so `task --list` doubles as the project's command reference. Tasks
that need the site depend on `up`, which is quick when DDEV is already running.

### Top-level shortcuts

| Task | Runs |
| --- | --- |
| `task` | `task --list` |
| `task up` / `task down` | `ddev start` / `ddev stop` |
| `task setup` | after a fresh clone: `up`, `be:install`, `fe:install`, `be:db:import` if a dump is given, `be:deploy` |
| `task dev` | `up`, then `fe:watch` and `fe:storybook` in parallel |
| `task check` | the full local check before a PR: `be:lint`, `fe:lint`, `be:test`, `fe:build`, then `qa:review`, whose findings are reported but never fail `check` (the review is advisory) |

### Back end (`be:`)

| Task | Runs |
| --- | --- |
| `be:install` | `ddev composer install` |
| `be:require -- <pkg>` | `ddev composer require <pkg>` |
| `be:update -- [pkg]` | `ddev composer update [pkg] --with-all-dependencies` (what Drupal core updates need) |
| `be:drush -- <args>` | `ddev drush <args>` (also `task drush -- …`) |
| `be:cr` | `ddev drush cr` |
| `be:deploy` | `ddev drush deploy` (updb, cim, cr, deploy hooks, in core's order) |
| `be:cex` / `be:cim` | `ddev drush config:export -y` / `config:import -y` |
| `be:login` | `ddev drush uli` |
| `be:db:export` / `be:db:import -- <file>` | `ddev export-db` / `ddev import-db` |
| `be:logs` | `ddev drush watchdog:show` |
| `be:xdebug -- on\|off` | `ddev xdebug on\|off` |
| `be:test` | all three suites inside DDEV: `ddev exec vendor/bin/phpunit` |
| `be:test:unit` / `be:test:kernel` / `be:test:site` | one suite: `--testsuite <name>` |
| `be:test -- --filter <name>` | extra arguments go to PHPUnit |
| `be:lint` / `be:lint:fix` | `phpcs` and `phpstan` / `phpcbf` |

### Front end (`fe:`)

Front-end tasks go through npm scripts, never through the tools themselves. The Taskfile
calls `npm run --if-present <script>`, so adding Vite, PostCSS, Tailwind, a TypeScript build
or anything else later means defining or changing an npm script, and the Taskfile does not
change. When a script is not defined yet, npm does nothing and exits 0. That way `task check`
works on day one, and gains checks as tooling is added. Each task's description names its npm
script.

| Task | npm script | Default in the template |
| --- | --- | --- |
| `fe:install` | `npm ci` | |
| `fe:build` | `build` | not defined: the starter theme ships plain CSS with no build step |
| `fe:watch` | `watch` | not defined |
| `fe:lint` / `fe:lint:fix` | `lint` / `lint:fix` | ESLint (`@eslint/js` recommended) and Stylelint (`stylelint-config-standard`, BEM allowed) on `web/themes/custom/*/components` and `web/modules/custom`. Not Drupal core's configs: they need core's own node packages, and the starterkit's generated CSS would fail them |
| `fe:test` | `test:js` | not defined |
| `fe:storybook` | `storybook` | from `qa-init` (http://localhost:6006) |
| `fe:storybook:build` | `storybook:build` | from `qa-init` |
| `fe:component -- <name>` | none | `ddev exec vendor/bin/mfd make:component <name>`, then `be:cr` |

The build scripts are root-level npm scripts, even when a tool is later configured inside the
theme folder. One `package.json` keeps Storybook, the review tooling and the theme build on
one lockfile, so the PR dependency audit sees everything.

### QA (`qa:`)

| Task | Runs |
| --- | --- |
| `qa:review` | `npm run qa:loop` (Storybook check and site check) |
| `qa:review:storybook` | `QA_DEV_CMD='' npm run qa:loop` |
| `qa:review:scoped` | `npm run qa:loop:scoped` |
| `qa:probe` | `python3 ~/.claude/lib/setup_project_probe.py probe --root .` (harness setup status) |

## This repository after the change

```
bin/mfd                            entry point (works from a checkout and from vendor/bin)
composer.json                      manifesto/mfd; symfony/console, symfony/process, symfony/filesystem (^7.4 || ^8.0)
src/Command/NewCommand.php         mfd new: options, --dry-run plan, summary, exit codes
src/Command/MakeComponentCommand.php  mfd make:component
src/Component/ComponentGenerator.php  writes an SDC's files
src/Project/ProjectRoot.php        finds the project root (.mfd.json) and reads it
src/Config/Naming.php              the naming rules
src/Config/ProjectConfig.php       readonly value object: validated inputs and defaults
src/Preflight.php                  tools, versions, harness, target directory, DDEV name
src/Shell/Shell.php                interface: every external command goes through it, as an argv array
src/Shell/ProcessShell.php         real implementation (Symfony Process, streams output)
src/State.php                      resume state in .ddev/mfd/
src/Step/Step.php                  interface: name(), plan(Context), run(Context)
src/Step/{Drupal,Theme,Harness,Templates,Toolkit,Finish}Step.php
src/Steps.php                      the ordered list; the one place a new step is registered
src/Template/Renderer.php          template/ → project, placeholder replacement
src/Harness/QaPatcher.php          gate.config.ts, stories.ts, figma-map.json
src/Npm/PackageMerger.php          lint scripts into package.json
resources/package-additions.json   what the merger adds
template/                          files copied into every new project
tests/Unit/, tests/Support/        PHPUnit; FakeShell stands in for ddev, the harness and git
tests/E2e/                         the slow end-to-end suite (separate testsuite)
Taskfile.yml                       this repo's own tasks: install, test, lint, e2e
docs/                              existing docs, updated to point at mfd
```

Host requirements for `mfd new`: PHP 8.3 or later on the command line, and Composer 2. Install
once per machine: clone this repository, run `task install` (Composer install, then a symlink
of `bin/mfd` into `~/.local/bin`). Teammates on a generated project need no host PHP: they run
`mfd` inside DDEV from the project's `vendor/bin`.

Extending: most changes are data (files in `template/`, tasks in `.taskfiles/`, npm scripts).
A new scaffold step is one class implementing `Step`, plus one line in `Steps.php`. A new
command is one Command class, registered in `bin/mfd`.

`docs/new-project.md` stays as the manual reference and says so at the top.

## Settings, configuration and resume state

- `web/sites/default/settings.php` is committed. DDEV keeps database credentials and the hash
  salt in `settings.ddev.php`, which is ignored. The scaffolder sets
  `$settings['config_sync_directory'] = '../config/sync'`, so `task setup` can rebuild a fresh
  clone with `site:install --existing-config`, or from a database dump.
- Resume state lives in `.ddev/mfd/` (`inputs`, plus a `done/<step>` file per
  step). That directory ignores itself, so it is never committed. `.mfd.json` is the
  committed marker.
- Every step is idempotent at the sub-command level, so a rerun after a failure partway through
  a step does not redo finished work.

## Error handling

- Exit codes follow the harness: 0 success, 1 a step failed, 2 bad input or failed pre-flight
  with nothing written.
- A failed step prints the command that failed, its output, and "rerun the same command to
  resume". Nothing is rolled back; resumable steps make rollback unnecessary.
- Every value is validated against the naming rules first, and every external command is run
  as an argv array (Symfony Process), never through a shell string, so no quoting is involved.
- Input errors and failed pre-flight checks throw `UserError` (exit 2); a failing step is
  wrapped in `StepFailed` (exit 1).

## Testing

- **Fast (PHPUnit, runs in seconds, no Docker):**
  - argument parsing
  - name validation and defaulting (`acme-corp` → `acme_corp`, `Acme Corp`)
  - placeholder substitution, including that no `{{…}}` remains in any file
  - skip-if-done behaviour
  - `--dry-run` writes nothing
  - a missing harness exits 2
  - the patches to the harness scaffold are applied

  A `FakeShell` records every command and fakes the files DDEV, Composer, the harness and git
  would write. Template tests render `template/` and run the real `task` and the generated
  scripts.
- **End-to-end (`task e2e`, a separate PHPUnit testsuite, slow, needs Docker):** scaffold a real project into a temporary
  directory, then assert:
  - `task be:test` runs all three suites, and each reports at least one test and no failures
    (to catch zero-test passes)
  - `task be:lint` and `task fe:lint` pass
  - `task fe:build` exits 0 when no build script is defined
  - after adding a `build` script to `package.json`, `task fe:build` runs it, which proves
    the plug-in path
  - `task fe:component -- hero` (through the project's `vendor/bin/mfd`) creates a component that
    then appears in Storybook
  - `task fe:storybook:build` succeeds and the index contains `<theme>/SDC/Card`
  - `task qa:review` writes a `report.json` with no Critical findings
  - `task check` passes end to end
  - `ddev delete -Oy` runs afterwards
- `task lint`: PHP_CodeSniffer (PSR-12) and phpstan (level 8) over `bin/`, `src/` and `tests/`.

## Risks and follow-ups

- **`locutus` upgrade (separate piece of work):** the Storybook Twig renderers bring in
  `locutus` 2.0.39, tracked in mf-harness as SB-01. Upgrading it to 3.x (3.0.36 at the time of
  writing) is out of scope here. `qa-init` prints the harness's own advisory when it runs.
- **Harness gaps this starter works around** (to raise against mf-harness so the patches can be
  removed): `node_modules/` missing from `.gitignore`; the placeholder `figma-map.json` stops
  the review; `scaffold/drupal/phpunit.xml.dist` is never installed; `qa:loop` does not run
  PHPUnit; no CI job runs the Drupal PHP suite; the preview adapter is not DDEV-aware.
- **Drift:** the scaffolder depends on `qa-init.sh` flags and on the output files it writes.
  The end-to-end test is the guard; the `.mfd.json` marker records the starter version
  for later upgrades.
- **Out of scope (YAGNI):** hosting targets (Acquia, Pantheon, Platform.sh), CI for the
  generated project beyond harness enrolment, config split, recipes, multisite, and upgrading
  existing projects.
