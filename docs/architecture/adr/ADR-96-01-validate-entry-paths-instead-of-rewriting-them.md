---
id: ADR-96-01
title: "Validate .elpx entry paths instead of rewriting them"
status: Proposed
date: 2026-08-05
tracking_issue: 96
deciders:
  - "@erseco"
reviewers:
  - "@erseco"
related:
  prs: [96]
  changes: []
  adrs: [ADR-93-01]
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-96-01: Validate .elpx entry paths instead of rewriting them

## Context

A `.elpx` package is a ZIP whose entry names are attacker-controlled: anyone who
can upload a file to Nextcloud can craft one. Turning such a name into something
the app will read or serve is this app's security boundary, and it has three
implementations — one per runtime that has to answer the question:

| Implementation | Location | Reached by |
|---|---|---|
| PHP | `lib/Service/ZipEntryService.php::normalizeEntry` | `AssetController`, `ThumbnailController`, `ElpxPreviewProvider` |
| TypeScript | `src/elpx/paths.ts::normalizeEntryPath` | `zip-reader`, URL builders, `parseRuntimeUrl` |
| Service Worker | `src/sw/exelearning-sw.js::normalizeEntry` | session registration, request matching |

The Service Worker copy is an inline mirror of the TypeScript one and must stay
inline: the browser loads the worker out-of-band, so it cannot import bundled
application code.

The three did not agree, and a comment asserted that they did.

## Problem

The three implementations must answer identically, because a package is served
through more than one of them: the browser renders it from the Service Worker,
while the server-side `AssetController` fallback and the preview provider read
the same archive in PHP. Which single rule should all three implement?

## Decision drivers

- **Exact agreement.** Any input must produce the same answer in all three, or a
  package renders through one path and 404s through another.
- **Reviewability.** This is security code. A rule a reviewer can hold in their
  head is worth more than a rule that is merely permissive.
- **No surprises for existing content.** Packages that work today should keep
  working.
- **Soundness of the lookup.** Whatever the rule returns is used to *find* an
  entry. It must not find a different one.

## Options considered

### Option 1: Resolve `.` and `..` (adopt the TypeScript behaviour in PHP)

Keep the browser behaviour and teach PHP to resolve dot segments and collapse
doubled slashes, rejecting only an attempt to climb above the package root.

- **Pros:** more permissive; accepts sloppily written archives; no change to the
  side that renders content today.
- **Cons:** does not actually converge anything — see the evidence below. PHP
  looks entries up by their stored name, so a resolved name finds a *different*
  entry or none at all. It also cannot be exercised over the runtime URL scheme,
  because URL parsers strip dot segments before the request is dispatched.

### Option 2: Reject `.` and `..` and rewrite nothing (chosen)

An entry path is accepted only when it is already canonical, and it is returned
unchanged. Rejected: the empty string, any NUL byte, any backslash, and any
empty, `.` or `..` segment — which covers leading, doubled and trailing slashes.

- **Pros:** `normalizeEntry(x)` is either `x` or `null`, so the three
  implementations agree by construction and no lookup can be redirected. It is
  one sentence to review.
- **Cons:** stricter than what the browser accepted before, so a package with a
  non-canonical entry name now fails to open at all rather than rendering. It
  also refuses entry names that are legal on POSIX but not in the ZIP format,
  such as a filename containing a backslash.

### Option 3: Resolve in PHP *and* canonicalize the archive side

Make PHP enumerate the central directory, normalize each stored name, and match
requests against that map, so a resolved request finds the entry it came from.

- **Pros:** would make Option 1 genuinely consistent.
- **Cons:** an enumeration per request; two distinct stored names can normalize
  to the same key, so it introduces a shadowing decision (which entry wins?) at
  the security boundary; and it still cannot help dot-segment names, which never
  survive URL parsing. Rejected as more machinery for a worse invariant.

## Evidence

All measurements below were reproduced against `origin/main` at commit
`7520972`, on PHP 8.5.9 and Node 26.6.0.

### The measured divergence

