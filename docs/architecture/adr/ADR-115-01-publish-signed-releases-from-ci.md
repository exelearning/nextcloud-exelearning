---
id: ADR-115-01
title: "Publish signed releases to the Nextcloud appstore from CI"
status: Proposed
date: 2026-09-22
tracking_issue: 115
deciders:
  - "@erseco"
reviewers:
  - "@erseco"
related:
  prs: [115]
  changes: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-115-01: Publish signed releases to the Nextcloud appstore from CI

## Context

The app is distributed as `exelearning-<version>.tar.gz`, built by `make package`
and attached to a GitHub Release by two workflows: `release.yml` (a human cuts a
release, or a manual `workflow_dispatch`) and `auto-release.yml` (a daily cron
that rebuilds when `exelearning/exelearning` publishes a new editor tag).

Neither reaches https://apps.nextcloud.com, so the only way to install the app is
to unpack a tarball into `apps/` by hand. The appstore is how Nextcloud
administrators actually discover and update apps.

Two distinct signatures exist in the Nextcloud distribution model, both derived
from one RSA key whose CSR is approved in `nextcloud/app-certificate-requests`:

| Signature | Covers | Verified by |
|---|---|---|
| Release signature | the tarball bytes | `OC\Installer::downloadApp`, on every install from the store |
| `appinfo/signature.json` | a hash per shipped file | `OC\IntegrityCheck\Checker::verifyAppSignature`, on the running instance |

The second is optional for third-party apps — `Checker::runInstanceVerification`
only checks a non-shipped app when it ships a `signature.json` — but it is what
turns "the download was not tampered with" into "the installed files were not
tampered with".

## Problem

Where do signing and publishing belong, given that the bundled editor makes the
package version follow upstream's release cadence rather than ours?

## Decision drivers

- A release must not require a laptop with the private key on it.
- The tarball the appstore serves must be byte-identical to the one signed.
- Releases must keep working while the certificate request is pending.
- A failed publish must be retried, not silently swallowed.
- Preview-only installs stay first-class: the tarball is still self-contained.

## Options considered

### Option 1: Publish by hand through the appstore web form

No CI secrets. But every release needs the private key on someone's machine, and
`auto-release.yml` fires unattended on a cron — the publish would lag the release
by however long it takes a human to notice.

### Option 2: Sign and publish from CI, guarded on the secrets being present

The official Nextcloud organisation workflow template
(`nextcloud/.github` → `appstore-build-publish.yml`) does exactly this: sign with
`occ integrity:sign-app`, attach to the release, push the URL to the store API.

### Option 3: Publish only the signed tarball, skip `signature.json`

Fewer moving parts in CI — no Nextcloud download — but the integrity checker then
has nothing to verify on the installed instance, and tampering after download
goes unreported.

## Evidence

- `lib/private/Installer.php` (nextcloud/server, master) verifies the release
  signature against the certificate published in the store entry:
  `openssl_verify(file_get_contents($tempFile), base64_decode($app['releases'][0]['signature']), $certificate, OPENSSL_ALGO_SHA512)`.
- `lib/private/IntegrityCheck/Checker.php::runInstanceVerification` checks a
  non-shipped app only `elseif (... file_exists(getAppPath($appId) . '/appinfo/signature.json'))`.
- `lib/private/Console/Application.php` requires `core/register_command.php`
  *before* the `installed` gate, so `integrity:sign-app` runs on an extracted,
  uninstalled server tarball.
- `nextcloudappstore/api/v1/release/parser.py::parse_changelog` matches
  `^## (?:\[)?(?:v)?(\d+\.\d+(\.\d+)?)` against the released version, reading
  `<app_id>/CHANGELOG.md` out of the tarball.
- `nextcloudappstore/api/v1/release/info.xsd` restricts `<version>` to semver.

## Decision

We will sign and publish from CI, in both release workflows:

1. `tools/sign-app.sh` unpacks the built tarball, runs `occ integrity:sign-app`
   against a Nextcloud downloaded at the `min-version` declared in
   `appinfo/info.xml`, and repacks in place — after `make package` stamped the
   version, so the hashes cover exactly what ships.
2. The signed tarball is attached to the GitHub Release.
3. `nextcloud-libraries/nextcloud-appstore-push-action` posts its URL and the
   release signature to the store API.

Both steps are guarded on `APP_PRIVATE_KEY` and `APPSTORE_TOKEN` being set. The
private key lives only as a repository secret.

In `auto-release.yml` the publish runs *before* the `.editor-version` commit, so
a failed publish leaves the marker stale and the next cron run retries.

`CHANGELOG.md` ships inside the package, and `auto-release.yml` closes
`## [Unreleased]` into `## [<version>] - <date>` before packaging.

## Consequences

### Positive

- A release reaches administrators without anyone holding the signing key.
- Installed files are covered by the integrity checker, not just the download.
- Release notes appear on the store page without a manual step.

### Negative

- Each release downloads a Nextcloud server tarball (~150 MB) to run `occ`.
- The certificate is a hard external dependency: rotating it means a new PR to
  `nextcloud/app-certificate-requests` and a new repository secret.

### Neutral

- The store keeps only the download URL, so deleting a GitHub release asset
  breaks installation of that version.

## Risks

The app version is the bundled editor version, always. The appstore rejects a
version it already holds, so an app-only fix cannot be published until upstream
cuts a new editor tag. Accepted deliberately; revisit by decoupling the app
version from the editor tag if app-only fixes become common.

## Validation

The first successful publish appears at https://apps.nextcloud.com/apps/exelearning,
and a Nextcloud instance installing it from the store reports no integrity
warning under Administration → Overview.

## Follow-up work

- Approve the CSR for app id `exelearning` and register the app under the
  official account.
- Set the `APP_PRIVATE_KEY` and `APPSTORE_TOKEN` repository secrets.

## References

- https://nextcloudappstore.readthedocs.io/en/latest/developer.html
- https://github.com/nextcloud/app-certificate-requests
- https://github.com/nextcloud/.github/blob/master/workflow-templates/appstore-build-publish.yml
