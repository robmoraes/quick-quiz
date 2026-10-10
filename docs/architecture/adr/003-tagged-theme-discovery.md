# ADR-003: Discover Themes Through Published Tags

- **Status:** accepted
- **Date:** 2026-10-10
- **Decision owners:** QuickQuiz maintainers
- **Scope:** Manager, Quiz API, and SPA Dev
- **Supersedes:** none
- **Superseded by:** none

## Context

SPA Dev currently uses the fixed `dev` theme. Editors want to add themes such
as Kubernetes to that site without frontend changes for each addition, while
future sites may serve unrelated audiences such as literature or agronomy.
SPA DSLab must remain unchanged in this increment.

Manager owns PostgreSQL authoring and publishes the snapshot loaded by the
Quiz API. Existing topic tags are authoring metadata and intentionally absent
from that projection. Theme tags would serve a different purpose: selecting
which public themes belong in a site's navigation.

## Decision Drivers

- Keep audience selection controlled by deployment configuration.
- Preserve existing API clients and SPA DSLab.
- Keep theme discovery and quiz content consistent within one loaded snapshot.
- Add no database access to the public API and no infrastructure dependency.

## Considered Options

### Option A: Publish Theme Tags and Configure the SPA at Runtime

Manager stores theme associations and publishes them in `themes.json`. The
Quiz API lists active themes with an optional single-tag filter. SPA Dev uses
one configured tag and lets players choose among those themes.

Advantages: reuses existing ownership and publication; changing eligible
content does not require a SPA build; old clients ignore the additive field.
Disadvantages: publication and API reload still control freshness; frontend
session state must handle more than one possible theme.

### Option B: Configure a Fixed List of Theme IDs in the SPA

Advantages: little new public API behavior; explicit deployment ownership.
Disadvantages: every theme addition needs a configuration change, and the
content owner cannot manage site membership through Manager tags.

### Option C: Discover Themes Directly From PostgreSQL in the Quiz API

Advantages: tag edits can appear immediately.
Disadvantages: discovery can disagree with loaded questions; adds database
credentials, connectivity, and a second runtime source of content.

## Decision

Choose Option A. Theme tags become published classification metadata; topic
tags remain PostgreSQL-only as defined in the existing topic-tag feature.
This extends ADR 002 without changing its authoring/publication boundary.

The public endpoint lists all active themes when no tag is supplied. SPA Dev
always supplies its environment-configured tag, defaulting to `dev`, and
offers no URL override. This is a navigation policy, not access control for
otherwise public themes. Quizzes and sessions remain scoped to one theme.

## Consequences

### Positive

- Editors can add eligible themes through Manager without changing the SPA.
- Discovery stays consistent with the same startup snapshot as quiz content.
- Existing fixed-theme clients remain compatible.

### Negative

- Theme tag edits require publication and API reload.
- The SPA needs explicit theme context and stale-response protection.
- A separate Dev startup script duplicates a small amount of configuration
  code in order to leave the DSLab image and release untouched.

### Operational

- Apply an additive association migration and explicitly tag intended themes.
- Deploy discovery before the new Dev UI; roll the UI back before removing it.
- Older Manager publishers can omit the new field; pause publication during an
  incompatible Manager rollback. Keep database associations available.
- No new secret, datastore, or running service is introduced.

## Validation and Review

Verify a tagged theme appears after publication/reload, produces a correct
selected-theme run, and does not alter DSLab's existing requests. Automate
empty/invalid scope, old-client compatibility, and theme/session isolation.

Revisit this decision if publication freshness becomes unacceptable, audience
rules require authorization, or a generic SPA needs multi-tag filtering.
Rollback and staged migration are described in the feature plan.

## References

- [Feature specification](../../specs/005-tagged-theme-selection/spec.md)
- [Implementation plan](../../specs/005-tagged-theme-selection/plan.md)
- [Manager-owned administration](001-manager-owned-quiz-admin-api.md)
- [PostgreSQL authoring and publication](002-postgresql-quiz-authoring-s3-publication.md)
