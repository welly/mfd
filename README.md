# mfd: Drupal 11 scaffolder

A Symfony Console tool that creates a Drupal 11 project with Single Directory Components (SDC), Storybook and the mf-harness design-review tooling in one command.

## Install (once per machine)

### Option 1: global install (recommended)

`mfd` is not on Packagist, so tell Composer where it lives first, then require it. There is no tagged release yet, so ask for `dev-main`:

```bash
composer global config repositories.mfd vcs https://github.com/welly/mfd
composer global require --with-all-dependencies manifesto/mfd:dev-main
```

Composer's global bin directory must be on your PATH (`composer global config bin-dir --absolute` prints it). Then:

```bash
mfd new "Acme Corp"
```

### Option 2: clone and symlink (for developing mfd)

```bash
git clone https://github.com/welly/mfd.git ~/tools/mfd
cd ~/tools/mfd && task install     # composer install + symlink ~/.local/bin/mfd
```

## Start a project

Every parameter except the project name is derived from it and can be overridden:

```bash
mfd new "Acme Corp"                                 # DDEV project acme-corp, theme acme_corp, label "Acme Corp"
mfd new "Acme Corp" --project-name acme --theme acme_ui   # every derived name can be overridden
mfd new --help                                      # show all options
```

## Inside a project

Inside the generated project directory, you have a `Taskfile.yml` with commands for day-to-day work:

```bash
task dev                   # Start the site, the front-end watcher and Storybook
task be:test               # All PHPUnit suites
task fe:component -- hero  # New Single Directory Component (same as ddev exec vendor/bin/mfd make:component hero)
task qa:review             # Full design review (advisory, not a blocker)
```

## What you get

