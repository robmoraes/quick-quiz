# Feature: Generic Quiz Web and Tagged Discovery

Status: Accepted, implemented and verified locally (2026-10-11).
See [verification.md](verification.md) for evidence and limits. The original
implementation was local only. An authorized follow-up on 2026-10-22 prepares
independent Web image publication; production deployment remains separate.

## Intent

Provide a subject-neutral QuickQuiz website where visitors can discover themes
and topics, find related interests through tags and play quizzes. The future
public address is `https://quickquiz.com.br`; development and validation run
locally first.

The current SPA Dev and SPA DSLab remain separate products. The new Web should
serve subjects such as development, literature and agronomy through the same
content model and gameplay API.

## Scope

In scope for the first version:

- A new Web service with a neutral, responsive visual identity that uses the
  available screen area while retaining readable text widths.
- Theme and topic discovery, search and visible tag filters.
- Theme tags for broad discovery and topic tags for interests that can span
  multiple themes.
- Public theme/topic pages suitable for search-engine indexing.
- The quiz journey using the existing run, answer, result and session rules.
- Compatible public API extensions for bounded discovery of published metadata.
- Local Docker Compose integration with the existing API, PostgreSQL and Redis.

Out of scope:

- Replacing or redesigning either existing SPA.
- Cross-theme quiz runs, new gameplay rules, accounts, rankings or personalized
  recommendation algorithms.
- A tag hierarchy, automatic tagging or a new search service.
- Advertising in this initial Web version; it is deferred while the neutral
  discovery and gameplay experience is established.
- Production deployment, DNS changes or remote infrastructure changes.

## Foundation Before This Feature

- `GET /api/themes` exposes active themes and theme tags, with an optional
  single-tag filter. It can also return an active theme with no playable topics.
- `GET /api/catalog` exposes localized topics, difficulties and question counts
  for a selected theme.
- Topic tags are editable in Manager Web and its administrative API, but are
  deliberately absent from the public API and PostgreSQL reader views.
- Topic-tags-only edits currently do not advance the gameplay publication
  revision. Theme tags and topic tags are separate associations; topic tags do
  not inherit theme tags automatically.
- PostgreSQL is the content authority; Redis retains runs and session state.

This feature extends the earlier Manager-only topic-tag boundary for public
discovery. It does not require changing the legacy quiz JSON format.

## Behavior

### R1. Discover and navigate

1. Visitors can browse published themes, open a theme, inspect its topics and
   choose a playable topic and difficulty.
2. Discovery shows enough context to distinguish a theme from a topic and
   identifies each topic's parent theme.
3. Search and filters apply to public metadata, without downloading question
   prompts, answers or the complete question bank.
4. Selected filters are visible and clearable. Returning from a detail page
   preserves meaningful search and filter context.
5. Loading, no content, no matching results and dependency failure are distinct
   states. An empty or unavailable topic is not offered as a playable quiz.

### R2. Topic tags in the first version

1. Public discovery exposes the tags of active topics under active themes.
   Inactive topics and topics of inactive themes are excluded, including from
   matching results, counts and related-content lists.
2. A visitor can select a topic tag and discover matching topics across themes.
   Results retain canonical theme/topic identities and localized display text.
3. A topic detail page offers navigation through its tags to other matching
   topics. A topic without tags remains discoverable through its theme.
4. Tags are navigation metadata, not permissions, difficulty levels or implicit
   instructions to combine themes in a run.
5. Use the existing canonical tag identities and normalization rules. The Web
   provides friendly labels through its pt-BR/en-US translation registry, with
   the original slug as fallback for an unmapped tag. A new tag remains usable
   without rebuilding the Web image. Do not infer a hierarchy or rewrite stored
   slugs. Topic responses always use an array, including `tags: []`.
6. A new uncached public discovery request observes a committed topic-tag edit
   without an API restart, even if the gameplay revision did not change.
   Initially, discovery responses and rendered pages use `Cache-Control:
   no-store`; no process-local discovery cache, shared HTML cache or build-time
   catalog snapshot is used. An already open page refreshes on reload, filter
   change or explicit retry; no live-push update is promised.
7. Filters for theme, theme tag, topic tag and difficulty are single-valued and
   combine with AND. Search matches literal case-insensitive substrings of
   canonical theme text or resolved localized topic text, according to the
   result type. Initial matching is accent-sensitive; no semantic ranking or
   automatic synonym expansion is implied.
8. Lists use explicit pagination: 24 entries by default, at most 100 per page,
   pages 1 through 1000, and deterministic ordering. Tags expose counts of
   matching resources under the current filters, not total question answers.
   See the [discovery contract](contracts/discovery.openapi.yaml) for exact
   validation, ordering and aggregate semantics.

### R3. Public pages and SEO

1. Public routes have an explicit BCP 47 locale prefix: `/pt-BR/` and `/en-US/`.
   Theme and topic pages use `/<locale>/themes/<theme>` and
   `/<locale>/themes/<theme>/topics/<topic>`, with stable canonical IDs and real
   HTML links. The root redirects to the configured default locale, initially
   pt-BR. Unsupported route locales return 404 rather than silently switching.
2. Public detail content and navigation are present in the initial HTML, with
   descriptive titles, descriptions, headings and canonical URLs.
3. Eligible home, theme and topic pages are indexable and appear in the sitemap
   when they contain playable content. Search/filter/tag permutations and empty
   detail pages use `noindex,follow` and are excluded. Curated indexable tag
   landing pages are deferred. Canonical and alternate-locale links refer to
   actual supported URLs, never query-driven duplicates.
4. Session state and personal results are excluded from indexing and shared
   HTML caches. Missing content returns a meaningful HTTP status; dependency
   failure is not presented as a successful empty page or a missing resource.
5. Catalog publication and newly created content must not require rebuilding
   the Web image to appear under its documented freshness policy.
6. Localize the interface, friendly tag labels and available topic translations.
   Themes currently have only canonical names/descriptions; display those
   faithfully rather than inventing translations. Topic fields use the existing
   requested-locale, fallback-locale and canonical-field fallback order.
7. Local operation defaults to nonindexable responses. The public site origin
   and indexing switch are runtime configuration for a future production
   rollout, not hardcoded build values.

### R4. Play and recover

1. The game provides a focused, responsive use of the screen, showing theme,
   topic, difficulty and progress where relevant to the current step.
2. Reuse existing question selection, grading, numeric difficulty, session
   exhaustion, snapshot and result contracts. Discovery does not start a run.
3. Keep active session/run identity bound to its originating theme. Browsing
   another theme cannot silently move an active run or change its grading.
4. Explain interruption, expired sessions and request failures, offering only
   recovery actions actually supported by the API.
   Reconcile an uncertain answer using authoritative run state before allowing
   another answer. Preserve automatic session closure on exhaustion, including
   the current final-run result transition, and hardcore confirmation/loss rules.
   Do not promise restoration of a full historical session aggregate that the
   existing API does not provide.
   If a run-creation response is lost before the client learns its run ID,
   show an uncertain-start state rather than retrying creation automatically.
   Retain the session context and offer confirmed reset/new-session recovery;
   there is no player lookup API that recovers this unknown run ID.
5. Main journeys work with keyboard navigation, visible focus and accessible
   labels. Responsive layouts handle long names and localized text.

### R5. Ownership, compatibility and operation

1. Manager remains the content writer. The Web uses the public Go API without
   administrative tokens, PostgreSQL writer credentials or direct database
   access. The API retains scoped, read-only content access.
2. Extend public discovery additively and preserve existing SPA requests,
   response contracts and audience behavior.
3. Discovery operations are bounded and paginated where needed. Avoid one
   catalog request per theme merely to construct the home page or tag counts.
   Detail pages may show active empty resources with counts and an explanation,
   but cannot start an empty quiz. Inactive and genuinely missing details return
   404; infrastructure failure returns 503.
4. Keep the Web stateless for player state; use the existing API/Redis boundary.
   Environment-owned URLs and deployment settings are runtime configurable.
   Any secret follows the repository's `ENV_NAME__FILE` precedence convention.
5. Run the new service through local Compose. The future production rollout
   requires a separate resource assessment and deployment decision.
6. New `/api/discovery` endpoints use the PostgreSQL provider and the existing
   API connection pool. Legacy local/S3 providers return explicit
   `discovery_unavailable` from these endpoints, preserving their existing
   player endpoints and readiness behavior. Public discovery needs no session,
   theme header or administrative token.

## UX Journey and Screen Brief

User: an anonymous learner arriving through the home page, a theme/topic link
or a search result. The desired outcome is to find a suitable subject, complete
a quiz and understand the confirmed result and next action.

```text
Discover -> theme/topic detail -> difficulty -> run
-> run result/next run -> session result -> discover again
```

The home page prioritizes discovery with a prominent search field, theme-tag
areas and a theme grid. Explore uses Themes and Topics tabs, each showing one
paginated result type. The initial tab is Themes; topic-tag links open Topics.
The URL carries `kind=themes|topics`, applicable filters and page. Changing a
tab or filter resets page to 1. An exact theme filter applies to Topics and
selecting it switches to that tab; switching to Themes removes that filter
while retaining the other applicable filters. Each topic card identifies its
parent theme.
Detail pages provide context, question counts, difficulty choices and tag links.
Discovery alone does not initialize a player run.

Use a neutral light palette, one accent color, a responsive grid and a readable
question column within a full-screen game layout. Nuxt UI provides the component
foundation; polished presentation must preserve semantic structure and focus.

| State | Visible behavior and next action |
| --- | --- |
| Loading | Stable placeholders and an accessible loading status |
| Empty content | Explain that this theme/topic has no playable questions |
| No matches | Show active search/filters and offer Clear filters |
| Retrieval failed | Explain the failure and offer Retry with filters retained |
| Run/answer pending | Show pending state and prevent accidental duplicate input |
| Outcome uncertain | Reconcile run state; preserve context rather than guess |
| Start outcome uncertain, run ID unknown | Explain uncertainty; offer confirmed reset/new-session recovery without automatic creation retry |
| Session expired | Explain expiry and offer the supported new-session action |
| Completed | Show confirmed results and a relevant next action |

Preserve filters and list position on back navigation. Use links for navigation
and buttons for actions, visible keyboard focus, labels for controls and polite
status announcements. Target applicable WCAG 2.2 AA behavior; check 320px reflow,
200% text resizing and realistic long/localized text. No conformance or usability
research result is claimed before validation.

## Acceptance Examples

- Given active topics under Kubernetes and Linux with the same interest tag,
  selecting that tag shows both topics with their respective parent themes.
- Given identically named topic IDs in different themes, navigation and quiz
  requests retain the selected theme/topic pair.
- Given a matching inactive topic or inactive parent theme, public discovery
  does not expose it or include it in matching counts.
- Given a topic without tags, it remains accessible through its active theme;
  selecting an unknown tag produces an explicit no-matches state.
- Given a committed topic-tags-only edit and an unchanged gameplay revision,
  the next public discovery/page request sees the edit without a restart or
  rebuild; an already open page can reload to see the change.
- Given a direct visit to a public topic URL, the initial HTML contains its
  title, descriptive metadata and links; an active personal run is not embedded
  in a shared cached page.
- Given the existing Dev and DSLab clients, their current discovery/gameplay
  behavior continues unchanged after the API extension.
- Given `themeTag=dev` and `topicTag=security`, results satisfy both filters;
  removing one filter preserves the other. Pagination keeps the selected query.
- Given a local/S3 API provider, new discovery responds with its documented
  unavailable error; existing catalogs, gameplay and readiness still work.
- Given a dependency outage, the Web returns a service failure with recovery,
  not a successful empty catalog; discovery has no user session or question bank.
- Given an exact theme filter in Explore, the Topics tab lists that theme's
  matches. Switching to Themes removes the exact-theme filter and resets page;
  unsupported parameters are never forwarded to an API operation.
- Given a lost run-creation response and no known run ID, the Web does not
  create another run automatically or claim to have recovered the first one.
  A confirmed reset/new-session action is available before another start.

These examples guide automated verification; no separate manual QA artifacts
are required. Delivered implementation evidence is recorded in
[verification.md](verification.md).

## Accepted Technical Direction

Use a separate `apps/web/` service using Nuxt,
Vue/TypeScript and Nuxt UI/Tailwind, with Node/Nitro rendering public pages and
the existing Go API owning discovery and gameplay. PostgreSQL and Redis are
reused. See [ADR 005](../../architecture/adr/005-generic-quiz-web.md) for the
runtime and service-boundary decision.

The [technical plan](plan.md), [tasks](tasks.md) and
[discovery contract](contracts/discovery.openapi.yaml) define the first
implementation slices. Verification is recorded separately in
[verification.md](verification.md).

## Decision State

The recommendations are accepted. The concrete filter, route and freshness
defaults above close the planning ambiguities for the first local version.
Advertising and curated tag SEO pages are deferred to later increments.
Production capacity and rollout remain future operational work, not a blocker
to implementing and exercising this local slice.

## References

- [Repository spec-driven standard](../standard.md).
- [Manager topic tags](../004-manager-topic-tags/spec.md).
- [Tagged theme discovery](../005-tagged-theme-selection/spec.md).
- [PostgreSQL quiz runtime](../006-postgresql-quiz-runtime/spec.md).
- [Engineering playbook UX](https://github.com/robmoraes/engineering-playbook/tree/main/ux),
  including journeys, screen structure, accessibility and feedback/recovery.
