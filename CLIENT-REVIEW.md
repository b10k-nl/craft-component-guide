# Client review — a developer's guide

How to hand a sprint's blocks to a client for sign-off with Component Guide,
deal with what they ask for, and get their approvals into git. The reference
is the *Client review* section of the [README](README.md); this is the
workflow.

*Pro. Off until you turn it on.*

---

## What it gives you

On staging, under each block's preview, your client either **approves a
story** — the block exactly as that story shows it — or **asks for changes**,
with a comment. Every story is then in one of three states, the same for
everyone who opens the guide:

| State | Meaning |
|---|---|
| **To review** | nobody has decided yet — or the block changed after the last decision |
| **Approved** | someone with the review permission approved it |
| **Changes requested** | someone asked for changes |

The approvals end up in your story files in git, with who and when, so
production knows which blocks were signed off without anyone reviewing there.

Three rules carry the whole thing:

1. **The latest decision decides, whoever made it.** If the client approved a
   story and you spot a problem, you ask for changes and the story is
   *Changes requested* for everyone. When the client approves the fixed
   version, it is *Approved* again.
2. **Decisions are never withdrawn.** Each one is kept in the story's
   **History**, with who and when. Nobody can later say they never approved a
   block that went live. Only a slip of the mouse can be taken back: right
   after **Approve** or **Request changes**, a notification offers **Undo** for
   a few seconds.
3. **A decision is about the block as it was.** Each one remembers the block's
   template and the story's args. Change either and the story goes back to
   *To review*.

---

## 1. Turn it on

**Settings → Plugins → Component Guide → Client review → Enable Client
Review**, or in `config/component-guide.php`:

```php
return [
    'enableClientReview' => true,
];
```

The setting is saved in project config, so it reaches staging and production
with your next deploy, like any other plugin setting. With it off there is no
review permission, nothing on the component page, no review filter or counts,
and nothing in the editors' blocks gallery.

## 2. Give the client an account on staging

1. **Users → User groups → New group**, e.g. *Client*, with:
   - **Access the control panel**
     - **Access Component Guide**
       - **Approve blocks or ask for changes (client review)**

   Nothing else is needed: no access to entries, assets or settings.
2. Create the client's user in that group. Their **full name** is what
   everyone sees next to their decisions; their **username** is what goes into
   the story files.

> With Craft's `useEmailAsUsername` on, the username *is* the email address,
> and it will be written into your repository. If that matters, turn it off or
> give client accounts a plain username.

Your own admin account can review too: admins have every permission.

## 3. Get the blocks ready

What the client approves is **a story as you wrote it**, so the stories are
the handover.

- **One story per case worth signing off**: *Light* and *Dark*, *with image*
  and *without*. If the client should see a long heading, write a story with
  one.
- **Mark the blocks stable.** A draft or deprecated block cannot be approved
  (changes can still be requested on it). The status toggle on the block's
  page, or `status: 'stable'` in the story file.
- **Write stories with `args:`.** The approval is added next to it, as
  `approval:`. A story written as bare args gets the line printed for you to
  add by hand.
- Deploy to staging.

### Start from a clean slate

Tried it yourself first? Clear your trial runs before the client starts, so
their history begins with them. In the control panel this needs **Enable
History Clearing** in the plugin settings — off by default. To allow it on
staging and never on production, choose an environment variable there, e.g.
`$CG_REVIEW_CLEARING`, and set `CG_REVIEW_CLEARING=true` in staging's `.env`
only.

Then:

- one block — **Clear this block's review history…** under its Client review;
- everything — **Clear review history…** in the sidebar, under Export;
- or from the command line, whatever the setting: `php craft
  component-guide/reviews/clear [--component=blocks-hero]`.

Admins can, and so can anyone you give **Clear the client review history**
(beside the review permission, under *Access Component Guide*) — a developer
without an admin account, say. That permission is listed only where clearing
is on. With the environment variable set on staging alone, give it to the
person on staging (**Users → … → Permissions**); a user group saved where
clearing is off — locally, say — loses it, and the next deploy takes it off
staging too. It asks first, deletes every decision there by everyone, and is
logged with who and how many. It cannot be undone — export first if you may
need them. Approvals already in your story files stay; they are code. Once
the client is reviewing, leave the history alone: it is what you point to when
someone remembers it differently.

## 4. What to send the client

Something like:

> Open *Component Guide* in the control panel. For each block, look at the
> preview — try the device buttons above it. Under **Client review**:
>
> - if it is right, click **Approve this story**;
> - if not, say what should change and click **Request changes**.
>
> You can try other texts, choices or pictures in **Controls**. They are not
> saved, but if you ask for changes they are sent along, so we see what you
> tried. To approve, reset them — only the story as we wrote it can be
> approved.
>
> The sidebar filter **To review** shows what is left.

