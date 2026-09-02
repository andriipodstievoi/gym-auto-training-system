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

# This builds a production image. Without saying so the container inherits
# APP_ENV=dev from .env, which turns the profiler on and puts the debug
# toolbar in front of whoever visits.
ENV APP_ENV=prod \
    APP_DEBUG=0

# Dependencies first so the layer caches across source changes.
#
# --no-dev matters: without it the image ships phpunit, phpstan, php-cs-fixer
# and web-profiler-bundle. That is both a much larger image and a debug
# toolbar in production.
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist --no-dev

COPY app/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# The stylesheet has to be built inside the image.
#
# AssetMapper compiles on the fly in dev, so a dev container looks correct
# without this and a production one serves an unstyled site - the CSS is simply
# not there. CI runs tailwind:build in a separate job, which proves the command
# works but puts nothing into this image.
#
# importmap:install fetches the vendored JS that assets/vendor holds locally
# and .dockerignore deliberately keeps out of the build context.
RUN php bin/console importmap:install --no-interaction \
 && php bin/console tailwind:build --minify \
 && php bin/console asset-map:compile \
 && mkdir -p var/cache var/log \
 && chown -R www-data:www-data var public

HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
    CMD cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

EXPOSE 9000
CMD ["php-fpm"]
