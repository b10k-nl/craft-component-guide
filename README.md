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

A Twig file is listed as a component only when it has a matching story file.
Hidden files/dirs, `node_modules`, `vendor` and cache folders are ignored.

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

- Optionally list undocumented Twig files
- Persistent scan cache + invalidation
- Viewport presets, more preview controls
- Additional story formats

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately (see
that file) rather than via public issues.

## License

[MIT](LICENSE.md).
