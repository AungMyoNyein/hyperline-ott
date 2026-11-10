# HyperLine OTT — Laravel 12 API
# PHP-FPM behind the gateway's FastCGI pass. Pinned to PHP 8.3 per the required stack.
FROM php:8.3-fpm-bookworm AS base

# libpq-dev -> pdo_pgsql, libicu-dev -> intl, libzip-dev -> zip
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install pdo_pgsql bcmath intl zip opcache pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies from the committed composer.lock before copying source, so
# application edits do not invalidate the slow Composer layer.
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && cp -a public public.distrib

# Test runner image: dev dependencies plus the suite, used by the `api-test`
# Compose service (`docker compose build --target dev api`).
FROM base AS dev

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --prefer-dist \
        --no-interaction \
        --no-progress \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-scripts

# Default target. Keeps test code and dev packages out of the runtime image.
FROM base AS production

RUN rm -rf tests phpunit.xml \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-scripts

EXPOSE 9000

# The gateway mounts the api_public volume over /var/www/html/public to serve
# static files, while PHP-FPM executes index.php from its own copy. Docker only
# seeds a named volume while it is empty, so a rebuilt image would otherwise be
# shadowed by stale volume content: re-apply the image's public files on start.
CMD ["sh", "-c", "cp -a /var/www/html/public.distrib/. /var/www/html/public/ 2>/dev/null; exec php-fpm"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(stream_socket_client("tcp://127.0.0.1:9000",$errno,$errstr,1)?0:1);'
