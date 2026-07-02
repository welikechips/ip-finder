#!/usr/bin/env bash
#
# Integration tests: build the image, boot the container, exercise the REAL HTTP
# endpoints, and run the unit tests inside the shipped PHP 8.3 runtime.
#
#   Requirements: docker + curl (no host PHP needed).
#   Run:          bash tests/integration.sh
#
# Network note: the hostname-lookup / geolocation paths need outbound network, so
# those checks assert on response STRUCTURE (status code, valid JSON), not on
# specific third-party data — that keeps them stable across runs.

set -uo pipefail
cd "$(dirname "$0")/.."

IMAGE="ip-finder-ci"
NAME="ip-finder-ci-run"
HOST_PORT="18099"
INTERNAL_PORT="10000"
BASE="http://localhost:${HOST_PORT}"

PASS=0; FAIL=0
ok()  { echo "  PASS  $1"; PASS=$((PASS + 1)); }
bad() { echo "  FAIL  $1"; FAIL=$((FAIL + 1)); }

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "==> Building image"
GIT_FULL="$(git rev-parse HEAD 2>/dev/null || echo 0000000000000000000000000000000000000000)"
GIT_SHORT="${GIT_FULL:0:7}"
docker build --build-arg GIT_COMMIT="$GIT_FULL" -t "$IMAGE" . >/tmp/ci-build.log 2>&1 || { echo "build failed:"; tail -20 /tmp/ci-build.log; exit 1; }

echo "==> Unit tests (inside the PHP 8.3 image)"
docker run --rm --entrypoint php -v "$PWD":/app -w /app "$IMAGE" tests/unit.php || bad "unit tests failed"

echo "==> Starting container on :${HOST_PORT} (PORT=${INTERNAL_PORT})"
docker run -d --name "$NAME" -e PORT="$INTERNAL_PORT" -p "${HOST_PORT}:${INTERNAL_PORT}" "$IMAGE" >/dev/null
ready=0
for _ in $(seq 1 30); do
  if curl -sf "$BASE/" >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
[ "$ready" = 1 ] || { echo "container never became ready:"; docker logs "$NAME"; exit 1; }

echo "==> HTTP endpoint checks"

# 1. Home page serves
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = "200" ] && ok "GET / -> 200" || bad "GET / -> $code (want 200)"

# 1b. Version (deployed git SHA) shows in the footer
curl -s "$BASE/" | grep -q "$GIT_SHORT" \
  && ok "version rendered (git $GIT_SHORT)" || bad "version SHA $GIT_SHORT not rendered"

# 1c. Timestamp shows Eastern timezone (EDT/EST)
curl -s "$BASE/" | grep -qE 'Last updated:.*(EDT|EST)' \
  && ok "timestamp shows Eastern tz (EDT/EST)" || bad "timestamp missing Eastern tz"

# 1d. Privacy disclaimer is present
curl -s "$BASE/" | grep -qi 'Privacy' \
  && ok "privacy disclaimer shown" || bad "privacy disclaimer missing"

# 1e. Theme toggle + copy-IP button present
curl -s "$BASE/" | grep -q 'id="theme-toggle"' && ok "theme toggle present" || bad "theme toggle missing"
curl -s "$BASE/" | grep -q 'class="copy-btn"' && ok "copy-IP button present" || bad "copy-IP button missing"

# 2. True-Client-IP is reported as the visitor IP
curl -s -H "True-Client-IP: 1.1.1.1" "$BASE/" | grep -q "1.1.1.1" \
  && ok "True-Client-IP surfaced as visitor IP" || bad "True-Client-IP not surfaced"

# 3. XFF leftmost is surfaced; proxy hops are NOT leaked (today's bug)
body=$(curl -s -H "X-Forwarded-For: 203.0.113.7, 172.71.195.123, 10.226.90.65" "$BASE/")
if echo "$body" | grep -q "203.0.113.7" && ! echo "$body" | grep -qE "172\.71\.195\.123|10\.226\.90\.65"; then
  ok "XFF leftmost surfaced, proxy hops hidden"
else
  bad "XFF handling wrong (leaked a hop or missed the client)"
fi

# 4. hostname-lookup: valid IP -> 200 + JSON with a hostname key
resp=$(curl -s -w '\n%{http_code}' "$BASE/hostname-lookup.php?ip=8.8.8.8")
hcode=$(echo "$resp" | tail -1); hbody=$(echo "$resp" | sed '$d')
{ [ "$hcode" = "200" ] && echo "$hbody" | grep -q '"hostname"'; } \
  && ok "hostname-lookup valid IP -> 200 + JSON" || bad "hostname-lookup valid IP -> $hcode / $hbody"

# 5. hostname-lookup: invalid IP -> 400
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/hostname-lookup.php?ip=not-an-ip")
[ "$code" = "400" ] && ok "hostname-lookup invalid IP -> 400" || bad "hostname-lookup invalid IP -> $code (want 400)"

# 6. hostname-lookup: missing param -> 400
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/hostname-lookup.php")
[ "$code" = "400" ] && ok "hostname-lookup missing param -> 400" || bad "hostname-lookup missing param -> $code (want 400)"

# 7. Security headers present on /
headers=$(curl -sD - -o /dev/null "$BASE/")
for h in "Content-Security-Policy" "X-Content-Type-Options: nosniff" "X-Frame-Options: DENY" "Strict-Transport-Security: max-age="; do
  echo "$headers" | grep -qi "$h" && ok "security header present: $h" || bad "missing security header: $h"
done

# 8. Rate limiting on hostname-lookup (5/60) -> a 429 shows within 7 shared-session hits
jar=$(mktemp); saw429=0
for _ in $(seq 1 7); do
  c=$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' "$BASE/hostname-lookup.php?ip=8.8.8.8")
  [ "$c" = "429" ] && saw429=1
done
rm -f "$jar"
[ "$saw429" = 1 ] && ok "rate limit returns 429 when exceeded" || bad "rate limit never triggered"

# 9. Privacy: access logging disabled -> no visitor requests recorded in the container logs
#    (all the curls above would show up here if access logging were on)
if docker logs "$NAME" 2>&1 | grep -qE '"(GET|POST) '; then
  bad "access logging active (visitor requests appear in container logs)"
else
  ok "no access logging (no visitor requests in container logs)"
fi

echo
echo "==> Integration: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1