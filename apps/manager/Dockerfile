ARG MANAGER_DOCKER_PHP_BASE_IMAGE=robmoraes/quick-quiz-php-base:8.3.37-alpine3.24-r1@sha256:61f8500bd6b6a1416074db42eb3d40847f3033f5cb333faff5549b0ef760b56b
FROM ${MANAGER_DOCKER_PHP_BASE_IMAGE}

RUN apk add --no-cache bash git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public", "public/dev-router.php"]
