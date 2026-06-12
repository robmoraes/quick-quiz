# Changelog

All notable changes to the QuickQuiz Dev SPA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
SPA Dev release tags use the app-scoped `spa-dev/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

## [0.1.2] - 2026-06-12

### Added

- Added the Google AdSense validation script to the SPA Dev document head.

## [0.1.1] - 2026-06-12

### Fixed

- Rebuilt the SPA Dev release after correcting the production
  `SPA_DEV_API_BASE_URL` environment variable used by the Docker image build.

## [0.1.0] - 2026-06-12

### Added

- Initial Quasar/Vue player app for QuickQuiz Dev with topic selection,
  difficulty selection, quiz runs, answer feedback, result flow, and session
  reset behavior.
- Developer-themed UI, localized copy, sound effects, syslog-style event
  presentation, result panels, and ad/interstitial surfaces for the MVP.
- API client integration for catalog, availability, run creation, answer
  submission, result retrieval, and session reset.
- Docker packaging for the SPA Dev image with Nginx static serving,
  healthcheck, and Docker Hub release support.
- App-scoped release workflow support for publishing the SPA Dev image from
  `spa-dev/vMAJOR.MINOR.PATCH` tags.

### Changed

- SPA Dev image builds now require `VITE_API_BASE_URL`; there is no Docker build
  default because the value is compiled into the static frontend bundle.
- The automated production release uses the `SPA_DEV_API_BASE_URL` production
  environment variable for the compiled API base URL.
- The SPA Dev release image repository is configurable through the
  `DOCKERHUB_SPA_DEV_IMAGE` production environment variable.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.2...HEAD
[0.1.2]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.1...spa-dev%2Fv0.1.2
[0.1.1]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.0...spa-dev%2Fv0.1.1
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/spa-dev%2Fv0.1.0
