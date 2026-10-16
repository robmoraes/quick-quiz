# Specs

This directory contains product and implementation specifications for
QuickQuiz Dev.

Specs are part of the public project history. They explain decisions,
constraints, and planned work before implementation.

## Standard and Scope

QuickQuiz already uses the engineering playbook's
[spec-driven development standard](https://github.com/robmoraes/engineering-playbook/blob/main/architecture/spec-driven-development.md).
The standard is shared across projects; [standard.md](standard.md) is the
reference copy maintained in this repository.

Feature specifications live here, alongside the code they govern. The numbered
sequence applies to the whole QuickQuiz repository, including features that
cross service boundaries. It is not a separate sequence for each application.
The service-based directories under `archive/` preserve the older organization.

Use `spec.md` for behavior, `plan.md` for design, `tasks.md` for ordered work,
and `contracts/` for machine-readable interfaces when applicable. Consequential
architecture decisions belong in `docs/architecture/adr/`. This convention does
not require installing Spec Kit or a shared specification service.

## Active Specs

- `standard.md`: spec-driven development standard used for new specs.
- `001-manager-question-form-flow/spec.md`: manager manual and AI-assisted
  question authoring flow spec.
- `001-manager-question-form-flow/plan.md`: technical plan for implementing
  the manager question authoring flow spec.
- `001-manager-question-form-flow/tasks.md`: implementation and validation
  task list for the manager question authoring flow spec.
- `002-quiz-admin-api/spec.md`: token-protected quiz administration API behavior.
- `002-quiz-admin-api/plan.md`: Manager-owned API architecture and verification plan.
- `002-quiz-admin-api/tasks.md`: implementation and validation task list.
- `003-manager-quiz-postgresql/spec.md`: PostgreSQL-backed Manager quiz
  authoring, legacy content import, and S3 publication behavior.
- `003-manager-quiz-postgresql/plan.md`: technical architecture and staged migration plan.
- `003-manager-quiz-postgresql/tasks.md`: implementation, automation, and production cutover tasks.

- `004-manager-topic-tags/spec.md`: PostgreSQL-only topic tags in Manager Web
  and the administrative API, excluded from published JSON and player services.
- `004-manager-topic-tags/plan.md`: persistence, compatibility, and publication isolation.
- `004-manager-topic-tags/tasks.md`: implementation and automated verification tasks.
- [005-tagged-theme-selection/spec.md](005-tagged-theme-selection/spec.md):
  implemented theme tags, public theme discovery, and environment-scoped theme
  selection in SPA Dev; SPA DSLab remains unchanged.
- [005-tagged-theme-selection/plan.md](005-tagged-theme-selection/plan.md):
  storage, publication, API compatibility, frontend state, and rollout design.
- [005-tagged-theme-selection/tasks.md](005-tagged-theme-selection/tasks.md):
  implementation checklist and local automated verification evidence.
- [006-postgresql-quiz-runtime/spec.md](006-postgresql-quiz-runtime/spec.md):
  approved PostgreSQL player reads, atomic publication, and stable run snapshots
  for a growing, subject-neutral catalog.
- [006-postgresql-quiz-runtime/plan.md](006-postgresql-quiz-runtime/plan.md):
  scoped SQL/Redis access, reader permissions, compatibility, and recovery.
- [006-postgresql-quiz-runtime/tasks.md](006-postgresql-quiz-runtime/tasks.md):
  implementation checklist and automated acceptance/capacity evidence.
- [ADR 004](../architecture/adr/004-postgresql-quiz-runtime.md): accepted
  replacement of the S3/runtime-snapshot publication boundary, deployed on 2026-10-11.
- [PostgreSQL runtime deployment](../runbooks/postgresql-quiz-runtime-release.md):
  production versions, cutover validation, backups, and recovery requirements.
- [007-generic-quiz-web/spec.md](007-generic-quiz-web/spec.md): implemented generic
  Web direction with topic tags, Nuxt, locale-aware discovery, SEO and local play.
- [007-generic-quiz-web/plan.md](007-generic-quiz-web/plan.md): public discovery,
  reader permissions, SSR/player boundaries and local Compose implementation.
- [007-generic-quiz-web/tasks.md](007-generic-quiz-web/tasks.md): completed local
  implementation checklist.
- [007-generic-quiz-web/verification.md](007-generic-quiz-web/verification.md):
  automated checks, screenshots, isolated local runtime and capacity limits.
- [ADR 005](../architecture/adr/005-generic-quiz-web.md): accepted Nuxt service
  and public topic-tag discovery decision, implemented and verified locally.

## Archive

Current implemented and historical specs live under `archive/`, preserving
their original grouping:

- `archive/api/`: backend and API foundation specs.
- `archive/architecture/`: repository-wide architecture and organization specs.
- `archive/manager/`: Symfony manager specs.
- `archive/mvp/`: MVP behavior and early refactor specs.
- `archive/spa-dev/`: Dev theme player SPA behavior and frontend refactor specs.
- `archive/draft/`: exploratory ideas that are not committed implementation
  plans.

## Use

Create specs before implementing changes that affect behavior, contracts,
architecture, or public documentation. Keep each spec small enough to implement
and verify independently.
