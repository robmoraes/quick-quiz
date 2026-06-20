# Changelog

All notable changes to the QuickQuiz API will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
API release tags use the app-scoped `api/v<MAJOR>.<MINOR>.<PATCH>` format.

## [Unreleased]

### Added

- Added a run state endpoint that returns active/finished run status and the
  public current question for frontend recovery.

## [0.3.2] - 2026-06-17

### Fixed

- Cloud Compose now uses API-specific OpenAI environment variables for the API
  service, keeping Manager runtime model selection isolated from solution
  generation.
- Cloud Compose now requires the API OpenAI key, model, and solution prompt path
  at config-render time so missing solution-generation settings fail before
  deployment.

## [0.3.1] - 2026-06-17

### Fixed

- Cloud Compose now mounts the API content root as writable and runs the API
  with the shared runtime UID/GID so generated solution artifacts can be
  persisted.
- Unexpected API application errors are logged with the original error details
  before returning generic server error responses.

## [0.3.0] - 2026-06-17

### Added

- Added public question solution generation with OpenAI-backed lazy creation,
  local persisted solution artifacts, content-hash invalidation, and per-question
  concurrency control.
- Added run-scoped solution authorization with a dynamic per-run request limit.

## [0.2.0] - 2026-06-16

### Added

- Advertising delivery endpoint with theme filtering, random sampling, optional
  emphasis ad count, and topic-prioritized fallback selection.
- Local `ads/ads.json` loader with support for ad metadata, expiration,
  active flags, emphasis flags, theme targeting, and optional topic targeting.

## [0.1.0] - 2026-06-12

### Added

- Initial Go API for QuickQuiz Dev with catalog, session, run, answer, result,
  reset, and health endpoints.
- Theme-aware local question storage using `themes.json`,
  `<theme>/index.json`, locale catalogs, active theme/topic flags, fallback
  locale behavior, and canonical question IDs.
- In-memory run and session state for the MVP, including question exhaustion,
  per-session availability, run question limits, session TTL, and graceful
  shutdown configuration.
- Docker packaging for the API image with non-root runtime, healthcheck, local
  content volume, and Docker Hub release support.
- App-scoped release workflow support for publishing the API image from
  `api/vMAJOR.MINOR.PATCH` tags.

### Changed

- The API release image repository is configurable through the
  `DOCKERHUB_API_IMAGE` production environment variable.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/api%2Fv0.3.2...HEAD
[0.3.2]: https://github.com/robmoraes/quick-quiz/compare/api%2Fv0.3.1...api%2Fv0.3.2
[0.3.1]: https://github.com/robmoraes/quick-quiz/compare/api%2Fv0.3.0...api%2Fv0.3.1
[0.3.0]: https://github.com/robmoraes/quick-quiz/compare/api%2Fv0.2.0...api%2Fv0.3.0
[0.2.0]: https://github.com/robmoraes/quick-quiz/compare/api%2Fv0.1.0...api%2Fv0.2.0
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/api%2Fv0.1.0
