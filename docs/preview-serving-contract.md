# Host-served opaque HTTP preview (serving contract)

This Nextcloud app can act as a **preview host** for the eXeLearning editor: it
serves the *editor preview* of untrusted, in-progress author content over HTTP
from an **opaque origin**, isolated from the Nextcloud session.

This document is the host-side companion to the canonical contract in eXe core:
**`doc/development/preview-serving-contract.md`** (repo `exelearning/exelearning`).
The core doc is authoritative. If the two ever disagree, core wins — in
particular the sandbox CSP string below must stay **byte-identical** to core.

## Why an HTTP transport (and not the Service Worker)

For *published* `.elpx` content this app already serves package bytes two ways:
the in-browser Service Worker (`src/sw/exelearning-sw.js`) and the authenticated
fallback `AssetController::fetch` (`/asset/{sessionId}/{path}`). Both are
**same-origin** and are fine for content the owner has committed.

The **editor preview** is different: it renders *untrusted, unsaved* author HTML
and SVG that can contain arbitrary scripts. Rendering that same-origin — or via a
Service Worker in our scope — would let it read the Nextcloud session, call our
APIs, and pivot. So the editor preview MUST be served from a **separate, authless,
cookieless origin** and never through the Service Worker. That is what this
contract provides.

## What this reuses vs. what is new

Reused idioms from the published-content path:
- The `DataDisplayResponse` + raw `addHeader(...)` serving idiom from
  `lib/Controller/AssetController.php`.
- Path-safety via `ZipEntryService::normalizeEntry()` (rejects `..`, `.`,
  absolute and NUL-tainted paths).
- The small extension→MIME map idiom from `AssetController::MIME_MAP`.

New in this contract (follow-up implementation):
- A `#[PublicPage]` **authless** serving route (the app has none today).
- A **content-addressed, per-session store** (not a single `.elpx`).
- The **sandbox-first CSP** applied to scriptable document types.

## Serving route (AUTHLESS capability URL)

```
GET {previewBasePath}/preview/{previewId}/{path}
```

- `previewId` MUST match
  `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`.
  Anything else → **404** (with the hardening headers, see below).
- The bare `/preview/{previewId}/` resolves `path` to `index.html`.
- No auth, no CSRF, **no credentials** — the response carries
  `Access-Control-Allow-Origin: *`, which is only sound because the origin is
  cookieless. The route must never emit a Nextcloud session cookie.

Reference implementation skeleton: **`lib/Controller/PreviewController.php`**.

## Required response headers (on EVERY response, including 404)

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `no-referrer` |
| `Cache-Control` | `no-store` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Access-Control-Allow-Origin` | `*` (never together with credentials) |
| `Content-Type` | the real MIME of the served blob |

## Sandbox CSP (scriptable document types only)

On every **scriptable** document type — `text/html`, `image/svg+xml`,
`application/xml`, `application/xhtml+xml` — add this `Content-Security-Policy`
header **verbatim** (byte-identical to eXe core):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

The leading `sandbox` directive drops the content into an opaque, unique origin;
the rest is defence-in-depth. Do **not** re-order, re-quote, or reformat it —
keep it in one shared constant so the published-content path and this preview
path stay identical.

## Capability + lifecycle model

- **Capability UUID.** Knowledge of `previewId` is the only bearer of access.
  Generate a v4 UUID per session; treat it as a secret; never log it.
- **Idle TTL.** A session expires after **30 min** of inactivity; expired →
  404. Every serve refreshes the timer.
- **Caps (defaults).** 5000 files and 200 MiB per session; 2 GiB global; reject
  writes past the cap.
- **Content-addressed store.** Blobs are keyed by SHA-256, **re-hashed
  server-side** on upload; hash-mismatched blobs are quarantined, never served.
  Manifest swaps are atomic.

## Management API (AUTHENTICATED, owner-scoped) — follow-up

Served under the normal authenticated app routes (NOT the public origin):

- `POST /api/preview-session` → `{ previewId, previewBasePath, limits }`
- `POST /api/preview-session/{id}/manifest` `{files:{path:{sha256,size}}}`
  → `{ manifestId, missing[], active }`
- `POST /api/preview-session/{id}/blobs` (multipart `manifestId`+`hashes`+files,
  re-hash server-side) → `{ stored[], mismatched[], active }`
- `DELETE /api/preview-session/{id}`

## Editor activation

The editor turns this on by pointing its embedding config at this host:

```js
window.__EXE_EMBEDDING_CONFIG__ = Object.assign(existingConfig, {
  previewTransport: 'http',                          // never 'sw' for untrusted preview
  previewBasePath: '/apps/exelearning',              // absolute URL from IURLGenerator
});
```

`previewBasePath` is returned by `POST /api/preview-session`
(`IURLGenerator::linkToRouteAbsolute('exelearning.preview.serve', …)`, trimmed to
the app root). The editor then loads `GET {previewBasePath}/preview/{previewId}/…`
inside its preview iframe.

## Status / follow-up

The serving endpoint in `lib/Controller/PreviewController.php` is a grounded
**skeleton**. Still to build (own PR, with tests):
1. `PreviewSessionStore` service (content-addressed store, caps, idle TTL, atomic
   manifest swap).
2. `PreviewSessionController` (the authenticated management API above).
3. `SANDBOX_CSP` extracted into one shared constant, reused by the
   published-content path.
4. PHPUnit coverage under `tests/Unit/Controller/PreviewControllerTest.php`
   (UUID validation, header set incl. 404, CSP-on-scriptable-types) — patch
   coverage ≥ 90%.