---
id: ADR-93-01
title: "Identify architecture records by GitHub tracking number"
status: Proposed
date: 2026-08-05
tracking_issue: 93
deciders:
  - "@erseco"
reviewers:
  - "@erseco"
related:
  prs: [93]
  changes: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-93-01: Identify architecture records by GitHub tracking number

## Context

This app had no architecture decision records. Decisions that clearly deserve one
— the Service Worker scope, the sandboxed iframe, package opacity, entry-path
normalization as the security boundary — are today described only in prose in
`AGENTS.md` and `DEVELOPMENT.md`, where they read as rules without the reasoning
that produced them.

The sibling repositories reached this point already carrying a globally
sequential four-digit counter whose next value was computed as
`max(existing) + 1`. In the main repository that rule failed measurably: across
`main` and 13 open pull requests, 16 identifiers were claimed by more than one
branch, one of them by six ([`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232)).
The failure is silent, because the collision lands in the *filename* and Git
merges two differently named files without reporting anything.

Starting from zero records is the cheapest possible moment to pick a convention.

## Problem

How should architecture records be identified here, given that this repository
has **no issue tracker** — issues are disabled — and that any identifier scheme
must be safe to allocate from independent branches without coordination?

## Decision drivers

- Identifiers must be allocatable on a branch with no central coordination.
- They must be short enough to cite from a code comment or a review.
- They must be stable once published: renaming breaks inbound links.
- One change may produce several decisions.
- The scheme must work with **issues disabled**.
- No new runtime dependency for the tooling: this is a Nextcloud app, not a
  documentation project.

## Options considered

### Option 1: A local sequential counter

A zero-padded four-digit number per record, incremented by hand.

- **Pros:** short, dense, familiar.
- **Cons:** the rule that already failed at scale in the main repository. Two
  branches pick the same number and nothing detects it. Rejected on evidence.

### Option 2: Dates plus slugs

`2026-08-05-serve-preview-from-opaque-iframe.md`.

- **Pros:** no contention; honest ordering.
- **Cons:** no short stable ID to cite; two records on one day still need a
  tiebreaker. Kubernetes used a date form and moved away from it.

### Option 3: UUIDs

- **Pros:** collision-free by construction.
- **Cons:** uncitable. `ADR-01J8XQ3M…` cannot be used in a review conversation.

### Option 4: GitHub tracking number

`ADR-<number>-<NN>-<decision-slug>.md`, the number being the change's issue when
it has one and its pull request otherwise.

- **Pros:** GitHub allocates the namespace, so there is nothing to compute; the
  collision domain shrinks to a single change; the number links back to the
  discussion; several decisions per change are modelled explicitly.
- **Cons:** a record has no number until its branch is pushed and the PR opened,
  which costs one rename before review.

## Evidence

- **Issues are disabled on this repository**, so a scheme requiring an issue
  could not be applied at all:

  ```console
  $ gh api repos/exelearning/nextcloud-exelearning -q .has_issues
  false
  ```

  The same holds for `exelearning/wp-exelearning`,
  `exelearning/omeka-s-exelearning` and `exelearning/moodle-mod_exelearning`.
  Any rule that mandates a tracking *issue* is unimplementable across the whole
  satellite ecosystem.

- **Issue and pull-request numbers share one sequence.** In GitHub's data model a
  pull request is an issue, which is why `/issues/<n>` resolves to a PR. So a PR
  number is exactly as collision-free as an issue number. The main repository's
  own migration is the demonstration: tracking issue #2232 and its implementing
  pull request #2233 were allocated consecutively.

- **The counter's failure is measured, not predicted:** 16 duplicated identifiers
  across 14 branches in `exelearning/exelearning` at migration time.

- **Prior art.** Kubernetes prefixes each KEP with its tracking issue number —
  *"This gives both the KEP a unique identifier and provides an easy breadcrumb
  for people to find the issue where the current state of the KEP is being
  updated"* ([keps/README.md](https://github.com/kubernetes/enhancements/blob/master/keps/README.md)).
  MADR notes that with subdirectories ADR numbers become unique "locally within a
  category only" ([madr](https://adr.github.io/madr/)).

## Decision

We will identify architecture records by their **GitHub tracking number** — the
issue when a change has one, otherwise the pull request.

1. ADRs are named `ADR-<number>-<NN>-<decision-slug>.md`, with `<NN>` a two-digit
   sequence scoped to that number alone, starting at `01`, present even for a
   single record.
2. Change designs live in `docs/architecture/changes/<number>-<change-slug>/`.
3. Frontmatter `id` and `tracking_issue` must agree with the filename, and the H1
   must be `# <id>: <title>`.
4. **No issue is ever opened merely to obtain an identifier.** Here that is not
   possible; elsewhere it would be process without safety.
5. The record index is **never committed**. `make architecture-records` prints it
   from frontmatter.
6. `make architecture-check` validates identifiers, metadata and cross-references,
   and runs in CI.
7. The tooling uses the Python standard library only.

## Consequences

### Positive

- Nothing to compute, so nothing to contend over. Two records can only collide if
  they share a tracking number, and then their authors are already coordinating.
- The identifier is a working link back to the pull request that produced it.
- Adding a second decision to a change never renames the first.
- No generated file in version control, so no branch ever conflicts on one.

### Negative

- A record has no identifier until its branch is pushed and the PR exists, so it
  is renamed once before review.
- Identifiers are longer and the space is sparse by construction.
- The number alone does not say whether it is an issue or a PR. Here it is always
  a PR, but the format does not encode that.

### Neutral

- Records stay plain Markdown with YAML frontmatter. No external ADR tool is
  adopted; doing so would be its own decision.
- Records are contributor-facing and excluded from the distributed app.

## Risks

- A record filed under the wrong number is well-formed and undetectable by
  tooling. It stays a review concern.
- Contributors arriving from a repository with a counter may reproduce
  `max(existing) + 1` from habit. Mitigated by CI rejecting the retired filename
  form outright, with a message pointing at the policy.

## Validation

- `make architecture-check` passes in CI on every pull request.
- No file matching `ADR-[0-9]{4}-` exists under `docs/architecture/`.
- No `records.md` is committed.

## Follow-up work

- Record the sandboxing and Service Worker boundary as an ADR; it is currently
  only prose in `AGENTS.md`.
- Converge the three entry-path normalizers, which today disagree on `.`, `..`
  and doubled separators. That change touches a security boundary and needs its
  own ADR.

## References

- Policy: [`docs/architecture/adr/README.md`](README.md)
- Ecosystem decision: [`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232)
- Kubernetes KEP process — https://github.com/kubernetes/enhancements/blob/master/keps/README.md
- MADR — https://adr.github.io/madr/
