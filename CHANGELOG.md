# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