| Input | PHP | TS / SW |
|---|---|---|
| `a/b/c` | `a/b/c` | `a/b/c` |
| `../escape` | `null` | `null` |
| `a/b/../c` | `null` | `a/c` |
| `a/./b` | `null` | `a/b` |
| `a//b` | `a//b` | `a/b` |

This is **not** a traversal vulnerability: every implementation rejected
`../escape`, and none escaped the package root. It was a consistency defect. The
docblock on `normalizeEntryPath` claimed the helper "matches the rule used by the
PHP-side `ZipEntryService`", which was false.

### ZIP entries are looked up by their stored name

`ZipArchive::statName()` matches central-directory names verbatim. Against an
archive built with four literal entry names:

```console
$ php statname.php
central directory names:
  [0] "a/b/../c"
  [1] "a/c"
  [2] "a/./d"
  [3] "a//e"
lookups:
  statName("a/b/../c") => found, content=DOTSEG
  statName("a/c"     ) => found, content=PLAIN
  statName("a/./d"   ) => found, content=DOTCUR
  statName("a/d"     ) => FALSE
  statName("a//e"    ) => found, content=DBLSLASH
  statName("a/e"     ) => FALSE
```

`a/b/../c` and `a/c` are two different entries with different contents in the
same archive. A resolving normalizer asked for the first would serve the second.
The browser side has the same property for the opposite reason: `zip-reader`
keys its map by the name it stores, and the Service Worker looks requests up in
that map.

So Option 1 does not fix the reported symptom. A package whose stored name is
`a/b/../c` would still 404 from the asset route after resolving, because the
route would look up `a/c`.

### Dot-segment entries are unaddressable over a URL

URL parsers apply RFC 3986 §5.2.4 dot-segment removal before a request is ever
dispatched, and percent-encoding does not evade it:

```console
$ node -e '...'
"/base/a/./d"      -> pathname "/base/a/d"
"/base/a/b/../c"   -> pathname "/base/a/c"
"/base/a//e"       -> pathname "/base/a//e"
"/base/a/%2E/d"    -> pathname "/base/a/d"
"/base/a/%2E%2E/c" -> pathname "/base/c"
encodeURIComponent(".") = "."   encodeURIComponent("..") = ".."
```

Both the Service Worker route (`RUNTIME_PREFIX`) and the server-side asset route
(`ASSET_PREFIX`) address entries through a URL path. An entry whose stored name
contains a dot segment therefore cannot be requested at all, whatever the
normalizer decides. Rejecting it states that plainly; resolving it pretends
otherwise. Empty segments do survive URL parsing, which is why `a//b` is a
deliberate choice rather than a forced one — it is rejected for consistency with
the rest of the rule, not because it is unreachable.

### Real packages are unaffected

Every `.elp` and `.elpx` file available locally — this repository's fixtures plus
the eXeLearning editor's own test corpus — was scanned for entry names with a
leading slash, a backslash, a `.` or `..` segment, an empty segment, or a NUL
byte:

```console
scanned 224 packages, 43539 entries
non-canonical entry names: 0
```

The producers of these packages (JSZip and Archiver in the editor; Python's
`zipfile` for the legacy v2 corpus) all emit forward-slash-separated relative
names. The ZIP specification agrees: APPNOTE §4.4.17.1 requires that a stored
name "MUST not contain a drive or device letter, or a leading slash", and that
"all slashes MUST be forward slashes".

## Decision

We will make entry-path handling a **validation**, not a transformation. All
three implementations accept a path only when it is already canonical, and
return it unchanged:

1. Reject the empty string.
2. Reject any path containing a NUL byte or a backslash.
3. Split on `/` and reject the path if any segment is empty, `.` or `..`.
4. Otherwise return the input, byte-identical.

`normalizeEntry(x)` is therefore `x` or `null`, never a third string.

Two supporting rules:

- **Resolution stays separate from validation.** `resolveRelativeEntry` still
  applies RFC 3986 dot-segment removal, because an href written inside package
  HTML may legitimately contain `./` and `../`. It resolves first and validates
  the result. Hrefs get resolved; stored entry names do not.
