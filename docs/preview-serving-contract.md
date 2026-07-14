# Opaque editor-preview snapshot contract

The embedded eXeLearning editor keeps its normal editing and export flows. For
preview only, it sends a complete ZIP snapshot to this app and loads the
returned capability URL in an iframe sandbox that does not contain
`allow-same-origin`. The rendered document therefore has an opaque origin and
cannot access the Nextcloud page, Web Storage, JavaScript objects, or editor
state.

This transport is intentionally a whole-snapshot contract. It has no asset
layers, revision graph, delta protocol, content-addressed blobs, or Service
Worker fallback.

## Management routes

```text
POST   /apps/exelearning/preview-session/{fileId}
DELETE /apps/exelearning/preview-session/{fileId}/{previewId}
```

The POST body is multipart form data with `snapshot` (a ZIP containing
`index.html`) and, when replacing an existing snapshot, `previewId`. It returns
`{"previewId":"..."}`. Both routes use normal Nextcloud authentication and
CSRF validation. The controller resolves `fileId` inside the current user's
files and enforces update permission before storing or deleting data.

The trusted editor iframe copies Nextcloud's request token into the
`requesttoken` header configured for these management calls. The token is not
placed in the capability URL or snapshot.

## Capability routes

```text
GET /apps/exelearning/preview/{previewId}
GET /apps/exelearning/preview/{previewId}/{path}
```

These `PublicPage` routes do not require an authenticated session. A random
UUIDv4 is the bearer capability. Snapshots expire after 30 minutes of inactivity
and each successful read refreshes that timer. The private store is below the
Nextcloud data directory, outside the web root.

Every response has `nosniff`, `no-referrer`, `no-store`, a restrictive
Permissions Policy, and no credential-dependent behavior. HTML, SVG, XML, and
XHTML also receive a sandbox CSP. Path canonicalization rejects traversal,
absolute paths, backslashes, symbolic links, and internal metadata names.
Unknown extensions are served as `application/octet-stream`.

The snapshot is limited to 5,000 files and 100 MiB uncompressed and must contain
`index.html`. Replacement is staged and published with a directory rename so a
reader sees either the previous complete snapshot or the replacement.

## Browser boundary

The eXeLearning core owns the iframe policy:

```text
sandbox="allow-scripts allow-forms allow-popups allow-downloads allow-presentation"
```

`allow-same-origin` is deliberately absent. Official eXeLearning JavaScript and
author-provided active content may execute inside this embedded preview, but the
opaque sandbox separates both from Nextcloud. There is no silent fallback to a
same-origin Service Worker or blob URL if snapshot creation fails.

Messages between the outer Nextcloud view and the trusted editor iframe are
accepted only from the expected `Window` and after payload validation. The
opaque preview does not participate in that protocol.
