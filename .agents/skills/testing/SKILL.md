---
name: testing
description: "Use when adding or fixing tests: the Vitest and PHPUnit split, why both suites run without a Nextcloud installation, what the fixtures are for, and what these tests structurally cannot prove."
compatibility: "Vitest for tests/js/**/*.test.ts, PHPUnit for tests/Unit/**/*.php. No database, no web server, no Nextcloud runtime."
---

# Testing this app

## When to use

Before adding a test, before changing `tests/phpunit.xml` or the bootstraps, and
when a test passes locally but you are unsure it proves anything.

## The split

| Suite | Location | Runner |
|---|---|---|
| Frontend | `tests/js/**/*.test.ts` | Vitest — `npm test` |
| Backend | `tests/Unit/**/*.php` | PHPUnit — `vendor/bin/phpunit --configuration tests/phpunit.xml` |
| Tooling | `tools/*_test.py` | `make architecture-test` |

Never swap them. Keep both fast and pure; integration against a real Nextcloud
belongs in CI, not in these suites.

## Why they run without Nextcloud

`tests/bootstrap.php` and `tests/bootstrap-standalone.php` exist so the PHP suite
can run with no Nextcloud, no database and no web server. That is what makes the
suite fast and what makes it runnable in a plain container.

It is also the limitation. **These tests cannot tell you** that a route name
matches its controller method, that the container can resolve a service, that the
preview provider is registered, or that an OCP method exists in the target
Nextcloud version. If your change touches routing, DI or registration, say so
explicitly rather than presenting a green suite as proof.

The corollary for design: put logic in services that take their collaborators as
constructor arguments. A service you can instantiate with fakes is a service you
can test here. A controller that reaches into the container is not.

## Fixtures

`tests/fixtures/` holds real packages:

- `propiedades.elpx`
- `un-contenido-de-ejemplo-para-probar-estilos-y-catalogacion.elpx`
- `old_elp_modelocrea.elp` — the legacy format

Use them for round-trip and parsing behaviour. Do **not** regenerate or rewrite
them to make a test pass: they are evidence of what real content looks like, and
editing one converts a failing test into a false green. If a fixture genuinely
needs to change, say why in the PR.

## What to test, concretely

The pure modules are the ones worth heavy coverage, and they already have tests:
`asset-map`, `files-mime`, `iframe-renderer`, `package-validator`, `paths`,
`zip-reader` on the TS side; `ZipEntryService` and the app constants on the PHP
side.

**Entry-path normalization deserves paired tests.** There are three
implementations and they do not currently agree — see the `elpx-package-safety`
skill. Any case you reason about should become a test on both sides, or the
divergence stays invisible.

## Before claiming success

```bash
npm run typecheck
npm test
vendor/bin/phpunit --configuration tests/phpunit.xml
make architecture-check
make architecture-test
```

Quote real output. If a command could not run because a dependency is missing in
the environment, say that explicitly — a command you skipped is not a command
that passed.
