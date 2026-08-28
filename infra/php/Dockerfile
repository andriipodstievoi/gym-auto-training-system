# syntax=docker/dockerfile:1
FROM php:8.3-fpm-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
 && apk add --no-cache icu-libs libzip fcgi \
 && docker-php-ext-install -j"$(nproc)" intl zip pdo_mysql opcache \
 && pecl install redis apcu \
 && docker-php-ext-enable redis apcu \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/php/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/app

# Dependencies first so the layer caches across source changes.
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY app/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
 && mkdir -p var/cache var/log \
 && chown -R www-data:www-data var

HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
    CMD cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

EXPOSE 9000
CMD ["php-fpm"]
