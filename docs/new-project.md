# Start a new project

Replace `acme` and `acme_theme` with your project and theme names throughout.

Set the path to the review installer once. It comes from the team's Claude Code harness; the
exact location depends on how the harness is installed on your machine:

```bash
export QA_INIT=~/.claude/skills/design-review/scaffold/qa-init.sh
```

## 1. Create the Drupal project

```bash
mkdir acme && cd acme
ddev config --project-name=acme --project-type=drupal11 --docroot=web
ddev start
ddev composer create-project "drupal/recommended-project:^11" --no-interaction
ddev composer require drush/drush --no-interaction
ddev drush site:install standard -y
```

## 2. Set up git, ignoring `node_modules` from the start

```bash
git init
cat >> .gitignore <<'EOF'
vendor/
web/core/
web/modules/contrib/
web/themes/contrib/
web/profiles/contrib/
web/libraries/
web/sites/*/files/
web/sites/*/settings*.php
node_modules/
EOF
```

Keep the `node_modules/` line. Without it, the review run lists every installed package as a
changed file and fails with `ENOBUFS`.

## 3. Generate your theme from core's starterkit

```bash
ddev exec vendor/bin/dr generate-theme acme_theme --name "Acme Theme" --path themes/custom
mkdir -p web/themes/custom/acme_theme/components
ddev drush theme:enable acme_theme -y
ddev drush config:set system.theme default acme_theme -y
```

On Drupal 11.4, `vendor/bin/dr` is the generator. The older `core/scripts/drupal` is
deprecated, and running it from `web/` fails to find the autoloader. The theme is written to
`web/themes/custom/acme_theme`. The starterkit does not create a `components/` folder, so the
second line adds it.

## 4. Add your first component

```bash
C=web/themes/custom/acme_theme/components/card
mkdir -p $C
cat > $C/card.component.yml <<'YAML'
$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
name: Card
props:
  type: object
  properties:
    heading:
      type: string
      title: Heading
      examples: ['Card heading']
slots:
  content:
    title: Content
YAML
cat > $C/card.twig <<'TWIG'
<article{{ attributes.addClass('card') }} data-qa="card">
  <h2 class="card__heading">{{ heading }}</h2>
  {% block content %}{% endblock %}
</article>
TWIG
printf '.card { padding: 1rem; border: 1px solid #ccc; }\n' > $C/card.css
ddev drush cr
```

Storybook builds each component's default story from the `examples` in its
`*.component.yml`, so give every prop a realistic example. The `$schema` line is for editor
support only.

## 5. Install the review tooling with Storybook

```bash
bash $QA_INIT --yes --storybook
```

This writes:

- `.storybook/main.mjs` and `.storybook/preview.mjs`;
- exact version pins for Storybook and the SDC addon in `package.json`, plus `storybook` and
  `storybook:build` scripts;
- the `qa/` review tooling and its Playwright configuration;
- `.gitignore` rules for the Storybook build output.

It also prints a security advisory about `locutus` 2.0.39, a transitive dependency of the
Twig renderers Storybook uses. These packages are local development tooling and never run in
production, but read the advisory before deciding to proceed.

What the Storybook configuration does, and why:

- It is `.mjs`, so it never needs `"type": "module"` in `package.json`, which would break
  CommonJS scripts elsewhere in the project.
- It renders with Twig.js (`twigLib: 'twig'`). The addon's other renderer, Twing, breaks the
  Storybook dev server for every component but the first.
- It finds every custom theme and module with a `components/` folder when Storybook starts, so
  a new theme needs no configuration change.
- It serves Drupal's own `drupal.js`, `once` and related files from your installed core
  instead of loading them from a CDN, and turns off Storybook's telemetry.

## 6. Point the review at your DDEV site

In `qa/gate.config.ts`, which belongs to your project and is never overwritten by the
installer, set the preview:

```ts
  baseUrl: process.env.QA_BASE_URL ?? 'http://acme.ddev.site',
  devServerCommand: process.env.QA_DEV_CMD ?? 'ddev start',
```

Use `http://`: the test browser does not trust DDEV's local HTTPS certificate.

In `qa/figma-map.json`:

- if the project has no Figma file, replace the contents with `{}`, because the shipped
  example entries otherwise stop the review before it checks anything;
- if it does, map your components there and run `npm run qa:refresh-figma`.

## 7. Replace the example site stories with real ones

`qa/stories.ts` ships with a placeholder. Replace it with pages where Drupal renders your
components. Use each component's plugin ID, `provider:machine-name`, as `component`:

```ts
import type { Story } from './story';

export const stories: Story[] = [
  {
    id: 'card-front',
    component: 'acme_theme:card',
    path: '/',
    contract: { requiredSelectors: ['[data-qa="card"]'] },
  },
];
```

Any component with a Storybook story but no entry here is reported as "verified in Storybook
only; not verified in Drupal" (`QA-SBONLY`, High). That is intended: Storybook renders
components in isolation, so it never proves the Drupal render. The finding stays until the
component is on a real page with an entry here.

## 8. Check it works

```bash
npm run storybook
```

Open <http://localhost:6006>. The Card story appears under `acme_theme/SDC/Card`. The first
story after start-up can come up blank once while Vite optimises dependencies; refresh.

```bash
QA_DEV_CMD='' npm run qa:loop
```

This runs the Storybook check only, with no DDEV needed. The report says the site check did
not run (`QA-SITE-NOTRUN`), which is expected here.

```bash
npm run qa:loop
```

This runs both checks: the console shows `==> Storybook lane`, then
`==> Playwright site lane`. The report is written to `qa/reports/runs/<run-id>/report.json`.

## 9. Commit

```bash
git add .gitignore .ddev composer.json composer.lock package.json package-lock.json \
        .storybook qa playwright.config.ts playwright.storybook.config.ts web/themes/custom
git commit -m "Scaffold Drupal 11 with Storybook and design review"
```

`qa/reports/` and `qa/.storybook-static/` are already ignored.

## Without the review tooling

If you do not have the harness, skip steps 5 to 7 and add Storybook yourself:

```bash
npm init -y
npm install --save-dev --save-exact storybook@10.6.0 @storybook/html-vite@10.6.0 \
  storybook-addon-sdc@0.24.21 twing@7.3.1 drupal-attribute@1.2.1
npm pkg set scripts.storybook="storybook dev -p 6006"
```

Then create `.storybook/main.mjs`:

```js
import { existsSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const DOCROOT = 'web';
const KINDS = ['themes/custom', 'modules/custom'];

function extensions() {
  const found = [];
  for (const kind of KINDS) {
    const base = join(DOCROOT, kind);
    if (!existsSync(base)) continue;
    for (const entry of readdirSync(base, { withFileTypes: true })) {
      if (entry.isDirectory() && existsSync(join(base, entry.name, 'components'))) {
        found.push({ name: entry.name, path: join(base, entry.name) });
      }
    }
  }
  return found;
}

const found = extensions();

export default {
  stories: KINDS.flatMap((kind) => [
    `../${DOCROOT}/${kind}/*/components/**/*.component.yml`,
    `../${DOCROOT}/${kind}/*/components/**/*.story.yml`,
  ]),
  core: { disableTelemetry: true },
  addons: [
    {
      name: 'storybook-addon-sdc',
      options: {
        sdcStorybookOptions: {
          twigLib: 'twig',
          namespaces: Object.fromEntries(found.map(({ name, path }) => [name, resolve(path)])),
        },
      },
    },
  ],
  framework: { name: '@storybook/html-vite', options: {} },
};
```

This version still loads Drupal's `drupal.js` and `once` from a CDN in every story; the review
installer's version serves them from your installed core instead.
