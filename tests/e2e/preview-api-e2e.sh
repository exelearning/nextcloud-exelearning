#!/usr/bin/env bash
#
# API-level end-to-end test of the opaque editor-preview contract against a REAL
# running Nextcloud (no browser).
#
# Authentication is HTTP Basic auth: it authenticates per request via the
# Authorization header and needs NO cookies, so it is reliable under the built-in
# php -S server (where the browser login form's SameSite/session cookies do not
# round-trip). CSRF is still exercised honestly, using Nextcloud's own rules
# (lib/private/AppFramework/Http/Request.php::passesCSRFCheck):
#
#   * passesCSRFCheck() returns true when the OCS-APIRequest header is present,
#     so the positive management calls send it (cookieless, no requesttoken).
#   * Omitting that header on an AUTHENTICATED management POST leaves the CSRF
#     check to fail (no token, no OCS-APIRequest) → the middleware answers 412
#     "CSRF check failed" — proving the route is NOT #[NoCSRFRequired]. That is
#     the real CSRF-enforcement assertion.
#
# It asserts: CSRF enforcement (412), the publish->serve round-trip, the authless
# opaque serving response (200 + sandbox CSP, no allow-same-origin), the
# bare-root 302, replace-in-place, that a REJECTED archive leaves the live
# snapshot untouched, owner-scoping (403 on both publish and delete), and 404
# after delete.
#
# Full editor-iframe browser E2E (external video / interactive-video) stays
# blocked on a capable editor build and is documented as such, not faked here.
#
# Required environment:
#   EXE_E2E_BASE   base URL incl. the front controller, e.g.
#                  http://127.0.0.1:8080/index.php
#   EXE_E2E_USER1 / EXE_E2E_PASS1   owner
#   EXE_E2E_USER2 / EXE_E2E_PASS2   a second, non-owner user
set -euo pipefail

BASE="${EXE_E2E_BASE:?EXE_E2E_BASE is required}"
# The host root (no front controller) for endpoints served as top-level scripts
# under php -S: the OCS entry point (/ocs/v2.php/...).
ROOT="${BASE%/index.php}"
TMP="${RUNNER_TEMP:-/tmp}"
U1="${EXE_E2E_USER1:?EXE_E2E_USER1 is required}"
P1="${EXE_E2E_PASS1:?EXE_E2E_PASS1 is required}"
U2="${EXE_E2E_USER2:?EXE_E2E_USER2 is required}"
P2="${EXE_E2E_PASS2:?EXE_E2E_PASS2 is required}"
MGMT="$BASE/apps/exelearning/api/preview-session"
SERVE="$BASE/apps/exelearning/preview"

fail() { echo "E2E FAIL: $*" >&2; exit 1; }

# Authenticated management request. The OCS-APIRequest header makes Nextcloud's
# passesCSRFCheck() pass without a session cookie or requesttoken (the reliable,
# cookieless way to drive an authenticated AppFramework route in CI). It does not
# change the response shape — these are plain (non-OCS) DataResponses.
api()      { local u="$1" p="$2" m="$3" url="$4"; shift 4; curl -s                       -u "$u:$p" -H 'OCS-APIRequest: true' -X "$m" "$@" "$url"; }
api_code() { local u="$1" p="$2" m="$3" url="$4"; shift 4; curl -s -o /dev/null -w '%{http_code}' -u "$u:$p" -H 'OCS-APIRequest: true' -X "$m" "$@" "$url"; }

# Build a snapshot ZIP. Args: <output path> then `name=contents` pairs.
# python3 is used rather than `zip` so the archive contents are exact and the
# script does not depend on a zip binary being installed on the runner.
make_zip() {
	local out="$1"; shift
	python3 - "$out" "$@" <<-'PY'
		import sys, zipfile
		out, *pairs = sys.argv[1:]
		with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
		    for pair in pairs:
		        name, _, body = pair.partition("=")
		        z.writestr(name, body)
	PY
}

# 0. Sanity — Basic auth actually authenticates user1 (so a later 412 is a real
#    CSRF rejection, not an auth failure hiding behind it).
who="$(curl -s -u "$U1:$P1" -H 'OCS-APIRequest: true' "$ROOT/ocs/v2.php/cloud/user?format=json" | jq -r '.ocs.data.id // empty' 2>/dev/null || true)"
[ "$who" = "$U1" ] || fail "Basic auth did not authenticate $U1 (cloud/user id='$who')"
echo "  ok: Basic auth authenticates $U1"

# 1. CSRF enforced — an AUTHENTICATED management POST WITHOUT the OCS-APIRequest
#    header (and no requesttoken) trips the CSRF middleware → 412. Proves the
#    management route is not #[NoCSRFRequired]. The 412 happens in middleware,
#    before the handler, so no snapshot upload is needed to prove it.
csrf="$(curl -s -o "$TMP/csrf.txt" -w '%{http_code}' -u "$U1:$P1" -X POST "$MGMT")"
[ "$csrf" = "412" ] || fail "CSRF proof: expected 412 without the CSRF-safe header, got $csrf (body: $(head -c 200 "$TMP/csrf.txt"))"
grep -qi 'csrf' "$TMP/csrf.txt" || echo "  note: 412 body did not explicitly mention CSRF: $(head -c 120 "$TMP/csrf.txt")"
echo "  ok: management enforces CSRF (412 for an authenticated request without the CSRF-safe header/token)"

