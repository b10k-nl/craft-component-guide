# Component Guide

A lightweight Storybook-style component guide for reusable Twig components in Craft CMS 5.

Component Guide scans a configurable templates directory, discovers Twig components and their story definitions, and renders isolated previews inside the Craft control panel — so content editors and developers can see what each component looks like and how to use it, without setting up a separate Storybook/Twig environment.

> **Status:** `0.1.0` — early MVP (alpha). The discovery/preview workflow is stable; APIs may still change before `1.0`.

---

## Requirements

- Craft CMS **5.0+**
- PHP **8.2+**

## What it does (MVP)

- Recursively discovers components from a configured templates folder.
- Supports nested (`button/button.twig` + `button/button.stories.php`) and adjacent (`button.twig` + `button.stories.php`) conventions.
- Two story formats (simple + rich) normalized to one internal model.
- Groups, lists and client-side-searches components in the CP.
- Renders each story in a sandboxed **iframe** with your front-end CSS.
- Generates a copy-pasteable Twig `{% include … with {…} only %}` snippet.
- Surfaces per-component errors without breaking the rest of the guide.
- Lists story-less templates via **marker files** (`GUIDE.md` / `BLOCKS.md` /
  `COMPONENTS.md`), so the guide doubles as a full component inventory.
- Persistent scan cache keyed by a filesystem fingerprint — invalidates itself
  the moment a story, template or marker changes.

## Installation

### From Packagist *(package name provisional — not yet registered)*

```bash
composer require b10k/craft-component-guide
php craft plugin/install component-guide
```

DDEV:

```bash
ddev composer require b10k/craft-component-guide
ddev exec php craft plugin/install component-guide
```

### Local development via a Composer path repository

This is how the plugin is wired into this project (kept local, not committed).

1. Place the plugin at `plugin-dev/b10k/craft-component-guide/`.
2. Add a path repository and the requirement to the **root** `composer.json`:

   ```json
   {
     "repositories": [
       { "type": "path", "url": "./plugin-dev/b10k/craft-component-guide" }
     ],
     "require": {
       "b10k/craft-component-guide": "@dev"
     }
   }
   ```

3. Install and enable:

   ```bash
   ddev composer require b10k/craft-component-guide:@dev
   ddev exec php craft plugin/install component-guide
   ```

> **Keeping it local:** the plugin source is git-ignored. Because a Craft plugin
> must appear in the root `composer.json`/`composer.lock`, run
> `git update-index --skip-worktree composer.json composer.lock` so those edits
> are never committed — otherwise `composer install` on staging/production would
> fail on the missing path.
>
> Installing also writes the plugin into `config/project/project.yaml`. To keep
> that shared file clean, revert it (`git checkout -- config/project/project.yaml`)
> and let the plugin live in your **local database** only. Caveat: because the
> project-config file no longer lists the plugin, running `project-config/apply`
> (which the Composer `post-install`/`post-update` hooks do) can uninstall it
> locally — just re-run `ddev exec php craft plugin/install component-guide` when
> that happens.

## Configuration

Settings can be edited in **Settings → Plugins → Component Guide**, or overridden
per-environment with a config file. The config file **wins**; any field it sets
is read-only in the CP.

`config/component-guide.php`:

```php
<?php

return [
    'componentPath' => '',                 // scan root, relative to templates/ ('' = whole dir)
    'storySuffix' => '.stories.php',
    'enableCpSection' => true,
    'enableIframePreview' => true,
    'previewCss' => ['/dist/css/app.css'], // string or array
    'previewJs' => ['/dist/js/app.js'],
];
```

| Setting | Default | Notes |
|---|---|---|
| `componentPath` | `''` (whole `templates/`) | Scan root relative to `templates/`. Empty scans everything; set e.g. `_components` to narrow. No absolute paths or `..`. |
| `storySuffix` | `.stories.php` | Must end in `.php`. |
| `enableCpSection` | `true` | Show/hide the CP nav item. |
| `enableIframePreview` | `true` | Isolated iframe vs. an "open preview" link. |
| `previewCss` / `previewJs` | `[]` | Front-end assets injected into the preview. |

**The model is Storybook-style:** the scanner walks the scan root recursively and
shows *every* Twig template that has an adjacent story file — anywhere in the
tree (components, page-builder blocks, partials). A template's author opts it into
the guide simply by dropping a `*.stories.php` next to it; nothing else registers
it. Components are grouped by their folder path (or by a story's `meta.group`).

## Directory conventions

```
templates/_components/
├── button/
│   ├── button.twig
│   └── button.stories.php
├── card.twig                 # adjacent convention also works
├── card.stories.php
└── navigation/menu/
    ├── menu.twig
    └── menu.stories.php
```

A Twig file is listed as a component only when it has a matching story file —
unless its folder is opted in via a **marker file** (see below).
Hidden files/dirs, `node_modules`, `vendor` and cache folders are ignored.

## Marker files: components without stories

Writing a story per component is an upgrade, not the ticket in. Drop a marker
file — `GUIDE.md`, `BLOCKS.md` or `COMPONENTS.md` — into any folder inside the
scan root, and **every** plain Twig template in that folder and its subfolders
is listed in the guide, story or not. Story-less templates appear as inert
“undocumented” cards with a hint to add a story; templates whose filename
starts with `_` are treated as internal partials and skipped.

The marker is also lightweight documentation:

```md
# Content Blocks

Reusable page blocks editors can add through the Matrix page builder.

## Anything below the intro is ignored by the guide — document freely.
```

- The **H1** becomes the group label. Group names mirror the folder hierarchy:
  a marker's H1 replaces its own folder's name in the chain and plain
  subfolders are humanized (`Components / Cards`). Components without an
  explicit `meta.group` inherit the derived name, so documented and
  undocumented cards share one section.
- The **intro text** below the H1 (up to the next heading) becomes the group
  description on the index page.
- One marker per folder; with duplicates, precedence is
  `GUIDE.md` → `BLOCKS.md` → `COMPONENTS.md` and the CLI scan warns.
- Not listed as components: files starting with `_` (internal partials) and
  `index.twig` / `undefined.twig` (the dispatcher pattern's entry point and
  fallback). A story file next to any of them still documents it explicitly.
- Markers live in git next to your templates and read as plain folder docs on
  GitHub. The scan cache tracks them automatically.

## Story format

### Simple

```php
<?php

return [
    'Primary'   => ['label' => 'Save', 'variant' => 'primary'],
    'Secondary' => ['label' => 'Cancel', 'variant' => 'secondary'],
];
```

### Rich (with metadata)

```php
<?php

return [
    'meta' => [
        'title' => 'Button',
        'group' => 'Atoms',
        'description' => 'Primary user-action button.',
        'status' => 'stable',
    ],
    'stories' => [
        'Primary' => [
            'args' => ['label' => 'Save', 'variant' => 'primary'],
            'description' => 'The default call to action.',
            'background' => '#f5f5f5',
            'viewport' => 'mobile',
            'tags' => ['action', 'form'],
        ],
    ],
];
```

Optional story keys: `args`, `description`, `background`, `viewport`, `tags`.

### Component example

```twig
{# templates/_components/button/button.twig #}
{% set label = label ?? 'Button' %}
{% set variant = variant ?? 'primary' %}
{% set disabled = disabled ?? false %}

<button type="button" class="button button--{{ variant }}"{% if disabled %} disabled{% endif %}>
    {{ label }}
</button>
```

Prefer plain arrays and scalar props. Craft element objects returned by trusted
story files are not blocked, but arrays keep stories portable.

## Scaffolding stories

Undocumented components (see marker files above) can bootstrap their own
story. In dev mode each undocumented card gets an **Add stories** button; the
equivalent on the command line is:

```sh
php craft component-guide/components/make hero            # writes hero.stories.twig
php craft component-guide/components/make hero --format=php
```

The scaffolder reads the template and guesses a `Default` story from it:

- root variables become args; `{% for it in items %}` becomes a three-item
  sample array carrying the keys the loop actually uses (`it.title` → `title`);
- `|default('…')` and the `{% set x = x ?? '…' %}` idiom supply real values;
- everything else is guessed by name — `*Url` → `#`, image-ish `*Url` → an
  inline SVG placeholder, `*Html` → a paragraph, `is*`/`has*` → `true`;
- the first sentence of the template's leading `{# … #}` comment becomes the
  component description.

The result is a **draft**: it is written with `status: wip`, the args are
guesses, and an existing story file is never overwritten. Review it, fix what
the heuristics got wrong, and promote the status when the component is
properly documented.

## Recommended architecture: adapters + presentational components

The guide works with any template structure, but this three-layer pattern gets
the most out of it — and keeps a Matrix page builder maintainable:

```
templates/_v2/
├── _blocks.twig             # dispatcher — loops the Matrix field (~10 lines)
├── _adapters/               # entry-aware glue (NOT listed in the guide)
│   ├── hero.twig            #   one per block type, named after its handle
│   ├── promoBanner.twig
│   └── undefined.twig       #   fallback: dev/preview-only “unmapped block” alert
└── _blocks/                 # presentational components (listed in the guide)
    ├── BLOCKS.md            #   marker file — inventory + group description
    ├── hero.twig            #   pure: accepts scalars/arrays, never an Entry
    ├── hero.stories.php     #   preview states for the guide
    └── promoBanner.twig
```

**Presentational components** accept plain scalars and arrays with `|default`
fallbacks — never a Craft element. That is exactly what makes them previewable
in the guide with simple array stories, and reusable outside the Matrix
context.

**Adapters** are the only layer that touches entries: one small template per
block type, receiving `{ block }`, doing all field access, `getFieldByHandle`
guards, image transforms, queries and fallbacks — then including the
presentational partial. They contain no markup of their own. Keep the folder
**outside** any marker-covered subtree so the guide never lists glue code as
components.

**The dispatcher** collapses to a loop with a convention-based include and an
automatic fallback (Twig picks the first template that exists):

```twig
{% for block in blocks.all() %}
    {% include [
        '_v2/_adapters/' ~ block.type.handle ~ '.twig',
        '_v2/_adapters/undefined.twig',
    ] with { block: block } only %}
{% endfor %}
```

`undefined.twig` renders a visible warning in dev mode / live preview and
nothing in production — a new block type shows up as an actionable alert
instead of silently rendering nothing.

Rules of thumb: a component never touches an Entry; an adapter never contains
markup; adding a block = entry type + adapter + component (+ story when it
earns one).

## Preview asset configuration

Set `previewCss` / `previewJs` (string or array of URLs) to load your compiled
front-end assets into the preview document. With none configured you get a clean,
unstyled document. CSS support matters most; JS is optional.

## Security & trust model

- Story files are **PHP** and therefore trusted project code — treat them like
  any template in your repo. They are only ever discovered inside the configured
  `componentPath`.
- The scanner refuses absolute paths and `..` traversal; the resolved directory
  must live inside your templates folder.
- Previews render only component/story **IDs** resolved through the repository —
  a request can never supply a raw template path.
- Every CP/preview action requires login and the `component-guide:access`
  permission.
- Previews are isolated in a `sandbox`ed iframe; metadata is escaped; absolute
  paths and stack traces are shown only when Craft dev mode is on.

Do **not** expose the plugin to untrusted users — PHP story files are not a
sandbox.

## Troubleshooting

- **"No components found."** — check `componentPath` (relative to `templates/`)
  and that story files end in `storySuffix`.
- **Component missing** — it needs a matching `*.twig` next to its story file.
- **Render error in preview** — the component threw; enable `devMode` to see the
  message/trace.
- **Styles missing in preview** — set `previewCss`.

## Development

```bash
composer install
composer test       # PHPUnit (scanner, parser, snippet generator)
composer analyse    # PHPStan (level 5)
composer check      # both
```

### CLI diagnostics

Run the same discovery the CP uses, to verify configuration without opening the
control panel:

```bash
php craft component-guide/components/scan
# DDEV:
ddev exec php craft component-guide/components/scan
```

## Roadmap

- Viewport presets, more preview controls
- Page-builder awareness: map Matrix entry types to their components
- Additional story formats

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately (see
that file) rather than via public issues.

## License

[MIT](LICENSE.md).
