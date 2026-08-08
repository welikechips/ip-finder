# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A small PHP web app that shows a visitor their external IP, reverse-DNS hostname, geolocation, and VPN/proxy hints.
Shipped as a Docker image and deployed on **Render free tier** at **https://ip.jiveturkey.rocks**. The repo also carries
`tor_check.py`, a standalone Python CLI (not part of the website).

## Commands

Everything is wrapped in the `Makefile` — run `make` to list targets.

```bash
make dev     # build + run locally at http://localhost:8090 (bakes the git SHA as version)
make test    # full suite: unit tests + integration (build, boot, curl the endpoints)
make unit    # unit tests only (runs inside the php:8.3 image, no host PHP needed)
make tor     # run the Tor checker inside the running container

# Underlying commands, if you need them directly:
docker compose up -d --build          # what `make dev` runs
bash tests/integration.sh             # what `make test` runs
php tests/unit.php                    # unit tests with host PHP on PATH
```

There is no test framework/composer — `tests/unit.php` is a plain-PHP assertion runner (exit 1 on failure);
`tests/integration.sh` is a bash harness. Run a subset by editing/commenting the relevant block; there is no per-test
selector.

## Deployment

- **Push to `main` = deploy.** `render.yaml` (Docker web service, `plan: free`, `region: virginia`) has
  `autoDeploy: true`, so Render rebuilds on every push. `.github/workflows/ci.yml` runs the test suite on push/PR.
- `deploy/docker-entrypoint.sh` rewrites Apache's listen port to Render's injected `$PORT` (defaults to 80 locally),
  then `exec`s `apache2-foreground` so Apache is PID 1.
- The domain `jiveturkey.rocks` is registered at **Bluehost (domain-only)**; `ip.jiveturkey.rocks` is a CNAME → the
  Render service. Render terminates TLS.
- Free-tier caveat: the service cold-starts (~30–60s) after 15 min idle.
- The page footer shows the deployed commit SHA, read from `RENDER_GIT_COMMIT` (runtime) or the `GIT_COMMIT` build arg;
  local builds show `dev`. `tests/integration.sh` builds with `--build-arg GIT_COMMIT` and asserts it renders.
- Geolocation (`getIPInfo` in `utils.php`) resolves **locally first** from MaxMind GeoLite2 (City + ASN) via the
  `maxminddb` PHP extension — zero third-party calls in the request path. The `.mmdb` files are baked into the image at
  build time when `MAXMIND_LICENSE_KEY` is set (Render passes service env vars as Docker build args; without a key the
  bake is skipped). `GEOIP_DB_DIR` (default `/usr/share/GeoIP`) locates the DBs. When the local DBs aren't present it
  falls back to ipinfo.io (set `IPINFO_TOKEN` for reliable datacenter-egress use) then token-free ipwho.is, so keyless
  builds still resolve. Refresh the DBs by rebuilding (push-to-main) or a monthly job. `tests/fixtures/geoip/` holds
  MaxMind's small Apache-licensed test DBs so CI exercises the real reader without a license key. Timestamps render in
  `America/New_York` (EDT/EST), a fixed default since VPNs make IP-based timezone unreliable.

## Architecture

Two independent IP-detection paths, surfaced as two tabs in the one page (`index.php`):

1. **Server Detection** (default tab) — `index.php` calls `getClientIP()` (`utils.php`) and reports the visitor's real
   IP, then reverse-DNS + geolocation on it. If the client IP isn't public (e.g. local dev with no proxy), it falls back
   to `getExternalIP()`, a server-side curl to public IP APIs (ipify/ipinfo/ip.sb/myip).
2. **Browser Detection** — `public/js/ip-finder.js` detects the IP client-side (ipify + an iframe fallback) and calls
   the `hostname-lookup.php` JSON endpoint for reverse DNS. Useful when a browser-only proxy (e.g. FoxyProxy) differs
   from the OS route.

`utils.php` holds the shared logic: IP validation, local-range detection, the external-IP/geolocation/hostname lookups (
with layered fallbacks incl. a DNS PTR path and an EC2-hostname heuristic), and per-session rate limiting. `index.php`
and `hostname-lookup.php` are the two entry points; both `require utils.php`.

## Load-bearing detail: client IP behind Render's Cloudflare edge

**Render fronts every service with Cloudflare.** The container therefore sees requests coming from a *public*
Render/Cloudflare proxy IP, and `X-Forwarded-For` is a multi-hop chain like
`<realclient>, <cloudflare>, <render-internal>`. Naïvely trusting `REMOTE_ADDR` or only private proxy ranges surfaces *
*Render's own egress IP** instead of the visitor's (this was a real bug).

`getClientIP()` resolves the real client IP in this priority order, and this is intentional — do not "simplify" it back
to `REMOTE_ADDR`:

1. `True-Client-IP` header (Cloudflare, single value, edge-set/unspoofable)
2. `CF-Connecting-IP` header (same)
3. **First (leftmost)** entry of `X-Forwarded-For`
4. `REMOTE_ADDR` (local/direct access only)

`mod_remoteip` was tried and removed because Render's proxy connects from public IPs, which breaks Apache's
private-range proxy trust. The logic lives in PHP instead. `tests/unit.php` locks this ordering (incl. anti-spoof + the
`172.71.x` Cloudflare-IP regression); `tests/integration.sh` asserts proxy hops never leak into the page.

## Security posture

`index.php` sets CSP + security headers and issues a CSRF token (validated on POST); both entry points apply per-session
rate limits (`enforceRateLimit`). The app is only meant to run behind the Render/Cloudflare edge — that's what makes
trusting the `*-Client-IP` headers safe.

## Note on `tor_check.py`

Standalone diagnostic CLI baked into the image but never invoked by the web app. It imports `requests` (see
`requirements.txt`), which the Dockerfile does **not** `pip install` — install it in the container before running if
needed.