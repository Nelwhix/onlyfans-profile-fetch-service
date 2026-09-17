FROM php:8.5-fpm-alpine@sha256:22a4c414bb8e91ac7aefe9b1d80e832caa67252aca58b6af7eeb3bc92188fc5b

ADD ./php/www.conf /usr/local/etc/php-fpm.d/

RUN addgroup -g 1000 laravel && adduser -G laravel -g laravel -s /bin/sh -D laravel

RUN mkdir -p /var/www/html && chown laravel:laravel /var/www/html

WORKDIR /var/www/html

RUN apk add --no-cache sqlite-dev && \
    docker-php-ext-install pdo_sqlite

RUN apk add --no-cache pcre-dev $PHPIZE_DEPS && \
    pecl install -o -f redis && \
    docker-php-ext-enable redis

RUN docker-php-ext-install bcmath pcntl

COPY --from=composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 /usr/bin/composer /usr/bin/composer
