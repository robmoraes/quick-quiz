# syntax=docker/dockerfile:1

FROM golang:1.22-alpine AS build

WORKDIR /src

COPY go.mod ./
RUN go mod download

COPY cmd ./cmd
COPY internal ./internal

RUN CGO_ENABLED=0 GOOS=linux go build \
    -trimpath \
    -ldflags="-s -w" \
    -o /out/quickquiz-api \
    ./cmd/api

FROM alpine:3.20

RUN addgroup -S quickquiz \
    && adduser -S -G quickquiz quickquiz \
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
ENV OPENAI_BASE_URL=https://api.openai.com/v1
ENV OPENAI_MODEL=gpt-5.4-mini
ENV OPENAI_SOLUTION_PROMPT_FILE=/app/.local/{{theme}}/ai-prompts/question-solution-prompt.txt
ENV OPENAI_TIMEOUT=30s

EXPOSE 8080
VOLUME ["/app/.local"]

USER quickquiz

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

ENTRYPOINT ["quickquiz-api"]
