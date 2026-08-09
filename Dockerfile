FROM php:8.3-apache

# Install Python and required dependencies
RUN apt-get update && \
    apt-get install -y \
    python3 \
    python3-pip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Set Python3 as default
RUN ln -s /usr/bin/python3 /usr/bin/python

# Set up PHP with required extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# GeoIP: libmaxminddb + the maxminddb PECL extension (registers MaxMind\Db\Reader, no Composer).
# This is what lets getIPInfo() resolve city/ASN from a local .mmdb instead of a third-party API.
RUN apt-get update && apt-get install -y $PHPIZE_DEPS libmaxminddb-dev \
    && pecl install maxminddb \
    && docker-php-ext-enable maxminddb \
    && rm -rf /var/lib/apt/lists/*

# GeoLite2 City + ASN databases, baked in at build time WHEN a MaxMind license key is provided
# (docker build --build-arg MAXMIND_LICENSE_KEY=...). Without a key this step is skipped and
# getIPInfo() falls back to its HTTP providers, so keyless builds (CI, local) still work. A
# build-arg is visible in `docker history`; for a public image pass the key via a BuildKit secret
# instead. Refresh by rebuilding (push-to-main redeploys) or a monthly job.
ARG MAXMIND_LICENSE_KEY=""
ENV GEOIP_DB_DIR=/usr/share/GeoIP
RUN if [ -n "$MAXMIND_LICENSE_KEY" ]; then \
        mkdir -p "$GEOIP_DB_DIR" && \
        for ed in GeoLite2-City GeoLite2-ASN; do \
            curl -fsSL "https://download.maxmind.com/app/geoip_download?edition_id=${ed}&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz" \
                | tar -xz -C /tmp && \
            find /tmp -name "${ed}.mmdb" -exec mv {} "$GEOIP_DB_DIR/" \; && \
            rm -rf /tmp/${ed}_* ; \
        done && ls -la "$GEOIP_DB_DIR" ; \
    else \
        echo "MAXMIND_LICENSE_KEY not set — skipping GeoIP DB bake; getIPInfo() uses the HTTP fallback." ; \
    fi

# Bundle the Tor Project exit list at build so isTorExit() checks it locally — no runtime fetch,
# works offline. It's a PUBLIC list and the visitor IP is never sent; membership is tested
# locally. Best-effort: a failed download does NOT fail the build (isTorExit falls back to a
# runtime fetch). Refreshed on every deploy (push-to-main rebuilds).
RUN mkdir -p /usr/share/tor \
 && (curl -fsSL https://check.torproject.org/torbulkexitlist -o /usr/share/tor/torbulkexitlist \
     || echo "Tor exit list download failed at build; isTorExit() will fetch at runtime.")
ENV TOR_EXIT_LIST=/usr/share/tor/torbulkexitlist

# Set working directory
WORKDIR /var/www/html

# Create directory structure
RUN mkdir -p /var/www/html/public/css /var/www/html/public/js

# Copy PHP application files
COPY index.php /var/www/html/
COPY hostname-lookup.php /var/www/html/
COPY utils.php /var/www/html/
COPY public/css/styles.css /var/www/html/public/css/
COPY public/js/ip-finder.js /var/www/html/public/js/

# Copy Python application
COPY tor_check.py /usr/local/bin/
RUN chmod +x /usr/local/bin/tor_check.py

# Set proper Apache permissions
RUN chown -R www-data:www-data /var/www/html

# Privacy: disable Apache access logging entirely so no visitor IPs are ever written
# to logs. This backs the "we don't log or store your data" statement on the page.
# Error logging stays for ops (it records no visitor IPs in normal operation).
RUN sed -ri '/CustomLog/d' /etc/apache2/sites-available/000-default.conf \
 && (a2disconf other-vhosts-access-log 2>/dev/null || true)

# Expose the default port (the platform overrides via $PORT at runtime).
EXPOSE 80

# App version: bake the git SHA if provided at build time (Render sets RENDER_GIT_COMMIT
# at runtime instead). Kept late so changing it doesn't bust earlier build-cache layers.
ARG GIT_COMMIT=""
ENV GIT_COMMIT=$GIT_COMMIT

# Entrypoint makes Apache listen on $PORT (Render assigns it; defaults to 80 locally),
# then execs apache2-foreground so Apache is PID 1 -> clean signals + accurate health.
# The Tor checker is a manual CLI: `docker exec -it <container> python /usr/local/bin/tor_check.py`.
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]