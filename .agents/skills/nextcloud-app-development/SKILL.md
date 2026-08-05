---
name: nextcloud-app-development
description: "Use when touching lib/ or appinfo/ in this Nextcloud app: how the app bootstraps, where controllers/services/routes belong, dependency injection, the preview provider, what OCP surface is allowed, and the traps that only show up on a real Nextcloud."
compatibility: "Nextcloud app framework, PHP 8.1+, namespace OCA\\ExeLearning. Unit tests run with no Nextcloud, no database and no web server."
---

# Developing this Nextcloud app

## When to use

Before adding or changing anything under `lib/`, `appinfo/` or `templates/`, and
before deciding whether some new behaviour belongs server-side at all.

## The shape of the app

```text
appinfo/info.xml         app id, version, dependencies, declared types
appinfo/routes.php       every HTTP route, one entry per controller method
lib/AppInfo/Application.php
                         bootstrap: init script, preview provider registration
lib/Controller/*.php     the HTTP boundary — one controller per concern
lib/Service/*.php        the logic — package lookup, permissions, ZIP entries
lib/Preview/ElpxPreviewProvider.php
                         Nextcloud preview provider for .elpx
```

Controllers today: `Asset`, `Editor`, `Package`, `Sw`, `Template`, `Thumbnail`,
`View`. Services: `ElpxPackageService`, `PermissionService`, `ZipEntryService`.

Keep that split. A controller parses and validates the request, calls a service,
and returns a response. Logic that could be unit-tested without HTTP belongs in a
service — that is the whole reason the tests can run with no Nextcloud present.

## Dependency injection

**Constructor injection only.** Never reach into the container, never call a
singleton locator, never `new` a service inside a controller. Nextcloud's
`QueryBuilder`-style autowiring resolves constructor type hints; a service that
takes its collaborators as constructor arguments is also a service you can
instantiate directly in a unit test with fakes.

`lib/AppInfo/Application.php` is the only place that registers things globally
(the init script and the preview provider). Resist adding more there: anything
registered at boot runs for every Nextcloud page load, including pages that have
nothing to do with this app.

## Routes

Every route goes in `appinfo/routes.php`. Two rules that bite:

- The route name must match `Controller#method` exactly, or Nextcloud 404s with
  no useful message.
- A route that serves package bytes must go through `PermissionService` before
  `ZipEntryService`. Never trust a file id from the request: resolve it through
  the user's own storage so Nextcloud's own permission model applies.

## What must stay out of the server

From `AGENTS.md`, and worth repeating because it is the most common drift:

- **Do not parse `content.xml`.** The viewer needs `index.html` and the package
  assets. `ZipEntryService` is deliberately restricted to named entries;
  `screenshot.png` is the only entry the preview provider pulls out by name.
- **Keep `.elpx` opaque.** Saving is a passthrough — the editor's exported bytes
  are written back unchanged. Do not regenerate, patch or rewrite packages
  server-side.
- **No non-Nextcloud backend.** Anything server-side is a controller, a service
  or a preview provider. Authentication, CSRF and permissions come from
  Nextcloud's APIs, not from anything hand-rolled.
- **Do not modify Nextcloud core MIME files.** Admin-side configuration is
  documented in the README.

## The preview provider

`ElpxPreviewProvider` is registered in `Application.php`. Preview generation runs
in contexts where the user session may not be what you expect and where failures
are silent — a provider that throws just yields no thumbnail. So:

- Fail by returning null, not by throwing.
- Do not assume a local file: `ZipEntryService` has a stream fallback that copies
  to a temp file for object storage and external mounts. Any new entry reader
  must keep that fallback, or the app breaks on exactly the installs that are
  hardest to debug.
- Always `@unlink` temp files in a `finally`.

## Strict types and namespace

PHP 8.1+, `declare(strict_types=1)` in **every** file, namespace
`OCA\ExeLearning`. English in code, identifiers, comments and documentation.

## Documentation

Use Context7 MCP for Nextcloud app framework and `@nextcloud/*` documentation.
The framework's APIs move, and a plausible-looking method that does not exist in
the target Nextcloud version fails only on a real install — long after your unit
tests went green. Prefer Context7 over recalling an API from memory.

`appinfo/info.xml` declares the supported Nextcloud versions; check it before
using anything recent.

## Before claiming success

Unit tests run without Nextcloud. That is a feature, and also a limitation: they
cannot tell you a route is misnamed, a service is unresolvable by the container,
or a preview provider is not registered. Those need a real install.

```bash
composer install
npm run typecheck
npm test
vendor/bin/phpunit --configuration tests/phpunit.xml
make architecture-check
```

If a change touches routing, DI registration or the preview provider, say
explicitly in the PR that it was not exercised against a running Nextcloud, if it
was not. Do not present a green unit suite as evidence for something the unit
suite structurally cannot check.

## Recording decisions

Decisions about the Nextcloud integration surface — what runs at boot, which OCP
APIs the app depends on, how permissions are enforced, what the preview provider
guarantees — are durable. Write an ADR. See the `architecture-records` skill.
