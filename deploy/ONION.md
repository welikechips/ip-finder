# Tor onion service for Just the tIP

Serve the app over a Tor **v3 onion service**, co-located in the existing Render container. Over the
onion there is no exit node and no client IP, so the app renders a dedicated *"nothing to show —
that's the point"* panel (see `isOnionHost()` in `utils.php` and the onion branch in `index.php`).

**Everything here is OFF by default.** Nothing changes on the clearnet site until you set
`ENABLE_ONION=1` and provide a key. `make test` and CI never start tor.

## ⚠️ Read this first — it's a keep-warm hack, not HA

This runs an always-on service on a host designed to sleep. Be clear-eyed:

- **Render free tier spins down after ~15 min idle**, which kills `tor`. Nothing wakes it (onion
  traffic never reaches Render's HTTP edge), so the onion is simply **down** until the next request
  to the *clearnet* URL wakes the container. The `keep-alive` workflow + an external monitor paper
  over this — imperfectly.
- **Every deploy drops the onion ~1–2 min** while tor republishes introduction points.
- **Two daemons, no supervisor.** If tor crashes, the clearnet site keeps serving but the onion
  stays down until the next restart.
- **The private key IS your address.** Lose it → new address. Leak it → someone can impersonate you.
  It lives in a Render **Secret File**, never in this repo.

If you want reliable, put it on a $5 always-on VPS instead. This path is the $0 "for fun" version.

## One-time: generate a stable key (offline)

The address is derived from `hs_ed25519_secret_key`. Generate it yourself so you hold custody and
the address never rotates.

**Plain address** (fast) — let tor generate one in a throwaway container and grab the key:

```bash
mkdir -p ./hs
docker run --rm -v "$PWD/hs:/var/lib/tor/hidden_service" -e ... \
  # simplest: run any tor image with a torrc that has HiddenServiceDir=/var/lib/tor/hidden_service
  # then read ./hs/hostname (the .onion) and ./hs/hs_ed25519_secret_key (the key to upload).
cat ./hs/hostname                     # <-- your .onion address
```

**Vanity address** (a `jive…` prefix) — grind it with [`mkp224o`](https://github.com/cathugger/mkp224o):

```bash
mkp224o -d ./hs jive          # ~minutes for 4 chars; 6+ gets exponentially slower — use a fast box
cat ./hs/<addr>.onion/hostname
# upload ./hs/<addr>.onion/hs_ed25519_secret_key as the Render Secret File
```

Back the key up **encrypted and offline**. It is unrecoverable if lost.

## Render setup (dashboard)

Provide the key one of two ways. **The env-var route is easiest — it works on every Render plan/UI
and is set exactly like any other env var. Use it if you can't find "Secret Files."**

**Option A — env var (recommended, no Secret File needed):**

```bash
# copy the base64 of the key to your clipboard (macOS) — the value never needs to be pasted anywhere
# but the Render env-var field:
base64 -i ~/ip-finder-onion-key/<addr>.onion/hs_ed25519_secret_key | pbcopy
```

Then in the service's **Environment** tab add:
- `ONION_KEY_B64` = (paste the base64) — the entrypoint decodes it into the hidden-service dir
- `ENABLE_ONION` = `1`
- `ONION_ADDRESS` = `<your-addr>.onion`  (makes the clearnet site send the `Onion-Location` header)

**Option B — Secret File:** in the service's **Environment** tab, scroll to the **Secret Files**
card → **Add Secret File** → filename `hs_ed25519_secret_key`, contents = the key file. Render mounts
it at `/etc/secrets/hs_ed25519_secret_key` (the entrypoint default). Still set `ENABLE_ONION=1` +
`ONION_ADDRESS`. If both a Secret File and `ONION_KEY_B64` are present, the env var wins.

Then **Deploy.** On boot the entrypoint installs the key, renders `torrc` with the live `$PORT`, and
starts tor. The `.onion` is printed to the service logs (`onion: serving <addr>.onion`).

## Keep it warm

- **In-repo:** `.github/workflows/keepalive.yml` pings the clearnet URL every ~10 min (best-effort;
  GitHub cron drifts).
- **Recommended:** an external **UptimeRobot** monitor (free, 5-min) on
  `https://ip.jiveturkey.rocks/?format=text`. More punctual than GitHub cron against the 15-min idle
  window.

## Verify

- **Tor Browser →** `http://<addr>.onion` → you should see the *"You reached us over Tor"* panel.
- **Clearnet →** `https://ip.jiveturkey.rocks` in Tor Browser should show the ".onion available"
  purple pill (from the `Onion-Location` header).

## Turn it off

Set `ENABLE_ONION=0` (or remove it) and redeploy. tor won't start; the clearnet site is unaffected.