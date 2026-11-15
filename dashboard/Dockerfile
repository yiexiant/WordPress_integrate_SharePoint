FROM node:22-bookworm AS assets

WORKDIR /app
COPY . .
RUN npm install --ignore-scripts && npm run build

FROM dunglas/frankenphp:1-php8.3-bookworm

RUN install-php-extensions pdo_sqlite mbstring intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
COPY --from=assets /app/public/build /app/public/build

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p /app-db storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && touch /app-db/database.sqlite \
    && chown -R www-data:www-data /app-db /app/storage /app/bootstrap/cache
