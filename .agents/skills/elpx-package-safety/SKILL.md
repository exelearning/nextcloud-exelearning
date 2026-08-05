---
name: elpx-package-safety
description: "Use when touching ZIP entry handling, entry-path validation, the Service Worker, or the sandboxed iframe. Entry-path validation is this app's security boundary and it has three implementations that must agree exactly, pinned by one shared vector table."
compatibility: "PHP ZipEntryService, TypeScript src/elpx/paths.ts, and the hand-written Service Worker src/sw/exelearning-sw.js."
---

# `.elpx` package handling and its security boundary

## When to use

Before changing anything that turns an untrusted string into a ZIP entry or a
runtime URL: `lib/Service/ZipEntryService.php`, `src/elpx/*`,
`src/sw/exelearning-sw.js`, or any new helper that handles entry paths.

## The boundary

A `.elpx` package is a ZIP whose entry names are **attacker-controlled**. Anyone
who can upload a file to Nextcloud can craft one. The only thing standing between
a crafted entry name and reading outside the package is entry-path validation.

`AGENTS.md` states the rule: any new helper that handles entries must call
`normalizeEntryPath` (TS) or `ZipEntryService::normalizeEntry` (PHP). Follow it.
Never inline your own check, never "just" `str_replace('..', '')` — that is
defeated by `....//`.

## The rule: validate, never rewrite

An entry path is accepted **only when it is already canonical**, and it is then
returned **unchanged**. Rejected outright:

- the empty string;
- any NUL byte;
- any backslash;
- any empty, `.` or `..` segment — which covers leading, doubled and trailing
  slashes as well as dot segments.

Nothing is ever repaired. `normalizeEntry(x)` is either `x` or `null`.

The reason is that entry names are looked up **verbatim**: `ZipArchive::statName()`
matches central-directory names byte-for-byte, and the browser keys its in-memory
map by the stored name. Rewriting `a/b/../c` to `a/c` would therefore hand back a
*different* entry than the one asked for — an archive can legitimately contain
both. Dot segments are also unreachable by construction: URL parsers apply
RFC 3986 §5.2.4 dot-segment removal before a request is dispatched, so a stored
name containing one can never be addressed over the runtime URL scheme.

Full reasoning, options and evidence:
[`ADR-XXXX-01`](../../../docs/architecture/adr/ADR-XXXX-01-validate-entry-paths-instead-of-rewriting-them.md).

## There are three implementations, and they must agree exactly

| Implementation | Location |
|---|---|
| PHP | `lib/Service/ZipEntryService.php::normalizeEntry` |
| TypeScript | `src/elpx/paths.ts::normalizeEntryPath` |
| Service Worker | `src/sw/exelearning-sw.js::normalizeEntry` |

The SW copy is an inline mirror of the TS one — deliberately, because the SW is
loaded out-of-band by the browser and cannot import bundled application code.
**Keep it inline. Do not make it import anything.**

They diverged once, before `ADR-XXXX-01`: PHP rejected `.`/`..` segments while
TS/SW resolved them, and PHP kept the empty segment from a doubled slash while
TS/SW collapsed it. It was never a traversal hole — both rejected `../escape` —
but a package containing `a/b/../c` rendered in the browser and 404'd from the
PHP asset controller and the preview provider, and the docblock on
`normalizeEntryPath` claimed the two matched.

### What to do

- If you change one, change all three in the same commit.
- Add the case to `tests/fixtures/entry-path-vectors.json`. It is a single file
  loaded by both test suites, so a divergence fails a test instead of shipping.
- Loosening or tightening the rule is a behaviour change to a security boundary:
  supersede `ADR-XXXX-01` rather than editing it, and do it in its own PR.

### Resolution is a separate concern

`resolveRelativeEntry` in `src/elpx/paths.ts` *does* resolve `./` and `../`,
because an href written inside package HTML may legitimately contain them. It
resolves first and then validates the result with `normalizeEntryPath`. Keep
that split: hrefs get resolved, stored entry names do not.

## Service Worker scope

Only `/apps/exelearning/runtime/` may be intercepted. The SW must never see
arbitrary Nextcloud URLs. Widening the scope is a security decision, not a
convenience: it puts the SW in front of authenticated Nextcloud responses.

`RUNTIME_PREFIX` and `ASSET_PREFIX` in `src/elpx/paths.ts` are the single place
where those URLs are built. Build runtime URLs there, not by string concatenation
at the call site.

## The iframe

Package content renders in a **sandboxed** iframe. Anything that relaxes the
sandbox attributes, or adds an origin to what the frame may reach, changes the
trust boundary between untrusted package content and the Nextcloud session. Write
an ADR before doing it.

## Packages stay opaque

Do not parse `content.xml`. Do not regenerate, patch or rewrite package contents.
Saving is a passthrough: the editor's exported bytes are written back unchanged.
Every deviation from this makes the app responsible for a format it does not own.

## Testing

Entry-path handling is pure and has no excuse for being untested:

- `tests/fixtures/entry-path-vectors.json` — **the** table. One file, three
  implementations.
- `tests/js/paths.test.ts` — runs the table against the TS helper *and* against
  the shipped Service Worker file, which it evaluates in a `node:vm` context
  with a stub `self`. That tests the real worker rather than a transcription of
  it.
- `tests/Unit/Service/ZipEntryServiceTest.php` — runs the same table against the
  PHP implementation through a `#[DataProvider]`.

Any new case you reason about — a crafted separator, an empty segment, a NUL
byte, a Windows path, a unicode look-alike — goes in the JSON table, where all
three pick it up at once.
