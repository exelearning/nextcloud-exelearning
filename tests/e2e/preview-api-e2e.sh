#!/usr/bin/env bash
#
# API-level end-to-end test of the editor-preview serving contract v2 against a
# REAL running Nextcloud (no browser). It proves the load-bearing security and
# protocol properties that unit tests cannot: management CSRF enforcement,
# owner-scoping, the opaque sandbox CSP on the authless serving route, the
# corrected bare-root and dropped-part behaviours, and cleanup.
#
# Auth is cookie/session based ON PURPOSE: only cookie-authenticated requests
# exercise Nextcloud's CSRF middleware, so asserting a management POST without a
# requesttoken is rejected is a genuine CSRF-enforcement proof (Basic Auth would
# bypass CSRF and hide the property).
#
# Required environment:
#   EXE_E2E_BASE   base URL incl. the front controller, e.g.
#                  http://127.0.0.1:8080/index.php
#   EXE_E2E_USER1 / EXE_E2E_PASS1   owner
#   EXE_E2E_USER2 / EXE_E2E_PASS2   a second, non-owner user
set -euo pipefail

BASE="${EXE_E2E_BASE:?EXE_E2E_BASE is required}"
# The host root (no front controller) for endpoints served as top-level scripts
# under php -S: status.php and the OCS entry points (/ocs/v2.php/...).
ROOT="${BASE%/index.php}"
TMP="${RUNNER_TEMP:-/tmp}"
JAR1="$TMP/exe-e2e-cookies-1.txt"
JAR2="$TMP/exe-e2e-cookies-2.txt"
: > "$JAR1"
: > "$JAR2"

fail() { echo "E2E FAIL: $*" >&2; exit 1; }

# Scrape the current requesttoken from a rendered Nextcloud page (it is exposed
# as the head element's data-requesttoken — the same value the CSRF middleware
# validates). The trailing `|| true` keeps a no-match from tripping `set -e`
# inside a command substitution (the caller checks for an empty result).
scrape_token() { grep -o 'data-requesttoken="[^"]*"' | head -1 | sed 's/^[^"]*"//;s/"$//' || true; }

# Fetch a fresh requesttoken for an authenticated session from the canonical
# /csrftoken JSON endpoint (what OC.requestToken refreshes from — no HTML
# scraping, and it requires a live session so it doubles as a login check).
# Echoes the token, or dumps a diagnostic and returns non-zero (login failed) so
# the caller reports it instead of dying opaquely. A guest/unauthenticated hit
# returns a login-page redirect body rather than JSON, so jq yields empty.
post_login_token() {
	local jar="$1" resp rt
	resp="$(curl -sL -c "$jar" -b "$jar" "$BASE/csrftoken")"
	rt="$(printf '%s' "$resp" | jq -r '.token // empty' 2>/dev/null || true)"
	if [ -n "$rt" ]; then printf '%s' "$rt"; return 0; fi
	echo "diagnostic: /csrftoken returned no token (login likely failed). First 200 bytes:" >&2
	printf '%s' "$resp" | head -c 200 >&2; echo >&2
	return 1
}

# Log $user in through the real login form (GET for the login token + SameSite
# cookies, POST the credentials) and echo a fresh post-login requesttoken.
# -L follows Nextcloud's post-login redirect and keeps cookie-jar continuity.
login() {
	local user="$1" pass="$2" jar="$3" page rt
	page="$TMP/exe-e2e-page-$user.html"
	curl -sL -c "$jar" -b "$jar" "$BASE/login" -o "$page"
	rt="$(scrape_token < "$page")"
	if [ -z "$rt" ]; then
		echo "diagnostic: login page for $user had no data-requesttoken (first 400 bytes):" >&2
		head -c 400 "$page" >&2; echo >&2
		fail "no pre-login requesttoken for $user"
	fi
	local code
	code="$(curl -sL -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' \
		--data-urlencode "user=$user" \
		--data-urlencode "password=$pass" \
		--data-urlencode "requesttoken=$rt" \
		"$BASE/login")"
	# Verify the session is genuinely authenticated. /csrftoken hands out a
	# token even to a guest session, so it cannot confirm login; the OCS
	# cloud/user endpoint returns the authenticated user id or fails.
	local who
	who="$(curl -s -H 'OCS-APIRequest: true' -b "$jar" "$ROOT/ocs/v2.php/cloud/user?format=json" | jq -r '.ocs.data.id // empty' 2>/dev/null || true)"
	if [ "$who" != "$user" ]; then
		echo "diagnostic: login POST for $user returned HTTP $code but the session is NOT authenticated (cloud/user id='$who')." >&2
		echo "diagnostic: cookie names in jar: $(grep -v '^#' "$jar" 2>/dev/null | awk '{print $6}' | tr '\n' ' ')" >&2
		fail "login did not authenticate $user (session cookie likely rejected — check Secure flag / protocol)"
	fi
	post_login_token "$jar" || fail "no post-login requesttoken for $user (login did not establish a session)"
}

