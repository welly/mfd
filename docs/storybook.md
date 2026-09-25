# Working with components and Storybook

## Where stories come from

There are no hand-written story files. `storybook-addon-sdc` reads every
`*.component.yml` under `web/themes/custom/*/components/` and `web/modules/custom/*/components/`
and creates one component in Storybook for each, with a `Basic` story whose props come from
the `examples` in the YAML.

The design review keeps two lists of its own:

| File | What it is |
| --- | --- |
| `qa/stories.ts` | Site stories you write by hand: real Drupal URLs to check in the running site |
| `qa/.storybook-static/qa-stories.json` | Generated from Storybook's index on every review run. Do not edit |

## Add a component

Run the scaffolder:

```bash
task fe:component -- <name>    # Creates a new component, e.g. task fe:component -- hero
```

This is a wrapper for `ddev exec vendor/bin/mfd make:component <name>`. It creates `components/<name>/<name>.component.yml`, `<name>.twig` and `<name>.css` in your theme. Storybook picks it up without any configuration change. Place it on a real page and add a site story for it in `qa/stories.ts` when you can.

## Add more stories

Inside the component's YAML:

```yaml
thirdPartySettings:
  sdcStorybook:
    stories:
      long_heading:
        props:
          heading: A much longer heading that should wrap onto two lines
```

Or as a separate file beside the component, one story per file:

```yaml
# components/card/card.long.story.yml
name: Long heading
props:
  heading: A much longer heading that should wrap onto two lines
```

Slots take a list of nodes: `type: component` to nest another component, `type: element` for
a tag with a value and attributes, `type: image` for an image URL.

## What Storybook can and cannot render

Storybook renders the Twig in your browser with Twig.js, with no Drupal behind it. Drupal
core's own Twig filters and functions (`|t`, `|clean_class`, `create_attribute()`, `path()`
and others) are emulated in JavaScript, so templates that use them render. Note that `|t`
does not translate, and `path()` and `url()` cannot resolve real routes.

Storybook does not have:

- contrib or custom Twig extensions, such as Twig Tweak's `drupal_view()`, unless you stub
  them (below);
- anything a preprocess function would add;
- real content: props come from `examples`;
- the field, block and page markup that surrounds the component in Drupal.

So a Storybook pass checks the component in isolation. Only a site story shows that Drupal
renders it correctly.

## Stub a Twig extension Storybook lacks

When a template calls a function Storybook does not have, the story shows "An error occurred
whilst rendering …" and the review reports it as a critical. Stub the function in
`.storybook/main.mjs`, beside `sdcStorybookOptions`:

```js
vitePluginTwigDrupalOptions: {
  functions: {
    // drupal_view() needs a Drupal site. In Storybook it renders a visible
    // placeholder showing where the view would go.
    drupal_view: (twig) => twig.extendFunction('drupal_view', (name, display = 'default') => {
      const esc = (s) => String(s).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
      return `<div class="sb-placeholder">View ${esc(name)}:${esc(display)}</div>`;
    }),
  },
},
```

Each function's source is copied into the browser bundle, so it must be self-contained: no
imports and no variables from the rest of `main.mjs`. Escape template arguments before putting
them in markup, as `esc` does. A stub hides whatever the real extension outputs, so the
component still needs a site story.

## Drupal behaviours

Stories run `Drupal.attachBehaviors()`. The review installer's configuration serves
`drupal.js`, `once` and related files from `web/core`, so keep core installed
(`ddev composer install`) before starting Storybook. Without core, every story logs
`Drupal is not defined`.

## Reading review findings

Run a review with these commands:

```bash
task qa:review                # Full design review (Storybook check, then the site check)
task qa:review:storybook      # Storybook check only; no DDEV needed
```

Findings from the Storybook check carry `sb-<width>` breakpoints.

| Finding | Severity | Meaning |
| --- | --- | --- |
| `QA-SBONLY-<provider:name>` | High | The component has a Storybook story but no site story in `qa/stories.ts` |
| `QA-SITE-NOTRUN` | Medium | No site preview was configured, so nothing was checked in Drupal |
| `QA-MARKUP-<story>-error` | Critical | The story rendered an error instead of the component |
| `QA-MARKUP-<story>-root` | Critical | The story rendered nothing |
| `QA-SB-FAIL-<story>-<width>` | Critical | That story's check failed without recording a finding, usually because it never finished rendering |
| `QA-SB-BUILD`, `QA-SB-INDEX` | Critical | Storybook did not build, or its index could not be read |
| `QA-SB-PW` | Critical | The Storybook check could not run, for example because its port was in use |
| `QA-SB-EMPTY` | Low | Storybook built with no stories |

The Storybook check serves the build on `127.0.0.1:6107`. Set `QA_STORYBOOK_PORT` to use
another port. It runs on full review runs only, not on `npm run qa:loop:scoped`.
