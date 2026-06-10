# Draft: Topic taxonomy

## Intent

QuickQuiz topics can represent programming languages, frameworks,
methodologies, books, practices, tools, and other development subjects. The
current topic refactor intentionally keeps topics flat, but a future taxonomy can
organize large catalogs without changing the basic quiz selection key.

## Problem

A flat topic list is enough for the current backend contract. As the catalog
grows, players and content maintainers may need grouping, filtering,
recommendations, and clearer relationships between topics.

Examples:

- `php` is a programming language.
- `laravel` is a framework in the PHP ecosystem.
- `scrum` is a methodology.
- `clean-code` is a book or literature topic.
- `xgh` is a methodology, satire, or cultural topic.

## Possible Shape

Topic taxonomy can be represented as optional metadata:

```json
{
  "topics": [
    {
      "id": "laravel",
      "label": "Laravel",
      "taxonomy": {
        "kind": "framework",
        "ecosystem": "php",
        "tags": ["web", "backend"]
      }
    }
  ]
}
```

Possible fields:

- `kind`: broad topic type, such as `programming_language`, `framework`,
  `methodology`, `book`, `tool`, or `practice`.
- `ecosystem`: related ecosystem or parent technology, such as `php`,
  `javascript`, or `go`.
- `tags`: flexible labels for filtering and recommendations.

## Use Cases

- Group topics in the UI without changing topic IDs.
- Filter the catalog by frameworks, books, methodologies, or languages.
- Recommend related topics after a run.
- Help content managers identify coverage gaps by kind or ecosystem.
- Support AI recommendation context with richer metadata.

## Non-goals

- Do not implement taxonomy in the current topic backend contract.
- Do not require every topic to have taxonomy metadata.
- Do not use taxonomy fields as machine-readable IDs for quiz selection.
- Do not replace the stable topic ID.

## Open Questions

- Should taxonomy live inside each topic entry or in a separate catalog
  structure?
- Should `kind` be a closed enum or a controlled string list?
- Should `ecosystem` support multiple values?
- Should books and authors have separate metadata later?
