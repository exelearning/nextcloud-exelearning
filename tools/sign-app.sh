#!/usr/bin/env bash
# Sign a packaged app tarball with the Nextcloud code signing certificate.
#
#   tools/sign-app.sh <tarball> <private-key> <certificate>
#
# Adds `appinfo/signature.json` to the packaged app and repacks the tarball
# in place. Without it the integrity checker has nothing to verify; with a
# stale or wrong one every Nextcloud running a signed core reports the app
# as tampered with, so this must run *after* `make package` stamped the
# version into the staged `appinfo/info.xml`.
#
# `core/register_command.php` is loaded before occ's "is this instance
# installed" gate, so a bare extracted server tarball is enough to run
# `integrity:sign-app` — no database, no config.php.
set -euo pipefail

if [ "$#" -ne 3 ]; then
	echo "usage: $0 <tarball> <private-key> <certificate>" >&2
	exit 1
fi

abspath() { printf '%s/%s\n' "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")"; }

tarball=$(abspath "$1")
key=$(abspath "$2")
cert=$(abspath "$3")

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

tar -xzf "$tarball" -C "$work"
app_id=$(cd "$work" && ls -d -- */ | head -n1 | tr -d /)
info="$work/$app_id/appinfo/info.xml"
test -f "$info" || { echo "Error: $tarball has no $app_id/appinfo/info.xml." >&2; exit 1; }

# Sign with the oldest server we claim to support: signature.json is
# forward-compatible, the hash format is not tied to the server version.
nc_major=$(sed -n 's:.*<nextcloud[^>]*min-version="\([0-9]*\).*:\1:p' "$info" | head -n1)
test -n "$nc_major" || { echo "Error: no <nextcloud min-version> in info.xml." >&2; exit 1; }

echo ">> fetching Nextcloud $nc_major to run occ"
curl -fsSL "https://download.nextcloud.com/server/releases/latest-${nc_major}.tar.bz2" | tar -xj -C "$work"

echo ">> signing $app_id"
php "$work/nextcloud/occ" integrity:sign-app \
	--privateKey="$key" \
	--certificate="$cert" \
	--path="$work/$app_id"

test -s "$work/$app_id/appinfo/signature.json" || {
	echo "Error: occ did not write appinfo/signature.json." >&2; exit 1; }

tar -C "$work" -czf "$tarball" "$app_id"
echo ">> signed $tarball"
