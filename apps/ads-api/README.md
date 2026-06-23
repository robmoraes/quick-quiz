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

The API reads and writes `ads/ads.json` under `ADS_SOURCE`. It also reads
`themes.json` and `<theme>/index.json` from the same source to validate theme
and topic targets.

## Local Commands

```sh
cd apps/ads-api
go run ./cmd/ads-api
go test ./...
```

## Environment

```text
HTTP_ADDR=:8080
ADS_SOURCE=.local
SHUTDOWN_TIMEOUT=10s
```