# 2. Publish a whole-project snapshot → { previewId }.
make_zip "$TMP/snapshot-v1.zip" \
	'index.html=<html><body>preview-v1</body></html>' \
	'content/photo.png=IMGBYTES!'
resp="$(api "$U1" "$P1" POST "$MGMT" -F "snapshot=@$TMP/snapshot-v1.zip")"
pid="$(printf '%s' "$resp" | jq -r '.previewId // empty')"
[ -n "$pid" ] || fail "publish returned no previewId: $resp"
echo "  ok: published snapshot $pid"

# 3. Serve (authless, NO auth): 200 + opaque sandbox CSP + no allow-same-origin.
hdrs="$(curl -s -D - -o "$TMP/served.html" "$SERVE/$pid/index.html")"
printf '%s' "$hdrs" | grep -q '^HTTP/[0-9.]* 200' || fail "serve not 200: $(printf '%s' "$hdrs" | head -1)"
csp="$(printf '%s' "$hdrs" | grep -i '^content-security-policy:' || true)"
printf '%s' "$csp" | grep -qi 'sandbox' || fail "serving CSP missing sandbox directive: $csp"
if printf '%s' "$csp" | grep -qi 'allow-same-origin'; then
	fail "serving CSP must NOT contain allow-same-origin: $csp"
fi
grep -q 'preview-v1' "$TMP/served.html" || fail "served body missing snapshot content"
echo "  ok: authless serve is 200 with opaque sandbox CSP (no allow-same-origin)"

# 3b. A non-scriptable file from the same snapshot is served too, and without the
#     sandbox CSP (it is an asset tier, not a document).
hdrs="$(curl -s -D - -o "$TMP/served.png" "$SERVE/$pid/content/photo.png")"
printf '%s' "$hdrs" | grep -q '^HTTP/[0-9.]* 200' || fail "asset serve not 200"
grep -q 'IMGBYTES!' "$TMP/served.png" || fail "asset body mismatch"
echo "  ok: snapshot assets serve from the same capability"

# 4. Bare capability root → 302 (never inline index.html bytes).
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid")"
[ "$code" = "302" ] || fail "bare root expected 302, got $code"
echo "  ok: bare root 302"

# 5. Replace in place — same capability id, new bytes, and a path the new archive
#    omits stops resolving (the whole tree is swapped, not merged).
make_zip "$TMP/snapshot-v2.zip" 'index.html=<html><body>preview-v2</body></html>'
resp="$(api "$U1" "$P1" POST "$MGMT" -F "snapshot=@$TMP/snapshot-v2.zip" -F "previewId=$pid")"
[ "$(printf '%s' "$resp" | jq -r '.previewId')" = "$pid" ] || fail "replace changed the capability id: $resp"
curl -s "$SERVE/$pid/index.html" | grep -q 'preview-v2' || fail "replace did not update the served bytes"
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid/content/photo.png")"
[ "$code" = "404" ] || fail "a path the new snapshot omits should 404, got $code"
echo "  ok: replace-in-place swaps the whole tree under the same capability"

# 6. A REJECTED archive must leave the live snapshot untouched — a bad upload can
#    never destroy a preview that was working.
make_zip "$TMP/snapshot-bad.zip" 'page.html=no index here'
code="$(api_code "$U1" "$P1" POST "$MGMT" -F "snapshot=@$TMP/snapshot-bad.zip" -F "previewId=$pid")"
[ "$code" = "400" ] || fail "archive without index.html expected 400, got $code"
curl -s "$SERVE/$pid/index.html" | grep -q 'preview-v2' || fail "a rejected upload clobbered the live snapshot"
echo "  ok: rejected archive is 400 and leaves the live snapshot intact"

# 7. Owner-scoping — user2 may neither replace nor delete user1's capability.
code="$(api_code "$U2" "$P2" POST "$MGMT" -F "snapshot=@$TMP/snapshot-v2.zip" -F "previewId=$pid")"
[ "$code" = "403" ] || fail "cross-user replace expected 403, got $code"
code="$(api_code "$U2" "$P2" DELETE "$MGMT/$pid")"
[ "$code" = "403" ] || fail "cross-user delete expected 403, got $code"
curl -s "$SERVE/$pid/index.html" | grep -q 'preview-v2' || fail "cross-user attempt altered the snapshot"
echo "  ok: cross-user management is 403 on both publish and delete"

# 8. Owner DELETE → 200, then serve → 404.
code="$(api_code "$U1" "$P1" DELETE "$MGMT/$pid")"
[ "$code" = "200" ] || fail "owner delete expected 200, got $code"
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid/index.html")"
[ "$code" = "404" ] || fail "serve after delete expected 404, got $code"
echo "  ok: owner delete 200, serve after delete 404"

echo "Preview API E2E: all checks passed."
