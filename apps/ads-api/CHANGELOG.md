# Changelog

All notable changes to the QuickQuiz Ads API will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Ads API release tags use the app-scoped `ads-api/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

### Added

- Added a dedicated Go Ads API with public advertising delivery through
  `GET /api/ads`.
- Added open administrative endpoints for the Manager to create the base ads
  file and list, create, update, or delete ads during the MVP.
- Added file-backed `ads/ads.json` persistence under `ADS_SOURCE`, with theme
  and topic validation from the shared content catalog.
- Added Docker packaging and app-scoped release workflow support for publishing
  Ads API images from `ads-api/vMAJOR.MINOR.PATCH` tags.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/ads-api%2Fv0.1.0...HEAD
