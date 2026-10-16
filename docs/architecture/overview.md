# Architecture Overview

QuickQuiz is a quiz system with separate player clients, game logic, content
authoring and storage. Subjects are unrestricted; the Dev SPA's `dev` audience
tag controls discovery, while DSLab keeps its fixed theme. There are no player
accounts. Manager authentication is separate from player sessions.

The generic [Nuxt Web](adr/005-generic-quiz-web.md) is implemented and verified
locally. It adds server-rendered public discovery and a separate quiz player;
its production rollout remains outside the current runtime described below.

## Runtime Shape

Production uses PostgreSQL reads and atomic publication after the separately
authorized 2026-10-11 cutover. See [ADR 004](adr/004-postgresql-quiz-runtime.md),
the [migration runbook](../runbooks/postgresql-quiz-runtime.md), and the
[deployment record](../runbooks/postgresql-quiz-runtime-release.md).

```text
Editor / protected administration API
  -> Symfony Manager (sole content writer)
     -> PostgreSQL authored content + atomic live publication
        -> SELECT-only versioned views -> Go Quiz API -> Player SPAs
                                           |
                                           -> bounded run snapshots -> Redis

Explicit operator export -> legacy JSON -> local / S3
Ads API -> its existing local / S3 storage
```

With PostgreSQL runtime, the API reads theme/topic metadata and aggregate counts
by scope, selects question IDs, and hydrates only the selected run packages.
There is no whole-bank startup download. Each operation uses a consistent
read-only transaction and a dedicated reader credential. Manager validates and
commits content plus publication in one transaction; new requests see updates
without restarting the API.

Runs capture their selected questions, translations and served options in Redis.
Edits, deletion or retirement affect new runs; an existing captured run continues
with its saved content. Session consumption uses per-session/theme indexes.
Generated explanations use the captured question and current optional theme
instructions; their cache identity includes content and generator settings.

The legacy local/S3 question provider and content-storage publication remain
available for staged compatibility and deliberate rollback. They preserve the
JSON contract and startup/reload behavior; fallback requires an explicit fresh
verified export and a v2-compatible reader image. There is no automatic storage
fallback after an outage.

## Core Boundaries

The SPAs render choices, runs and results. They send the selected theme, locale
and session headers, and never receive answer correctness or database access.

The Go API owns game rules, selection, grading, exhaustion and results. Redis
holds session/run state and explanation cache; PostgreSQL holds live quiz content
when selected. OpenAI is optional for explanations, not gameplay.

Manager owns authoring, validation, administration authentication, migrations,
publication and explicit export. PostgreSQL also stores Manager accounts and
AI instructions; Manager login sessions use Redis. The API reader can only SELECT
allowlisted runtime views, not Manager users, sessions or base tables.

Theme tags support discovery. Topic tags are public navigation metadata in the
new PostgreSQL-backed `/api/discovery` contract; they remain absent from legacy
gameplay responses and JSON exports. The deployed API predates this additive
discovery feature. Inactive themes/topics retain unpublished content. JSON
exports remain portable content artifacts, not code or secrets. See
[ADR 003](adr/003-tagged-theme-discovery.md) and
[ADR 005](adr/005-generic-quiz-web.md).

Ads API remains an independent service. Its storage and target metadata are not
migrated by the quiz runtime change.

## Non-Goals

- Public player accounts or persistent player score history.
- Real question banks or secrets committed to the repository.
- Additional nodes, Kubernetes or managed database infrastructure in this change.
- Replacing the specialized SPAs or adding editorial approval workflows.
