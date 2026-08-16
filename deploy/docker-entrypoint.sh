#!/bin/sh
# Make Apache listen on the port the platform assigns.
# Render (and most PaaS) inject $PORT; default to 80 for local/dev.
# `exec` keeps apache2-foreground as PID 1 -> clean signal handling + accurate health.
set -e

PORT="${PORT:-80}"
sed -ri "s!Listen 80!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!VirtualHost \*:80!VirtualHost *:${PORT}!" /etc/apache2/sites-enabled/000-default.conf

# --- Optional Tor onion service -----------------------------------------------------------------
# Off unless ENABLE_ONION=1 (default 0), so CI/local/clearnet boots are byte-for-byte unchanged.
# When on: install the hidden-service secret key (from a Render Secret File / mounted path) into
# HiddenServiceDir so the .onion address is STABLE, render torrc with the live $PORT, and start
# tor in the BACKGROUND — Apache stays PID 1 for clean signals. If tor dies, the clearnet site
# keeps serving and the onion is down until the next restart (a known limitation, see ONION.md).
if [ "${ENABLE_ONION:-0}" = "1" ]; then
    HS_DIR=/var/lib/tor/hidden_service
    KEY_SRC="${ONION_KEY_FILE:-/etc/secrets/hs_ed25519_secret_key}"
    mkdir -p "$HS_DIR"
    # The hidden-service key can come from EITHER a base64 env var (ONION_KEY_B64 — works on any
    # Render plan/UI, set it like any other env var; no Secret File needed) OR a mounted Secret
    # File. The env var wins if both are present. Neither -> tor grinds a throwaway address.
    if [ -n "${ONION_KEY_B64:-}" ]; then
        if printf '%s' "$ONION_KEY_B64" | base64 -d > "$HS_DIR/hs_ed25519_secret_key" 2>/dev/null; then
            echo "onion: installed hidden-service key from ONION_KEY_B64" >&2
        else
            echo "onion: ONION_KEY_B64 is set but failed to base64-decode — check the value" >&2
        fi
    elif [ -f "$KEY_SRC" ]; then
        cp "$KEY_SRC" "$HS_DIR/hs_ed25519_secret_key"
        echo "onion: installed hidden-service key from $KEY_SRC" >&2
    else
        echo "onion: no key (ONION_KEY_B64 unset, no file at $KEY_SRC) — tor will generate a throwaway address" >&2
    fi
    # tor refuses to start unless the key dir is tor-owned and mode 700.
    chown -R debian-tor:debian-tor /var/lib/tor 2>/dev/null || true
    chmod 700 "$HS_DIR"
    sed "s!__PORT__!${PORT}!g" /etc/tor/torrc.template > /etc/tor/torrc
    tor -f /etc/tor/torrc &
    # Print the .onion once tor derives it, so it can be read from the platform logs on first boot.
    ( sleep 8; [ -f "$HS_DIR/hostname" ] && echo "onion: serving $(cat "$HS_DIR/hostname")" >&2 ) &
fi

exec apache2-foreground