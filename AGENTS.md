# Repository Guidelines

## Project Structure & Module Organization

This repository contains the QuickQuiz Dev MVP.

Project premise: simple MVP, no database, no authentication. Still use robust architecture: clear boundaries, testable business logic, explicit contracts, and low coupling.

- `apps/api/`: Go API. Entry point: `cmd/api/main.go`; business logic: `internal/app`; domain: `internal/domain`; HTTP: `internal/httpapi`; stores: `internal/store`.
- `apps/manager/`: Symfony manager app for editing, validating, localizing, and publishing quiz packs.
- `apps/spa-dev/`: Quasar/Vue app. Main page: `src/pages/IndexPage.vue`; layout: `src/layouts/MainLayout.vue`; API client: `src/services/api.ts`; styles: `src/css/app.scss`.
- `docs/`: documentation, including `docs/openapi.yaml` and timeline files in `docs/timeline/YYYY-MM-DD.md`.
- `docs/specs/`: product, architecture, and implementation specifications.
- `apps/api/.local/themes.json`: local theme metadata, ignored by Git, simulating future S3 storage.
- `apps/api/.local/<theme>/index.json`: published topic catalog for a theme.
- `apps/api/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json`: local question files. The path defines question id, theme, locale, topic, and numeric difficulty.

## Build, Test, and Development Commands

API:

```sh
cd apps/api
go run ./cmd/api      # start API on :8080
go test ./...         # run Go tests
gofmt -w cmd internal # format Go code
```

SPA Dev:

```sh
cd apps/spa-dev
npm run dev     # start Quasar dev server
npm run lint    # run ESLint
npm run build   # production build and type checks
npm run format  # run Prettier
```

## Coding Style & Naming Conventions

Use `gofmt` for Go files. Keep package names short and lowercase. Keep domain rules out of HTTP handlers; place them in `internal/app` or `internal/domain`.

Use TypeScript in Vue components and services. Prefer explicit API types in `src/services/api.ts`. Frontend labels may differ from backend values; `difficulty` is numeric in the API and mapped in the frontend.

For i18n/l10n, follow the engineering playbook. Use BCP 47 tags such as `en-US` and `pt-BR`; call the API field `locale`, not `language`, for localized content. Keep locale separate from timezone. Do not localize API error codes, enum values, logs, metrics, traces, or machine-readable IDs.

Question packages are canonical in `FALLBACK_LOCALE` (`en-US` by default). Every supported locale must replicate the same `<language>/<difficulty>/<question-id>` files as translations; locales must not add or remove exclusive question packages. Session exhaustion is keyed by canonical question id, language, and difficulty, never by locale.

Theme entries in `apps/api/.local/themes.json` and topic entries in `apps/api/.local/<theme>/index.json` must include `active`. Only `active: true` themes and topics are loaded by the API; `active: false` keeps packages present but unpublished.

## Testing Guidelines

Backend tests use Go’s `testing` package. Place tests next to code as `*_test.go`; name tests with `Test...`.

Frontend relies on lint and build checks. Run `npm run lint` and `npm run build`.

## Commit & Pull Request Guidelines

Use the engineering playbook preferred branching model: Trunk-Based
Development. Keep `main` releasable and integrate small changes through
short-lived branches. Reference:
https://github.com/robmoraes/engineering-playbook/blob/main/github/branching-strategies.md#preferred-model-trunk-based-development

Name branches as `<type>/<description>`, where `type` follows the same
vocabulary as Conventional Commits. Use `feat/*`, `fix/*`, and `docs/*` for
normal work. Use `release/v<version>` only when stabilization or maintenance
requires it, and `hotfix/v<version>-<description>` only for urgent fixes to a
supported release line. Examples: `feat/spa-dev-adsense-validation`,
`fix/spa-dev-v0.1.1-release-config`, `docs/github-standards`,
`release/v1.8`, and `hotfix/v1.8.3-timeout`.

Use Conventional Commits with clear scopes:

```text
fix(backend): cap run total by available questions
feat(frontend): map numeric difficulty labels
docs(openapi): add api contract
```

Pull requests should include a summary, validations, screenshots for UI changes, and API contract changes.

GitHub issues must be created in English.

## Timeline Maintenance

Keep `docs/timeline/YYYY-MM-DD.md` updated. Use one-line entries: `HH:MM-03: scope: what changed`.

When Codex creates a spec, add a timeline entry with the creation timestamp.
When Codex implements a spec, add a separate timeline entry with the
implementation timestamp.

## Security & Configuration Tips

Do not commit real question banks or secrets. Keep local content under `apps/api/.local/themes.json`, `apps/api/.local/<theme>/index.json`, and `apps/api/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json`. Question files contain only `prompt`, `correctOptions`, and `wrongOptions`; theme and topic metadata come from `index.json` files. Configure `QUESTION_STORAGE_PROVIDER` as `local` or `s3`; use `QUESTION_SOURCE` for local files and `AWS_REGION`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL`, and `S3_FORCE_PATH_STYLE` for S3 storage. Also configure `FALLBACK_LOCALE`, `SUPPORTED_LOCALES`, `RUN_QUESTION_LIMIT`, `SESSION_TTL`, and `HTTP_ADDR`.
