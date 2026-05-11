# Agents conventions for `nextcloud-exelearning`

This document is for coding agents (and humans) maintaining this Nextcloud
app. Read it before generating code.

## What this project is

A **real Nextcloud app** that previews and (optionally) edits eXeLearning
`.elpx` packages from Nextcloud Files. The viewer extracts the ZIP in the
browser, registers a scoped Service Worker, and renders `index.html`
inside a sandboxed iframe.

## What this project is **not**

- It is not a static web app. There is no GitHub Pages deploy.
- It is not Google Drive. The app must never depend on Google Identity
  Services, the Drive SDK, the Drive Picker, the Drive UI integration,
  OAuth, GitHub Pages, or refresh tokens.
- It does not modify Nextcloud core MIME files. Admin-side configuration
  is documented in the README.

If you find code copied from `gdrive-exelearning`, audit it. Reuse only the
pieces that genuinely apply to a Nextcloud app (e.g. the editor `postMessage`
protocol, the iframe boot HTML pattern). Remove everything Drive-specific.

## Hard rules

- **Inspect local code before generating.** If files already exist, read
  them and decide whether to reuse, replace, or rename — never paste a fresh
  scaffold over working code.
- **Do not parse `content.xml`.** The viewer only needs `index.html` plus
  the package assets. The PHP-side `ZipEntryService` is restricted to
  named entries; `screenshot.png` is the only entry the preview provider
  pulls out by name.
- **Keep `.elpx` packages opaque.** Do not regenerate, patch, or rewrite
  package contents from the app. Saving is a passthrough: the editor's
  exported bytes are written back unchanged.
- **Do not add a non-Nextcloud backend.** Anything server-side runs as a
  Nextcloud controller, service, or preview provider. CSRF protection,
  authentication and permissions go through Nextcloud's APIs.
- **Use `@nextcloud/viewer`, `@nextcloud/files`, `@nextcloud/axios`,
  `@nextcloud/router`, `@nextcloud/l10n`**. Do not introduce React, Angular,
  or alternative HTTP clients.
- **Service Worker scope must stay narrow.** Only
  `/apps/exelearning/runtime/` may be intercepted. The SW must never see
  arbitrary Nextcloud URLs.
- **The editor must be optional.** Preview-only installs are first-class.
  Do not make any preview code path depend on `js/editor/` being present.
- **Never commit `js/editor/`.** Those files are downloaded or built by
  `make download-editor` / `make build-editor` and tracked elsewhere.

## Coding conventions

- PHP 8.1+, strict types in every file, namespace `OCA\ExeLearning`.
  Constructor injection only — no direct singleton lookup.
- TypeScript strict mode; Vue only inside `src/viewer/*` and `src/editor/*`.
- English in source code, identifiers, comments, documentation, and
  first-version UI strings.
- Tests live in `tests/js/**/*.test.ts` (Vitest) and `tests/Unit/**/*.php`
  (PHPUnit). Keep them fast and pure; integration is for CI against a real
  Nextcloud.
- Path normalization is **the** security boundary for package assets. Any
  new helper that handles entries must call `normalizeEntryPath` (TS) or
  `ZipEntryService::normalizeEntry` (PHP).

## Documentation lookup

Use Context7 MCP for current library documentation (Nextcloud app
framework, `@nextcloud/*` packages, fflate, Vue 2, Vitest, PHPUnit, …).
Prefer it over web search for library docs even when you think you know the
answer — your training cutoff probably misses the latest APIs.

## Before claiming success

Always run, and quote command output in the PR:

```sh
composer install
npm install
npm run typecheck
npm test
npm run build
make -n download-editor fetch-editor-source build-editor clean-editor build dev lint typecheck
git diff --check
```

For PHP changes also run:

```sh
vendor/bin/phpunit --configuration tests/phpunit.xml
```

If a command does not run yet because the dependency is missing in the
environment, say so explicitly. Do not claim "tests pass" when you have not
seen them pass.

## Where to look

- `src/main.ts` — registers the Viewer handler and Files actions.
- `src/viewer/ElpxViewer.vue` — the Viewer modal component.
- `src/elpx/*` — pure-TS extraction, validation, session, SW client, paths.
- `src/files/*` — Files-app integration: MIME helpers and actions.
- `src/editor/*` — optional editor scaffold using the upstream embedding
  protocol; safe to ignore unless touching the editor.
- `js/exelearning-sw.js` — the Service Worker itself, hand-written.
- `lib/AppInfo/Application.php` — app bootstrap, init script, preview
  provider registration.
- `lib/Controller/*` — HTTP boundary, one controller per concern.
- `lib/Service/*` — package lookup, permission checks, ZIP entry reads.
- `lib/Preview/ElpxPreviewProvider.php` — Nextcloud preview provider.

## Repository layout

```text
nextcloud-exelearning/
├── appinfo/                # info.xml, routes.php
├── lib/                    # PHP backend (Application, Controllers, ...)
├── src/                    # TypeScript / Vue frontend
├── js/                     # build outputs + hand-written sw + editor bundle
├── img/                    # app icon and MIME icon
├── templates/              # PHP templates rendered server-side
├── tests/                  # Unit + JS tests
├── composer.json
├── package.json
├── webpack.config.js
├── tsconfig.json
├── Makefile
├── README.md
├── AGENTS.md
└── CHANGELOG.md
```
