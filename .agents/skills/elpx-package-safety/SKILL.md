---
name: elpx-package-safety
description: "Use when touching ZIP entry handling, entry-path normalization, the Service Worker, or the sandboxed iframe. Entry-path normalization is this app's security boundary and it currently has three implementations that do NOT agree."
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
a crafted entry name and reading outside the package is path normalization.

`AGENTS.md` states the rule: any new helper that handles entries must call
`normalizeEntryPath` (TS) or `ZipEntryService::normalizeEntry` (PHP). Follow it.
Never inline your own check, never "just" `str_replace('..', '')` — that is
defeated by `....//`.

## There are three implementations, and they disagree

| Implementation | Location |
|---|---|
| PHP | `lib/Service/ZipEntryService.php::normalizeEntry` |
| TypeScript | `src/elpx/paths.ts::normalizeEntryPath` |
| Service Worker | `src/sw/exelearning-sw.js::normalizeEntry` |

The SW copy is an inline mirror of the TS one — deliberately, because the SW is
loaded out-of-band by the browser and cannot import bundled application code.
Those two agree. **The PHP one does not.** Measured behaviour:

| Input | PHP | TS / SW |
|---|---|---|
| `a/b/c` | `a/b/c` | `a/b/c` |
| `../escape` | `null` | `null` |
| `a/b/../c` | **`null`** | **`a/c`** |
| `a/./b` | **`null`** | **`a/b`** |
| `a//b` | **`a//b`** | **`a/b`** |

PHP rejects any `.` or `..` segment outright. TS/SW resolve them and only reject
an attempt to escape the root. PHP also keeps an empty segment from a doubled
slash; TS/SW collapse it.

**This is not a traversal hole.** Both reject `../escape`; neither escapes the
package root. It is a *consistency* defect: a package containing `a/b/../c`
renders in the browser but 404s from the PHP asset controller and the preview
provider, and the difference is invisible until someone ships such a package.

The docblock on `normalizeEntryPath` claims it "matches the rule used by the
PHP-side `ZipEntryService`". **That comment is wrong**, which is the dangerous
part: it tells the next maintainer the two agree.

### What to do about it

- **Do not** treat the two as interchangeable when reasoning about behaviour.
- If you change one, change all three, and add a shared test vector table so the
  divergence cannot silently return.
- Converging them is a behaviour change to a security boundary. That deserves an
  ADR and its own PR — not a drive-by edit inside an unrelated change.

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

- `tests/js/paths.test.ts` — the TS normalizer.
- `tests/Unit/Service/ZipEntryServiceTest.php` — the PHP one.

Any new case you reason about — a crafted separator, an empty segment, a NUL
byte, a Windows path, a unicode look-alike — becomes a test case in both, or it
is not covered.
