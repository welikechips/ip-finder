# IP Tools

Two tools for inspecting your network identity:

1. **Enhanced IP Finder** — a PHP web app that shows your external IP, reverse-DNS hostname, geolocation, and VPN/proxy
   hints. Live at **https://ip.jiveturkey.rocks**.
2. **Tor Connection Checker** — a standalone Python CLI that verifies whether your connection is routing through Tor,
   and checks for DNS leaks.

The web app is the deployed product; the Tor checker is a diagnostic CLI baked into the same Docker image.

## Live site

**https://ip.jiveturkey.rocks** — deployed on Render (free tier) from a Docker image built out of this repo. Every push
to `main` auto-deploys. The footer shows the deployed git commit as the app's version.

## Enhanced IP Finder

### Run locally

Requires Docker.

```bash
make dev     # build + run at http://localhost:8090 (bakes the git SHA as the version)
make down    # stop it
```

(`make dev` wraps `docker compose up -d --build`; run `make` to list all targets.)

### How it works

The page offers two detection modes:

- **Server Detection** (default) — the server reports the IP your connection presents to it. Behind a reverse proxy (the
  Render/Cloudflare edge in production) it reads the real client IP from the `True-Client-IP` / `CF-Connecting-IP`
  headers or the first `X-Forwarded-For` hop; for local/direct access it falls back to a server-side lookup against
  public IP APIs.
- **Browser Detection** — detects the IP from the browser itself (useful when a browser-only proxy such as FoxyProxy
  differs from the OS route), then resolves the hostname via the `hostname-lookup.php` endpoint.

Security: the app sets a strict Content-Security-Policy and related headers, issues a CSRF token for the refresh form,
and rate-limits requests per session.

## Tor Connection Checker

A command-line tool (run inside the container) that reports on your Tor / DNS setup.

```bash
# default output
docker exec -it ip-tools python /usr/local/bin/tor_check.py

# custom output file (writes into the mounted results/ volume)
docker exec -it ip-tools python /usr/local/bin/tor_check.py /results/my_check.json
```

> Note: `tor_check.py` imports `requests` (listed in `requirements.txt`) which is **not** installed in the image by
> default — run `pip install requests` in the container first if needed.

**What it reports:**

- Local and external IP addresses
- Whether you're connected to the Tor network
- Your DNS servers, classified as local vs public
- Whether DNS requests are likely going through Tor (DNS-leak check)
- DNS resolution tests for common domains
- Recommendations, saved as a timestamped JSON report under `results/`

The checker inspects your **host** connection — it does not route the container through Tor. Run Tor Browser or a Tor
service on the host first.

## Development

```bash
make test    # full suite: unit tests + integration (build, boot, curl the endpoints)
make unit    # unit tests only (runs inside the php:8.3 image)
```

These wrap `bash tests/integration.sh` and the unit runner. There is no test framework — `tests/unit.php` is a plain-PHP assertion runner (exit 1 on failure) and
`tests/integration.sh` is a bash harness. CI (`.github/workflows/ci.yml`) runs the whole suite on every push and PR.

## Deployment

Render builds the `Dockerfile` and runs the container as a web service (`render.yaml`, free plan).
`deploy/docker-entrypoint.sh` makes Apache listen on Render's assigned `$PORT`. The custom domain `ip.jiveturkey.rocks`
is a CNAME (DNS managed at Bluehost) pointing at the Render service; Render issues the TLS certificate. Free-tier
services cold-start (~30–60s) after 15 minutes idle.

## Project structure

```
index.php             # IP Finder web page (Server + Browser detection tabs)
hostname-lookup.php   # JSON endpoint: reverse-DNS a given IP
utils.php             # Shared PHP: IP validation, lookups, client-IP, rate limiting
public/               # css + js assets
tor_check.py          # Standalone Tor / DNS diagnostic CLI
Dockerfile            # PHP 8.3 + Apache image (also carries the Tor CLI)
docker-compose.yml    # Local dev (port 8090)
Makefile              # Convenience targets (run `make` to list)
render.yaml           # Render deployment blueprint
deploy/               # Entrypoint ($PORT handling)
tests/                # unit.php + integration.sh
results/              # Tor checker JSON output (volume-mounted)
```

## Security note

These tools are for educational and diagnostic use. For maximum privacy when checking Tor, use the official Tor Browser
Bundle.