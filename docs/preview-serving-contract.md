# Host-served opaque HTTP preview — serving contract v2

This Nextcloud app is a **preview host** for the eXeLearning editor: it serves
the *editor preview* of untrusted, in-progress author content over HTTP from an
**opaque origin**, isolated from the Nextcloud session.

This document is the host-side companion to the canonical contract in eXe core:
**`doc/development/preview-serving-contract.md`** (repo `exelearning/exelearning`).
Core is authoritative; if the two disagree, core wins — in particular the sandbox
CSP string must stay **byte-identical** to core's `previewCspHeader()`.

**Protocol version: 2.** v1 (a full SHA-256 manifest + content-addressed blob
diff) is removed, not kept alongside. A create-session response without
`protocolVersion: 2` must surface an error (no silent fallback).

## Why an HTTP transport (and not the Service Worker)

For *published* `.elpx` content this app serves package bytes same-origin (the
Service Worker `src/sw/exelearning-sw.js` and the authenticated
`AssetController::fetch`) — fine for content the owner has committed.

The **editor preview** is different: it renders *untrusted, unsaved* author HTML
and SVG that can contain arbitrary scripts. Rendering that same-origin — or via a
Service Worker in our scope — would let it read the Nextcloud session, call our
APIs and pivot. A Service Worker **cannot** back an opaque origin (its
subresources bypass the SW). So the editor preview is served from a **separate,
authless, cookieless origin** and never through the Service Worker.

## The three layers (why v2)

v2 splits a preview into three layers with different lifecycles so a refresh
costs `O(changed documents + new assets)` instead of `O(whole project)`:

| Layer | Contents | Lifecycle | Transferred |
|---|---|---|---|
| **Fixed installation resources** (1) | official libraries, base iDevice runtimes, base theme files, PDF.js, content CSS, logo, fonts | immutable per installed editor version | **never** — served from the installed static editor distribution, gated by a build manifest |
| **Session project assets** (2) | author images/audio/video/PDF — anything with an asset identity | immutable per `assetKey`, whole session | **once per session** |
| **Generated documents** (3) | page HTML, navigation, generated CSS/JS, user theme/iDevice files | change every edit | **only the changed files**, as an atomic revision delta |

Classification is by **provenance, not name**: a resource is *fixed* only when the
client resolved it from the installation-immutable editor distribution. Custom
themes, user-installed iDevices and anything embedded in an `.elpx` ride the
session layers.

## Implementation map

All protocol logic is OCP-free and unit-testable; the controllers are thin
Nextcloud adapters.

| Concern | Class |
|---|---|
| Isolation policy (CSP, scriptable set, Permissions-Policy, MIME, path normalization) | `lib/Service/Preview/PreviewPolicy.php` |
| Session store (three layers, atomic revisions, budgets, TTL, immutability) | `lib/Service/Preview/PreviewSessionStore.php` |
| Fixed-resource layer (manifest lookup + containment) | `lib/Service/Preview/FixedResourceManifest.php` |
| Serving HTTP policy (headers/CSP/cache tiers/Range/304) | `lib/Service/Preview/PreviewServer.php` |
| Management HTTP policy (ownership gate, status/body mapping) | `lib/Service/Preview/PreviewSessionApi.php` |
| Authless serving controller | `lib/Controller/PreviewController.php` |
| Authenticated management controller | `lib/Controller/PreviewSessionController.php` |
| Idle-TTL cleanup job | `lib/BackgroundJob/PreviewCleanupJob.php` |

## A. Management API (AUTHENTICATED, owner-scoped)

Served under the normal authenticated app routes (CSRF **on** — these are called
by the embedded editor same-origin, mirroring `editor#save`; they are **not**
`#[NoCSRFRequired]`). Ownership is scoped to the `IUserSession` user id.

| Method & path | Body | Success |
|---|---|---|
| `POST /apps/exelearning/api/preview-session` | – | `201 { previewId, protocolVersion: 2, revision: 0, limits }` |
| `POST …/preview-session/{previewId}/assets` | multipart: `assets` (JSON `[{key,size}]`), `files[]` index-aligned | `200 { stored, alreadyStored, rejected }` |
| `POST …/preview-session/{previewId}/revisions` | multipart: `revision` (JSON, below), `files[]` aligned with `writes` | `200 { revision, active: true }` |
| `DELETE …/preview-session/{previewId}` | – | `200 { success: true }` |

- **Asset keys** match `^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$` and are **immutable**:
  re-uploading an existing key returns it in `alreadyStored` and never replaces
  bytes (a replaced author file gets a new key). The server treats the key as an
  opaque token — it never hashes asset bytes. Enforced on the buffered bytes; a
  declared/actual size mismatch rejects that entry.
- **Revision JSON** (`writes` = paths, index-aligned with `files[]`):

  ```jsonc
  {
    "baseRevision": 17,          // the revision the client believes is active
    "nextRevision": 18,          // must be baseRevision + 1
    "writes": ["index.html"],    // aligned with files[]
    "deletes": ["html/old.html"],
    "assetRefs": { "content/photo.png": "3f2a…@9c41d2e8" },  // FULL map served path → assetKey
    "fixedRefs": { "libs/jquery/jquery.min.js": "libs/jquery/jquery.min.js" }  // FULL map served path → fixedResourceId
  }
  ```

  Validation order: session exists (`404`) → `baseRevision`/`nextRevision`
  consistent else `409 { reason: "revision-conflict", currentRevision }` → every
  path normalized/safe else `400` → every `assetRefs` value stored else
  `422 { reason: "missing-assets", missing }` → every `fixedRefs` value in the
  fixed manifest else `422 { reason: "unknown-fixed-resources", resources }` →
  file-count/byte budgets else `413`.
- **Atomicity.** A revision is published by writing its content-addressed blobs,
  writing the revision manifest (temp+rename), then swapping the `current`
  pointer (temp+rename). A concurrent `GET` reads `current` once then an
  immutable manifest, so it observes revision *N* or *N+1*, never a mixture.
- **Budgets & TTL.** 30-min idle TTL, 4 sessions/user, 5000 files/session, 200
  MiB/session, 128 MiB/asset, 2 GiB global (per-user and global caps evict LRU).

## B. Serving route (AUTHLESS capability URL)

```
GET /apps/exelearning/preview/{previewId}/{path}
```

- **Authless, cookieless.** The opaque iframe sends no SameSite cookies, so this
  route does not depend on the session. It is gated solely on the unguessable
  server-minted `previewId` UUID + idle TTL (Nextcloud's cookieless serving
  primitive, `#[PublicPage]` + `#[NoCSRFRequired]`). It never emits a session
  cookie and always answers `Access-Control-Allow-Origin: *` (sound only because
  it is cookieless — never paired with credentials).
- `previewId` must match
  `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`; else 404.
- The bare `/preview/{previewId}` resolves `path` to `index.html`.
- **Resolution order** (exact-key against the active revision only):
  `documents[path]` → `assets[assetRefs[path]]` → `fixed[fixedRefs[path]]` → 404.
  A path only ever names a stored entry; only the server-controlled manifest
  `path` reaches the filesystem, contained under the distribution root.
- **Range** on session assets: `Accept-Ranges: bytes`, single-range `206`, `416`
  on unsatisfiable. **Conditional**: `ETag: "<assetKey>"`, `If-None-Match` → `304`.

### Required response headers (on EVERY response, including 404)

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `no-referrer` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Access-Control-Allow-Origin` | `*` (never with credentials) |
| `Content-Type` | the served file's real MIME (always set explicitly — Nextcloud serves unknown extensions as `text/plain`) |

`Cache-Control` is **tiered by layer**:

| Response | Cache-Control |
| --- | --- |
| Generated document (layer 3) | `no-store` |
| Session asset (layer 2) | `no-cache` (+ `ETag`, `If-None-Match` → 304) |
| Fixed resource (layer 1) | `private, max-age=31536000` |
| 404 / errors | `no-store` |

### Sandbox CSP (every scriptable document type, from every layer)

On `text/html`, `image/svg+xml`, `application/xml`, `text/xml` and
`application/xhtml+xml` — whether the response resolves from the session or the
fixed layer — add this `Content-Security-Policy` **verbatim** (byte-identical to
eXe core `previewCspHeader()`; `PreviewPolicy::CSP`):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'
```

The leading `sandbox` directive drops the document into an opaque, unique origin
even when opened top-level (a raw `*.html` **or** `*.svg` in a new tab stays
opaque — an author SVG served without it runs its inline `<script>` same-origin).

> **Two CSP strings in this app.** The preview CSP above (`PreviewPolicy::CSP`)
> is a **different** string from `IframeSandbox::contentSecurityPolicy()` used for
> *published* content. Published content pins `frame-src`/`img-src` to the
> maintained provider hosts (token-exfiltration hardening for a longer-lived
> capability token); the editor preview is a short-lived ephemeral capability and
> matches core's preview CSP verbatim. They are deliberately **not** unified —
> unifying would either weaken published-content hardening or drift the preview
> CSP from core. Do not change published-content behaviour to reconcile them.

## The fixed-resource manifest

The fixed layer resolves ids through
`bundles/preview-fixed-resources.json` inside the installed static editor
distribution (`js/editor/`, staged by `make download-editor`). eXe core emits it
into the distribution; `resources[id].path` is relative to the distribution root.
`FixedResourceManifest` resolves an id by **exact map lookup** (never path
arithmetic on client input) and reads the file with a containment check.

When the bundled editor predates the manifest (as with the current v4.0.2
bundle), the file is absent and the **fixed layer is simply disabled**: any
`fixedRefs` in a revision is rejected with `422 unknown-fixed-resources` and the
client demotes those refs into the session layers. This is never fatal.

## Storage

Sessions are file-backed under `{datadirectory}/exelearning/preview-sessions/`
(PHP is request-scoped — an in-memory map cannot survive between the management
POSTs and the authless GETs). Each session directory holds `meta.json`, a
`.accessed` TTL/LRU marker, a `current` pointer, `assets/{sha1(key)}`,
content-addressed `documents/{sha1(bytes)}`, and `revisions/{N}.json`. The store
requires a POSIX-local directory (atomic rename + link + flock), matching the
contract's PHP-host note ("atomic pointer swap … rename a `current` marker"). It
is resolved from the Nextcloud data directory rather than `IAppData` (which may
be object storage) — the only OCP seam; the store itself is OCP-free.

## Cleanup

`PreviewCleanupJob` (a `TimedJob` registered in `appinfo/info.xml`
`<background-jobs>`) sweeps idle-expired sessions every 10 minutes; the serving
route also drops an expired session opportunistically on access, so preview URLs
stop resolving at the TTL even if cron is starved.

## Editor activation (not wired here)

Turning the preview on is a client-side change (the editor points its embedding
config at `previewTransport: 'http'` + this host's `previewBasePath`). That
wiring is intentionally **not** part of this change — this PR delivers only the
host server side of the contract.

## Conformance

Beyond the CSP drift-check, the shared vectors in
`tests/fixtures/preview-contract/vectors.json` (vendored verbatim from eXe core)
are replayed against this app's seams in
`tests/Unit/Service/Preview/PreviewContractConformanceTest.php`, so protocol
semantics — not just the CSP string — stay aligned with every other host.
