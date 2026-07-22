# Contributing

Thanks for your interest in Component Guide.

## Reporting issues

Open an issue at `TODO: <github-repo-url>/issues` with:

- Craft + PHP versions and the plugin version,
- your `componentPath` and a minimal story/template that reproduces the problem,
- what you expected vs. what happened (and any dev-mode error output).

## Security

Please report suspected security issues **privately** to `TODO: <security-contact-email>`
rather than opening a public issue. Note that PHP story files are trusted project
code by design; reports should concern the plugin overstepping that boundary
(e.g. scanning/rendering outside the configured directory, missing permission
checks, path/XSS leaks).

## Development

```bash
composer install
composer check   # PHPUnit + PHPStan
```

- Target PHP 8.2+, Craft 5, PSR-12.
- Keep controllers thin; put logic in `src/services`.
- Add/extend unit tests for scanner, parser and snippet-generator changes.
- Update `CHANGELOG.md` under the unreleased heading.

## Pull requests

Small, focused PRs with a clear description. Ensure `composer check` passes.
