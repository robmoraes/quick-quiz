# syntax=docker/dockerfile:1.7

ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27

FROM --platform=$BUILDPLATFORM node:${NODE_VERSION}-alpine AS build

WORKDIR /app

COPY apps/spa-dslab/package.json apps/spa-dslab/package-lock.json ./
COPY apps/spa-dslab/eslint.config.js apps/spa-dslab/index.html apps/spa-dslab/postcss.config.js apps/spa-dslab/quasar.config.ts apps/spa-dslab/tsconfig.json ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY apps/spa-dslab/public ./public
COPY apps/spa-dslab/src ./src

ARG VITE_API_BASE_URL
ARG VITE_ADS_API_BASE_URL
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_ADS_API_BASE_URL=${VITE_ADS_API_BASE_URL}
RUN test -n "${VITE_API_BASE_URL}" || (echo "VITE_API_BASE_URL build arg is required" >&2; exit 1)
RUN test -n "${VITE_ADS_API_BASE_URL}" || (echo "VITE_ADS_API_BASE_URL build arg is required" >&2; exit 1)
RUN npm run build

FROM nginx:${NGINX_VERSION}-alpine

COPY deploy/docker/spa-dslab/nginx.conf /etc/nginx/nginx.conf
COPY --from=build /app/dist/spa /usr/share/nginx/html

RUN chown -R nginx:nginx /usr/share/nginx/html /var/cache/nginx /var/run

EXPOSE 8080

USER nginx

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

CMD ["nginx", "-g", "daemon off;"]
