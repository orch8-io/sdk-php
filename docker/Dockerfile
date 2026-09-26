# Minimal dev/test image for orch8/sdk: PHP 8.3 CLI + pcntl + Composer.
FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /app
