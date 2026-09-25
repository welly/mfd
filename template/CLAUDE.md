# {{SITE_NAME}}

Drupal 11 site with Single Directory Components (SDC), Storybook and the mf-harness
design-review tooling. Created with mfd (see `.mfd.json`).

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
- Project tooling comes from `mfd` (a dev dependency; run it inside DDEV as `ddev exec vendor/bin/mfd`).
  `mfd list` shows its commands; `task fe:component` wraps `mfd make:component`.

## Harness

- Load `~/.claude/references/drupal-pitfalls.md` when working on Drupal code.
- Harness setup state: `task qa:probe`. Finish setup with `/setup-project` in Claude Code.
- The design review (`task qa:review`) is advisory. Merge checks run in CI once the repository
  is enrolled.
