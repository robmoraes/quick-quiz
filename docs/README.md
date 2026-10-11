# QuickQuiz Dev Documentation

This directory is the public documentation entry point for the QuickQuiz Dev
monorepo.

## Project Map

- [Architecture overview](architecture/overview.md): system goals, boundaries,
  and runtime shape.
- [Service map](architecture/services.md): how the manager, API, SPA, and quiz
  pack data relate to each other.
- [API docs](api/README.md): backend responsibilities, local development, and
  OpenAPI entry point.
- [SPA docs](spa/README.md): player frontend responsibilities and validation.
- [Manager docs](manager/README.md): quiz pack editing and validation app.
- [Manager administration API](manager/admin-api.md): token-protected catalog and quiz CRUD for automation.
- [Data docs](data/README.md): quiz pack structure, locale rules, and content
  editing risks.
- [Specs](specs/README.md): product and implementation specifications.
- [Timeline](timeline/): short development log entries.
- [Runbooks](runbooks/): operational recovery and manual release procedures.
- [PostgreSQL runtime deployment and recovery](runbooks/postgresql-quiz-runtime-release.md):
  PostgreSQL runtime versions, validation, backups, and recovery.
- [Latest SPA Dev release](runbooks/spa-dev-theme-caption-release.md):
  version 0.9.1, layout correction, deployment checks, and SPA-only rollback.

## Stable Contracts

- [OpenAPI contract](openapi.yaml)
- [Ads API OpenAPI contract](openapi-ads.yaml)
- [Manager administration OpenAPI contract](openapi-manager-admin.yaml)
- [Quiz pack contract](quiz-pack-contract.md)

## Documentation Policy

Keep the root README short and move details here. Service-specific operational
notes can stay near their code, but cross-service contracts, architecture, and
public onboarding documentation should live under `docs/`.
