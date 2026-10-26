# Changelog

All notable changes to QuickQuiz Web will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Web release tags use the app-scoped `web/v<MAJOR>.<MINOR>.<PATCH>` format.

## [Unreleased]

### Fixed

- Improve quiz punctuation and code legibility with a self-hosted JetBrains Mono
  font, disabled programming ligatures, larger answer text and clearer standalone
  symbols. Bare operators such as `+`, `*` and `>` remain visible instead of
  being interpreted as Markdown structure.

## [0.1.0] - 2026-10-22

### Added

- Generic, responsive quiz website with Portuguese (`pt-BR`) and English
  (`en-US`) interfaces, independently of the existing Dev and DSLab SPAs.
- Server-rendered theme and topic discovery with search, pagination, theme
  tags and topic tags for finding related subjects across themes.
- Localized page metadata, canonical and alternate URLs, and paginated
  sitemaps. Filtered and personal pages stay outside indexing; local mode
  disables indexing throughout the site.
- Browser quiz flow with difficulty selection, answer feedback, round review,
  optional answer explanations and consolidated session results.
- Per-tab session context, known-run reconciliation after reload and explicit
  recovery from uncertain requests, expired runs and interrupted connections.
- Prominent correct/incorrect feedback with icons, distinct colors, previous
  question identification and screen-reader announcements.
- Multi-platform Node 24 Docker packaging, non-root runtime, file-backed
  configuration, liveness/readiness endpoints and optional local Compose profile.
- Independent Docker Hub image releases from `web/vMAJOR.MINOR.PATCH` tags,
  with lint, type checks, automated tests and production build before publishing
  `linux/amd64` and `linux/arm64` images.

Public discovery requires a compatible Quiz API with PostgreSQL runtime,
migration `0005_public_quiz_discovery.sql` and explicitly reconciled reader
grants. See the [local operation guide](../../docs/runbooks/generic-quiz-web-local.md)
and [image release guide](../../docs/runbooks/generic-quiz-web-image-release.md).
Image publication and production deployment are separate operations.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/web%2Fv0.1.0...HEAD
[0.1.0]: https://github.com/robmoraes/quick-quiz/releases/tag/web%2Fv0.1.0
