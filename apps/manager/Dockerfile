ARG MANAGER_DOCKER_PHP_VERSION=8.3
FROM php:${MANAGER_DOCKER_PHP_VERSION}-cli-alpine

RUN apk add --no-cache bash git libpq libxml2 sqlite-libs unzip \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev libxml2-dev sqlite-dev \
  && docker-php-ext-install pdo_pgsql pdo_sqlite simplexml \
  && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public", "public/dev-router.php"]
