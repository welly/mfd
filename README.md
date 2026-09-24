# Drupal starter

How to start a Drupal 11 project with Single Directory Components (SDC), Storybook and a
browser-based design review, from an empty folder to a first reviewed component.

Right now this repository holds the documentation for that setup. The steps are written so
they can later become template files and a bootstrap script.

## What you get

- A Drupal 11 site on [DDEV](https://ddev.com), built from `drupal/recommended-project`.
- A custom theme generated from core's starterkit, with a `components/` folder for SDCs.
- [Storybook](https://storybook.js.org) with
  [`storybook-addon-sdc`](https://storybook.js.org/addons/storybook-addon-sdc), which turns
  every `*.component.yml` into stories with no hand-written story files.
- A design-review harness (Playwright and axe) that checks accessibility and markup on every
  Storybook story and on real Drupal pages, and reports any component checked only in
  Storybook.

## Documentation

- [Start a new project](docs/new-project.md): the exact steps, in order.
- [Working with components and Storybook](docs/storybook.md): adding components and stories,
  stubbing Twig extensions Storybook lacks, and reading review findings.

## Requirements

- DDEV 1.25 or later, with Docker running
- Node.js 20 or later
- Composer
- Git
- For the design-review steps: the team's Claude Code harness, which provides the
  `qa-init.sh` installer. Without it, Drupal and Storybook still work; see
  [Without the review tooling](docs/new-project.md#without-the-review-tooling).

## Versions these steps were checked against

| Tool | Version |
| --- | --- |
| Drupal | 11.4 |
| DDEV | 1.25.4 |
| Storybook and `@storybook/html-vite` | 10.6.0 |
| `storybook-addon-sdc` | 0.24.21 |
| Node.js | 24 |

Checked on 2026-09-24. Later versions may change commands or behaviour.
