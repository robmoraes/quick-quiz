# Feature: Token-protected Quiz Administration API

## Intent

QuickQuiz needs a machine-consumable administration boundary so a trusted local
Codex session can inspect the current catalog, choose the most appropriate theme
and topic for a study subject, and persist complete question packages.

The primary user is the owner operating Codex from an authorized workstation.
The desired outcome is a documented JSON API that reuses the Manager's quiz-pack
rules and storage backend.

## Scope

In scope:

- catalog discovery across themes, topics, locales, difficulties, and question
  counts;
- CRUD for themes and topics;
- CRUD for localized question sets;
- batch creation of up to 50 question sets in one request;
- local and S3-compatible persistence through the existing Manager storage;
- authentication with one administrative Bearer token;
- file-backed token configuration;
- OpenAPI documentation and examples suitable for an automated client.

Out of scope:

- player authentication or player account changes;
- automatic question generation by the server;
- automatic semantic ranking of themes or topics;
- token issuance, rotation endpoints, or multiple token identities;
- automatic Quiz API restart after publishing content;
- changes to the canonical quiz-pack file format.

## Behavior

1. All endpoints under `/api/admin/quiz` require
   `Authorization: Bearer <token>`.
2. The configured token comes from `MANAGER_ADMIN_API_TOKEN__FILE` when
   present, with `MANAGER_ADMIN_API_TOKEN` as fallback.
3. An absent or invalid token returns HTTP 401. When no valid administrative
   token of at least 32 characters is configured, the API returns HTTP 503 and
   remains fail-closed.
4. Authentication compares tokens in constant time. Tokens and request bodies
   must not appear in application logs.
5. `GET /api/admin/quiz/catalog` returns all themes and their topics, localized
   display metadata, publication state, and canonical counts by difficulty.
   An optional `locale` query selects display metadata and defaults to the
   fallback locale.
6. Theme endpoints list, read, create, replace, and delete theme metadata.
   Creating an existing theme returns HTTP 409. Reading, replacing, or deleting
   an unknown theme returns HTTP 404.
7. Topic endpoints list, read, create, replace, and delete central metadata plus
   optional localized metadata in one request.
8. Theme or topic deletion returns HTTP 409 while descendant content exists.
   The caller must set `recursive=true` to remove descendant content.
9. Question endpoints list summaries and read the full localized question set.
   Full responses are available only through this protected API.
10. A question set contains the same question ID, topic, and difficulty in every
    supported locale. Create and replace requests must contain exactly all
    supported locales.
11. Batch creation accepts one to 50 question sets for one topic. Blank IDs are
    allocated sequentially with the existing
    `<topic>-<difficulty>-<sequence>` convention.
12. Batch creation validates every item and path before writing any item.
    Multi-object writes compensate completed writes when a later storage
    operation fails.
13. Creating an existing question set returns HTTP 409. Replacing or deleting an
    unknown or incomplete question set returns HTTP 404.
14. Question payloads keep only `prompt`, `correctOptions`, and
    `wrongOptions`, and retain the option-count rules for difficulties 1-4.
15. Successful mutations return a `publication` object stating that the Quiz
    API must be restarted to reload question and catalog changes.
16. Mutations log only operation, resource identifiers, outcome, and request ID.
17. Error responses use
    `{"error":{"code":"machine_code","message":"Human-readable message"}}`.
18. JSON syntax errors and invalid query values return HTTP 400. Content
    validation errors return HTTP 422.

## Acceptance Examples

### Discover the best placement

Given themes and topics already exist,
when an authorized client requests the catalog with `locale=pt-BR`,
then it receives descriptions and question counts by difficulty sufficient for
Codex to choose an existing target or propose a new topic.

### Reject an unauthenticated request

Given the API token is configured,
when a client omits the Authorization header,
then the response is HTTP 401,
and no content storage operation runs.

### Create a localized package

Given theme `dev`, topic `git`, and locales `en-US,pt-BR`,
when an authorized client submits five valid difficulty-2 question sets,
then all ten locale files are written,
the response contains the five allocated IDs,
and it reports that the Quiz API requires reload.

### Reject incomplete locales

Given `en-US,pt-BR` are supported,
when a request contains only `pt-BR`,
then the response is HTTP 422,
and no question file is written.

### Guard recursive deletion

Given a topic contains question files,
when an authorized client deletes it without `recursive=true`,
then the response is HTTP 409 and nothing changes.
When the request is repeated with `recursive=true`,
then central metadata, localized metadata, and question files are removed.

## Data and Contracts

The API persists the existing structures:

- `themes.json`;
- `<theme>/index.json`;
- `<theme>/<locale>/index.json`;
- `<theme>/<locale>/<topic>/<difficulty>/<question-id>.json`.

The HTTP contract is defined in `docs/openapi-manager-admin.yaml`.

## Quality Attributes

Security:

- Bearer authentication is mandatory and fail-closed.
- The token is a secret and supports the repository's `NAME__FILE` convention.
- The API never accepts the token in query parameters.
- Existing S3 IAM and bucket restrictions remain authoritative.

Reliability:

- Localized sets are validated before writes.
- Multi-key mutations use compensating restoration on partial storage failure.
- S3 versioning remains the production recovery mechanism.

Performance:

- Catalog discovery may list the complete current MVP catalog.
- A batch is capped at 50 question sets and the existing 2 MiB request limit.

Observability:

- Mutation logs include identifiers and outcomes without question content,
  answer pools, or credentials.

## Rollout and Operations

Deploy the new Manager image, create a random token with at least 32 characters,
mount it at `/run/secrets/admin_api_token`, and set
`MANAGER_ADMIN_API_TOKEN__FILE` accordingly.

Content mutations are immediately durable in S3. The Quiz API still loads
content at startup, so question and catalog changes become playable after the
operator restarts only the Quiz API.

Rollback uses the previous Manager image. Existing quiz-pack files remain
compatible.
