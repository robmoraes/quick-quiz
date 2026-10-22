# QuickQuiz Web

Generic quiz discovery and gameplay in Nuxt 4, Vue/TypeScript and Nuxt UI.
Public discovery is rendered on the server. Personal quiz state is created only
after an explicit browser action and uses the existing Go gameplay API.

Dependencies: Quiz API. The API uses the existing PostgreSQL and Redis services.
The Web has no database credential, administrative token or persistent volume.

See the [local runbook](../../docs/runbooks/generic-quiz-web-local.md),
[image release guide](../../docs/runbooks/generic-quiz-web-image-release.md),
[changelog](CHANGELOG.md),
[spec 007](../../docs/specs/007-generic-quiz-web/spec.md) and
[canonical API contract](../../docs/openapi.yaml).

## Validation

Use Node 24 in Docker; no application dependency installation is required on
the host. Run from the repository root:

```sh
docker run --rm --user "$(id -u):$(id -g)" \
  -e HOME=/tmp -v "$PWD/apps/web:/app" -w /app node:24-bookworm-slim \
  sh -c 'npm ci --no-audit --no-fund && npm run lint && npm run typecheck && npm test && npm run build'
docker run --rm -v "$PWD/deploy/docker/web:/checks:ro" -w /checks \
  node:24-alpine node --test config-files.test.mjs
```

The test suite covers the discovery query/client, locale/configuration/SEO
utilities, and the game state machine with injected synthetic API responses.
Browser validation and PostgreSQL integration evidence are recorded in the spec.

## Routes

- `/<locale>/`: search, areas and theme collection.
- `/<locale>/explore`: paginated themes or topics with intersecting filters.
- `/<locale>/themes/<theme>` and `/topics/<topic>` beneath it: public details.
- `/<locale>/play` and `/<locale>/results`: browser-only personal journey.

Supported interface locales are `pt-BR` and `en-US`. Theme metadata remains
canonical; topic fields follow the API's localized fallback. Unknown tag labels
fall back to the stored slug, so new tags do not require an image rebuild.

Discovery HTML and API reads use `no-store`. There is no shared catalog cache
or build-time question snapshot. Query/filter pages and personal routes are
excluded from indexing; local mode disables indexing everywhere. Sitemap
generation reads bounded public metadata pages, never question prompts/answers.

Each tab stores only minimal run context and a bounded result summary in
`sessionStorage`. Reload reconciles a known run with the server. If a create
request ends without a run ID, the Web cannot discover that run; recovery needs
an explicitly confirmed session reset. There is no account or complete
server-side historical session report.
