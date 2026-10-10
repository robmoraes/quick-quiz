# Architecture Overview

QuickQuiz Dev is a small fullstack quiz system built around explicit service
boundaries and a file-based content contract.

The MVP avoids infrastructure that is not needed yet: there is no player
account system or player authentication. PostgreSQL stores authored quiz content;
the player API reads its published JSON projection. The
architecture still separates user interface, HTTP API, business rules, content
validation, and content storage so the project can evolve without turning the
MVP into a tightly coupled prototype.

## Goals

- Provide a fast quiz experience for developers.
- Keep backend rules testable outside HTTP handlers.
- Keep the player SPA focused on game flow and presentation.
- Let the manager validate quiz packs before the API serves them.
- Keep quiz content portable as JSON files.
- Support locale-specific content without localizing machine-readable values.

## Runtime Shape

```text
Player browser
  |
  | Quasar/Vue SPA
  v
Go API
  |
  | reads validated quiz packs
  v
Local files or S3-compatible storage

Content editor
  |
  | Symfony Manager
  v
PostgreSQL authoring -> publication -> local or S3-compatible storage
```

## Core Boundaries

The SPA is a player client. It renders catalog choices, starts runs, submits
answers, shows results, and sends locale/theme/session headers.

The API is the game authority. It validates run state, selects questions,
checks answers, enforces session exhaustion, and hides unpublished content.

The manager is the content editing tool. It stores canonical quiz content in
PostgreSQL and publishes quiz pack JSON objects through local or S3-compatible
storage, preserving the canonical file contract.

The quiz pack is the player-facing content projection. It is not application
code and must not contain secrets or runtime-only metadata. Published theme tags
support public discovery; topic tags remain private authoring metadata. See
[ADR 003](adr/003-tagged-theme-discovery.md).

## Non-Goals For The MVP

- Public player accounts.
- Persistent player score history.
- Real advertising provider integration.
- Real question banks committed to the public repository.

