# QuickQuiz Dev MVP: Run, sounds, and message theming

## Objective

Another round of organizing the JSON data files used as the program's source of
truth.

Centralize the metadata of a quiz package (language);

Keep language-sensitive data in the respective language folders;

### Centralizar

Create the file: `backend/.local/index.json`

```json
{
  "languages": [
    {
      "key": "php",
      "name": "General PHP",
      "description": "Version-agnostic PHP language fundamentals",
      "weight": 100,
      "created_at": "2026-01-01T00:00:00-03:00",
      "active": true
    },
    {
      "key": "go",
      "name": "General Go",
      "description": "Version-agnostic Go language fundamentals",
      "weight": 200,
      "created_at": "2026-01-01T00:00:00-03:00",
      "active": true
    },
    {
      "key": "xgh",
      "name": "The Legendary eXtreme Go Horse",
      "description": "Some call it satire, some call it an anti-pattern, and some suspect that half of the internet is still running because of it. 🐎💨",
      "weight": 300,
      "created_at": "2026-06-06T04:12:00-03:00",
      "active": true
    }
  ]
}
```

The file is the same as the original, keeping name and description as fallback
when they do not exist in the language folders;

Implementation notes:

- `backend/.local/index.json` becomes the canonical publication source for quiz
  packages.
- The `active`, `weight`, and `created_at` fields must always come from the
  centralized file.
- The `name` and `description` fields from the centralized file work as textual
  fallback when the locale does not have an override.
- The API must continue exposing `createdAt` in camelCase in the HTTP contract,
  even if the JSON source uses `created_at`.

### Language-Sensitive Data

Change the index.json files for each language to contain only language-sensitive
information: `backend/.local/pt-BR/index.json` becomes:

```json
{
  "languages": [
    {
      "key": "php",
      "name": "PHP Geral",
      "description": "Quiz de PHP agnóstico a versão"
    },
    {
      "key": "go",
      "name": "Golang Geral",
      "description": "Quiz atemporal sobre Golang"
    },
    {
      "key": "xgh",
      "name": "O Lendario eXtreme Go Horse",
      "description": "Tem quem chame de sátira, tem quem chame de anti-pattern, e tem quem suspeite que metade da internet ainda esteja funcionando graças a ele. 🐎💨"
    }
  ]
}
```

Implementation notes:

- The `backend/.local/<locale>/index.json` files must be treated as localized
  overrides, not as the publication source.
- Only `name` and `description` must be read from these localized files.
- If the localized file does not exist for a supported locale, the API must use
  `name` and `description` from the centralized file.
- If the localized file exists but does not have an entry for an active
  language, the API must use `name` and `description` from the centralized file
  for that specific entry.
- If the localized file has a `key` that does not exist in the centralized file,
  the backend must fail while loading the question source. This avoids orphaned
  metadata and divergence between locales.

## API

The API must be prepared to support this new structure;

The API must use the centralized `backend/.local/index.json` file for metadata;

When the `backend/.local/*/index.json` file exists, the API must prioritize the
name and description fields from that file;

The API response contract must not change. The change is internal to the data
source loader:

- `id` still comes from `key`;
- `label` is still filled from `name`;
- `description` still comes from `description`;
- `weight` still comes from `weight`;
- `createdAt` still comes from `created_at`;
- `difficulties` is still calculated from existing questions.

Expected validations:

- The `backend/.local/index.json` file is required.
- Each active language in the central file must have a non-empty `key`.
- Only languages with `active: true` in the central file must be loaded.
- Languages with `active: false` in the central file must be ignored even if
  they exist in localized files or have question folders.
- The existing translated-package validation must continue ensuring that each
  loaded locale replicates the same
  `<language>/<difficulty>/<question-id>.json` paths from the fallback locale for
  active languages.
- Locale content fallback continues to be applied when a supported locale has no
  questions available for the requested combination.
