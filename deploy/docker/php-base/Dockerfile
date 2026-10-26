# syntax=docker/dockerfile:1.7

ARG PHP_UPSTREAM_IMAGE=php:8.3.36-fpm-alpine3.24@sha256:25e15cf35ed0f5b75d8cf14fc2c4ed7e0e6bee00d359e681b7fa3e91a7bee49f

FROM ${PHP_UPSTREAM_IMAGE}

COPY deploy/docker/php-base/security.ini /usr/local/etc/php/conf.d/zz-quickquiz-security.ini

LABEL org.opencontainers.image.title="QuickQuiz PHP base" \
      org.opencontainers.image.description="PHP-FPM runtime with the extensions shared by QuickQuiz Manager images" \
      org.opencontainers.image.source="https://github.com/robmoraes/quick-quiz"

RUN apk upgrade --no-cache \
    && apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps
