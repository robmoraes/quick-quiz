# ADR-005: Add a Nuxt Web Service for Generic Quiz Discovery and Play

- **Status:** accepted, implemented and verified locally (2026-10-11);
  see [verification](../../specs/007-generic-quiz-web/verification.md)
- **Date:** 2026-10-11
- **Decision owners:** QuickQuiz maintainers
- **Scope:** new generic Web, public metadata discovery and local runtime
- **Extends:** [ADR 004](004-postgresql-quiz-runtime.md)
- **Supersedes:** the Manager-only topic-tag restriction of ADR 004 for the
  new public discovery contract, without changing gameplay or legacy JSON
- **Superseded by:** none

## Context

QuickQuiz already has specialized Vue/Quasar SPAs, a Go player API, Manager-owned
PostgreSQL content and Redis sessions. A new generic site should support
development and other subjects, discover themes/topics through tags and provide
a neutral full-screen quiz experience at the future `quickquiz.com.br` domain.
The existing SPAs remain independently usable.

Public theme and topic pages need directly accessible URLs and useful HTML on
the first response. Current theme tags are public, but topic tags exist only
in Manager. The API should expose bounded metadata discovery rather than make
the Web connect to authoring tables or load the entire question bank.

## Decision

Add a separate `apps/web` Nuxt application with Vue/TypeScript and Nuxt UI/Tailwind.
Use the Nitro Node-server runtime to render public pages; keep personalized
gameplay state client-only. Reuse the Go API for public metadata, questions,
grading, run snapshots, explanations and Redis-backed session rules.

Add compatible PostgreSQL-backed `/api/discovery` routes through the existing
Go pool and scoped reader views. Publish topic tags as navigation metadata,
preserving active-parent filtering and canonical theme/topic identities.
Manager remains the sole writer. Existing SPA routes, response contracts and
the legacy quiz JSON format are unchanged.

Use locale-prefixed public pages, a discovery-first home, simple combined
filters and explicit pagination. Initially do not cache discovery responses or
rendered pages across requests. Topic-tag edits do not advance gameplay
revisions; no-store avoids introducing a second invalidation protocol now.
Keep search/tag permutations and personal results out of indexing; curated
tag SEO pages and advertising are later increments.

Run through local Docker Compose with existing PostgreSQL and Redis. No new
database, search service, privileged Web credential, production deployment or
host-level dependency installation is part of this decision.

## Alternatives Considered

| Option | Assessment |
| --- | --- |
| Another client-only Quasar SPA | Familiar tooling and static hosting, but public metadata would depend on client rendering rather than the selected initial-HTML approach |
| Quasar SSR | Viable and familiar; Nuxt is selected for the separate page-oriented generic site and its rendering/routing ecosystem |
| Astro with Vue islands | Viable for a catalog with an embedded game; Nuxt keeps the multi-page discovery and interactive app in one Vue application model |
| Symfony/Twig plus a Vue player | Reuses PHP knowledge, but adds a separate presentation/template model for the new site's interactive journey |
| Nuxt with direct PostgreSQL access | Would duplicate content access, credentials and domain boundaries already owned by Go/Manager |

The Nuxt choice preserves Vue/TypeScript familiarity while providing server
rendering and a distinct visual foundation. It is a fit decision, not a claim
that the alternatives cannot support SEO or interactivity.

## Consequences

- Adds a Node runtime process, a separately versioned app and dependency updates.
  Its production memory and traffic budget must be measured before deployment;
  no fit claim is made for the current 2 GiB node yet.
- Web has no direct database pool or privileged credential. API discovery
  reuses the existing four-connection default pool and deadlines.
- No-store favors simple freshness initially at the cost of rendering/query
  work on each request. Any later caching decision must cover topic-tag edits
  independently from gameplay revision.
- Reuse public types and behavior through an SSR-safe client. Current SPA
  modules touch browser globals or Quasar UI services and are not imported into
  the Nitro server.
- Additive views and explicit reader grants support rollback to the old API
  without reversing schema or restoring content. New discovery in local/S3
  mode has an explicit unavailable response while existing services keep working.
- Locale-aware UI does not imply translated theme authoring. Current canonical
  themes are displayed faithfully; localized topic fields use existing fallback.

## Verification and References

Implementation and validation are tracked in
[spec 007](../../specs/007-generic-quiz-web/spec.md), its
[plan](../../specs/007-generic-quiz-web/plan.md) and
[tasks](../../specs/007-generic-quiz-web/tasks.md). No runtime evidence is claimed
by this architecture record.

- [Nuxt rendering modes](https://nuxt.com/docs/4.x/guide/concepts/rendering)
- [Nuxt deployment](https://nuxt.com/docs/4.x/getting-started/deployment)
- [Nuxt UI](https://nuxt.com/modules/ui)
- [Google JavaScript SEO guidance](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics)
- [Engineering playbook UX](https://github.com/robmoraes/engineering-playbook/tree/main/ux)
