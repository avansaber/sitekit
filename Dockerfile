FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libonig-dev libzip-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl mbstring opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

FROM php-base AS dependencies

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM node:24-bookworm-slim AS assets

WORKDIR /var/www/html

COPY package.json package-lock.json ./
RUN npm ci

COPY --from=dependencies /var/www/html/vendor ./vendor
COPY resources ./resources
COPY app ./app
COPY postcss.config.js tailwind.config.js vite.config.js ./
RUN npm run build

FROM php-base AS app

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build
COPY deploy/production/php.ini /usr/local/etc/php/conf.d/sitekit.ini
COPY deploy/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-sitekit.conf

RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && php artisan storage:link \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

ENTRYPOINT ["bash", "/var/www/html/deploy/production/entrypoint.sh"]

CMD ["php-fpm"]

FROM caddy:2.10-alpine AS caddy

COPY --from=app /var/www/html/public /var/www/html/public
COPY deploy/production/Caddyfile /etc/caddy/Caddyfile