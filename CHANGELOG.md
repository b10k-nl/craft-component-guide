# Changelog

All notable changes to Component Guide are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## 0.1.0 - Unreleased

Initial MVP (alpha).

### Added
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
- The persistent scan cache key now includes an internal schema version, so
  plugin updates that change the cached result's shape or semantics invalidate
  stale entries automatically.
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
