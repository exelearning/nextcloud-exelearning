# nextcloud-exelearning

[![CI](https://github.com/exelearning/nextcloud-exelearning/actions/workflows/ci.yml/badge.svg)](https://github.com/exelearning/nextcloud-exelearning/actions/workflows/ci.yml)

<a href="https://ateeducacion.github.io/nextcloud-playground/?blueprint-url=https://raw.githubusercontent.com/exelearning/nextcloud-exelearning/main/blueprint.json" target="_blank" rel="noopener noreferrer"><img src="https://raw.githubusercontent.com/ateeducacion/nextcloud-playground/refs/heads/main/assets/playground-preview-button.svg" alt="Open in Nextcloud Playground" width="224"></a>

> ℹ️ The eXeLearning editor is bundled into the app, so it works out of the box when the playground boots; the first load may take a few extra seconds while the app is installed. Viewer, preview and editing work normally.

Preview and edit [eXeLearning](https://exelearning.net/) `.elpx` packages
directly inside Nextcloud Files.

## Quick test (no install)

The fastest way to try this app is the **Nextcloud Playground** — a full
Nextcloud running in your browser via WebAssembly, no server required. Click
the badge at the top of this README and you'll get a fresh Nextcloud with:

* The `exelearning` app installed and enabled.
* The Files app open, logged in as `admin` / `admin`.

Upload an `.elpx` package in Files and click it to open the viewer. Nothing is
installed locally; everything runs in the browser and is discarded when you
close the tab.

The instance is provisioned from [`blueprint.json`](blueprint.json), which
installs the app with the playground's `installApp` step from a built
`exelearning.zip` artifact. Every pull request gets its own one-click
playground link (posted as a PR comment) built from that branch — see
[`.github/workflows/playground-preview.yml`](.github/workflows/playground-preview.yml).

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

## Compatibility

Each cell below is exercised in CI on a real Nextcloud install with the
listed PHP version (sqlite throughout — this app has no DB schema of
its own, so a multi-DB sweep would only re-test Nextcloud core). The
matrix is defined in [`.github/workflows/ci.yml`](.github/workflows/ci.yml);
reproduce it locally with `make ci-matrix`.

| Nextcloud           | PHP        | Status                              |
| ------------------- | ---------- | ----------------------------------- |
| 31 (best effort)    | 8.2, 8.4   | Supported (verified in CI)          |
| 32                  | 8.3        | Supported (verified in CI)          |
| 33                  | 8.2, 8.4   | Supported (verified in CI)          |
| 33                  | 8.5        | Experimental (allow-failure in CI)  |

Older Nextcloud versions (28, 29, 30) are EOL and not part of the matrix.
NC 31 enters the supported range as best effort because upstream
maintenance ends mid-2026; NC 32 and 33 are the reference targets.

| Component       | Version                                |
|-----------------|----------------------------------------|
| Nextcloud       | 31, 32 or 33                           |
| PHP             | 8.2 – 8.5 (8.5 experimental)           |
| Node            | 20, 22 or 24 LTS                       |
| npm             | 10 or 11                               |
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

The first run pulls the `nextcloud:stable` image (resolves to the current
upstream major) and the SQLite install finishes in seconds; subsequent
`make up` invocations skip the pull. Pin a specific major with
`NC_VERSION=33 make up`.

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
make up NC_VERSION=33              # pin a Nextcloud major (default: stable)
make up DOCKER_IMAGE=nextcloud:33-apache  # full image override
make up NC_ADMIN_USER=ernesto NC_ADMIN_PASS=changeme
make up DOCKER_NAME=nc-test        # use a different container name
```

Reproduce the full CI matrix locally:

```bash
make ci-matrix                     # iterates NC 31/32/33 × PHP 8.2/8.3/8.4
NC_VERSIONS=33 PHP_VERSIONS=8.4 make ci-matrix  # narrow to one cell
```

Data lives only in the container; `make down` is destructive — add your
own `-v` mount on `/var/www/html/data` if you need to keep uploads.

If you would rather wire it up by hand (no Make), the equivalent commands
are documented in [DEVELOPMENT.md](DEVELOPMENT.md#troubleshooting) under
"The 'apps' folder is not writable".

## Development, testing and architecture

Developer documentation lives in [`DEVELOPMENT.md`](DEVELOPMENT.md):

- [Local development](DEVELOPMENT.md#local-development) — composer / npm
  setup, linking into a Nextcloud install, watch mode.
- [Running tests](DEVELOPMENT.md#running-tests) — Vitest and PHPUnit.
- [Verifying the viewer works](DEVELOPMENT.md#verifying-the-viewer-works)
  — what to look for in DevTools.
- [MIME mapping](DEVELOPMENT.md#mime-mapping) — `.elpx` / `.elp`
  registration plus the static icon alias.
- [Viewer integration](DEVELOPMENT.md#viewer-integration) — the data flow
  from click to sandboxed iframe.
- [Thumbnails](DEVELOPMENT.md#thumbnails) — preview provider and fallback.
- [Optional editor support](DEVELOPMENT.md#optional-editor-support) —
  enabling the in-app eXeLearning editor.
- [Security model](DEVELOPMENT.md#security-model) — sandbox flags, SW
  scope, ZIP limits.
- [Known limitations](DEVELOPMENT.md#known-limitations) and
  [Troubleshooting](DEVELOPMENT.md#troubleshooting).

Coding agents should also read [`AGENTS.md`](AGENTS.md) for the rules and
conventions that apply when generating code.

## Issues and Support

Issue tracking for this app is centralized in the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning) repository.
Please [open new issues there](https://github.com/exelearning/exelearning/issues/new),
and browse [existing `nextcloud`-labeled issues](https://github.com/exelearning/exelearning/issues?q=is%3Aissue+label%3Anextcloud)
before reporting a bug or requesting a feature.

## License

AGPL-3.0-or-later. See [`LICENSE`](LICENSE).
