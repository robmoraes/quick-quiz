# QuickQuiz Ads API

Dedicated Go API for QuickQuiz advertising delivery and management.

The public player contract is:

- `GET /api/ads?limit=2&emphasis=1&topic=php`
- Theme comes from the `X-QuickQuiz-Theme` header.

The Manager uses the open administrative endpoints during the MVP:

- `GET /api/admin/ads/file`
- `PUT /api/admin/ads/file`
- `GET /api/admin/ads?theme=dev`
- `GET /api/admin/ads/{id}`
- `POST /api/admin/ads`
- `PUT /api/admin/ads/{id}`
- `DELETE /api/admin/ads/{id}`

The API uses `ADS_STORAGE_PROVIDER=local|s3`. The `local` provider reads and writes `ads/ads.json` under `ADS_SOURCE`. The `s3` provider reads and writes the same document at `s3://<S3_BUCKET>/<S3_PREFIX>/ads/ads.json`.

Both providers also read `themes.json` and `<theme>/index.json` from the same content root to validate theme and topic targets. S3 uses the default AWS credential chain and supports a custom endpoint for S3-compatible development services.

## Local Commands

```sh
cd apps/ads-api
go run ./cmd/ads-api
go test ./...
```

## Environment

```text
HTTP_ADDR=:8080
ADS_STORAGE_PROVIDER=local
ADS_SOURCE=.local
SHUTDOWN_TIMEOUT=10s
AWS_REGION=us-east-1
S3_BUCKET=
S3_PREFIX=questions
S3_ENDPOINT_URL=
S3_FORCE_PATH_STYLE=false
```
