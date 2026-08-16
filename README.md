# Just the tIP

A small PHP web app that shows a visitor their external IP, reverse-DNS hostname, geolocation, ASN / org, and
VPN / datacenter / Tor-exit hints — plus a client-side WebRTC leak check. Live at **https://ip.jiveturkey.rocks**, and
over Tor at `jiveserzcd3zj6ptn3o3cr5l35pfibmw4vgzvkjjsvuwwuknnnptc6qd.onion` (see [Tor onion service](#tor-onion-service-optional)).

The repo also carries `tor_check.py`, a standalone Python CLI (Tor / DNS-leak diagnostics) baked into the same Docker
image but **not** part of the website.

## Highlights

- **Two detection modes** — *Server Detection* (the IP your connection presents to the server) and *Browser Detection*
  (a live re-check from the browser itself).
- **Local-first, no third-party request path** — with a MaxMind key set, geolocation, reverse DNS, and Tor-exit
  detection all resolve on-box. See [Local-first](#local-first-no-third-party-request-path).
- **VPN / datacenter / Tor-exit flags** (the Tor-exit flag renders loud — a filled `🧅` badge + a purple card
  highlight), a **WebRTC leak check**, **dark-by-default theme** (light / auto still a click away), and
  **copy-to-clipboard**.
- **Terminal + JSON API** — `curl ip.jiveturkey.rocks` → bare IP; `?format=json` → structured JSON.
- **Privacy** — no database, no persistence, Apache access logging disabled (test-guarded); a visible privacy card
  states what's kept (nothing) and where lookups happen.
- **Optional Tor onion service** — the same image can serve itself over a `.onion`, where there's no exit node and no IP
  to show by design. Off by default; see [Tor onion service](#tor-onion-service-optional).

## Run locally

Requires Docker.

```bash
make dev     # build + run at http://localhost:8090 (bakes the git SHA as the version)
make down    # stop it
make         # list all targets
```

## How it works

The page offers two detection modes:

- **Server Detection** (default) — the server reports the IP your connection presents to it. Behind the Render/Cloudflare
  edge it reads the real client IP from `True-Client-IP` / `CF-Connecting-IP` or the first `X-Forwarded-For` hop; for
  local/direct access it falls back to a server-side lookup against public IP APIs.
- **Browser Detection** — a live re-check that fetches the app's **own** same-origin `?format=text` echo endpoint (no
  third-party service), then resolves the hostname via `hostname-lookup.php`. It can differ from Server Detection when
  the network changed after page load (e.g. a browser VPN/proxy toggled).

Reverse DNS uses the **system resolver** (`gethostbyaddr` + PTR), geolocation + ASN come from **local MaxMind GeoLite2**
(below), and the VPN / datacenter / Tor flags are computed locally.

### Local-first (no third-party request path)

With `MAXMIND_LICENSE_KEY` set, a deployed request touches **no third-party service** for IP, hostname, geolocation, or
Tor detection:

- **Geolocation + ASN** — local **MaxMind GeoLite2** (City + ASN), read via the `maxminddb` PHP extension. The `.mmdb`
  files are baked into the image at build. Without a key the bake is skipped and geo falls back to ipinfo.io → ipwho.is,
  so it still works with zero config.
- **Reverse DNS** — the system resolver only.
- **Tor-exit detection** — a Tor Project bulk exit list baked into the image at build; membership is checked locally
  (your IP is never sent anywhere).

The one deliberate exception is the **WebRTC leak check**, which needs a public **STUN** server (Google's) by protocol —
it never sees the page or its data.

### Configuration (Render env vars — both optional)

| Var | Purpose |
|-----|---------|
| `MAXMIND_LICENSE_KEY` | Bakes local GeoLite2 at build → geolocation resolves on-box (free [GeoLite2](https://www.maxmind.com/en/geolite2/signup) key). Without it, geo uses the HTTP fallback. |
| `IPINFO_TOKEN` | Authenticates the ipinfo.io **fallback** for reliability from datacenter egress. Only matters when the local DB isn't baked. |

### Tor onion service (optional)

The same Docker image can publish the app over a **Tor v3 onion service**, co-located with Apache in one container.
It's **off by default** — nothing changes on the clearnet site until you enable it. Over the onion there's no exit node
and no client IP, so the page renders a dedicated *"nothing to show — that's the point"* panel instead of a lookup, and
the clearnet site advertises the onion via an `Onion-Location` header. **Full setup (incl. offline / vanity key
generation with `mkp224o`): [`deploy/ONION.md`](deploy/ONION.md).**

Enable it with three Render settings (the key is never committed):

| Var | Purpose |
|-----|---------|
| `ENABLE_ONION` | `1` to start `tor` alongside Apache (default `0` = off). |
| `ONION_ADDRESS` | Your `<addr>.onion` — makes the clearnet site send the `Onion-Location` header so Tor Browser offers it. |
| `ONION_KEY_B64` | Base64 of the hidden-service secret key (or provide a Secret File `hs_ed25519_secret_key`). Secret — set in the dashboard, never in git. |

> **Honest caveat:** on Render's free tier this is a *keep-warm hack*, not HA — idle spin-down kills `tor` and nothing
> wakes it, so `.github/workflows/keepalive.yml` (plus an external monitor like UptimeRobot) pings the site to keep it
> warm, and each deploy drops the onion for ~1–2 min. For reliable uptime, run the same design on an always-on box.

### Terminal / API

```bash
curl ip.jiveturkey.rocks                 # -> bare IP as text/plain (curl/wget/etc.)
curl "ip.jiveturkey.rocks?format=json"   # -> JSON: ip, hostname, city, region, country, org, timezone, flags[]
```

Browsers (any `text/html` client) get the full HTML page; `Accept: application/json` also returns JSON.

## Security

- **Content-Security-Policy** — `connect-src 'self'` + the WebRTC STUN server, `frame-src 'none'`, and a **per-request
  script nonce** (distinct from the CSRF token).
- **CSRF token** on the refresh form (validated on POST).
- **Per-IP rate limiting** — a file-bucket keyed on the client IP (not the session, so it can't be bypassed by dropping a
  cookie); fails open but logs the degrade.
- **HSTS** (clearnet host only — meaningless over the TLS-less onion, so it's omitted there) plus `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. The clearnet also sends an `Onion-Location` header
  when the onion is configured.

The app is meant to run behind the Render/Cloudflare edge — that's what makes trusting the `*-Client-IP` headers safe.

## Tor Connection Checker (`tor_check.py`)

A standalone command-line tool (Python **stdlib only** — no dependencies to install) that reports on your host's Tor /
DNS setup. It is **not** part of the website.

```bash
docker exec -it ip-tools python /usr/local/bin/tor_check.py
docker exec -it ip-tools python /usr/local/bin/tor_check.py /results/my_check.json   # custom output path
```

Reports: local + external IPs, Tor connectivity, DNS servers (local vs public), a DNS-leak check, and recommendations
(saved as timestamped JSON under `results/`). It inspects your **host** connection — run Tor Browser / a Tor service on
the host first. *(`requirements.txt` lists `requests`, but nothing imports it — the CLI is stdlib-only.)*

## Development

```bash
make test    # full suite: unit tests + integration (build, boot, curl the endpoints)
make unit    # unit tests only (runs inside the php:8.3 image)
```

`tests/unit.php` is a plain-PHP assertion runner (no framework; exit 1 on failure); `tests/integration.sh` is a bash
harness that builds the image, boots it, and curls the real endpoints. `tests/fixtures/` holds MaxMind's Apache-licensed
test databases and a sample Tor list so CI exercises the real readers with no license key. CI
(`.github/workflows/ci.yml`) runs the whole suite on every push and PR.

## Deployment

Render builds the `Dockerfile` and runs the container as a web service (`render.yaml`, free plan). Every push to `main`
auto-deploys. `deploy/docker-entrypoint.sh` makes Apache listen on Render's assigned `$PORT`. The custom domain
`ip.jiveturkey.rocks` is a CNAME (DNS at Bluehost) → the Render service; Render issues the TLS certificate. Free-tier
services cold-start (~30–60s) after 15 minutes idle.

**Weekly data refresh.** The GeoLite2 databases and the Tor exit list are baked at build, so they only refresh on
deploy. `.github/workflows/refresh-deploy.yml` triggers a cache-cleared Render rebuild once a week to keep them current.
It needs two repo secrets — `RENDER_API_KEY` and `RENDER_SERVICE_ID` — and no-ops safely if they're unset.

**Tor onion service.** When `ENABLE_ONION=1`, `deploy/docker-entrypoint.sh` also starts `tor` inside the container
(Apache stays PID 1) so the app is reachable at its `.onion`, and `.github/workflows/keepalive.yml` pings the clearnet
URL to keep the free-tier instance from spinning `tor` down. Setup + key handling:
[`deploy/ONION.md`](deploy/ONION.md).

## Project structure

```
index.php             # Just the tIP web page (Server + Browser detection tabs)
hostname-lookup.php   # JSON endpoint: reverse-DNS a given IP
utils.php             # Shared PHP: IP validation, client-IP, local GeoIP, rate limiting, Tor/flag helpers
public/               # css + js assets
tor_check.py          # Standalone Tor / DNS diagnostic CLI (stdlib only)
Dockerfile            # PHP 8.3 + Apache + maxminddb ext; bakes GeoLite2 + Tor list at build
docker-compose.yml    # Local dev (port 8090)
Makefile              # Convenience targets (run `make` to list)
render.yaml           # Render deployment blueprint (incl. onion env vars)
deploy/               # Entrypoint ($PORT + optional tor), torrc.template, ONION.md (onion runbook)
tests/                # unit.php + integration.sh + fixtures/ (GeoIP + Tor test data)
.github/workflows/    # ci.yml (tests) + refresh-deploy.yml (weekly data refresh) + keepalive.yml (onion warm)
```

## Security note

These tools are for educational and diagnostic use. For maximum privacy when checking Tor, use the official Tor Browser
Bundle.
