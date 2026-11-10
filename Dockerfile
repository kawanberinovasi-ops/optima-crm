# Image untuk hosting berbasis Docker (Render Free, Koyeb, VPS). Laravel Cloud tidak memakai file ini.
# Build dari root repo karena frontend (index.html, assets/) ada di luar backend/.
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_pgsql pgsql intl bcmath zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependensi dulu supaya layer ini di-cache selama composer.lock tidak berubah.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY backend/ ./
COPY index.html /src/index.html
COPY assets /src/assets
RUN rm -f public/assets \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && php artisan optima:build-frontend --from=/src \
    && rm -rf /src tests \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

COPY docker/start.sh /usr/local/bin/optima-start
RUN chmod +x /usr/local/bin/optima-start

ENV APP_ENV=production LOG_CHANNEL=stderr DB_CONNECTION=pgsql PORT=8080
EXPOSE 8080
CMD ["optima-start"]
