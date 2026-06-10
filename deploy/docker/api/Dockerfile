# syntax=docker/dockerfile:1.7

ARG GO_VERSION=1.22
ARG ALPINE_VERSION=3.20

FROM --platform=$BUILDPLATFORM golang:${GO_VERSION}-alpine${ALPINE_VERSION} AS build

WORKDIR /src

COPY apps/api/go.mod ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download

COPY apps/api/cmd ./cmd
COPY apps/api/internal ./internal

ARG TARGETOS
ARG TARGETARCH
RUN --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 \
    GOOS="${TARGETOS:-linux}" \
    GOARCH="${TARGETARCH:-$(go env GOARCH)}" \
    go build \
      -trimpath \
      -ldflags="-s -w" \
      -o /out/quickquiz-api \
      ./cmd/api

FROM alpine:${ALPINE_VERSION}

RUN addgroup -S -g 10001 quickquiz \
    && adduser -S -D -H -u 10001 -G quickquiz quickquiz \
    && apk add --no-cache ca-certificates tzdata

WORKDIR /app

COPY --from=build /out/quickquiz-api /usr/local/bin/quickquiz-api

RUN mkdir -p /app/.local \
    && chown -R quickquiz:quickquiz /app

ENV HTTP_ADDR=:8080
ENV QUESTION_STORAGE_PROVIDER=local
ENV QUESTION_SOURCE=/app/.local
ENV FALLBACK_LOCALE=en-US
ENV SUPPORTED_LOCALES=en-US,pt-BR
ENV RUN_QUESTION_LIMIT=10
ENV SESSION_TTL=30m
ENV SHUTDOWN_TIMEOUT=10s

EXPOSE 8080
VOLUME ["/app/.local"]

USER 10001:10001

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

ENTRYPOINT ["quickquiz-api"]
