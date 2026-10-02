# syntax=docker/dockerfile:1

# --- Frontend assets (Vite) -------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.* tsconfig*.json ./
COPY resources ./resources
COPY public ./public

# VITE_* values are baked into the bundle at build time.
ARG VITE_APP_NAME="Railway Assistant"
ARG VITE_MAP_TILE_URL=""
ARG VITE_MAP_ATTRIBUTION=""
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_MAP_TILE_URL=${VITE_MAP_TILE_URL} \
    VITE_MAP_ATTRIBUTION=${VITE_MAP_ATTRIBUTION}
RUN npm run build

# --- PHP dependencies -------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- Runtime ----------------------------------------------------------------
FROM php:8.4-apache AS app
WORKDIR /var/www/html

RUN docker-php-ext-install opcache \
    && a2enmod rewrite headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN php artisan package:discover --ansi \
    && chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/database/database.sqlite

VOLUME ["/var/www/html/storage"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
