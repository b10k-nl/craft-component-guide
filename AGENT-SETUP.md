# Setting up Component Guide with a coding agent

This file is written for a coding agent, not for a person. Point your agent at
it — "read AGENT-SETUP.md in this package and follow it" — and it will document
a component library that already exists: one marker file, one story per
component.

A human then reviews the result **in the guide, on the rendered previews**, and
promotes what is correct. Nothing here is meant to run unattended.

---

## Before you start

Read these sections of `README.md` in this package. They are the source of truth
for the format; this file only tells you how to apply it.

- **Story format**
- **Directory conventions**
- **Marker files: components without stories**
- **Placeholder tokens**, including *What to tokenise — and what not to*
- **Recommended architecture: adapters + presentational components**

If anything below contradicts the README, the README wins. Say so in your report
rather than guessing.

---

## Step 1 — Find the component folders

Look under the project's `templates/` directory for folders holding
presentational components. Common names: `_components/`, `_blocks/`,
`components/`, `blocks/`.

**More than one is normal, and supported.** Projects often separate
page-builder blocks from shared UI — `_blocks/` and `_components/` side by
side. Each folder gets its own marker file and becomes its own group in the
guide, and group names mirror the folder hierarchy.

So: list every candidate you found, say in one line what each appears to hold,
and **ask which ones to document** — one, several, or all. Do not choose for
the human, and do not document every folder that merely contains Twig.

**One marker per component folder, not per subfolder.** A marker covers its own
folder and everything below it; plain subfolders become sub-groups
automatically (`Components / Cards`). Adding a marker to every subfolder
produces a mess of one-item groups.

## Step 2 — Decide what is a component

Document only **presentational** templates: ones that render markup from plain
variables passed in.

Skip:

- files whose name starts with `_` — internal partials, deliberately excluded
- `index.twig` and `undefined.twig` — dispatcher entry point and fallback
- **adapters** — templates whose job is to read Craft entry and field objects
  and hand plain variables to a component. An adapter usually mentions
  `entry.`, `block.`, `.one()`, `craft.` or field handles; a presentational
  component should not.
- anything under `node_modules`, `vendor`, or a cache folder

If you cannot tell whether a file is a component or an adapter, list it in your
report as uncertain and leave it alone.

## Step 3 — Write the marker file

Create `GUIDE.md` in the component folder (use `BLOCKS.md` or `COMPONENTS.md`
only if one already exists there).

```md
# Content Blocks

One sentence on what this folder holds and who uses it.
```

- The **H1** becomes the group label in the guide.
- The **paragraph below it** becomes the group description.
- Everything after the next heading is ignored by the guide — free space.

Keep it to those two lines unless the folder genuinely needs more. Do not invent
history, ownership or roadmap.

## Step 4 — Write one story file per component

**First, ask which format to use.** Stories come in two languages with the same
shape — Twig (`*.stories.twig`) and PHP (`*.stories.php`). Ask the human which
they want, and **ask it together with the Step 1 question, in one message**, so
you interrupt once rather than twice.

If they have no preference, two things decide it:

- **If the project already has story files, match them.** Do not mix formats
  inside one folder.
- **Otherwise use Twig** — it is the language the templates are already in.

The README section *Story format* has the exact shape of both. Follow it there;
the examples further down this file are Twig, and translate directly.

For `hero.twig`, write `hero.stories.twig` — or `hero.stories.php` — next to it.

Read the component template first and collect every variable it uses, including
ones guarded with `?? null` or `?? []`. Those guards tell you which arguments
are optional — useful when choosing which stories to write, so use it.

Then check whether this component reaches editors. In
`config/project/entryTypes/`, look for an entry type whose **handle** exactly
matches the template's base name (`hero.twig` ↔ handle `hero`; matching is
case-sensitive). If one exists, the component appears in the editors' blocks
gallery, and its story is what fills the card.

**Never rename a template or an entry type to make them match.** If a component
looks like a page-builder block but has no matching handle, put it in your
report and move on. That decision belongs to a human.

When a matching entry type does exist, read its field layout: the field types
tell you the shape of the real arguments (plain text → string, assets → image
URL, matrix or entries → list of objects). Use it to get the argument shapes
right instead of guessing from variable names.

---

## Content rules

The README section *What to tokenise — and what not to* is the rule. In short:

**Use placeholder tokens** for anything that carries no information —
photography (`@image_1600x600`), icons (`@icon_star`), filler prose
(`@lorem_p_2`), the third and fourth items of a list. Tokens are deterministic,
so previews do not flicker between renders.

**Write real copy** where the story asserts something: the flagship state, a
heading long enough to wrap, a label that nearly overflows, a card with no
image.

Do not fill a whole story with lorem. A preview full of lorem proves the
component renders; it does not show whether it works.

## States

If a component switches on a variable — `theme`, `variant`, `size`, `layout`,
`inverted` — write **one story per state**, named after the state:

```twig
{% set stories = {
    'Light': { args: { theme: 'light' } },
    'Dark':  { args: { theme: 'dark' } },
} %}
```

Also worth a story of its own: the shortest useful form, with optional arguments
omitted, and any edge case the guards in the template hint at.

Do not write more than four stories for one component without a reason. More is
noise.

## Status — always `draft`

Every story you write gets `status: 'draft'`.

This matters. The statuses are `stable`, `beta`, `draft` and `deprecated`, and
only `stable` — or no status at all — makes a component addable in the editors'
gallery. So after your pass:

- the **developer's** index is fully populated, with previews
- the **editors'** gallery stays quiet until a human promotes each component

That is the intended shape. A generated story that claims `stable` is a claim
nobody checked, which is precisely the failure this plugin exists to prevent.
Never promote a status yourself.

---

## Hard rules

- **Do not modify component templates.** If a component looks broken, report it.
- **Do not touch `config/project/`** or any Craft configuration.
- **Do not rename anything.**
- **Do not commit, tag or push.** Leave the working tree dirty; the diff is the
  point.
- **Do not delete or rewrite an existing `*.stories.twig`.** If one is already
  there, leave it alone and note it in your report.

## When you are done

Report, in this order:

1. Components documented, with counts.
2. Components matched to an entry type — and components that look like
   page-builder blocks but have **no** matching handle. That second list is the
   most useful thing you can hand over.
3. Files you skipped, and why.
4. Anything you were unsure about.

Then tell the human, in these words or close to them:

> Open the control panel → **Component Guide**. Every component now has a
> preview rendered from its own template. Look at them: the ones that render
> wrong have a story with wrong arguments, and fixing the story is a two-line
> edit. When a component looks right, change its status to `stable` — that is
> what puts it in front of editors.

That review pass is the whole point. You produced drafts; the previews are how a
human checks them in minutes instead of reading a large diff.
