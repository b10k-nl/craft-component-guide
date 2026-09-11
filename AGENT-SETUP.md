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

## Step 1 — Find the components, starting from the blocks

**Do not start from folder names.** On a real project `_components/` usually
holds shared partials — pagination, sidebar boxes, form fragments — while the
page-builder blocks, the half that reaches editors, sit one level down or in a
folder named after nothing in particular. Choosing by folder name reliably
finds the wrong half.

Start from the adapters, because they are findable mechanically:

```
grep -rn "block.type ==" templates/
grep -rn "\.type ==" templates/
```

A template that loops a Matrix field and switches on the block type is an
adapter, and **every template it includes is a presentational component** —
with its argument list written out at the include site. That is the
highest-value set on the project, and it arrives already documented.

Then, separately, look for folders of shared presentational partials. Common
names: `_components/`, `_blocks/`, `components/`, `blocks/`.

**More than one is normal, and supported.** Projects often separate
page-builder blocks from shared UI — `_blocks/` and `_components/` side by
side. Each folder gets its own marker file and becomes its own group in the
guide, and group names mirror the folder hierarchy.

So: list what you found — the block set first, named by the adapter that feeds
it, then the candidate folders with one line each on what each appears to hold
— and **ask which to document**: the blocks, one folder, several, or all. Do
not choose for the human, and do not document every folder that merely
contains Twig.

**One marker per component folder, not per subfolder.** A marker covers its own
folder and everything below it; plain subfolders become sub-groups
automatically (`Components / Cards`). Adding a marker to every subfolder
produces a mess of one-item groups.

## Step 2 — Decide what is a component

Document only **presentational** templates: ones that render markup from plain
variables passed in.

**One signal settles it, and it is not a judgement call.** If a template is
pulled in like this:

```twig
{% include 'path/to/thing.twig' with { heading: …, items: … } only %}
```

then `only` cuts off the surrounding context: that template physically cannot
reach anything except the variables listed at the include site. It is
presentational **by construction**, and that variable list is the argument list
your story needs. When you find this, stop reasoning and write the story.

**The adapter test is about fetching data, not about naming Craft.** A real
adapter runs element queries and walks fields: `.one()`, `.all()`,
`.eagerly()`, `craft.entries`, `block.someField.one()`, iterating a Matrix
field.

These do **not** make a template an adapter:

- `craft.app.request.getQueryParam(…)`, `craft.app.config…`, `|t`, `url()` —
  ambient calls that return null or a harmless default inside a preview
- a project helper called on a value that was handed in, such as
  `craft.myplugin.intToUid(thing.id, …)` — a story satisfies that with a plain
  hash: `thing: { id: 123 }`

So count what a template **fetches**, not how often the word `craft` appears. A
template that says `craft.` eight times and fetches nothing is a component, and
skipping it is the most expensive mistake you can make in this pass.

**The `_` prefix means nothing by itself.** Craft's own conventions put an
underscore on any template that should not be routed to, so on many projects
nearly every file has one. Count before you judge: if most files in the folder
start with `_`, the prefix carries no information — ignore it entirely. Only
where a folder mixes both, a few `_`-prefixed files among plain ones, does the
prefix mean "internal partial", and only there should you skip them.

Skip:

- `index.twig` and `undefined.twig` — dispatcher entry point and fallback
- true adapters, by the test above
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

**Write the paragraph as plain prose.** The guide prints it as text, so backticks,
asterisks and links appear as the characters you typed. Name a file or a handle
in plain words instead of marking it up.

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
`config/project/entryTypes/`, look for an entry type whose **handle** matches
the template's base name. Matching **ignores case and separators**, so all of
these are the same name to the guide:

    inline-donation-form.twig
    inline_donation_form.twig
    _inline-donation-form.twig   ↔   handle `inlineDonationForm`
    inlineDonationForm.twig

If one exists, the component appears in the editors' blocks gallery, and its
story is what fills the card.

If two templates in the same guide have names that are the same once normalised
— `hero-card.twig` and `heroCard.twig` — neither is offered, and the guide says
so on both cards. Report the pair; renaming one is a human's call.

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
2. The adapters you found, and which presentational template each block type
   is handed to — the map a human cannot get from the file tree.
3. Components matched to an entry type — and components that look like
   page-builder blocks but have **no** matching handle. That second list is the
   most useful thing you can hand over.
4. Files you skipped, and why.
5. Anything you were unsure about.

Then tell the human, in these words or close to them:

> Open the control panel → **Component Guide**. Every component now has a
> preview rendered from its own template. Look at them: the ones that render
> wrong have a story with wrong arguments, and fixing the story is a two-line
> edit. When a component looks right, change its status to `stable` — that is
> what puts it in front of editors.

That review pass is the whole point. You produced drafts; the previews are how a
human checks them in minutes instead of reading a large diff.

### Report failures, never a pass rate

You may render your stories to find mistakes, and you should report every error
you find — with the file and the message. **Do not report how many rendered
cleanly.** “34 of 34 render without error” reads as verification, and it invites
someone to skip the review pass that is the entire point of this file.

It would also be a claim you cannot make. Your render and the control panel's
are not the same request, and a template can compile in one and fail in the
other — a Twig filter or function from another plugin may be registered for
site requests only. So say what broke and stay silent about the rest.
