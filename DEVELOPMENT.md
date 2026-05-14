# Development guide

Reference documentation for developers working on `nextcloud-exelearning`.
For the user-facing overview and quick start, see [`README.md`](README.md).
For the rules coding agents must follow, see [`AGENTS.md`](AGENTS.md).

## Local development

```sh
composer install
npm install
npm run build      # writes js/exelearning-main.js + chunks
npm run typecheck
npm test
```

To work on the frontend with auto-rebuild:

```sh
npm run watch
```

To install the app into a local Nextcloud:

```sh
ln -s "$(pwd)" /path/to/nextcloud/apps/exelearning
sudo -E -u www-data php /path/to/nextcloud/occ app:enable exelearning
```

The Service Worker requires `https://` or a `localhost` origin. A plain
`http://` deployment over the LAN will fail to register the worker and the
viewer will show "Service Workers are not available in this browser
context."

## Running tests

The repository ships pure tests that do not need a running Nextcloud
instance.

```sh
# JavaScript / TypeScript
npm install
npm run typecheck    # vue-tsc
npm test             # vitest — 46 cases covering paths, MIME, validator,
                     #          zip-reader, files MIME helpers
```

```sh
# PHP — minimal unit tests without composer.
#       Uses tests/bootstrap-standalone.php which stubs the OCP base classes
#       so the tests can run in any PHP 8.1+ environment.
php /tmp/phpunit.phar \
    --no-coverage --no-configuration \
    --bootstrap=tests/bootstrap-standalone.php \
    tests/Unit
```

With a real composer install (`composer install`) you can run the same
suite via `vendor/bin/phpunit --configuration tests/phpunit.xml`.

## Verifying the viewer works

After opening a `.elpx` you should see, in the browser DevTools:

- **Network** — a `GET /apps/exelearning/package/by-file-id/{id}` request
  that returns `application/vnd.exelearning.elpx`.
- **Application → Service Workers** — a worker registered against this
  origin with scope `/apps/exelearning/runtime/`.
- **Network (with "Other" filter on)** — requests to
  `/apps/exelearning/runtime/{sessionId}/index.html` and to relative assets
  beneath that path, all served by the Service Worker (the size column
  shows "(ServiceWorker)").
- A sandboxed `<iframe>` in the Viewer modal whose `src` matches the
  runtime URL.

## MIME mapping

Nextcloud does not know about `application/vnd.exelearning.elpx` by default,
so existing `.elpx` uploads are usually detected as `application/zip` or
`application/octet-stream`. The viewer handler covers both, but a clean
admin install should also configure mapping:

`config/mimetypemapping.json`:

```json
{
    "elpx": ["application/vnd.exelearning.elpx", "application/zip"],
    "elp":  ["application/vnd.exelearning.elpx", "application/zip"]
}
```

Both extensions get the same vendor MIME so they share the eXeLearning
icon (and the same viewer / editor flow). Legacy `.elp` content is
detected by the editor and migrated to `.elpx` on first save — see
issue #20.

Then refresh Nextcloud's MIME caches:

```bash
sudo -E -u www-data php occ maintenance:mimetype:update-js
sudo -E -u www-data php occ maintenance:mimetype:update-db --repair-filecache
```

Do **not** edit Nextcloud core `mimetypemapping.dist.json` directly.

### Static `.elp(x)` MIME icon (recommended)

The Files list normally shows the preview provided by
`ElpxPreviewProvider` (the package's `screenshot.png`, or the bundled
fallback when there is none). For contexts that bypass `core/preview`
— sharing dialogs, breadcrumbs, the new Vue Files app's icon column —
configure the static MIME icon too:

1. Add the alias to `config/mimetypealiases.json`:

   ```json
   {
       "application/vnd.exelearning.elpx": "exelearning",
       "application/x-exelearning":        "exelearning"
   }
   ```

2. Copy this app's icon into Nextcloud core (the only directory
   `maintenance:mimetype:update-js` scans for SVGs — see the comment
   at the top of `core/js/mimetypelist.js`):

   ```bash
   sudo install -o www-data -g www-data -m 0644 \
       /var/www/nextcloud/apps/exelearning/img/filetypes/exelearning.svg \
       /var/www/nextcloud/core/img/filetypes/exelearning.svg
   ```

3. Refresh the MIME caches again:

   ```bash
   sudo -E -u www-data php occ maintenance:mimetype:update-js
   sudo -E -u www-data php occ maintenance:mimetype:update-db --repair-filecache
   ```

Step 2 is brittle because Nextcloud upgrades may replace `core/img/`;
restore the SVG after each upgrade or stage it via a theme override.
The dev stack (`make up`) does this automatically — see the
`registering .elp(x) MIME mapping + icon alias` step in the Makefile.

## Viewer integration

The app registers a Viewer handler from `src/main.ts`, which is loaded as a
Nextcloud init script (`\OCP\Util::addInitScript`) so the handler is
available before the Viewer probes for MIME associations. The handler
component lives at `src/viewer/ElpxViewer.vue` and orchestrates:

```text
ElpxViewer.vue
   ↓
elpx/elpx-loader.ts          → GET /apps/exelearning/package/by-file-id/{id}
   ↓
elpx/zip-reader.ts (fflate)  → in-memory ZIP extraction with size/entry limits
   ↓
elpx/package-validator.ts    → require index.html
   ↓
elpx/viewer-session.ts       → session id + per-entry map
   ↓
elpx/service-worker-client.ts → POST session into js/exelearning-sw.js
   ↓
elpx/iframe-renderer.ts      → sandboxed iframe at
                               /apps/exelearning/runtime/{session}/index.html
```

