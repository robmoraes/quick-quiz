# syntax=docker/dockerfile:1.7

ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27

FROM --platform=$BUILDPLATFORM node:${NODE_VERSION}-alpine AS build

WORKDIR /app

COPY apps/spa-dev/package.json apps/spa-dev/package-lock.json ./
COPY apps/spa-dev/eslint.config.js apps/spa-dev/index.html apps/spa-dev/postcss.config.js apps/spa-dev/quasar.config.ts apps/spa-dev/tsconfig.json ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY apps/spa-dev/public ./public
COPY apps/spa-dev/src ./src

ARG VITE_API_BASE_URL=http://localhost:8080
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
RUN npm run build

FROM nginx:${NGINX_VERSION}-alpine

COPY deploy/docker/spa-dev/nginx.conf /etc/nginx/nginx.conf
COPY --from=build /app/dist/spa /usr/share/nginx/html

RUN chown -R nginx:nginx /usr/share/nginx/html /var/cache/nginx /var/run

EXPOSE 8080

USER nginx

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
