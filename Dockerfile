FROM php:7.4-apache

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

# Create a script to manage both services
RUN echo '#!/bin/bash\n\
echo "Starting Apache service..."\n\
apache2-foreground &\n\
echo "Both services are now running."\n\
echo "Access the IP Finder at: http://localhost:80/"\n\
echo "Or using the old URL: http://localhost:80/whats-my-ip.php"\n\
echo "Run Tor Check with: docker exec -it container_name tor_check.py"\n\
tail -f /dev/null' > /usr/local/bin/start-services.sh && \
    chmod +x /usr/local/bin/start-services.sh

# Set proper Apache permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port for web server
EXPOSE 80

# Set the entrypoint
ENTRYPOINT ["/usr/local/bin/start-services.sh"]