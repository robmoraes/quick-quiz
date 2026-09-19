# syntax=docker/dockerfile:1.7

ARG PHP_BASE_IMAGE=robmoraes/quick-quiz-php-base:8.3.33-alpine3.24-r1
ARG NGINX_VERSION=1.27

FROM --platform=$BUILDPLATFORM ${PHP_BASE_IMAGE} AS vendor

RUN apk add --no-cache git unzip

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
COPY apps/manager/migrations ./migrations
COPY apps/manager/public ./public
COPY apps/manager/src ./src
COPY apps/manager/templates ./templates
COPY apps/manager/assets ./assets
COPY apps/manager/importmap.php ./importmap.php

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console asset-map:compile

FROM ${PHP_BASE_IMAGE} AS fpm

WORKDIR /app

COPY deploy/docker/manager/php.ini /usr/local/etc/php/conf.d/zz-quickquiz.ini
COPY deploy/docker/manager/php-fpm.conf /usr/local/etc/php-fpm.d/zz-quickquiz.conf
COPY deploy/docker/manager/fpm-entrypoint.sh /usr/local/bin/manager-fpm-entrypoint

RUN echo 'include=/tmp/quickquiz-fpm-runtime.conf' >> /usr/local/etc/php-fpm.conf \
    && chmod +x /usr/local/bin/manager-fpm-entrypoint
COPY --from=vendor /app /app

RUN mkdir -p /app/var /content \
    && chown -R www-data:www-data /app/var /content \
    && chmod -R a+rwX /app/var /content

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV MANAGER_PHP_MEMORY_LIMIT=128M
ENV MANAGER_PHP_POST_MAX_SIZE=2M
ENV MANAGER_PHP_UPLOAD_MAX_FILESIZE=2M
ENV MANAGER_PHP_OPCACHE_MEMORY=96
ENV MANAGER_FPM_MAX_CHILDREN=8
ENV MANAGER_FPM_START_SERVERS=2
ENV MANAGER_FPM_MIN_SPARE_SERVERS=1
ENV MANAGER_FPM_MAX_SPARE_SERVERS=3

EXPOSE 9000

CMD ["manager-fpm-entrypoint"]

FROM nginx:${NGINX_VERSION}-alpine AS nginx

COPY deploy/docker/manager/nginx.conf /etc/nginx/nginx.conf
COPY --from=vendor /app/public /app/public

RUN chown -R nginx:nginx /app/public /var/cache/nginx /var/run

EXPOSE 8080

USER nginx

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
