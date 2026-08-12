# Changelog

All notable changes to Component Guide are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## Unreleased

### Added
- The scan cache is now taggable and appears in **Utilities → Caches** as
  “Component Guide scan cache”, so it can be cleared on its own instead of
  forcing Craft's global “clear everything”.
- Uninstalling drops that cache and logs what was deliberately left behind:
  story and marker files stay, because they are project code in git — "remove
  the plugin and your project is untouched" only holds if nothing deletes them.
  README and BETA.md now spell out what goes, what stays and what was never
  touched.
- **One story per state, on request.** When a template switches on a value
  (`theme == 'dark'`, `mediaPosition == 'right'`), the scaffolder can write one
  story per value instead of a single `Default` — named after the state
  (`Light`, `Dark`, `Media right`). Opt-in and self-explanatory: the buttons
  read **Add story** and **Add 2 stories** (however many were found, with a
  tooltip naming the values), and the second only appears where states exist.
  `--states` does the same from the CLI.
- **Placeholder tokens in stories.** String args can now say what kind of
  content they need instead of carrying it: `@lorem_w_6`, `@lorem_p_2`,
  `@image_1600x600`, `@icon_star`. Expansion is deterministic (seeded by
  component + story + argument path), so previews never flicker and gallery
  thumbnails match the detail page, while items in a list still differ from
  each other. Photos fall back to an inline placeholder when the network
  isn't available; icons are inline Craft system icons. Unknown `@…` values
  pass through untouched.
- The story scaffolder emits those tokens instead of baked-in "Lorem ipsum",
  so generated stories stay short and readable — and blocks added from the
  gallery are prefilled with the resolved text, not the raw token.
- The blocks gallery now works in **all** Matrix view modes. Cards and Index
  fields are `Craft.NestedElementManager` instances with none of the inline
  mode's markup, so the picker hooks the class-level `afterInit` event and uses
  the manager's public API (`settings.createAttributes`, `addButton()`,
  `createElement()`) instead of CSS selectors — which also makes it resilient
  to Craft's markup changing between minors. Prefill stays inline-only: in
  cards/index mode Craft creates the entry server-side and opens a slideout.
- `previewTemplate` is now editable in the settings screen (it was config-file
  only) and documented there as the recommended route for Vite/manifest
  builds — previously the most useful preview setting was invisible in the UI.

### Changed
- A story's `viewport` is now validated like `status`: `desktop`, `tablet` or
  `phone`, with aliases (`mobile` → `phone`, `ipad` → `tablet`, …). An
  unrecognised value used to be accepted and then silently ignored by the
  preview; it now surfaces as a scan error naming the valid options.
- The scaffold button is now labelled **Add story** (singular): it always
  writes one story file, and the second button says how many stories go
  inside it.
- Story scaffolding is gated on Craft's `allowAdminChanges` instead of
  `devMode` — the flag that actually means "this environment may change
  project files" — and where it is off the index states that plainly instead
  of silently hiding the button.
- The settings screen is grouped into Discovery / Previews / Control panel
  sections instead of one flat list, with shorter instructions.
- The override note is passed to Craft's form macros as a plain string (or
  `null`) rather than a macro's Markup object, so a stray newline can never
  render an empty phantom warning again.

## 0.1.0-beta.3 - 2026-08-04

### Added
- The blocks gallery blocks only what a developer explicitly marked: entry
  types whose component carries a non-stable status (`draft`, `deprecated`, …)
  render as disabled cards with a one-line reason. Story-less and unmatched
  types stay addable (empty) so the gallery never blocks normal content work;
  the native "New Block" menu is untouched.
- Blocks added from the gallery are prefilled with the first story's scalar
  args (matched to field handles, `bodyHtml` → `bodyText` alias included), so
  a new block is immediately visible on the page instead of rendering empty.

### Changed
- The `wip` status is now called `draft` (canonical vocabulary:
  `stable | beta | draft | deprecated`). Existing story files keep working —
  `wip` and `in progress` normalize to `draft` as aliases; the scaffolder now
  writes `status: 'draft'`.

### Fixed
- The picker panel cooperates with Craft's overlay stack (z-index 100 plus a
  `cg-overlay-open` flag), so modal and slideout footer buttons stay reachable
  while the gallery is open.
- Settings fields that are NOT overridden by `config/component-guide.php` no
  longer show a phantom empty warning icon (the override-note macro emitted
  stray whitespace, which Craft's form macros treat as a warning).
- The “previews render without your site's CSS” hint no longer shows when a
  `previewTemplate` is configured — Vite/manifest asset tags injected there
  count as styling.

## 0.1.0-beta.2 - 2026-07-30

### Added
- Previews that render nothing now explain why instead of showing a blank
  frame (markup behind a condition the args don't satisfy, or a template that
  reads Craft data itself).
- The story scaffolder builds stand-in hashes for variables accessed by dotted
  paths, so templates written against a Matrix block (`block.heading`) get a
  renderable story without refactoring — in Twig a plain hash reads the same
  as an element. Nested paths nest; trailing method calls are declared but
  can't be faked, and the scaffold says so in a note. Guessed values are
  context-aware, so nested paths get plausible stand-ins too.

### Fixed
- `block` is no longer treated as a Twig keyword by the scaffolder: in Craft
  page-builder templates it is almost always the Matrix block variable.
  `{% block x %}` and `block('x')` are still recognised as language
  constructs.

## 0.1.0-beta.1 - 2026-07-30

First public beta — the initial MVP.

### Added
- Marker-file discovery skips `index.twig` and `undefined.twig` — the entry
  point and fallback of the recommended dispatcher pattern are not components.
  (An explicit story file still documents them if you want it to.)
- Onboarding empty state: with nothing discovered yet, the index explains the
  two ways in (marker file → instant inventory, story file → previews) using
  the project's actual scan path, and links to the settings screen.
- A non-blocking notice above the grid when `previewCss` isn't configured, so
  unstyled previews read as "not set up yet" rather than "broken".
- Story scaffolder: an "Add stories" button on undocumented cards (dev mode
  only) and a `component-guide/components/make <id>` console command generate
  a skeleton story from the template's variables — loop sources become sample
  item arrays, `|default()` and `{% set x = x ?? … %}` fallbacks become values,
  the first sentence of the leading `{# … #}` comment becomes the description,
  and the rest is guessed from variable names. Writes `.stories.twig` by
  default (`--format=php` for the PHP format), marks the result `status: wip`,
  and never overwrites an existing story file.
- The "Blocks gallery" trigger is duplicated in the Live Preview editor pane
  header, so it stays reachable on long Matrix fields without scrolling to the
  field's bottom "New Block" row.
- Recursive component discovery from a configurable templates directory.
- Nested (`button/button.twig` + `button/button.stories.php`) and adjacent-file
  conventions.
- Simple and rich PHP story formats, normalized to shared internal models.
- Control-panel section: component index (grouped, searchable) and detail pages.
- Isolated, sandboxed iframe previews with configurable front-end CSS/JS.
- Copy-pasteable Twig `{% include … with {…} only %}` usage snippets.
- Native settings screen with `config/component-guide.php` overrides.
- Per-component, non-fatal error reporting.
- `component-guide:access` permission gating all CP/preview actions.
- `component-guide/components/scan` console command for CLI diagnostics.
- `component-guide/components/render` console command that prints a story's
  full preview document for verifying preview configuration.
- Unit tests for the scanner, story parser and snippet generator; PHPStan level 5.
- Persistent scan cache keyed by a filesystem fingerprint (story-file mtimes),
  so it invalidates automatically when stories or templates change. Toggleable
  via the `enableScanCache` setting.
- Marker-file discovery: drop a `GUIDE.md`, `BLOCKS.md` or `COMPONENTS.md` into
  a folder to list every Twig template in its subtree as an "undocumented"
  component — no story file needed. Group names mirror the folder hierarchy
  ("Components / Cards"): a marker's H1 replaces its own folder's name in the
  chain and is inherited by every component in the subtree without an explicit
  meta group (documented or not); the intro text below the H1 becomes the
  group description on the index page.
  Underscore-prefixed files are skipped, duplicate markers in one directory
  produce a non-fatal warning (GUIDE → BLOCKS → COMPONENTS precedence), and the
  scan-cache fingerprint tracks markers and covered templates automatically.

### Changed
- The persistent scan cache key now includes the mtimes of the scanner and
  story parsers, so changing that code invalidates stale entries by itself —
  in development and after a `composer update` — instead of relying on a
  hand-bumped version constant.
- Plugin components are wired explicitly, guaranteeing Twig story support
  (`*.stories.twig`) is always active.
- Component lookups by ID are indexed instead of linear scans.
- The preview document rendering is shared between the web controller and the
  CLI via `PreviewRenderer::renderDocument()`.
- The Matrix picker's DOM observer coalesces mutation bursts into a single
  scan per frame, reducing overhead on busy CP pages.

### Fixed
- Ungrouped picker cards are no longer clipped/overlapping. Root cause: as
  DIRECT children of the panel's scroll container, Chromium sizes grid rows
  from the `.card` button's containment-affected intrinsic height
  (`container-type: inline-size`), cutting descriptions and thumbnails off —
  `align-items: start` alone did not cover it. Cards now always sit one
  nesting level below the scroller: ungrouped mode renders a single
  headingless group wrapper, mirroring the (working) grouped layout.
- Toggling the picker's "Group" checkbox no longer reloads every preview
  iframe: cards are moved atomically (`moveBefore()`, with an `appendChild`
  fallback), thumbnail sizing ignores the intermediate `about:blank` load,
  and all thumbnails are re-measured after the re-layout.
- Preview CSS/JS settings saved from the CP form are normalized to arrays
  within the same request.
- The repeated `folder/name` component-ID collapse is now case-insensitive.
