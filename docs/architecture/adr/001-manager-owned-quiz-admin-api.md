# ADR 001: Keep quiz administration in the Manager boundary

Status: Accepted

## Context

QuickQuiz needs a protected JSON API for trusted automation to inspect and
modify themes, topics, and localized question packages. The Go Quiz API already
serves players, while the Symfony Manager already implements content
validation, localization rules, and local/S3 persistence.

## Decision

Expose the administration API from the Manager under `/api/admin/quiz`.
Protect the whole namespace with a dedicated Bearer token. Reuse
`QuizPackService` and `ContentStorage`; keep the Go Quiz API read-only with
respect to quiz-pack content.

## Consequences

Content rules remain implemented in one service. Deploying or rotating the
administrative token affects only Manager FPM. The public Quiz API keeps its
smaller attack surface.

The Manager becomes an HTTP integration boundary for automation and must keep a
versioned OpenAPI contract. Content changes still require a Quiz API restart
because that service intentionally loads its dataset at startup.
