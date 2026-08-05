---
tracking_issue: NNNN
title: "Short change title"
status: draft
date: YYYY-MM-DD
authors:
  - "@github-user"
reviewers:
  - "@github-user"
implementation_prs: []
related_adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:

1. Find the change's GitHub tracking NUMBER. Issues are disabled on this
   repository, so it is the pull request number.
2. Create `docs/architecture/changes/<number>-<change-slug>/`.
3. Copy the frontmatter above into each document you create, and the matching
   section skeleton below into that document.
4. CREATE ONLY THE DOCUMENTS THAT CARRY REAL CONTENT. A small change may be a
   single proposal.md. Do not duplicate content across documents.
5. `implementation_prs` belongs ONLY in the canonical document — the first of
   proposal.md, spec.md, design.md, research.md, tasks.md that exists.
6. Status lives in the frontmatter only. Do not add a `## Status` section.
7. Run `make architecture-check`.

Delete these guidance comments before submitting. See ./README.md for the policy.
-->

# Short change title — <document kind>

<!-- ===================== proposal.md ===================== -->

## Motivation

<!-- Why now. What is broken, missing or costly. -->

## Problem

## Scope

<!-- In scope and explicitly out of scope. -->

## Goals

- ...

## Non-goals

- ...

<!-- ===================== spec.md ===================== -->

## Requirements

<!-- Normative statements: must / must not / may. Number them so reviews and
tests can cite them. -->

## Scenarios

<!-- Given / when / then, from the user's or the API's point of view. -->

## Acceptance criteria

- [ ] ...

<!-- ===================== design.md ===================== -->

## Current state

<!-- What exists today, with repository paths. -->

## Technical design

<!-- Modules, data flow, interfaces. Name the PHP classes and TS modules. -->

## Data model

## Migration and compatibility

<!-- Existing packages, stored state, and installs that skip a version. -->

## Security and sandboxing

<!-- Service Worker scope, iframe sandbox, CSP, path normalization. This app's
security boundary is entry-path normalization — say how the change respects it. -->

## Accessibility

## Internationalization

## Performance

## Testing strategy

<!-- Vitest under tests/js/, PHPUnit under tests/Unit/. Name the files. -->

## Rollout plan

## Risks and mitigations

## ADRs required or referenced

| Decision | ADR |
|---|---|
| ... | ADR-NNNN-01 |

<!-- ===================== research.md ===================== -->

## Measurements

<!-- Numbers, with the method used so they can be reproduced. -->

## Alternatives considered

## External prior art

<!-- Nextcloud app framework docs, specifications, comparable implementations.
Cite links; do not paste large excerpts. -->

<!-- ===================== tasks.md ===================== -->

## Plan

- [ ] Step 1
- [ ] Step 2

## Progress

## References
