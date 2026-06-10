# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.3
ARG NGINX_VERSION=1.27

FROM --platform=$BUILDPLATFORM php:${PHP_VERSION}-cli-alpine AS vendor

RUN apk add --no-cache git sqlite-libs unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_SECRET=docker-build-placeholder
ENV MANAGER_DATABASE_URL=sqlite:////tmp/manager.sqlite
ENV MANAGER_CONTENT_ROOT=/tmp/content
ENV FALLBACK_LOCALE=en-US
ENV SUPPORTED_LOCALES=en-US,pt-BR

COPY apps/manager/composer.json apps/manager/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
      --no-dev \
      --prefer-dist \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --optimize-autoloader

COPY apps/manager/bin ./bin
COPY apps/manager/config ./config
COPY apps/manager/public ./public
COPY apps/manager/src ./src
COPY apps/manager/templates ./templates
COPY apps/manager/assets ./assets
COPY apps/manager/importmap.php ./importmap.php

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console asset-map:compile

FROM php:${PHP_VERSION}-fpm-alpine AS fpm

RUN apk add --no-cache sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
    && docker-php-ext-install opcache pdo_sqlite \
    && apk del .build-deps

WORKDIR /app

COPY deploy/docker/manager/php.ini /usr/local/etc/php/conf.d/zz-quickquiz.ini
COPY deploy/docker/manager/php-fpm.conf /usr/local/etc/php-fpm.d/zz-quickquiz.conf
COPY --from=vendor /app /app

RUN mkdir -p /app/var /content \
    && chown -R www-data:www-data /app/var /content \
    && chmod -R a+rwX /app/var /content

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_SECRET=change-me
ENV MANAGER_DATABASE_URL=sqlite:////app/var/manager.sqlite
ENV MANAGER_CONTENT_ROOT=/content
ENV FALLBACK_LOCALE=en-US
ENV SUPPORTED_LOCALES=en-US,pt-BR

EXPOSE 9000
VOLUME ["/app/var", "/content"]

CMD ["php-fpm", "-F"]

FROM nginx:${NGINX_VERSION}-alpine AS nginx

COPY deploy/docker/manager/nginx.conf /etc/nginx/nginx.conf
COPY --from=vendor /app/public /app/public

RUN chown -R nginx:nginx /app/public /var/cache/nginx /var/run

EXPOSE 8080

USER nginx

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