MGMT="$BASE/apps/exelearning/api/preview-session"
SERVE="$BASE/apps/exelearning/preview"

RT1="$(login "$EXE_E2E_USER1" "$EXE_E2E_PASS1" "$JAR1")"
[ -n "$RT1" ] || fail "no post-login requesttoken for user1"

# 1. CSRF enforced — an AUTHENTICATED create WITHOUT a requesttoken → 412. The
#    session cookie jar is sent (so the request passes auth and reaches the CSRF
#    middleware); only the requesttoken header is omitted.
code="$(curl -s -o "$TMP/step1.txt" -w '%{http_code}' -c "$JAR1" -b "$JAR1" -X POST "$MGMT")"
if [ "$code" != "412" ]; then
	fail "create without requesttoken expected 412, got $code (body: $(head -c 200 "$TMP/step1.txt"))"
fi
grep -qi 'csrf' "$TMP/step1.txt" || echo "  note: 412 body did not explicitly mention CSRF: $(head -c 120 "$TMP/step1.txt")"
echo "  ok: management enforces CSRF (412 without requesttoken)"

# 2. Create WITH requesttoken → 201 { previewId, protocolVersion: 2 }.
resp="$(curl -s -c "$JAR1" -b "$JAR1" -H "requesttoken: $RT1" -X POST "$MGMT")"
pid="$(printf '%s' "$resp" | jq -r '.previewId // empty')"
[ -n "$pid" ] || fail "create returned no previewId: $resp"
[ "$(printf '%s' "$resp" | jq -r '.protocolVersion')" = "2" ] || fail "protocolVersion != 2: $resp"
echo "  ok: created session $pid"

# 3. Upload an asset (multipart: assets JSON + index-aligned files[]).
KEY="aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8"
printf 'IMGBYTES!' > "$TMP/asset.bin" # 9 bytes
curl -s -c "$JAR1" -b "$JAR1" -H "requesttoken: $RT1" \
	-F "assets=[{\"key\":\"$KEY\",\"size\":9}]" \
	-F "files[]=@$TMP/asset.bin" \
	-X POST "$MGMT/$pid/assets" >/dev/null
echo "  ok: uploaded asset"

# 4. Publish revision 1.
printf '<html><body>preview-v1</body></html>' > "$TMP/index.html"
resp="$(curl -s -c "$JAR1" -b "$JAR1" -H "requesttoken: $RT1" \
	-F "revision={\"baseRevision\":0,\"nextRevision\":1,\"writes\":[\"index.html\"],\"deletes\":[],\"assetRefs\":{\"content/photo.png\":\"$KEY\"},\"fixedRefs\":{}}" \
	-F "files[]=@$TMP/index.html" \
	-X POST "$MGMT/$pid/revisions")"
[ "$(printf '%s' "$resp" | jq -r '.revision')" = "1" ] || fail "publish revision != 1: $resp"
echo "  ok: published revision 1"

# 5. Serve (authless, NO cookie): 200 + opaque sandbox CSP + no allow-same-origin.
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

# 7. Owner-scoping — user2 DELETE on user1's session → 403.
RT2="$(login "$EXE_E2E_USER2" "$EXE_E2E_PASS2" "$JAR2")"
[ -n "$RT2" ] || fail "no post-login requesttoken for user2"
code="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR2" -b "$JAR2" -H "requesttoken: $RT2" -X DELETE "$MGMT/$pid")"
[ "$code" = "403" ] || fail "cross-user delete expected 403, got $code"
echo "  ok: cross-user management is 403"

# 8. Dropped multipart part — 1 write declared, 0 file parts → 400, revision unchanged.
code="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR1" -b "$JAR1" -H "requesttoken: $RT1" \
	-F "revision={\"baseRevision\":1,\"nextRevision\":2,\"writes\":[\"index.html\"],\"deletes\":[],\"assetRefs\":{},\"fixedRefs\":{}}" \
	-X POST "$MGMT/$pid/revisions")"
[ "$code" = "400" ] || fail "dropped-part revision expected 400, got $code"
curl -s "$SERVE/$pid/index.html" | grep -q 'preview-v1' || fail "revision advanced despite dropped part"
echo "  ok: dropped-part revision rejected (400), revision pointer unchanged"

# 9. Owner DELETE → 200, then serve → 404.
code="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR1" -b "$JAR1" -H "requesttoken: $RT1" -X DELETE "$MGMT/$pid")"
[ "$code" = "200" ] || fail "owner delete expected 200, got $code"
code="$(curl -s -o /dev/null -w '%{http_code}' "$SERVE/$pid/index.html")"
[ "$code" = "404" ] || fail "serve after delete expected 404, got $code"
echo "  ok: owner delete 200, serve after delete 404"

echo "Preview API E2E: all checks passed."
