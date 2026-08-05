---
name: architecture-records
description: "Use when writing, renaming or reviewing an ADR or change document in this repository: how the tracking-number identifier works when issues are disabled, what the validator enforces, and why the index is never committed."
compatibility: "Python 3 standard library only. No network, no dependencies. Run with `make architecture-check`."
---

# Architecture records in this repository

## When to use

Before creating a file under `docs/architecture/`, before renaming one, and when
`make architecture-check` fails and the message is not self-explanatory.

## The identifier, and the one thing people get wrong

A record is named after the **GitHub tracking number** of the change it belongs
to:

```text
docs/architecture/adr/ADR-<number>-<NN>-<decision-slug>.md
docs/architecture/changes/<number>-<change-slug>/
```

**There is no global counter.** Do not look at the existing records and pick the
next integer. That rule is what this convention replaces: every branch evaluates
it against its own working tree, two branches pick the same number, and because
the collision is in the *filename* Git merges both files cleanly and reports
nothing.

**Issues are disabled on this repository:**

```console
$ gh api repos/exelearning/nextcloud-exelearning -q .has_issues
false
```

So the tracking number is always a **pull request** number. Do not try to open an
issue to get one — you cannot, and you should not want to.

### The chicken-and-egg case

A record written before its PR exists has no number yet. The sequence is:

1. Write the record with a placeholder name.
2. Push the branch and open the PR.
3. Rename the file to the PR's number, set `id` and `tracking_issue`, fix the H1.
4. Run `make architecture-check`.

One rename, before review. That is the cost of not having a counter, and it is
cheaper than a silent collision.

### The two-digit sequence

`<NN>` is scoped to that tracking number **only**, starts at `01`, and is present
even when a change has a single ADR. That last part matters: it means adding a
second decision later never renames the first one, so inbound links stay valid.

`ADR-42-01` and `ADR-43-01` coexist happily. Two `ADR-42-01` do not.

## What the validator enforces

`make architecture-check` runs `tools/architecture_records.py`. It fails on:

| Category | Examples |
|---|---|
| Grammar | filename not `ADR-<n>-<NN>-<slug>.md`; slug not kebab-case; leading zeros; retired `ADR-NNNN` form |
| Agreement | `id` ≠ filename; `tracking_issue` ≠ filename; H1 ≠ `# <id>: <title>` |
| Uniqueness | duplicate `id`; duplicate sequence within one number |
| Vocabulary | ADR status outside `Proposed/Accepted/Rejected/Superseded`; change status outside the lowercase set |
| Shape | non-calendar dates; non-integer issue/PR references |
| References | `related.adrs`, `related.changes`, `related_adrs` that resolve to nothing |
| Supersession | one-sided `supersedes`/`superseded_by`; a superseded record not set to `Superseded` |
| Hygiene | a retired identifier anywhere in the tree; a committed `records.md` |

Run its own tests with `make architecture-test` (Python `unittest`, no
dependency).

## The index is not a file

There is no `records.md`. Print it:

```bash
make architecture-records
```

It is derived entirely from frontmatter. Committing it would guarantee a merge
conflict on every concurrent branch — the exact problem this convention removes —
so the validator treats its presence as an error.

If you want a rendered index somewhere, generate it at that moment. Do not
reintroduce the file.

## Status is frontmatter-only

Never add a `## Status` section. It duplicates `status:` and drifts from it. One
canonical source per mutable field; the validator has no way to catch a stale
prose heading, which is precisely why the heading is banned rather than checked.

## ADR or change document?

- **ADR** — a durable *decision*, append-only. "We will serve published content
  from an opaque iframe."
- **Change document** — the *design* for a unit of work, historical once shipped.
  "Here is how the opaque viewer is built, tested and rolled out."

A change usually contains several ADRs. Extract the decisions that will outlive
the feature; link them with `related_adrs`. Do **not** create one ADR per section
of a design — that is the most common failure mode, and it produces records
nobody can cite.

## What deserves an ADR here

Decisions about the Service Worker scope and the iframe sandbox; the editor
`postMessage` contract; whether `.elpx` packages stay opaque; entry-path
normalization as the security boundary; the Nextcloud integration surface
(controllers, preview provider, OCP usage); release packaging and what ships.

Not: bug fixes restoring intended behaviour, routine refactors, dependency bumps,
or anything with no cross-cutting consequence.

## Superseding

Accepted records are append-only. To change one:

1. Write a new ADR under the number that motivates the change.
2. New record: `supersedes: [ADR-<old>]`.
3. Old record: `status: Superseded` and `superseded_by: [ADR-<new>]`.

Both directions are required — CI rejects a one-sided relationship, and it also
rejects a superseded record left at `Accepted`.

## Common failures and their fix

| Message | Fix |
|---|---|
| `uses the retired global numbering` | Rename to the tracking-number form. |
| `frontmatter id "X" does not match filename` | The filename is authoritative; fix `id`. |
| `H1 is "…" but should be "…"` | The H1 mirrors `id` and `title` exactly, including punctuation. |
| `references unknown ADR` | The target does not exist on this branch. Fix or drop it. |
| `the record index must not be committed` | `git rm` the `records.md`. |
| `references retired identifier` | Use the current identifier. |