## 5. Work through the requests

The sidebar marks **✎** where changes are requested; the review filter
**Changes requested** and the count on the index list them. A block counts as
approved only when every one of its stories is; one approved story of two
leaves it *To review*.

On a block's page you see the comment and, if the client tried other values,
**Show the values tried** puts them back in the preview. From there **Copy as
story** in Controls gives you the story to paste, if the case deserves one.

Then fix it:

- **In the block's template, or in the story's args** — commit and deploy to
  staging. The story goes back to *To review* by itself: *“the block has changed since, look at it
  again.”*
- **Somewhere the fingerprint cannot see** — a partial the template includes,
  the CSS — click **Mark as fixed** on the request (you need to be an admin).
  The story goes back to *To review* the same way. **Not fixed yet** takes it
  back.

The client looks again and approves, or asks again.

Beware the side effect of rule 3: changing a block's template sends **every**
story of that block back to review, approved ones included. That is the
point — the client approved a block that looks different now — but bundle
your fixes to a block rather than deploying them one by one. A change to one
story's args sends only that story back.

## 6. Take the approvals into git

On staging, as an admin: **Export client reviews** in the sidebar. You get one
JSON file, with the site and, if `CRAFT_ENVIRONMENT` is set there, the Craft
environment it came from — the import repeats them at the top, so you know whose decisions
you are reading. The button shows while there are decisions you have not
exported yet, and how many.

Where the story files are written — usually locally, on the same commit as
staging:

```sh
php craft component-guide/reviews/import --file=component-guide-reviews-staging.example.com-2026-10-30.json --dry-run
```

In DDEV the command runs in a container that cannot see `~/Downloads`; read
the file through standard input instead:

```sh
ddev craft component-guide/reviews/import --dry-run --file=- < ~/Downloads/component-guide-reviews-….json
```

For each story, the latest decision:

- **Approved** → one line in the story, nothing else in the file changes:

  ```twig
  'Light': {
      approval: { by: 'anna', date: '2026-10-30', version: '3f9c1a2b7d4e' },
      args: { … },
  },
  ```

  A later approval replaces it.
- **Changes requested** → listed with the comment and the values tried,
  numbered, so you can work down the list. If the
  story still has an `approval:` from earlier, it is taken out: it is no
  longer the latest word.
- **Approved on an earlier version** — the template or the story's args differ
  from what the reviewer saw →
  not imported. Either your checkout is not what staging runs, or the block
  changed after the approval; ask the client to look again.

If the output is what you expect, run it again without `--dry-run`, review the
diff, commit, deploy. Importing the same file twice changes nothing.

The command needs client review turned on where it runs too — it is, if your
local project config matches staging.

## 7. Production

Nobody needs to review on production. The approvals came with git:

- the guide shows those stories as **Approved**, with the approver's full name
  if they have an account there, otherwise their username;
- editors see an **approved** label on those cards in the blocks gallery;
- if a block's template or a story's args change after its approval, the story shows as *To
  review* there too — the `version` in the approval no longer matches.

If you want editors to be able to flag a block during content entry, give them
the review permission on production as well. Their requests show up for you
the same way, and the same export and import bring them home.

## 8. Where the data lives

- Decisions are kept in each reviewer's Craft **user preferences** (key
  `componentGuide.reviews`) — no table, no migration. At most 200 per person;
  past that the oldest go.
- **Deleting a user deletes their decisions** (Craft drops a user's
  preferences with the user). Export before you remove a client's staging
  account.
- **Uninstalling the plugin** removes every `componentGuide.*` key from user
  preferences. The `approval:` lines in your story files stay — they are your
  code.

---

## When something looks wrong

| You see | Why |
|---|---|
| **Approve this story** is greyed out | Values were changed in Controls — reset them. |
| There is no **Approve this story** button | The story is approved already, or the block is a draft or deprecated (the page says so). |
| No Client review section at all | The setting is off, or the site runs Lite. |
| The client has no Approve or Request changes buttons | Their group lacks *Approve blocks or ask for changes* — or *Access Component Guide* above it. |
| An approved story is suddenly *To review* | Its block's template, or the story's args, changed after the approval. |
| The import says *not imported — approved an earlier version* | Your template differs from the one the client saw. Same commit as staging? |
| The import prints a line to add by hand | The story has no `args:`, or the story file is PHP. |
| A name shows as a username | That user has no full name, or no account on this site. |
