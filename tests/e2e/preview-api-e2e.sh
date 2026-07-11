#!/usr/bin/env bash
#
# API-level end-to-end test of the editor-preview serving contract v2 against a
# REAL running Nextcloud (no browser).
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
# It asserts: CSRF enforcement (412), the create->asset->revision->serve
# round-trip, the authless opaque serving response (200 + sandbox CSP, no
# allow-same-origin), the bare-root 302, owner-scoping (403), dropped-part
# rejection (400) without advancing the revision, and 404 after delete.
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

# 0. Sanity — Basic auth actually authenticates user1 (so a later 412 is a real
#    CSRF rejection, not an auth failure hiding behind it).
who="$(curl -s -u "$U1:$P1" -H 'OCS-APIRequest: true' "$ROOT/ocs/v2.php/cloud/user?format=json" | jq -r '.ocs.data.id // empty' 2>/dev/null || true)"
[ "$who" = "$U1" ] || fail "Basic auth did not authenticate $U1 (cloud/user id='$who')"
echo "  ok: Basic auth authenticates $U1"

# 1. CSRF enforced — an AUTHENTICATED management POST WITHOUT the OCS-APIRequest
#    header (and no requesttoken) trips the CSRF middleware → 412. Proves the
#    management route is not #[NoCSRFRequired]. The 412 happens in middleware, so
#    no session is created.
csrf="$(curl -s -o "$TMP/csrf.txt" -w '%{http_code}' -u "$U1:$P1" -X POST "$MGMT")"
[ "$csrf" = "412" ] || fail "CSRF proof: expected 412 without the CSRF-safe header, got $csrf (body: $(head -c 200 "$TMP/csrf.txt"))"
grep -qi 'csrf' "$TMP/csrf.txt" || echo "  note: 412 body did not explicitly mention CSRF: $(head -c 120 "$TMP/csrf.txt")"
echo "  ok: management enforces CSRF (412 for an authenticated request without the CSRF-safe header/token)"

# 2. Create → 201 { previewId, protocolVersion: 2 }.
resp="$(api "$U1" "$P1" POST "$MGMT")"
pid="$(printf '%s' "$resp" | jq -r '.previewId // empty')"
[ -n "$pid" ] || fail "create returned no previewId: $resp"
[ "$(printf '%s' "$resp" | jq -r '.protocolVersion')" = "2" ] || fail "protocolVersion != 2: $resp"
echo "  ok: created session $pid"

# 3. Upload an asset (multipart: assets JSON + index-aligned files[]).
KEY="aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8"
printf 'IMGBYTES!' > "$TMP/asset.bin" # 9 bytes
api "$U1" "$P1" POST "$MGMT/$pid/assets" \
	-F "assets=[{\"key\":\"$KEY\",\"size\":9}]" \
	-F "files[]=@$TMP/asset.bin" >/dev/null
echo "  ok: uploaded asset"

# 4. Publish revision 1.
printf '<html><body>preview-v1</body></html>' > "$TMP/index.html"
resp="$(api "$U1" "$P1" POST "$MGMT/$pid/revisions" \
	-F "revision={\"baseRevision\":0,\"nextRevision\":1,\"writes\":[\"index.html\"],\"deletes\":[],\"assetRefs\":{\"content/photo.png\":\"$KEY\"},\"fixedRefs\":{}}" \
	-F "files[]=@$TMP/index.html")"
[ "$(printf '%s' "$resp" | jq -r '.revision')" = "1" ] || fail "publish revision != 1: $resp"
echo "  ok: published revision 1"

# 5. Serve (authless, NO auth): 200 + opaque sandbox CSP + no allow-same-origin.
hdrs="$(curl -s -D - -o "$TMP/served.html" "$SERVE/$pid/index.html")"
printf '%s' "$hdrs" | grep -q '^HTTP/[0-9.]* 200' || fail "serve not 200: $(printf '%s' "$hdrs" | head -1)"
csp="$(printf '%s' "$hdrs" | grep -i '^content-security-policy:' || true)"
printf '%s' "$csp" | grep -qi 'sandbox' || fail "serving CSP missing sandbox directive: $csp"
if printf '%s' "$csp" | grep -qi 'allow-same-origin'; then
	fail "serving CSP must NOT contain allow-same-origin: $csp"
fi
grep -q 'preview-v1' "$TMP/served.html" || fail "served body missing revision content"
echo "  ok: authless serve is 200 with opaque sandbox CSP (no allow-same-origin)"

# 6. Bare capability root → 302 (never inline index.html bytes).
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid")"
[ "$code" = "302" ] || fail "bare root expected 302, got $code"
echo "  ok: bare root 302"

# 7. Owner-scoping — user2 on user1's session → 403.
code="$(api_code "$U2" "$P2" DELETE "$MGMT/$pid")"
[ "$code" = "403" ] || fail "cross-user delete expected 403, got $code"
echo "  ok: cross-user management is 403"

# 8. Dropped multipart part — 1 write declared, 0 file parts → 400, revision unchanged.
code="$(api_code "$U1" "$P1" POST "$MGMT/$pid/revisions" \
	-F "revision={\"baseRevision\":1,\"nextRevision\":2,\"writes\":[\"index.html\"],\"deletes\":[],\"assetRefs\":{},\"fixedRefs\":{}}")"
[ "$code" = "400" ] || fail "dropped-part revision expected 400, got $code"
curl -s "$SERVE/$pid/index.html" | grep -q 'preview-v1' || fail "revision advanced despite dropped part"
echo "  ok: dropped-part revision rejected (400), revision pointer unchanged"

# 9. Owner DELETE → 200, then serve → 404.
code="$(api_code "$U1" "$P1" DELETE "$MGMT/$pid")"
[ "$code" = "200" ] || fail "owner delete expected 200, got $code"
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid/index.html")"
[ "$code" = "404" ] || fail "serve after delete expected 404, got $code"
echo "  ok: owner delete 200, serve after delete 404"

echo "Preview API E2E: all checks passed."
