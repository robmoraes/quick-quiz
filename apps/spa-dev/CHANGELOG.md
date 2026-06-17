# Changelog

All notable changes to the QuickQuiz Dev SPA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
SPA Dev release tags use the app-scoped `spa-dev/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

## [0.5.0] - 2026-06-17

### Added

- Added shareable topic URLs through the `topic` query parameter.
- Added an answer-table solution action that opens a full in-panel AI
  explanation view for rejected answers as a review comment with a notification badge.
- Added run-scoped question solution requests for rejected answers.
- Added read-state behavior that hides the review comment badge after the
  solution comment is loaded.

## [0.4.0] - 2026-06-16

### Added

- API-backed advertising cards in the side layout slots, with regular ads on
  the left and emphasis ads on the right.
- Mobile result advertising fallback when side slots are hidden.

### Changed

- Ad requests now include the selected topic when available so the API can
  prioritize topic-targeted products.
- The top advertising region was removed to give more space to quiz content.

## [0.3.0] - 2026-06-16

### Added

- Added a first-visit welcome panel before the topic selection flow.

### Changed

- Updated the welcome panel action label.
- Refined topic selection copy.
- Changed the welcome panel seen flag to use session storage instead of local
  storage.

## [0.2.0] - 2026-06-13

### Added

- Added an in-app SPA Dev version indicator.
- Added Markdown rendering for question prompts and result review text.
- Added session and topic availability cards for the topic and difficulty
  selection screens.
- Added the large QuickQuiz logo, help access, and settings access to the
  title area on pre-run and result screens.
- Added explicit expired-run recovery when the API returns `run_not_found`
  during answer, result, or end-session flows.

### Changed

- Refined the pre-run and result screen title chrome to use Quasar utility
  classes and simplified release-facing labels.
- Reworked availability counters to use result-style scalar cards and clearer
  active/inactive session semantics.
- Removed the run ad interstitial path and cleaned up unused CSS left by the
  title-bar and counter refactors.

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

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.5.0...HEAD
[0.5.0]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.4.0...spa-dev%2Fv0.5.0
[0.4.0]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.3.0...spa-dev%2Fv0.4.0
[0.3.0]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.2.0...spa-dev%2Fv0.3.0
[0.2.0]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.2...spa-dev%2Fv0.2.0
[0.1.2]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.1...spa-dev%2Fv0.1.2
[0.1.1]: https://github.com/robmoraes/quick-quiz/compare/spa-dev%2Fv0.1.0...spa-dev%2Fv0.1.1
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/spa-dev%2Fv0.1.0
