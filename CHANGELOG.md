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
- Unit tests for the scanner, story parser and snippet generator; PHPStan level 5.
