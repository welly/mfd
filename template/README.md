# {{SITE_NAME}}

Drupal 11 on DDEV with the `{{THEME}}` theme, Single Directory Components and Storybook.

- Site: http://{{PROJECT}}.ddev.site
- Theme: `web/themes/custom/{{THEME}}`
- Storybook: http://localhost:6006 (`task fe:storybook`)

## Requirements

DDEV 1.25 or later with Docker running, Node.js 20 or later, [Task](https://taskfile.dev), git,
Python 3 (needed by `task qa:probe`, the harness setup probe), and the team's Claude Code
harness.

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
