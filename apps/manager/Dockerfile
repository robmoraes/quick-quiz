ARG MANAGER_DOCKER_PHP_BASE_IMAGE=robmoraes/quick-quiz-php-base:8.3.36-alpine3.24-r1@sha256:7e4cd839953eba6479c23b77706d3a98a31aa7181e84efc9a30ef3f6cac033e3
FROM ${MANAGER_DOCKER_PHP_BASE_IMAGE}

RUN apk add --no-cache bash git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public", "public/dev-router.php"]
