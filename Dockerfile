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

# Create a symlink for backward compatibility
RUN ln -sf /var/www/html/index.php /var/www/html/whats-my-ip.php

# Copy Python application
COPY tor_check.py /usr/local/bin/
RUN chmod +x /usr/local/bin/tor_check.py

# Set proper Apache permissions
RUN chown -R www-data:www-data /var/www/html

# Expose the default port (the platform overrides via $PORT at runtime).
EXPOSE 80

# Entrypoint makes Apache listen on $PORT (Render assigns it; defaults to 80 locally),
# then execs apache2-foreground so Apache is PID 1 -> clean signals + accurate health.
# The Tor checker is a manual CLI: `docker exec -it <container> python /usr/local/bin/tor_check.py`.
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]