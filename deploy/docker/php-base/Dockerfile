# syntax=docker/dockerfile:1.7

ARG PHP_UPSTREAM_IMAGE=php:8.3.37-fpm-alpine3.24@sha256:5dd9d8f16428908a77e3084ef77b27ab47bf63d5af2fe5b7eb8906610672de47

FROM ${PHP_UPSTREAM_IMAGE}

COPY deploy/docker/php-base/security.ini /usr/local/etc/php/conf.d/zz-quickquiz-security.ini

LABEL org.opencontainers.image.title="QuickQuiz PHP base" \
      org.opencontainers.image.description="PHP-FPM runtime with the extensions shared by QuickQuiz Manager images" \
      org.opencontainers.image.source="https://github.com/robmoraes/quick-quiz"

RUN apk upgrade --no-cache \
    && apk add --no-cache 'libpq>=18.7-r0' \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps
