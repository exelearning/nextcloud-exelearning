# Architecture Decision Records

## Purpose

An **Architecture Decision Record (ADR)** captures a single durable architectural
decision together with the reasoning behind it: the context, the problem, the
options considered, the evidence, the decision itself, and its consequences.

ADRs exist so that contributors — human and AI — can answer *"why is it built
this way?"* years later without archaeology through pull request threads. A
decision recorded only in a PR description is easy to lose.

This repository had no decision records when the convention was introduced, so
there is nothing to migrate: it starts on the convention rather than adopting it
later. The identifier model matches the rest of the eXeLearning ecosystem
(see [`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232)).

## Identification

Records are identified by the **GitHub tracking number** of the change they
belong to — the **issue** when there is one, and the **pull request** otherwise.

GitHub allocates issue and pull-request numbers from a single repository-wide
sequence, so the two can never collide; in GitHub's data model a pull request
*is* an issue, which is why `/issues/<n>` resolves to a PR.

> **Issues are disabled on this repository**, so in practice every tracking
> number here is a pull request number. Verify with
> `gh api repos/exelearning/nextcloud-exelearning -q .has_issues`.

There is **no global counter**. Never compute "the next free number" — that rule
is unsafe on parallel branches, because every branch evaluates it against its own
working tree and the resulting collision is a *filename* collision that Git
merges cleanly without reporting anything.

### Filename

```text
ADR-<tracking-number>-<local-sequence>-<decision-slug>.md
```

```text
ADR-42-01-serve-published-content-from-an-opaque-iframe.md
ADR-42-02-relay-external-media-through-the-trusted-parent.md
```

### Rules

- `<tracking-number>` has no leading zeros.
- `<local-sequence>` is two digits, scoped **only** to that tracking number,
  starting at `01`. It is present even when a change has a single ADR, so adding
  a second one later never renames the first.
- A local sequence is never reused within the same tracking number, even if a
  record is rejected or removed.
- `<decision-slug>` is lowercase kebab-case and names the **decision**, not the
  topic. `serve-published-content-from-an-opaque-iframe` is a decision;
  `published-content` is a topic.
- Frontmatter `id` must equal `ADR-<number>-<sequence>` and `tracking_issue`
  must equal the number. The field keeps the name `tracking_issue` because
  GitHub models a pull request as an issue; it holds whichever number identifies
  the change. CI enforces both.
- The H1 must be exactly `# <id>: <title>`.
- Identifiers are **stable**. If a change that started as a pull request later
  gains an issue, keep the original identifier.
- Do not open an issue merely to obtain an identifier — here you could not
  anyway.

### The chicken-and-egg case

A record written before its pull request exists has no number yet. Push the
branch, open the PR, then name the record with the PR's number. That is one
rename before review, and it is why the local sequence exists: the number is the
only part that is unknown up front.

## Status values

| Status | Meaning |
|---|---|
| `Proposed` | Under discussion; not yet agreed. |
| `Accepted` | Agreed and in force. |
| `Rejected` | Considered and declined. Kept for the record. |
| `Superseded` | Replaced by a later ADR (see `superseded_by`). |

Status lives in the frontmatter **only**. Do not add a `## Status` section
repeating it — one canonical source per mutable field.

## Canonical metadata

| Field | Required | Holds |
|---|---|---|
| `id` | yes | the identity; must match the filename |
| `title` | yes | the title; mirrored by the H1 |
| `status` | yes | lifecycle state |
| `date` | yes | `YYYY-MM-DD` |
| `tracking_issue` | yes | the GitHub number that owns the decision |
| `deciders` | yes | who decided |
| `reviewers` | no | who reviewed |
| `related.prs` | no | implementation / review traceability |
| `related.changes` | no | change directories this decision belongs to |
| `related.adrs` | no | sibling decisions |
| `supersedes` / `superseded_by` | no | decision history |
| `ai_assistance.tool` / `.model` | yes | provenance (`none` if unused) |
| `legacy_id` | only if a record is ever renamed | the retired identifier |

`related.prs` is a traceability *list*. The identifier is the single stable
number in `tracking_issue`.

## When an ADR is required

Write one when a change introduces or modifies a **durable** decision that future
contributors should not have to re-litigate. In this app that includes:

- how published content is isolated and served (sandboxing, CSP, opaque origins);
- the embedding contract with the eXeLearning editor (`postMessage`, capability
  handshakes);
- storage of published packages and their assets;
- Nextcloud integration boundaries (app framework usage, OCP surface, migrations);
- release packaging and what ships in the distributed app.

Do **not** write one for bug fixes that restore intended behaviour, routine
refactors, dependency bumps, or purely local implementation details. Do not
create one ADR per section of a design, and do not create empty ADRs to fill a
gap — the sequence is expected to have gaps.

## Evidence

Every technical claim should cite a verifiable source: a repository path plus
commit, official documentation or a specification, a benchmark or reproducible
experiment, or a linked PR, change document or prior ADR.

## AI assistance

```yaml
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
```

Set both to `none` if no AI tool was involved. This records how the document was
produced so the evidence can be weighed, not to pass judgement.

## Superseding

Accepted ADRs are append-only. Do not rewrite them except for typos or broken
links. To change an accepted decision:

1. Write a new ADR under the tracking number that motivates the change.
2. Set `supersedes: [ADR-<old>]` in the new record.
3. Set `status: Superseded` and `superseded_by: [ADR-<new>]` in the old one.

CI rejects a one-sided relationship: both directions must be present, and a
superseded ADR must carry `status: Superseded`.

## The index is not a file

There is **no committed index**. It is derived entirely from frontmatter, and a
generated file in version control conflicts on every concurrent branch — the very
problem this convention removes. Print it on demand:

```bash
make architecture-records
```

CI fails if a `records.md` is ever committed.

## Workflow

1. Identify the change's tracking number (its PR, since issues are disabled).
2. Copy [`template.md`](template.md) to `ADR-<number>-<NN>-<decision-slug>.md`,
   `<NN>` being the next free sequence **for that number only**.
3. Fill in context, problem, options, evidence, decision, consequences. Start at
   `status: Proposed`.
4. Run `make architecture-check`.
5. Reviewers discuss; on approval the status becomes `Accepted`.

## Review checklist

- [ ] The filename uses the change's tracking number, and `<NN>` starts at `01`.
- [ ] The slug names the decision, not the topic.
- [ ] `id` matches the filename; `tracking_issue` matches the number.
- [ ] The H1 is `# <id>: <title>`.
- [ ] Context, problem, options, decision and consequences are all present.
- [ ] Every technical claim cites a verifiable source.
- [ ] Positive, negative and neutral consequences are stated honestly.
- [ ] `status` appears only in the frontmatter.
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] `make architecture-check` passes.
