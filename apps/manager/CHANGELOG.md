# Changelog

All notable changes to the QuickQuiz Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Manager release tags use the app-scoped `manager/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

## [0.1.0] - 2026-06-12

### Added

- Initial Symfony manager app for editing and validating QuickQuiz content
  packs without database-backed content storage.
- Theme, catalog topic, question package, validation, localization, and AI
  recommendation flows for the MVP manager experience.
- Local admin support with SQLite-backed manager state and commands for
  creating manager users.
- Docker packaging for manager FPM and manager web images, including production
  Composer dependencies, PHP-FPM, Nginx, OPcache, SQLite support, healthcheck,
  and Docker Hub release support.
- App-scoped release workflow support for publishing the manager FPM and web
  images from `manager/vMAJOR.MINOR.PATCH` tags.

### Changed

- The manager release image repositories are configurable through the
  `DOCKERHUB_MANAGER_FPM_IMAGE` and `DOCKERHUB_MANAGER_WEB_IMAGE` production
  environment variables.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.1.0...HEAD
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/manager%2Fv0.1.0
