# Changelog

All notable changes to the QuickQuiz DSLab SPA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this app adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
SPA DSLab release tags use the app-scoped `spa-dslab/v<MAJOR>.<MINOR>.<PATCH>`
format.

## [Unreleased]

### Added

- Added the initial DSLab-themed player app with Distributed Systems Lab copy,
  splash branding, background artwork, and logo treatment.
- Added Docker image packaging and GitHub Actions release workflow support for
  `spa-dslab/vMAJOR.MINOR.PATCH` tags.

### Changed

- Ad requests now use the dedicated Ads API base URL configured through
  `VITE_ADS_API_BASE_URL`.
- Topic selectors now hide exhausted topics instead of rendering disabled
  options.
- Updated severity result copy from developer/programmer wording to the DevOps
  context used by DSLab.

### Removed

- Removed the Google AdSense validation script from the document head.

[Unreleased]: https://github.com/robmoraes/quick-quiz/compare/spa-dslab%2Fv0.1.0...HEAD