- A Drupal 11 site on [DDEV](https://ddev.com), built from `drupal/recommended-project`.
- A custom theme generated from core's starterkit, with a `components/` folder for Single Directory Components.
- [Storybook](https://storybook.js.org) with [`storybook-addon-sdc`](https://storybook.js.org/addons/storybook-addon-sdc). The addon turns every `*.component.yml` into a Storybook story automatically, with no hand-written story files. Storybook is included and enabled by default. Note: Storybook SDC support currently requires the mf-harness `feature/drupal-storybook-sdc` branch (until merged into main).
- A design-review harness (Playwright and axe) that checks accessibility and markup on every Storybook story and on real Drupal pages.
- `mfd` as a dev dependency so every team member runs the same locked version via `ddev exec vendor/bin/mfd`.

## Prerequisites

| Tool | Version | Where it runs | Used for |
| --- | --- | --- | --- |
| DDEV | 1.25 or later | host | Drupal, PHP 8.3, Composer |
| Docker | latest | host | DDEV runs inside Docker |
| Node.js | 20 or later | host | Storybook and front-end build |
| npm | latest | host | Front-end package management |
| Git | latest | host | Version control |
| Task | latest | host | Running development tasks |
| PHP | 8.3 or later | host | Running mfd itself (for `mfd new`) |
| Composer | 2 or later | host | Installing mfd globally or adding packages |
| python3 | 3 or later | host | Harness setup checks |
| mf-harness | latest | `~/.claude` | Design-review tooling (required) |

## Options for `mfd new`

| Option | Default | What it sets |
| --- | --- | --- |
| `name` (argument) | required | Project's human name, e.g. `"Acme Corp"` or `acme-corp` |
| `--project-name` | derived from name | DDEV machine name, 3-63 lowercase letters/digits/hyphens |
| `--theme` | derived from project name | Theme machine name, e.g. `acme_corp` |
| `--theme-label` | derived from name | Theme human name shown in Drupal, e.g. `Acme Corp` |
| `--site-name` | same as theme label | Drupal site name |
| `--dir` | `./<project name>` | Target directory |
| `--dry-run` | off | Print the steps and change nothing |
| `--yes` | off | Never prompt (mfd new does not prompt; kept for harness parity) |

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success. A rerun with the same arguments skips every finished step, including the harness setup probe. Run `task qa:probe` in the project to see the next setup step. |
| 1 | A step failed. Rerun the same command to resume from where it stopped. |
| 2 | User error or pre-flight check failed. Nothing was written. |

## Project naming

Only the `name` argument is required. All other names are derived from it automatically:

| `mfd new …` | DDEV project | Theme | Theme label / site name |
| --- | --- | --- | --- |
| `Something` | `something` | `something` | `Something` |
| `"Acme Corp"` | `acme-corp` | `acme_corp` | `Acme Corp` |
| `"O'Brien & Co"` | `obrien-co` | `obrien_co` | `O'Brien & Co` |
| `acme-corp` | `acme-corp` | `acme_corp` | `Acme Corp` |
| `"Acme Corp" --project-name acme --theme acme_ui` | `acme` | `acme_ui` | `Acme Corp` |

The derivation removes punctuation and apostrophes, lowercases, replaces spaces with hyphens, and cuts to 63 characters.

The name must be ASCII: letters, digits, spaces and `. , & ' ( ) -` only. A non-ASCII name, such
as a brand with an accented character, is rejected with exit 2 before anything is written. To use
that brand anyway, pass `--project-name` and `--theme` (both already restricted to ASCII by their
own naming rules) and an ASCII `--theme-label`. If no valid project name can be derived from the
input at all (for example a label starting with a digit), supply one explicitly with
`--project-name`.

## Environment variables

| Variable | Purpose |
| --- | --- |
| `MFD_REPOSITORY` | Git repository URL for `mfd` itself. Default: `https://github.com/welly/mfd`. Override to test a branch. |
| `MFD_VERSION` | Version constraint for `mfd` in the generated project. Default: `dev-main`. Override to pin a release. |
| `QA_INIT` | Path to the mf-harness `qa-init.sh` installer. Default: `~/.claude/skills/design-review/scaffold/qa-init.sh`. Override if your harness is elsewhere. |
| `HARNESS_PROBE` | Path to the mf-harness setup probe. Default: `~/.claude/lib/setup_project_probe.py`. Override if your harness is elsewhere. |

## Secrets

`web/sites/default/settings.php` is meant to be committed to git and never holds the database credentials or hash salt; `mfd new` only runs `git init`, so review and commit it yourself. DDEV's gitignored `settings.ddev.php` holds both. The installer protects this: it snapshots `settings.php` before running `drush site:install`, then restores the exact original bytes afterwards, so the version you review and commit never ends up dirty.

## Known issues

- New projects add a temporary Composer `conflict` entry blocking `twig/twig` 3.30.0, which breaks Drupal 11.4.7 rendering (`TypeError: Twig\Runtime\EscaperRuntime::escape(): Argument #4 ($autoescape) must be of type bool, null given`), until Drupal or Twig ships a fix. See `DrupalStep::BROKEN_PACKAGES`.

## Developing mfd

To extend mfd with a new command or a new step in `mfd new`, clone the repository and run tasks:

```bash
task test                  # Fast unit and integration tests (no Docker)
task lint                  # PSR-12 code standards and phpstan level 8 static analysis
task e2e                   # Slow end-to-end tests against real DDEV (requires Docker and the harness)
```

See [Developing mfd](docs/developing.md) for a complete guide on adding commands and steps.

## Documentation

- [Start a new project](docs/new-project.md): what `mfd new` creates and how to use it.
- [Working with components and Storybook](docs/storybook.md): adding components and stories, stubbing Twig extensions, and reading review findings.
- [Developing mfd](docs/developing.md): how the tool is structured and how to add commands and steps.

## Versions tested

| Tool | Version |
| --- | --- |
| Drupal | 11.4 |
| DDEV | 1.25.4 |
| Storybook and `@storybook/html-vite` | 10.6.0 |
| `storybook-addon-sdc` | 0.24.21 |
| Node.js | 24 |

Tested on 2026-09-24. Later versions may change commands or behaviour.
