# syntax=docker/dockerfile:1.7
ARG NODE_IMAGE=node:24-alpine

FROM --platform=$BUILDPLATFORM ${NODE_IMAGE} AS build
WORKDIR /app
COPY apps/web/package.json apps/web/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY apps/web/nuxt.config.ts apps/web/tsconfig.json ./
COPY apps/web/app ./app
COPY apps/web/server ./server
COPY apps/web/shared ./shared
COPY apps/web/public ./public
RUN npm run build

FROM ${NODE_IMAGE} AS runtime
WORKDIR /app
ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000 \
    NODE_OPTIONS=--max-old-space-size=256
COPY --from=build --chown=node:node /app/.output ./.output
COPY --chown=node:node deploy/docker/web/entrypoint.mjs ./entrypoint.mjs
COPY --chown=node:node deploy/docker/web/config-files.mjs ./config-files.mjs
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=4s --start-period=15s --retries=3 \
    CMD wget -qO- http://127.0.0.1:3000/healthz >/dev/null || exit 1
CMD ["node", "entrypoint.mjs"]
