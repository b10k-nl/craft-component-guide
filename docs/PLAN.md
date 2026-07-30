# Component Guide — plan

> The living, detailed planning notes are private; this document is the public
> summary: what the plugin believes in, where it stands, and where it is
> heading. For day-to-day changes see [CHANGELOG.md](../CHANGELOG.md).

## What this plugin wants to be

A Storybook-style component guide that lives **inside** the Craft control
panel — no separate Node toolchain, no second dev environment. It should make
the components a project already has *visible*: to developers (what exists,
how to call it) and to editors (what a block looks like before adding it).

## Principles

1. **Convention over configuration.** Story files next to templates, marker
   files instead of settings screens, folder structure as the source of truth.
   Everything lives in git alongside the templates it describes.
2. **A story is an upgrade, not the ticket in.** The guide must be useful on
   day one, before anyone writes a single story — marker files list what
   exists; stories add previews on top.
3. **Presentational-first.** Components that take plain values preview
   perfectly and reuse anywhere. The guide nudges toward that architecture
   (see “Recommended architecture” in the README) but works with what you
   have — including templates written against Matrix blocks.
4. **Zero lock-in.** Nothing in your content or templates depends on the
   plugin. Remove it and your site is exactly as it was.

## Where it stands

The current beta covers: recursive component discovery (PHP and Twig story
formats), marker-file inventory with hierarchy-aware groups, isolated previews
with the site's own CSS, a block gallery inside Live Preview, a story
scaffolder that drafts a first story from the template's variables, a
persistent self-invalidating scan cache, and CLI tooling. See
[BETA.md](../BETA.md) for how to try it.

## Direction

Roughly in order:

- **Dogfooding & beta feedback.** Real projects decide what's next; the list
  below is a bet, not a promise.
- **Page-builder awareness.** Map Matrix entry types to their components so
  the guide reflects what editors can actually add.
- **Deeper story automation.** Make the path from “undocumented” to “fully
  documented with realistic args” shorter and smarter.
- **Preview ergonomics.** Viewport presets, background samples, and
  interactive controls for story args.
- **Editor experience.** Tighter links between the guide, the block picker
  and Live Preview.

Feedback and ideas are welcome — see [BETA.md](../BETA.md) for where to send
them.
