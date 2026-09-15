# syntax=docker/dockerfile:1.7

ARG PHP_UPSTREAM_IMAGE=php:8.3.33-fpm-alpine3.24@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8

FROM ${PHP_UPSTREAM_IMAGE}

LABEL org.opencontainers.image.title="QuickQuiz PHP base" \
      org.opencontainers.image.description="PHP-FPM runtime with the extensions shared by QuickQuiz Manager images" \
      org.opencontainers.image.source="https://github.com/robmoraes/quick-quiz"

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps
