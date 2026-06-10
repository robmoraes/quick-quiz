# Architecture: apps monorepo and theme SPA boundaries

## Intent

Problem: the repository currently has top-level `backend/`, `manager/`, and
`frontend/` applications. The backend API already supports multiple themes, and
the manager is expected to administer content across one or more themes, but
the current `frontend/` is specifically the player SPA for the Dev theme. A
generic `frontend/` path does not leave a clear, healthy place for a future
theme-specific SPA such as Geography.

Users or stakeholders:

- project maintainer preparing the repository for publication;
- API and manager maintainers;
- maintainers of the current Dev player SPA;
- future maintainers of additional theme SPAs.

Desired outcome: keep the monorepo, but make deployable application boundaries
explicit by moving applications under `apps/` and naming the current player
frontend as the Dev theme SPA. This should preserve the convenience of changing
API, manager, and player SPA code together while creating a clear path for
future theme SPAs.

Non-goals:

- split the project into multiple repositories now;
- create a Geography SPA now;
- extract shared frontend packages now;
- change API behavior or theme contracts;
- redesign deploy topology beyond path updates;
- rewrite existing feature specs.

## Scope

In scope:

- define the target repository layout under `apps/`;
- make the current backend API application path explicit as `apps/api`;
- make the current manager application path explicit as `apps/manager`;
- make the current Dev player SPA path explicit as `apps/spa-dev`;
- update READMEs, command examples, documentation, and deploy assets to use the
  new paths;
- rename the SPA spec group path from `docs/specs/spa` to
  `docs/specs/spa-dev`.

Out of scope:

- backend API versioning implementation;
- OpenAPI contract changes;
- manager feature changes;
- SPA feature changes;
- shared package extraction;
- CI/CD provider-specific redesign;
- changing existing spec content beyond the SPA spec folder path.

Assumptions:

- the monorepo remains the source of truth for the MVP phase;
- the backend API and manager have scope greater than or equal to a theme;
- each player SPA normally targets one theme and sends that theme on API
  requests;
- future theme SPAs may share contracts with the Dev SPA but should not depend
  on Dev-specific UI, labels, help files, sounds, or visual conventions;
- path changes are large enough to warrant a dedicated architecture step before
  adding another theme SPA.

Dependencies:

- existing backend theme support;
- existing manager theme-management direction;
- existing Dev SPA;
- existing documentation and deploy files that reference `backend/`,
  `manager/`, or `frontend/`;
- existing spec history under `docs/specs/spa`.

## Behavior

1. The repository must remain a monorepo after this architecture step.
2. Deployable applications must live under `apps/`.
3. The current backend API must move from `backend/` to `apps/api/`.
4. The current manager must move from `manager/` to `apps/manager/`.
5. The current player frontend must move from `frontend/` to `apps/spa-dev/`.
6. `apps/spa-dev/` must be documented as the player SPA for the `dev` theme,
   not as a generic frontend for every theme.
7. Future theme SPAs should have sibling paths such as `apps/spa-geography/`.
8. Root-level documentation must explain that API and manager are multi-theme
   or theme-management applications, while player SPAs are theme-specific.
9. README command examples must be updated from old root paths to the new app
   paths.
10. Deploy documentation, Docker files, systemd files, environment examples,
    and local development docs must reference the new app paths.
11. Existing `docs/specs/spa/` files must move to `docs/specs/spa-dev/`.
12. Existing SPA spec content must not be rewritten as part of this move unless
    a path reference would become actively misleading.
13. The architecture step must not introduce a shared frontend package until a
    second SPA creates concrete duplication pressure.
14. The architecture step must not change API compatibility expectations.
15. Any future breaking API contract must still use an explicit versioning or
    migration strategy independent of the monorepo layout.

## Acceptance Examples

### Scenario: maintainer finds the Dev SPA

Given the repository has been reorganized

When a maintainer looks for the player app for the Dev theme

Then they find it under `apps/spa-dev/`

And the README identifies it as theme-specific.

### Scenario: maintainer plans a Geography SPA

Given a new Geography player SPA is planned

When the maintainer inspects the repository layout

Then `apps/spa-geography/` is the obvious sibling location

And no API or manager move is required to add that application.

### Scenario: command examples remain usable

Given a developer follows repository documentation after the move

When they run backend, manager, or SPA commands from the documented paths

Then the commands reference `apps/api`, `apps/manager`, and `apps/spa-dev`
instead of stale root-level paths.

### Scenario: specs preserve history

Given the architecture move is implemented

When a reviewer checks existing SPA specs

Then they are under `docs/specs/spa-dev/`

And their content remains materially unchanged except for necessary path
references.

## Data and Contracts

Inputs:

- current repository layout;
- current READMEs and docs;
- current deploy scripts and configuration files;
- existing app build and development commands;
- existing spec files under `docs/specs/spa`.

Outputs:

- repository layout with deployable apps under `apps/`;
- documentation and command examples that match the new paths;
- SPA specs grouped under `docs/specs/spa-dev`;
- no runtime behavior change.

API, schema, event, or CLI changes:

- no API, schema, or event contract changes;
- command working directories change because applications move under `apps/`.

Persistence changes:

- none.

Machine-readable contract:

- not required.

## Quality Attributes

Security:

- path reorganization must not expose secrets, local question banks, or ignored
  content that is currently excluded from Git.

Privacy:

- no new personal data or tracking.

Accessibility:

- not applicable; no UI behavior should change.

Performance:

- build and runtime performance should not change.

Reliability:

- local commands, build outputs, deploy assets, and environment examples must
  keep working from their new paths;
- relative paths in Docker, systemd, and documentation must not silently point
  at removed root-level directories.

Observability:

- existing logs, metrics, traces, and session events must remain unchanged.

## Rollout and Operations

Migration:

- move application directories into `apps/`;
- update path references in READMEs, docs, deploy assets, and development
  commands;
- move `docs/specs/spa` to `docs/specs/spa-dev` without rewriting feature spec
  history;
- verify Git preserves file history where practical.

Feature flag or configuration:

- none.

Rollback:

- revert the path-move commit or move applications back to the previous
  root-level paths and restore documentation references.

Monitoring:

- local build and test commands;
- deployment dry-run or configuration review where practical.

## Verification

Planned checks:

- `cd apps/api && go test ./...`;
- `cd apps/spa-dev && npm run lint`;
- `cd apps/spa-dev && npm run build`;
- manager dependency or smoke command from `apps/manager`, if available;
- documentation grep for stale `cd backend`, `cd frontend`, and `cd manager`
  command examples;
- review deploy files for stale root-level app paths.

Evidence to record:

- command output;
- summary of moved paths;
- list of documentation and deploy references updated;
- any intentionally deferred stale reference with rationale.
