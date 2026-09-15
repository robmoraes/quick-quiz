# Plan: Token-protected Quiz Administration API

## Architecture

Implement the API in `apps/manager`. The Manager already owns content
validation, localized-set invariants, and local/S3 writes. Adding writes to the
player Go API would duplicate those rules and expand its public responsibility.

Add an HTTP request subscriber for the `/api/admin/quiz` namespace. It
authenticates one configured Bearer token before controller execution. Add a
thin JSON controller and a testable administration service over
`QuizPackService`.

Extend `QuizPackService` with theme-scoped instances, exact lookup helpers,
atomic batch question creation, and guarded recursive removal.

## Contract

Add `docs/openapi-manager-admin.yaml` with these resources:

- `GET /api/admin/quiz/catalog`;
- `GET|POST /api/admin/quiz/themes`;
- `GET|PUT|DELETE /api/admin/quiz/themes/{theme}`;
- `GET|POST /api/admin/quiz/themes/{theme}/topics`;
- `GET|PUT|DELETE /api/admin/quiz/themes/{theme}/topics/{topic}`;
- `GET|POST /api/admin/quiz/themes/{theme}/topics/{topic}/questions`;
- `GET|PUT|DELETE /api/admin/quiz/themes/{theme}/topics/{topic}/questions/{difficulty}/{questionId}`.

POST on the questions collection accepts a batch. PUT replaces one complete
localized question set.

## Security

Configure `MANAGER_ADMIN_API_TOKEN` with an empty default and support
`MANAGER_ADMIN_API_TOKEN__FILE` through the existing generic secret loader.
The subscriber returns JSON before controllers for missing or invalid
credentials. Use `hash_equals` and reject configured tokens shorter than 32
characters.

Wire only the Manager FPM container to the secret. Nginx and SPAs receive no
token.

## Persistence and consistency

Continue using `ContentStorage`. Multi-key writes capture prior object content
and compensate on failure. Recursive deletes capture deleted content and
restore it if any delete or metadata update fails.

S3 versioning provides an additional operational rollback path. No schema or
database migration is required.

## Verification

- unit tests for token states and constant contract behavior;
- service tests for discovery, theme/topic CRUD, batch creation, locale parity,
  conflicts, recursive guards, and rollback;
- controller tests for JSON/status mappings;
- full Manager test suite;
- Symfony route inspection;
- Compose configuration validation;
- OpenAPI YAML parse validation.
