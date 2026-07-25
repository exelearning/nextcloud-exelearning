# Opaque editor preview — Nextcloud adapter

The embedded editor renders its preview **filtered** by default: sanitised, with
no author JavaScript running. When the author opts in to running their own code,
the editor needs somewhere to put the real project bytes that is **not** the
Nextcloud page — a browser-enforced **opaque origin** the content cannot reach
out of.

This app is that somewhere. The editor POSTs the whole project as one ZIP and
gets back an unguessable capability id; the app serves that tree from an authless
route under a sandbox CSP. There is no authored-content `srcdoc` transport and no
Service Worker fallback here: missing or invalid configuration **fails closed**
and the filtered preview stays.

The sandbox CSP string must stay **byte-identical** to eXe core's
`previewCspHeader()`; core is authoritative.

## Why not the Service Worker

For *published* `.elpx` content this app serves package bytes same-origin (the
Service Worker `src/sw/exelearning-sw.js` and the authenticated
`AssetController::fetch`) — fine for content the owner has committed.

The **editor preview** is different: it renders *untrusted, unsaved* author HTML
and SVG that can contain arbitrary scripts. Rendering that same-origin — or via a
Service Worker in our scope — would let it read the Nextcloud session, call our
APIs and pivot. A Service Worker **cannot** back an opaque origin (its
subresources bypass the SW), so the preview is served from an authless,
cookieless route and never through the Service Worker.

## The two endpoints

| | Request | Result |
|---|---|---|
| Management | `POST {basePath}/api/preview-session` | multipart `snapshot=<zip>`, optional `previewId` → `{previewId}` |
| Management | `DELETE {basePath}/api/preview-session/{previewId}` | drops the snapshot |
| Serving | `GET {basePath}/preview/{previewId}/{path}` | the snapshot, authless |

Management is the only authenticated surface: a logged-in `IUserSession` user
plus Nextcloud's ordinary CSRF check (the actions are deliberately **not**
`#[NoCSRFRequired]`, mirroring `editor#save`), and the store scopes every
snapshot to its owner (403/404).

Serving carries no authentication at all. The unguessable id plus the idle TTL is
the whole credential, which is what makes the origin opaque — an iframe pointed
at this URL carries no Nextcloud session, so author code inside it has nothing to
steal.

## Why one whole snapshot

An earlier revision implemented a layered protocol (contract v2): immutable asset
keys uploaded once, incremental document revisions, and a manifest of fixed
installation resources resolved out of the editor distribution — all to avoid
re-uploading unchanged bytes. The editor no longer speaks it; it was handed a
contract nothing read while the one it does read (`previewSnapshot`) was
withheld, which left the opaque preview unreachable here. One ZIP per refresh
replaced the store, the fixed-resource layer and the four-operation management
API.

## Storage

    {datadirectory}/exelearning/preview-snapshots/{previewId}/
      meta.json    ownerUserId, createdAt, bytes
      .accessed    empty marker; its mtime is the idle-TTL / LRU clock
      content/     the extracted snapshot

Outside the web root, so no direct web-server path can bypass the serving route
and its sandbox CSP. The root comes from `datadirectory` rather than `IAppData`
because the store needs a POSIX-local directory for atomic renames, and
`IAppData` may be object storage. Content sits in its own subdirectory so no
author path can collide with the store's own files — there are no reserved names
to police. A write is staged beside the live tree and swapped in, so a reader
sees the previous snapshot or the new one, never a half-written one.

## What an archive must survive before extraction

`SnapshotArchive` vets every entry **before** anything is written, then extracts
under a byte budget:

- Unsafe entries (absolute paths, backslashes, `.`/`..` segments, NUL bytes) and
  symbolic links reject the **whole** archive in the first pass.
- The zip-bomb cap is enforced on the **real decompressed bytes** as they stream
  out, not on the sizes declared in the central directory — those are supplied by
  whoever built the archive. The declared total is only an early reject.
- An `index.html` must be present, or it is not a preview.

## Bounds

Nextcloud is the one adapter where these matter beyond a single author, because
one shared instance hosts many users against one filesystem:

| Bound | Default |
|---|---|
| Idle TTL | 30 min |
| Snapshots per user | 4 (LRU-evicted) |
| Files per snapshot | 5000 |
| Bytes per snapshot | 200 MiB |
| Global | 2 GiB (LRU-evicted, then 507) |

Serving a snapshot pushes its idle clock back, so a preview in use never expires
under the author. Every publish sweeps, and `PreviewCleanupJob` sweeps every
10 minutes, so the store never depends on cron alone to bound its size.

## Response headers

Every response, 404s included, carries `X-Content-Type-Options: nosniff`,
`Referrer-Policy: no-referrer`, the preview `Permissions-Policy` and
`Access-Control-Allow-Origin: *` — safe here precisely because the route is
authless and cookieless, and never to be paired with credentials. There is
deliberately no `X-Frame-Options`: framing is governed by the CSP
`frame-ancestors` directive.

Every **scriptable** type — `text/html`, `image/svg+xml`, XML, XHTML — also gets
the sandbox CSP, so a capability URL stays opaque even when opened directly. Not
just HTML: an author-supplied SVG runs its inline `<script>` top-level, and
`nosniff` does not help — SVG is already a scriptable type.

Caching is tiered: a scriptable document is `no-store` (it is rewritten on every
refresh), everything else revalidates with an `ETag` and supports single-range
206/416, which is what makes a video inside the snapshot seek without a full
re-download.

The bare capability URL (`…/{previewId}`) never serves `index.html` bytes: it
302s to `…/{previewId}/index.html`, so the opaque iframe's base URL is the
snapshot directory. The `Location` is relative, so it stays correct under any
Nextcloud webroot.

## Client wiring

`EditorController::previewSnapshotConfig()` injects into
`window.__EXE_EMBEDDING_CONFIG__`:

```jsonc
"previewSnapshot": {
  "managementUrl":     "{basePath}/api/preview-session",
  "servingBaseUrl":    "{basePath}/preview",
  "deleteUrlTemplate": "{basePath}/api/preview-session/{previewId}",
  "managementHeaders": { "requesttoken": "…" }
}
```

Every URL is generated server-side through the router, so it carries the correct
webroot and front-controller prefix under a sub-path install. `requesttoken` is
the current Nextcloud CSRF token: it stays valid for the whole editing session,
because a token is bound to a per-session secret that only rotates when the
session id does — and that invalidates the parent page hosting the iframe, which
reloads and injects a fresh one.

## External video inside the opaque iframe

An opaque origin fails YouTube's and Vimeo's embedder check (Error 153), so the
players cannot run inside the sandbox. `exe_embed_shim.js` — inlined into the
served package by `ContentController` — demotes provider iframes to geometry
placeholders, and `exe_embed_relay.js` on the trusted parent (driven by
`src/embed/relay-host.ts`) overlays the real player over each one.

## Tests

`PreviewSnapshotStoreTest` covers the capability lifecycle, owner scoping, the
archive guards and the DoS bounds; `PreviewServerTest` covers the serving
response contract; `PreviewPolicyTest` covers MIME/scriptable classification and
path normalization.
