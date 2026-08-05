# Architecture changes

## Purpose

A **change** is one unit of significant technical work, identified by its GitHub
tracking number. Its documents describe *what* will be built and *how*: goals,
non-goals, observable behaviour, technical design, migration, security,
accessibility, testing and rollout — agreed **before** implementation starts.

They make a large change reviewable as a whole, instead of arriving as a big pull
request that reviewers must reverse-engineer.

## Changes vs ADRs

| Artifact | Answers | Lifetime |
|---|---|---|
| **Change document** | *What* will be built and *how* | May become historical once implemented |
| **ADR** | *Which* durable decision was made and *why* | Long-lived, append-only |

A change is a **design**; an [ADR](../adr/README.md) is a **decision**. A single
change often contains several durable decisions — a storage choice, a sandboxing
boundary, a compatibility guarantee. Those belong in ADRs so they outlive the
feature work; the change links to them via `related_adrs` instead of burying them
in prose. Do **not** create one ADR per section of a design.

## Layout

One directory per tracking number:

```text
docs/architecture/changes/<tracking-number>-<change-slug>/
```

| File | Responsibility |
|---|---|
| `proposal.md` | Motivation, problem, scope, goals, non-goals |
| `spec.md` | Observable behaviour, requirements, scenarios, acceptance criteria |
| `design.md` | Technical implementation design |
| `research.md` | Evidence, experiments, alternatives, source analysis |
| `tasks.md` | Implementation plan and progress |

**Create only the files that carry real content.** Empty placeholders are not
required and must not be added to complete the set — a small change may be a
single `proposal.md`. Do **not** duplicate the same content across `proposal.md`,
`spec.md` and `design.md`; each answers a different question.

Issues are disabled on this repository, so the tracking number is the pull
request number. See [the ADR policy](../adr/README.md) for the full
identification rules, including the chicken-and-egg case.

## Canonical metadata

Mutable change-level metadata (`title`, `status`, `implementation_prs`,
`related_adrs`) lives in exactly one file: the **first** of `proposal.md`,
`spec.md`, `design.md`, `research.md`, `tasks.md` that exists. Other documents may
repeat `tracking_issue`, `title`, `status` and `date`, but must not declare
`implementation_prs` — that would create a second source of truth, and CI rejects
it.

```yaml
tracking_issue: 42
title: "Opaque published-content viewer"
status: in-review
date: 2026-08-05
authors:
  - "@erseco"
implementation_prs:
  - 42
related_adrs:
  - ADR-42-01
```

Every document's `tracking_issue` must match the directory. CI enforces it.

## Status values

| Status | Meaning |
|---|---|
| `draft` | Being written; not ready for review. |
| `in-review` | Under review; open for feedback. |
| `accepted` | Design agreed; implementation may start. |
| `implemented` | Shipped. Kept as a historical record. |
| `superseded` | Replaced by a newer change. |
| `abandoned` | Dropped before implementation. Kept for the record. |

Status lives in the frontmatter **only**. Do not add a `## Status` section.

Once `implemented`, avoid rewriting except for typo and link fixes. If the design
changes substantially, create a new change directory and mark the previous one
`superseded`.

## When a change document is required

- significant new features;
- major refactors of a subsystem (the viewer, the Service Worker, the editor
  bridge, the preview provider);
- cross-cutting changes to sandboxing, packaging or the Nextcloud integration
  surface;
- proposals with multiple implementation phases.

Skip it for bug fixes, small enhancements and localized changes with an obvious
implementation. A durable decision that needs no full design goes straight to an
[ADR](../adr/README.md).

## The index is not a file

There is **no committed index**. Print it on demand:

```bash
make architecture-records
```

It is derived entirely from frontmatter, and a generated file in version control
conflicts on every concurrent branch. CI fails if a `records.md` is ever
committed.

## Workflow

1. Identify the change's tracking number (its PR).
2. Create `docs/architecture/changes/<number>-<change-slug>/`.
3. Copy the relevant sections of [`template.md`](template.md) into the documents
   you actually need. Start at `status: draft`.
4. Capture durable decisions as [ADRs](../adr/README.md) and list them in
   `related_adrs`.
5. Run `make architecture-check`.
6. On approval set `accepted` and implement; when it ships set `implemented` and
   record the PRs in `implementation_prs`.

## Review checklist

- [ ] The directory uses the change's tracking number.
- [ ] Every document's `tracking_issue` matches the directory.
- [ ] Only documents with real content exist; no empty placeholders.
- [ ] Content is not duplicated across `proposal.md`, `spec.md` and `design.md`.
- [ ] Goals and non-goals are explicit.
- [ ] Security, sandboxing and testing are addressed.
- [ ] Durable decisions are captured as ADRs and listed in `related_adrs`.
- [ ] `status` appears only in the frontmatter.
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] `make architecture-check` passes.
