# Testing the HTTP preview with a core pull-request artifact

The Nextcloud adapter already injects Preview Serving Contract v2 configuration and exposes the management and capability routes. A released editor older than `HttpPreviewProvider` ignores that configuration, so browser activation must be tested against a reproducible static-editor artifact from the target eXeLearning core commit.

## Build one canonical editor artifact

From the eXeLearning core checkout:

```bash
make bundle
make build-static
```

Verify that the static distribution contains:

```text
public/app/workarea/interface/elements/preview/HttpPreviewProvider.js
public/app/workarea/interface/elements/preview/StaticServiceWorkerPreviewProvider.js
public/bundles/preview-fixed-resources.json
```

It must not contain the removed `SrcdocPreviewProvider` or `srcdocInliner` files.

Archive the distribution once and record its SHA-256. Use the same archive in every LMS/CMS integration test so transport behavior cannot drift between host builds.

## Install it in the Nextcloud development environment

Replace only the generated embedded-editor distribution used by the development container. Do not commit the generated editor bundle to this application repository.

Run the browser test with a normal Nextcloud cookie session. Basic-auth API coverage remains useful, but it does not prove that the embedded editor sends the current `requesttoken` or that the iframe is configured correctly.

Verify this complete flow:

1. The editor bootstrap injects protocol version 2, management base, serving base, and `requesttoken`.
2. `POST /apps/exelearning/api/preview-session` creates an owner-scoped session.
3. New assets are uploaded once.
4. Revision 1 is published atomically.
5. The iframe loads `/apps/exelearning/preview/{previewId}/index.html`.
6. The iframe sandbox omits `allow-same-origin`.
7. Scriptable serving responses include the sandbox CSP.
8. Editing one page publishes only changed generated documents.
9. Serving requests omit credentials.
10. Management requests retain the Nextcloud cookie and `requesttoken`.
11. A missing or invalid request token is rejected.
12. Session expiry or process cleanup is recovered through a new session after `404`.

Also cover page navigation, external media, a large single-range asset response, a dropped multipart part, wrong-owner management access, and cleanup.

## Static Service Worker escape hatch

An embedded Nextcloud editor must never use the static Service Worker transport. A development-only php-wasm environment may opt in only with both fields:

```jsonc
{
  "previewTransport": "static-service-worker",
  "allowUnsafeEmbeddedPreview": true
}
```

`previewTransport` alone is rejected. This mode is same-origin, is not a security sandbox, and must not be enabled in production.

## Merge evidence

Record in the PR:

- core commit SHA;
- editor archive SHA-256;
- Nextcloud and PHP versions;
- browser version;
- captured management and serving requests;
- sandbox and CSP assertions;
- CSRF-negative result;
- proof that unchanged assets were not retransmitted.
