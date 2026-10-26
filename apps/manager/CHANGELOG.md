# Changelog

All notable changes to the QuickQuiz Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Manager release tags use the app-scoped `manager/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

## [0.15.1] - 2026-10-26

### Security

- Upgrade the shared PHP runtime to 8.3.36 and disable session upload progress
  before request parsing in development and FPM images.
- Update Symfony HttpFoundation and YAML to 7.4.21 for CVE-2026-108082 and
  CVE-2026-109413; upgrade the Manager Nginx image to pinned 1.30.6.
- Audit locked production dependencies and both runtime architectures in PRs,
  daily checks and Manager releases. Promote `latest` only after the release
  audit passes; retain digest-based audit evidence and fail on incomplete scans.

## [0.15.0] - 2026-10-22

### Added

- Additive migration `0005_public_quiz_discovery.sql` exposing active topic
  tags through the SELECT-only `quiz_runtime_v1.topic_tags` view for Web discovery.

### Changed

- Runtime reader provisioning includes the new discovery view. Explicitly
  reconcile existing reader grants after migration, preserving the reader password.
- Update the default displayed Manager version to `0.15.0`; environment
  configuration can still override it through `MANAGER_VERSION`.

## [0.14.0] - 2026-10-11

### Added

- Opt-in atomic PostgreSQL quiz publication: authoring and publication commit
  together, and the API sees changes without a content reload or S3 writes.
- Additive runtime views and explicit SELECT-only API reader-role provisioning.
- Revision-checked backend activation and streamed, verified legacy exports for
  deliberate recovery with a snapshot-compatible API image.
- Automated PostgreSQL integration checks and a staged cutover/recovery runbook.

### Changed

- PostgreSQL publication responses report `apiReloadRequired: false`, with null
  export checksum/count; legacy publication remains available and unchanged.
- Instantiate content storage only when required; database-mode quiz and prompt
  operations no longer require an S3 bucket or a question-directory mount.
- Use subject-neutral explanation defaults and clarify theme-context guidance.

## Released through [0.13.0]

Cumulative notes retained from earlier releases.

### Added

- PostgreSQL theme tags in Manager Web and the protected administration API,
  with atomic replacement, omission/clearing semantics, and migration 0003.
- Theme tag publication and validated import/export round trips, including
  explicit replacement additions/removals in dry-run reports.

- PostgreSQL topic tags for Manager Web and the protected administration API
  (Manager 0.12.0): normalized shared tags, atomic replacement, omission/clearing
  semantics, and replacement-import preservation. Tags-only edits do not access
  content storage or change publication revisions. Migration 0002 is required.

- Token-protected administration API for catalog discovery and theme, topic,
  and complete localized question-set CRUD through local or S3 storage.
- Added a QuickQuiz API session monitor screen backed by the open active-session
  endpoint.
- Added Ads API client integration through `QUICKQUIZ_ADS_API_BASE_URL` for ad
  file provisioning and advertising CRUD.
- Advertising forms now support multiple theme targets with optional per-theme
  topic filters.

### Changed

- Advertising management now delegates ad persistence to the dedicated Ads API
  instead of writing the ads file directly from the manager.
- Moved the manager navigation into a left drawer/sidebar layout.

### Fixed

- Hide AI footer controls before authentication and defer model discovery until
  an authenticated Manager page is rendered.

## [0.6.0] - 2026-06-17

### Added

- AI prompt management now includes the API question-solution prompt and exports
  it to the selected theme content folder for the Go API to consume.

## [0.5.0] - 2026-06-17

### Added

- SQLite-backed AI prompt management scoped by selected theme, including list,
  edit, restore default, and JSON upload import flows.
- Runtime OpenAI model selection in the manager footer, with available models
  fetched once per session and filtered for QuickQuiz content generation use.

### Changed

- Manager OpenAI services now load theme-specific prompt overrides and use the
  runtime-selected model for question, localization, and catalog AI actions.
- Catalog Save with AI now saves translated localized topic metadata for
  supported locales instead of copying fallback text.

## [0.4.0] - 2026-06-16

### Added

- Advertising management screens for creating the base ads file, listing,
  creating, editing, and deleting ad entries.
- Ad targeting by QuickQuiz theme and optional topic selection loaded from each
  theme catalog.

## [0.3.0] - 2026-06-14

### Added

- Create with AI now treats Prompt text as generation guidance when generating
  a complete question draft, while answer-only suggestions continue to use
  Prompt as the existing question statement.
- Reusable AI help drawer for manager screens with AI actions, including
  accordion help entries when multiple AI resources are available.
- SPA favicon assets are now used by the manager app.

### Changed

- Questions list table keeps enough minimum vertical space for single-row AI
  edit dropdown menus.
- AI help drawer opens on the right side with an edge toggle for closing and
  reopening the drawer.

## [0.2.0] - 2026-06-13

### Added

- Manual and AI-assisted question creation/editing flows, including localized
  question set replication, answer-only AI suggestions, AI availability guards,
  and Bootstrap Icons.
- Dark-mode manager interface as the default and only visual theme.
- Manager version display in the fixed footer.
- Multi-locale question deletion with UI feedback for deleted and already
  missing locale variants.
- Topic datetime handling that persists manager-written `created_at` values in
  UTC and displays/edits them in the browser timezone.
- AI-assisted catalog topic helpers for fallback description suggestions,
  canonical fallback saves, and localized catalog translations.
- Topic list filtering by keyword and sorting by key, name, weight, or created
  date.
- Selected-theme stats screen with content totals, locale/topic/difficulty
  breakdowns, run capacity, and content health metrics.

### Changed

- Topic, question, and catalog action controls now use compact icon/button
  treatments aligned with the manager UI.
- Catalog localized edit forms prefill from fallback topic metadata when the
  localized entry is missing or blank.

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

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.15.1...HEAD
[0.15.1]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.15.0...manager%2Fv0.15.1
[0.15.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.14.0...manager%2Fv0.15.0
[0.14.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.13.0...manager%2Fv0.14.0
[0.6.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.5.0...manager%2Fv0.6.0
[0.5.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.4.0...manager%2Fv0.5.0
[0.4.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.3.0...manager%2Fv0.4.0
[0.3.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.2.0...manager%2Fv0.3.0
[0.2.0]: https://github.com/robmoraes/quick-quiz/compare/manager%2Fv0.1.0...manager%2Fv0.2.0
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/manager%2Fv0.1.0
[0.13.0]: https://github.com/robmoraes/quick-quiz/releases/tag/manager%2Fv0.13.0
