# nextcloud-exelearning

Preview and edit [eXeLearning](https://exelearning.net/) `.elpx` packages
directly inside Nextcloud Files.

## What this app does

When a user clicks a `.elpx` file in Nextcloud Files:

1. A Nextcloud Viewer modal opens.
2. The package is downloaded for the current user.
3. The browser extracts the ZIP archive in memory.
4. The internal `index.html` renders inside a **sandboxed iframe**.
5. Relative assets (`html/`, `content/`, `libs/`, `theme/`, `idevices/`,
   images, CSS, JS, audio, video, …) are served by a scoped Service Worker
   from the in-memory extraction — no second request to the server.

Optional features:

- Thumbnails powered by `screenshot.png` inside the `.elpx` package.
- "Edit with eXeLearning" action that opens the bundled static eXeLearning
  editor (requires `make download-editor`).

## How this is different from sibling projects

- **gdrive-exelearning** is a static, browser-only web app that integrates
  with Google Drive. This project is a real Nextcloud app: no OAuth, no
  Drive Picker, no GitHub Pages, no Google client SDKs. Permission and
  permission checks live in Nextcloud's APIs.
- **exeviewer** is the architectural inspiration for in-browser ZIP
  extraction and Service Worker routing. This project keeps that core idea
  but binds the entry points (URL routing, authentication, file access) to
  Nextcloud.

## Requirements

| Component       | Version                                |
|-----------------|----------------------------------------|
| Nextcloud       | 29, 30 or 31                           |
| PHP             | 8.1 or 8.2 (8.3 supported, see info.xml) |
| Node            | 20 LTS                                 |
| npm             | 10                                     |
| Bun (optional)  | latest stable, only for `build-editor` |

Browsers must support Service Workers in the Nextcloud origin. The viewer
will refuse to start otherwise.

## Quick start with Docker

A single `make` target builds the frontend, starts a Nextcloud container
with auto-install enabled (admin user + SQLite), copies the app files into
the container, configures `apps_paths` so Nextcloud can write to
`custom_apps/`, enables the app, and registers the `.elpx` MIME mapping:

```bash
make up
```

The first run pulls the `nextcloud:30` image and the SQLite install
finishes in seconds; subsequent `make up` invocations skip the pull.

When the script prints "Nextcloud + eXeLearning is ready" open
<http://localhost:8080> and log in as `admin` / `admin`. Upload an `.elpx`
in the Files app and click it.

### Why `docker cp` instead of a bind mount?

The Makefile copies `appinfo/`, `lib/`, `js/`, `templates/`, `img/` and
`src/sw/` into the container with `docker cp` after Nextcloud is
installed. It does **not** bind-mount the repo (`-v $(pwd):…`). On
Docker-on-macOS bind mounts go through a slow shared filesystem, and
pulling `node_modules/` (≈1 GB, hundreds of thousands of files) through it
during Nextcloud's startup stalls the install. Copying just the runtime
files (a few MB) brings `make up` down to seconds.

If you change PHP or rebuild the frontend, `make sync` re-copies the same
six directories into the running container so you do not have to
`make restart`:

```bash
npm run build && make sync
```

### Related targets

| Target          | What it does                                              |
|-----------------|-----------------------------------------------------------|
| `make up`       | Build + start Nextcloud with this app installed           |
| `make sync`     | Re-copy app files into the running container              |
| `make down`     | Stop and remove the container                             |
| `make restart`  | `make down` then `make up`                                |
| `make logs`     | Tail the Nextcloud container logs                         |
| `make shell`    | Open a `www-data` shell inside the container              |
| `make status`   | Run `occ status` against the container                    |

### Tunables

```bash
make up DOCKER_PORT=9000           # serve on http://localhost:9000
make up DOCKER_IMAGE=nextcloud:31  # pin a different image
make up NC_ADMIN_USER=ernesto NC_ADMIN_PASS=changeme
make up DOCKER_NAME=nc-test        # use a different container name
```

Data lives only in the container; `make down` is destructive — add your
own `-v` mount on `/var/www/html/data` if you need to keep uploads.

If you would rather wire it up by hand (no Make), the equivalent commands
are documented in [Troubleshooting](#troubleshooting) under "The 'apps'
folder is not writable".

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
    "elpx": ["application/vnd.exelearning.elpx", "application/zip"]
}
```

`config/mimetypealiases.json` (optional, controls the file icon):

```json
{
    "application/vnd.exelearning.elpx": "exelearning"
}
```

Then refresh Nextcloud's MIME caches:

```bash
sudo -E -u www-data php occ maintenance:mimetype:update-js
sudo -E -u www-data php occ maintenance:mimetype:update-db --repair-filecache
```

Do **not** edit Nextcloud core `mimetypemapping.dist.json` directly.

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

If the screenshot is missing, Nextcloud falls back to the generic MIME icon
in `img/mimetype-exelearning.svg`.

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

## License

AGPL-3.0-or-later. See [`LICENSE`](LICENSE).