The Service Worker is scoped to `/apps/exelearning/runtime/` and never
intercepts other Nextcloud routes.

## Thumbnails

`lib/Preview/ElpxPreviewProvider.php` extracts `screenshot.png` from the
package and returns it as a Nextcloud preview image. `content.xml` is never
parsed.

When the package has no `screenshot.png` (or it fails to decode), the
provider falls back to the bundled `img/elpx-preview-fallback.png` —
a white document silhouette with the upstream eXeLearning logo —
generated from the upstream PNG by `tools/gen-preview-fallback.py`.

The matching MIME icon for non-preview contexts lives at
`img/filetypes/exelearning.svg`. Nextcloud does not auto-discover icons
in app directories; see [Static `.elp(x)` MIME icon](#static-elpx-mime-icon-recommended)
for the admin steps that wire it up.

## Optional editor support

The static eXeLearning editor is not bundled. To enable the "Edit with
eXeLearning" action:

```sh
make download-editor                     # prebuilt release zip
# or
make build-editor                        # clones source and builds it
```

The editor is dropped into `js/editor/`. Reload the page; the action will
appear in the Files row menu.

Save flow:

1. The editor iframe posts `SAVE_FILE` with the new bytes.
2. `editor-page.ts` POSTs them to `/apps/exelearning/editor/save` with the
   open-file `If-Match` ETag.
3. The PHP controller refuses the write if the file changed on disk
   (`HTTP 412`).

## Security model

- `.elpx` HTML never runs in the parent Nextcloud window.
- The iframe uses
  `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads"`.
- The Service Worker only intercepts URLs under
  `/apps/exelearning/runtime/`.
- All ZIP paths are normalized; `..`, absolute paths and NUL-tainted entries
  are rejected.
- Decompressed packages are bounded by:
  - `maxPackageSizeMb` = 250 MB compressed
  - `maxUncompressedSizeMb` = 500 MB
  - `maxZipEntries` = 5000
- The backend re-checks user, file existence, read permission, MIME, and
  size before serving any bytes.
- `content.xml` is never parsed by this app.

## Known limitations

- Public-share preview only works when the share grants read access.
  Anonymous viewing of `.elpx` over a public link beyond Nextcloud's normal
  share rules is not implemented.
- The Service Worker requires an HTTPS or `localhost` origin.
- Browsers without `DecompressionStream` and `crypto.randomUUID` are not
  supported (modern evergreen browsers only).

## Troubleshooting

### "The 'apps' folder is not writable"

This is a Nextcloud admin warning, not an app error. It appears when
Nextcloud has no writable directory in its `apps_paths` list, which blocks
installing or updating apps from the App Store. Two fixes:

**1. Add a writable `custom_apps` path (recommended):**

```bash
sudo -E -u www-data php occ config:system:set apps_paths 0 path     --value=/var/www/html/apps
sudo -E -u www-data php occ config:system:set apps_paths 0 url      --value=/apps
sudo -E -u www-data php occ config:system:set apps_paths 0 writable --value=false --type=boolean
sudo -E -u www-data php occ config:system:set apps_paths 1 path     --value=/var/www/html/custom_apps
sudo -E -u www-data php occ config:system:set apps_paths 1 url      --value=/custom_apps
sudo -E -u www-data php occ config:system:set apps_paths 1 writable --value=true  --type=boolean
sudo chown -R www-data:www-data /var/www/html/custom_apps
```

Adjust the absolute paths to match your install layout. The Docker
quick-start uses `/var/www/html/...`; a Debian/Ubuntu package install
typically uses `/var/www/nextcloud/...`.

**2. Or disable the App Store entirely** (read-only deploys):

```bash
sudo -E -u www-data php occ config:system:set appstoreenabled --value=false --type=boolean
```

This app does not need the App Store to run — it is shipped through the
filesystem — so disabling it is harmless.

### "Service Workers are not available in this browser context"

The viewer needs a secure context. Use `https://` or browse Nextcloud at
`http://localhost`. Plain `http://` over a LAN address will fail.

### `.elpx` opens as a generic ZIP

You skipped the MIME refresh. Re-run:

```bash
sudo -E -u www-data php occ maintenance:mimetype:update-js
sudo -E -u www-data php occ maintenance:mimetype:update-db --repair-filecache
```

Then hard-reload the Files page. The viewer also recognises `.elpx` by
extension as a fallback, so existing uploads still open even before the
MIME refresh — only the icon and the default action lookup change.

### Viewer says "Session not registered"

The Service Worker was unregistered or restarted between the page handing
over the bytes and the iframe asking for them. Close and reopen the file
in the Viewer; the bytes are re-extracted on every open.

### `nextcloud-exelearning-main.js` is missing

`js/` is build output. Run `npm install && npm run build`. The Service
Worker source lives at `src/sw/exelearning-sw.js` and is served by
`SwController`, not built — it never disappears.

### Tests cannot find PHPUnit

Either `composer install` (full project setup) or download a PHAR:

```bash
curl -fsSL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-10.5.phar
php /tmp/phpunit.phar --bootstrap=tests/bootstrap-standalone.php tests/Unit
```

## Acceptance criteria

See [`AGENTS.md`](AGENTS.md) and the MVP acceptance list in the project
specification for the full checklist used during reviews.
