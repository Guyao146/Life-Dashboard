FROM php:8.2-apache-bookworm

LABEL org.opencontainers.image.title="Life Dashboard" \
      org.opencontainers.image.source="https://github.com/Guyao146/Life-Dashboard" \
      org.opencontainers.image.licenses="LicenseRef-Sakura-License-1.2"

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install -d -o www-data -g www-data -m 0700 /var/lib/life-dashboard

ENV LIFE_HUB_ENV_FILE=/run/secrets/life-dashboard.env \
    LIFE_HUB_CONTAINER=1

COPY docker/apache.conf /etc/apache2/conf-enabled/life-dashboard.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/life-dashboard.ini
WORKDIR /var/www/html
COPY index.html upgrade.html app.js styles.css version.js config.php update.php LICENSE LICENSING.md ./
COPY assets/ ./assets/
RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 0755 {} + \
    && find /var/www/html -type f -exec chmod 0644 {} + \
    && php -r 'exit(extension_loaded("curl") && extension_loaded("mbstring") ? 0 : 1);' \
    && apache2ctl configtest

VOLUME ["/var/lib/life-dashboard"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/version.js") === false ? 1 : 0);'