# Secure (opaque-origin) published-content viewer

The Nextcloud Viewer renders **published `.elpx` content** — untrusted, author-authored
HTML/JS — in a browser-enforced **opaque origin**, and relays external media
(YouTube/Vimeo/Dailymotion/EducaMadrid/PDF + the interactive-video iDevice) to the
trusted parent page. This matches the secure-iframe mode already shipped by the
Moodle, WordPress and Omeka S plugins.

## Why

Rendered author content runs scripts. Same-origin, those scripts can read the
Nextcloud DOM, the session/cookie surface, IndexedDB and app pages. The fix is an
**opaque origin**: a sandboxed iframe **without `allow-same-origin`**, whose
document is served with a response-level `Content-Security-Policy: … sandbox …`.
A Service Worker **cannot** back an opaque origin (its subresources bypass it), so
the opaque path is served over real HTTP, not the SW.

## How it works

| Piece | File |
|---|---|
| Opaque sandbox tokens + published CSP + provider whitelist + embed mode | `lib/Service/IframeSandbox.php` |
| Capability token (fileId-bound, HMAC, short-lived) | `lib/Service/ContentTokenService.php` |
| Cookieless serving route `/content/{token}/{path}` | `lib/Controller/ContentController.php` |
| Token minted at view-open (read permission checked here) | `lib/Controller/ViewController.php` |
| Opaque iframe (`allow-scripts allow-popups allow-forms`) | `src/elpx/iframe-renderer.ts` |
| Relay + media host in the parent page | `src/embed/relay-host.ts` + `src/viewer/ElpxViewer.vue` |
| eXe-core embed/media bridge mirrors | `src/embed/exe_embed_shim.js`, `exe_embed_relay.js`, `exe_media_policy.js`, `exe_media_host.js` |

Flow: the Viewer downloads + validates the package (legacy `.elp` → migration
prompt), then loads the opaque iframe from `/content/{token}/index.html`. The PHP
`ContentController` verifies the token, resolves the file, serves each entry with
the sandbox CSP, and **inlines the embed shim** into HTML. Inside the opaque iframe
the shim promotes each cross-origin/PDF sub-iframe to a geometry placeholder; the
parent's relay (`exe_embed_relay.js`) overlays a real player over it, and the media
host (`exe_media_host.js`) drives the interactive-video iDevice.

## Capability model (cookieless)

The opaque origin sends no Nextcloud cookie, so the serving route is a
`#[PublicPage]` gated purely by an unguessable, short-lived, fileId-bound HMAC
token minted in `ViewController` — where the user's read permission is checked.
This mirrors Moodle's `tokenpluginfile` model. The response carries
`Access-Control-Allow-Origin: *` (sound only because the origin is cookieless) and
`Referrer-Policy: no-referrer` / `X-Content-Type-Options: nosniff` on every
response including 404s.

## Modes / escape hatches

- **Secure (default).** Opaque `/content` route. No setting required.
- **Legacy same-origin (dev only).** Set `EXELEARNING_UNSAFE_LEGACY_IFRAME=1` to
  fall back to the same-origin Service-Worker `/runtime` path. Never a UI setting.
- **Embed relay mode.** `strict` (default) overlays only the maintained provider
  hosts; set `EXELEARNING_EMBED_OPEN=1` for `open` mode (any cross-origin https
  player the shim reports).

## Keeping the bridge in sync

`src/embed/exe_*.js` are byte-synced mirrors of eXe core
`public/app/common/exe_embed_bridge/` + `exe_media_bridge/`. Verify with the core
drift checker: `node scripts/check-embed-sync.mjs --nextcloud <this-repo>`. They are
excluded from ESLint/Biome (they keep upstream's code style).
