# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Bump supported range to Nextcloud 31–33 and PHP 8.2–8.5 (was NC 29–31,
  PHP 8.1–8.3). Drops 28/29/30 — all EOL upstream. See #12.
- Build the Files-app integration against `@nextcloud/files@^4`. NC 33
  ships the v4 globals at `window._nc_files_scope.v4_0` and silently
  ignored actions registered with the v3 build.
- `Makefile` now defaults `NC_VERSION=stable` (was `nextcloud:30`).
  Override with `NC_VERSION=33 make up` to pin a major.
- Migrate frontend from Vue 2.7 to Vue 3.5 (see #13). All SFCs now use
  `defineComponent`; `beforeDestroy` is `beforeUnmount`; `::v-deep` is
  `:deep()`; the root in `view-page.ts` uses `createApp().mount()`. No
  public surface changes; tests, lint and webpack build pass on Vue 3.

### Added

- Viewer now makes the eXeLearning teacher-layer selector available by loading
  the package index with `?exe-teacher=1`. eXeLearning exports hide teacher-only
  content by default (see exelearning/exelearning#1972); since the Nextcloud
  Viewer is a personal file viewer — the person opening the package is
  effectively its author/teacher — the selector is always offered. It stays OFF
  by default; the viewer reveals it, and the package's own JS persists the
  choice and propagates the param across in-package navigation.
- CI matrix (`.github/workflows/ci.yml`) covering NC 31/32/33 × PHP
  8.2/8.3/8.4 with rotated databases (sqlite/mysql/pgsql), plus an
  experimental PHP 8.5 cell. Each cell installs the app into a real
  Nextcloud server and verifies it enables cleanly, and an API-level
  end-to-end job exercises the editor-preview management and serving routes
  against a live Nextcloud (CSRF enforcement, ownership, revision publish,
  sandbox CSP, session deletion).
- `make ci-matrix` reproduces the matrix locally with Docker.
- README "Compatibility" table backed by the CI matrix and a CI badge.
- Initial Nextcloud app scaffold (`appinfo/info.xml`, routes, bootstrap).
- `@nextcloud/viewer` handler for `.elpx`, `.elp`, `application/zip` and
  `application/octet-stream` MIME types.
- In-browser ZIP extraction with fflate, path normalization and ZIP-bomb
  guards (`src/elpx/*`).
- Scoped Service Worker at `/apps/exelearning/runtime/` for serving extracted
  package assets without a second server round-trip.
- Sandboxed iframe renderer
  (`allow-scripts allow-same-origin allow-forms allow-popups allow-downloads`).
- Backend `PackageController`, `AssetController`, `ThumbnailController`,
  `EditorController`, `SwController`.
- `ElpxPreviewProvider` returning `screenshot.png` as the thumbnail.
- Optional editor scaffold using the upstream postMessage protocol
  (`src/editor/*`); preview works without it.
- Files-app actions: open in viewer, edit (when editor installed), download.
- Vitest unit tests for path normalization, MIME detection, package
  validation, ZIP reader, and Files MIME helpers.
- PHPUnit unit tests for `ZipEntryService::normalizeEntry` and the
  `Application` MIME constants.
- Makefile targets mirrored from `gdrive-exelearning`
  (`download-editor`, `fetch-editor-source`, `build-editor`, `clean-editor`,
  plus `build`, `dev`, `watch-js`, `lint`, `typecheck`, `test`, `clean`,
  `appstore`).

### Security

- ZIP entry paths normalized; `..`, absolute paths and NUL-tainted entries
  rejected on both the PHP and TS sides.
- Permissions re-checked in PHP for every request — Service Worker URLs are
  intercepted client-side from the same session, but server-side downloads
  enforce user, MIME and size.
- `content.xml` is never parsed by this app.
