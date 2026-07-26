# Changelog

All notable changes to Component Guide are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## 0.1.0 - Unreleased

Initial MVP (alpha).

### Added
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

### Changed
- Plugin components are wired explicitly, guaranteeing Twig story support
  (`*.stories.twig`) is always active.
- Component lookups by ID are indexed instead of linear scans.
- The preview document rendering is shared between the web controller and the
  CLI via `PreviewRenderer::renderDocument()`.
- The Matrix picker's DOM observer coalesces mutation bursts into a single
  scan per frame, reducing overhead on busy CP pages.

### Fixed
- Preview CSS/JS settings saved from the CP form are normalized to arrays
  within the same request.
- The repeated `folder/name` component-ID collapse is now case-insensitive.
