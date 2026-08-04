# Component Guide — beta

Thanks for testing this. It's a Storybook-style component browser that lives
inside Craft's control panel: it scans your `templates/` folder, lists your
reusable Twig components and renders live previews of them.

This is an early beta — **please don't run it on a production site.** Local or
staging only. Free to use during the beta (see [LICENSE.md](LICENSE.md)).

## Requirements

- Craft CMS 5
- PHP 8.2+

## Install

```bash
composer config repositories.component-guide vcs https://github.com/b10k-nl/craft-component-guide.git
composer require b10k/craft-component-guide:0.1.0-beta.3
php craft plugin/install component-guide
```

On DDEV, prefix each line with `ddev` (`ddev composer config …`, and
`ddev craft plugin/install component-guide`).

If Composer asks for a GitHub token, swap the repository type for a plain git
clone, which skips the GitHub API entirely:

```bash
composer config --unset repositories.component-guide
composer config repositories.component-guide '{"type":"git","url":"https://github.com/b10k-nl/craft-component-guide.git"}' --json
```

To remove it afterwards: `composer remove b10k/craft-component-guide` and
`composer config --unset repositories.component-guide`. The plugin never writes
to your database beyond its own settings row, and only writes files when you
explicitly press “Add stories”.

## First five minutes

1. **Open “Component Guide” in the CP sidebar.** With nothing configured you
   get an onboarding panel. Is it clear what to do next? That's the single
   thing I most want feedback on.

   ![The onboarding panel shown before anything is configured](docs/images/onboarding.png)
2. **Make it list your components.** Drop a `GUIDE.md` file into the folder
   where your components or page-builder blocks live — every Twig template in
   it (and its subfolders) shows up immediately, no story files needed:

   ```sh
   printf '# Blocks\n\nPage-builder blocks.\n' > templates/_blocks/GUIDE.md
   ```

   The first heading becomes the section name, the text under it the section
   description.
3. **Add previews.** In dev mode each listed component has an **Add stories**
   button: it reads the template's variables and writes a first-draft
   `*.stories.twig` next to it. The values are guesses — open the file and fix
   them. Same thing from the CLI:
   `php craft component-guide/components/make <component-id>`.
4. **Make previews look real.** Settings → point `previewCss` at your compiled
   front-end stylesheet (e.g. `@web/dist/assets/app.css` or whatever your build
   emits). Without it previews render unstyled — that's expected, not a bug.
5. **Sanity-check from the command line:**
   `php craft component-guide/components/scan` prints everything it found.

The README covers the story formats, the recommended template architecture and
all settings in more detail.

## What feedback helps most

- **Discovery:** did it find the right templates, and miss or wrongly include
  anything? Component names and section names sensible?
- **Onboarding:** where did you get stuck or have to guess?
- **Scaffolder:** how close were the generated stories to usable?
- **Previews:** did they match the real front end once CSS was configured?
- **Speed:** how did it feel on a big templates tree?
- **Naming and wording:** anything confusing or off.

Rough edges are expected — I'd rather hear “this felt weird” than nothing.

## Known limitations

- Components without a story file are listed but not clickable (no detail page
  or preview yet).
- The story scaffolder is heuristic: it reads the template with regexes, so
  complex templates — especially ones that pull live data themselves — will
  need manual fixing.
- Components that query Craft or use Sprig internally may render partially in
  previews.
- No paid/free feature split yet; everything is enabled.

## Reporting

GitHub issues: <https://github.com/b10k-nl/craft-component-guide/issues>

Prefer to keep it private (client code, screenshots)? Message me directly —
that's just as welcome.
