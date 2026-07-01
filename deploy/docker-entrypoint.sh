#!/bin/sh
# Make Apache listen on the port the platform assigns.
# Render (and most PaaS) inject $PORT; default to 80 for local/dev.
# `exec` keeps apache2-foreground as PID 1 -> clean signal handling + accurate health.
set -e

PORT="${PORT:-80}"
sed -ri "s!Listen 80!Listen ${PORT}!" /etc/apache2/ports.conf
sed -ri "s!VirtualHost \*:80!VirtualHost *:${PORT}!" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground