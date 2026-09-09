# Changelog

All notable changes to Component Guide are documented here. This project adheres
to [Semantic Versioning](https://semver.org).

## Unreleased

### Fixed
- **Previews are served from a site route now, not a control-panel one.** A CP
  request does not have the Twig extensions that other plugins register for site
  requests only — Formie's filters, Sprig, and plenty of project modules. A
  component template that used one of those did not merely misbehave: it failed
  to compile, because Twig resolves filters when it parses, so even a branch that
  never runs took the whole preview down with an “Unknown filter” error. The same
  cause left Sprig-backed components rendering their chrome and nothing else.
  The preview URL is now built in one place, so the index, the component page and
  the blocks gallery cannot drift apart, and the control-panel route stays
  registered for headless installs and for any URL somebody bookmarked. Found by
  running the plugin on a real client project, where five of thirteen components
  could not render at all.
- **A component whose preview throws now says so on its card.** The index showed
  nothing: a failed thumbnail was a pink rectangle and everything else about the
  card — the description, the story count, the “Mark stable” button — looked
  exactly like a healthy one. The error badge existed, but only for scan errors.
  The index cannot know on its own, because it never renders a story (the
  thumbnail frames do, in the browser, and rendering every story server-side
  would cost a full render per card on each page load), so each preview now
  reports its own outcome to the page that framed it and the card turns the
  badge on, with the message in its tooltip.

  Two things it deliberately does not do. It does not light up for a story that
  rendered nothing — that is a legitimate state for a component whose guards are
  doing their job, and a badge that cries wolf stops being read. And it does not
  block “Mark stable”: a render error can come from the environment rather than
  the story, and a gate that can be wrong is worse than silence.
- **The index no longer calls a component ready for editors when it has nothing
  to show them.** A story file that exists but fails to parse leaves the
  component “documented” — the file is there, which is what stops the scaffolder
  overwriting it — while holding no stories at all. Such a component was counted
  in “ready for editors” and chipped “in gallery”, both of which were untrue:
  the blocks gallery had no preview and no prefill for it and rendered it as a
  bare title bar, the same as any block type the project never documented. The
  developer was told the handoff had completed when it had not. Appearing in the
  gallery now means a matched entry type **and** at least one story that actually
  parsed, decided in one place so the index and the gallery cannot disagree.

## 1.2.1 - 2026-09-07

### Fixed
- **The agent recipe pointed at the wrong folder.** A dry run on a real project
  documented a folder of shared partials — pagination, sidebar boxes, form
  fragments — and left the page-builder blocks alone, which is the half that
  reaches editors. Two rules were at fault. Step 1 asked which folder holds
  components, and that is a question projects answer with a folder name; it now
  starts from the adapters instead (the templates that switch on a block type),
  because every template an adapter includes is a presentational component with
  its argument list already written out at the include site. Step 2 treated any
  mention of `entry.`, `block.` or `craft.` as proof of an adapter, and so
  rejected six templates that an adapter already feeds with plain variables —
  their `craft.` calls were a query parameter, a config value and a helper
  called on an id that a story can supply. The test now asks what a template
  *fetches*; `{% include … only %}` is stated as proof that a template is
  presentational, since `only` cuts off the surrounding context and leaves it
  nothing else it could be; and a leading underscore is read in context —
  where nearly every file in a folder carries one, the prefix carries no
  information and skipping those files documents nothing.
- The recipe's report format now asks which presentational template each block
  type is handed to. That map is the part a human cannot get from the file tree.
- **The recipe no longer lets an agent report a pass rate.** One reported “34 of
  34 render without error” and the control panel disagreed on five of them: an
  agent's render and the control panel's are not the same request, and a
  template that compiles in one can fail in the other when a Twig filter from
  another plugin is registered for site requests only. A number like that reads
  as verification and invites skipping the review pass the whole recipe exists
  to set up. Errors get reported with file and message; silence covers the rest.
- **Marker descriptions are prose now, not Markdown.** The guide prints the
  paragraph as text, so backticks and asterisks showed up as characters. The
  recipe says to write it plainly.

### Added
- **The control panel names the recipe.** 1.2.0 shipped it and then never
  mentioned it: the empty-state panel described the one-button path only, and
  that panel is gone exactly when a folder is large enough for the recipe to
  matter. It is named in the panel now and, separately, as a hint above the list
  whenever more than three components have no story. Both carry the one line to
  hand an agent.

## 1.2.0 - 2026-09-07

### Added
- **Status toggle in the control panel.** A documented component that is `draft`
  gets a “Mark stable” button on its index card; a `stable` one gets “Back to
  draft”. Only those two: `beta` and `deprecated` express a developer's
  lifecycle decision and stay in the IDE. The edit is surgical — the one
  `status` entry inside `meta` changes, every other byte of the story file is
  left as written — and it goes through the same two gates as “Add story”
  (`allowAdminChanges`, writable templates directory), so it does not appear on
  read-only environments. Exists because a scaffolder or a coding agent can
  leave forty drafts behind, and promoting each by opening a file is the kind
  of chore that makes help feel like more work. Posts over fetch and repaints
  the card in place: a reload would throw a reviewer back to the top of the
  list after every single decision, which at forty components is the whole
  cost of the feature. The card is now a box with a stretched overlay link
  rather than one large `<a>`, so it can hold real buttons and still open the
  component when clicked anywhere else.
- **Status toggle on the component page too** — next to the status chip, where
  a reviewer is already looking at the preview when they decide. The index is
  for the obvious ones; this is for the ones you had to open.
- **“Changed from the control panel” banner.** The CP has no `git status`, so
  it now says what it wrote itself: every scaffold and every status change is
  journalled (in runtime storage, never in the repository), and the index lists
  the files until they have been dealt with. The journal verifies its own claim
  on every read and drops an entry the moment there is doubt — the file's hash
  no longer matches what was written (edited or reverted since), `.git/index`
  is newer than the file (committed since), or a person clicked “Reviewed”. A
  warning that can be wrong errs towards silence: a banner that lies twice is a
  banner nobody reads.
- **`AGENT-SETUP.md`** — a setup recipe written for coding agents, shipped in
  the package root so it is on disk after `composer require`. Point any agent at
  it and it documents an existing component library: one marker file, one
  `draft` story per component, a report of blocks with no matching entry-type
  handle. It does not duplicate the story format (it points at the README) and
  never commits; the human reviews the result on the rendered previews and
  promotes with the new toggle.

## 1.1.2 - 2026-08-25

### Changed
- Scaffolded stories now include `group`, empty. The key has always worked and
  the README documents it, but the scaffolder never wrote it — so a developer
  working from a generated file had no way to learn the option exists, and the
  three keys it did write (`title`, `description`, `status`) read as the whole
  vocabulary. It is emitted empty rather than filled: the parser trims and drops
  empty values, so an empty group is identical to no group at all and the
  component keeps inheriting from its folder hierarchy or marker file. A real
  group written into every scaffold would freeze that inheritance, and renaming
  a marker's H1 would stop moving anything under it. The generated file now
  shows the full meta vocabulary with the one optional key sitting where you
  would type it.

## 1.1.1 - 2026-08-24

### Fixed
- The story-file hint on undocumented cards no longer breaks words mid-letter
  (“the” rendering as “t / he”). `word-break: break-all` was set on the whole
  card meta line so long template paths would wrap; it now applies only to the
  path itself, which is the part that needs it.

### Changed
- The index intro is one short sentence again. It named the entry-type matching
  rule, the gallery and both places editors meet it — four lines of prose before
  anything actionable. The rule is now reported by the page itself (the
  “ready for editors” count, the **in gallery** chips, the hint on story-less
  cards), so the prose no longer has to teach it.

## 1.1.0 - 2026-08-24

### Added
- **The index now reports what editors actually get.** A component reaches the
  blocks gallery only when a Matrix entry type carries its template's base name
  as a handle — a rule that lived in the picker's JavaScript and was invisible
  from the control panel, so a mismatched name made the gallery silently empty
  with nothing to explain it. The index now counts the components that are
  documented, stable and matched (“N ready for editors”), marks each one **in
  gallery** with the block name it becomes, and tells a story-less template
  that a story would also buy it a card in the gallery. The count is hidden
  entirely on projects where no component matches an entry type, so a guide
  that was never about page-builder blocks doesn't display a permanent zero.
- Component pages say the same thing in one line, including the inverse: a
  component marked `draft` or `deprecated` states that the gallery is showing
  editors that block as unavailable.

### Fixed
- **Scaffolding on read-only hosting no longer lies about why it failed.**
  With `allowAdminChanges` on but a read-only deployed filesystem — Craft
  Cloud, containers, some managed hosts — the **Add story** button appeared,
  the write failed, and the message read “check filesystem permissions”,
  sending the developer after a `chmod` that does not exist. The button is
  now hidden wherever the templates directory isn't writable, with the same
  stated-reason notice the `allowAdminChanges` gate already used, and the
  scaffolder itself says the directory is read-only and that the story should
  be scaffolded locally and committed. Found on Craft Cloud, 24.08.2026.

### Changed
- The intro and onboarding copy name the second half of the plugin: a story
  file buys previews here *and* a card editors click to add the block.
- New `GalleryMatcher` service, now the single source of the entry-type
  matching rule; the picker endpoint and the control panel share it instead of
  keeping two copies that could drift apart.

## 1.0.1 - 2026-08-18

Packaging and metadata only — nothing about the plugin's behaviour changes.

### Changed
- The distributed package no longer ships tests, docs, screenshots or static
  analysis config, so installing it downloads roughly 700 KB less. The
  repository is unchanged; this only affects what Composer pulls down.
- `composer.json` gained discovery keywords (`matrix`, `matrix blocks`,
  `page builder`, `blocks`, `block preview`, `live preview`,
  `authoring experience`) so the package is findable by the words people
  actually search for rather than only the ones that describe it.
- The bundled README now documents installing from the Plugin Store first;
  the `1.0.0` tag still carried the pre-release instructions.

## 1.0.0 - 2026-08-17

First stable release. The discovery and preview workflow has run on real
Craft 5 projects through seven public betas; this release marks the API stable
and the plugin production-ready. Everything below landed since `0.1.0-beta.3`.

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