- **The rule is pinned by one shared table.**
  `tests/fixtures/entry-path-vectors.json` is loaded by
  `tests/js/paths.test.ts` and by `tests/Unit/Service/ZipEntryServiceTest.php`.
  The JavaScript suite additionally evaluates the shipped Service Worker file in
  a `node:vm` context with a stub `self`, so the mirror is tested rather than
  transcribed.

We do not attempt to validate an entry name as a *filesystem* path beyond the
above. The name is never used as one — it is a lookup key for `ZipArchive` and
for an in-memory `Map` — so rules about drive letters or reserved device names
would be scope this app cannot justify.

## Consequences

### Positive

- The three implementations agree by construction: the accepted set is defined
  by a predicate, and the returned value is the input.
- No request can be redirected onto a different archive entry, because no
  request is ever rewritten.
- The rule fits in one sentence, which is what a security reviewer needs.
- A divergence now fails a test: one JSON table drives both suites, and the
  Service Worker is executed from its shipped source.
- The false docblock on `normalizeEntryPath` is gone.

### Negative

- Stricter than the browser was. A package containing a non-canonical entry name
  now fails to open entirely (`ZipReadError` with code `UNSAFE_ENTRY`) where it
  previously rendered. Measured impact on 224 real packages: none.
- Entry names that are legal on POSIX but not in the ZIP format — a filename
  containing a backslash, most plausibly — are refused. This is a deliberate
  trade: refusing is safe, mis-serving is not.
- `readEntry()` no longer tolerates a leading slash on a caller-supplied name.
  All in-tree callers pass canonical names.

### Neutral

- `resolveRelativeEntry` gained a documented behaviour: a leading slash in an
  href addresses the package root. Previously it produced a doubled slash that
  the old normalizer silently collapsed. The helper has no production caller
  today; it is exercised by tests and kept for the iframe renderer.
- The word "normalize" is kept in both function names. Renaming them would touch
  `AGENTS.md`, the skill and every call site for no behavioural gain; the
  docblocks now state that the functions validate.

## Risks

- **A package in the wild that we have not seen.** The corpus is 224 packages
  from this project's own ecosystem, not a survey of everything a third-party
  tool might produce. If such a package appears, the failure is loud (the viewer
  refuses to open it with a specific error) rather than silent, which is the
  right direction, but it is a regression for that user. Superseding this ADR
  with a narrower rule is the remedy, not a local patch to one of the three.
- **Drift.** Three implementations of one rule is inherently fragile. The shared
  table mitigates it but does not eliminate it: someone could add a case to the
  JSON and fix only two implementations. Both suites run in CI, so that fails.

## Validation

- `tests/fixtures/entry-path-vectors.json` runs green in both suites, including
  the Service Worker mirror loaded from its shipped source.
- Deliberately reverting the empty-segment check in
  `src/sw/exelearning-sw.js` fails 6 vector tests, confirming the guardrail
  detects a real divergence rather than a transcribed one.
- An accepted path is asserted byte-identical to its input on both sides.

## Follow-up work

- Record the Service Worker scope and the iframe sandbox as ADRs; they are still
  only prose in `AGENTS.md` and in the `elpx-package-safety` skill.

## References

- Skill: [`.agents/skills/elpx-package-safety/SKILL.md`](../../../.agents/skills/elpx-package-safety/SKILL.md)
- Shared vectors: [`tests/fixtures/entry-path-vectors.json`](../../../tests/fixtures/entry-path-vectors.json)
- Prior record noting the divergence as follow-up work: [`ADR-93-01`](ADR-93-01-identify-records-by-github-tracking-number.md)
- RFC 3986 §5.2.4, "Remove Dot Segments" — https://www.rfc-editor.org/rfc/rfc3986#section-5.2.4
- PKWARE APPNOTE.TXT §4.4.17.1, file name field — https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT
- PHP `ZipArchive::statName()` — https://www.php.net/manual/en/ziparchive.statname.php
