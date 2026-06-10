# QuickQuiz Dev Quiz Pack Contract

This document defines the canonical quiz pack contract for QuickQuiz Dev.

Quiz packs are JSON files. JSON is the source of truth for quiz content by
default and for an indefinite period. A database migration for quiz content is
not planned and should be considered only if a concrete future limitation of
the JSON format justifies it.

The manager is a tool for editing and validating quiz pack JSON files. The Go
API is a consumer of valid quiz packs.

## Roles

Producer:

- creates or edits quiz pack files;
- may be a contributor, script, import tool, or the manager.

Manager:

- reads and writes quiz pack JSON files;
- validates paths, metadata, locales, and question bodies before saving;
- must preserve the canonical file contract.

Consumer:

- reads quiz pack JSON files;
- derives machine metadata from paths and catalog files;
- must reject invalid packs instead of guessing missing metadata.

## Content Root

The content root is configured by `QUESTION_SOURCE`.

For local development, the default root is:

```text
apps/api/.local
```

The expected structure is:

```text
apps/api/.local/
  themes.json
  <theme>/
    index.json
    <locale>/
      index.json
      <topic>/
        <difficulty>/
          <question-id>.json
```

Example:

```text
apps/api/.local/
  themes.json
  dev/
    index.json
    en-US/
      index.json
      php/
        1/
          php-1-001.json
    pt-BR/
      index.json
      php/
        1/
          php-1-001.json
```

## Theme Rules

Themes identify product/content domains such as `dev`, `math`, or `history`.
The API is theme-agnostic, while each frontend distribution chooses exactly one
theme through the required `X-QuickQuiz-Theme` header.

Global theme metadata is required:

```text
apps/api/.local/themes.json
```

Schema:

```json
{
  "themes": [
    {
      "id": "dev",
      "name": "Development",
      "description": "Programming and software engineering quizzes.",
      "weight": 100,
      "createdAt": "2026-06-07T00:00:00-03:00",
      "active": true
    }
  ]
}
```

Only `active: true` themes are loaded by the Go API. Unknown, missing, or
inactive themes are rejected at the API boundary before catalog, session, or run
logic is executed.

## Locale Rules

Locales must use BCP 47 tags, such as `en-US` and `pt-BR`.

Configuration:

- `FALLBACK_LOCALE`: canonical content locale. Default: `en-US`.
- `SUPPORTED_LOCALES`: comma-separated supported locales. Default:
  `en-US,pt-BR`.

The fallback locale defines the canonical set of active topic packages and
question packages. Every supported non-fallback locale must replicate the same
question package paths for active topics.

Locales must not add exclusive question packages. Locales must not remove
question packages that exist in the fallback locale.

Machine-readable values must not be localized:

- topic keys;
- difficulty IDs;
- question IDs;
- locale tags;
- API enum values;
- error codes;
- logs, metrics, and traces.

## Central Catalog

The central catalog is required:

```text
apps/api/.local/<theme>/index.json
```

It is the publication source for quiz topic packages.

Schema:

```json
{
  "topics": [
    {
      "key": "php",
      "name": "General PHP",
      "description": "Version-agnostic PHP topic fundamentals",
      "weight": 100,
      "created_at": "2026-01-01T00:00:00-03:00",
      "active": true
    }
  ]
}
```

Fields:

| field | required | meaning |
| --- | --- | --- |
| `key` | yes | Stable topic key. Must match the topic folder name. |
| `name` | no | Fallback display name when locale override is missing. |
| `description` | no | Fallback description when locale override is missing. |
| `weight` | no | Ordering weight used by consumers. |
| `created_at` | no | Package creation or publication timestamp. |
| `active` | yes | Publishes or hides the topic package. |

Rules:

- `key` must be non-empty and unique.
- `active: true` publishes the topic package.
- `active: false` keeps files present but unpublished.
- Only active topic packages are loaded by the Go API.
- `active`, `weight`, and `created_at` must come from the central catalog.
- Inactive topic folders may exist, but consumers must ignore them.

## Localized Catalog Overrides

Each locale may provide localized display metadata:

```text
apps/api/.local/<theme>/<locale>/index.json
```

Schema:

```json
{
  "topics": [
    {
      "key": "php",
      "name": "PHP Geral",
      "description": "Fundamentos de PHP independentes de versao"
    }
  ]
}
```

Rules:

- Localized catalog files are overrides, not publication sources.
- Localized entries may override only `name` and `description`.
- `key` must exist in the central catalog.
- Duplicate localized keys are invalid.
- Unknown localized keys are invalid.
- If a localized file is missing, consumers use central `name` and
  `description`.
- If a localized entry is missing for an active topic, consumers use central
  `name` and `description` for that topic.
- Managers should not write `active`, `weight`, or `created_at` to localized
  catalog files.

## Question Path Contract

Question metadata is derived from the file path:

```text
apps/api/.local/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json
```

Example:

```text
apps/api/.local/dev/en-US/php/1/php-1-001.json
```

Derived metadata:

| metadata | source |
| --- | --- |
| `theme` | `<theme>` directory |
| `locale` | `<locale>` directory |
| `topic` | `<topic>` directory |
| `difficulty` | `<difficulty>` directory |
| `id` | `<question-id>.json` file name without extension |

Question files must not define `id`, `theme`, `locale`, `topic`, or `difficulty` as
authoritative metadata. Consumers must derive those values from the path.
Managers should not write those fields.

Only `.json` files inside difficulty directories are question files. Other files
must be ignored by consumers.

## Difficulty Contract

Difficulty is numeric.

| difficulty | label convention | options shown | wrong options required |
| --- | --- | ---: | ---: |
| `1` | easy | 3 | 2 |
| `2` | normal | 5 | 4 |
| `3` | hard | 7 | 6 |
| `4` | hardcore | 7 | 6 |

Rules:

- Difficulty directories must be numeric.
- Only `1`, `2`, `3`, and `4` are valid.
- Frontend labels may differ by locale, but API and pack values stay numeric.
- Hardcore behavior is a run rule, not a pack file rule.

## Question File Contract

Question files contain only player-facing content and option pools:

```json
{
  "prompt": "Which PHP tag starts a standard PHP code block?",
  "correctOptions": ["<?php"],
  "wrongOptions": ["<?", "<?=", "<script>"]
}
```

Fields:

| field | required | meaning |
| --- | --- | --- |
| `prompt` | yes | Question text displayed to the player. |
| `correctOptions` | yes | Pool of valid correct options. |
| `wrongOptions` | yes | Pool of wrong options. |

Rules:

- `prompt` must be non-empty.
- `correctOptions` must contain at least one item.
- `wrongOptions` must contain enough items for the difficulty.
- Question files must not include explanations or answer keys for players.
- Question files must not include topic metadata; metadata comes from paths
  and catalogs.

Recommended content quality:

- Prefer several `correctOptions` so repeated sessions can vary the displayed
  correct answer.
- Prefer more `wrongOptions` than the minimum required by difficulty.
- Keep option text concise enough for the frontend layout.
- Avoid duplicate options inside the same question.

## Canonical Package Parity

The fallback locale owns canonical package identity.

For every active topic in the central catalog:

```text
<topic>/<difficulty>/<question-id>.json
```

must exist in every supported locale loaded by the consumer.

Example valid pair:

```text
apps/api/.local/dev/en-US/php/1/php-1-001.json
apps/api/.local/dev/pt-BR/php/1/php-1-001.json
```

Example invalid translated-only package:

```text
apps/api/.local/dev/pt-BR/php/1/php-1-999.json
```

The translated package is invalid unless the fallback locale also has:

```text
apps/api/.local/dev/en-US/php/1/php-1-999.json
```

## Consumer Responsibilities

Consumers must:

- read theme metadata from `<content-root>/themes.json`;
- read each active theme central catalog from `<content-root>/<theme>/index.json`;
- validate central catalog keys;
- load only active topic packages;
- read localized catalog overrides from `<content-root>/<theme>/<locale>/index.json`;
- reject localized catalog keys not present in the central catalog;
- derive question metadata from paths;
- reject invalid difficulty directories;
- reject invalid question JSON files;
- reject supported locales that diverge from fallback package paths;
- expose only public question data to players.

Consumers must not:

- trust `id`, `theme`, `locale`, `topic`, or `difficulty` fields in question JSON;
- expose full `correctOptions` or `wrongOptions` pools to the frontend;
- expose the correct answer before answer validation;
- localize machine-readable IDs or enum values.

## Manager Responsibilities

Managers must:

- preserve JSON as the canonical quiz pack format;
- write global theme metadata to `themes.json`;
- write central catalog metadata to `<theme>/index.json`;
- write localized names and descriptions to `<theme>/<locale>/index.json`;
- write question files with only `prompt`, `correctOptions`, and
  `wrongOptions`;
- create, edit, and remove translated question files only when package parity is
  preserved;
- validate content before saving;
- avoid partial writes;
- keep quiz content independent from manager authentication storage.

Managers must not:

- store quiz questions as the canonical source in a database;
- write manager-only metadata into quiz pack files;
- use localized catalogs to publish or unpublish topic packages;
- create locale-exclusive question packages.

## Collaboration Model

Quiz packs are designed to be easy to review and submit.

Contributors should be able to submit:

- central catalog changes for new topic packages;
- fallback locale question packages;
- translated locale question packages that match fallback paths.

Reviewers and tools should validate:

- JSON syntax;
- catalog keys and active state;
- path-derived metadata;
- supported locale parity;
- question body fields;
- difficulty requirements;
- absence of player-facing explanations or answer keys.

## Minimal Valid Pack

```text
apps/api/.local/
  themes.json
  dev/
    index.json
    en-US/
      index.json
      php/
        1/
          php-1-001.json
    pt-BR/
      index.json
      php/
        1/
          php-1-001.json
```

`apps/api/.local/themes.json`:

```json
{
  "themes": [
    {
      "id": "dev",
      "name": "Development",
      "description": "Programming and software engineering quizzes.",
      "weight": 100,
      "createdAt": "2026-06-07T00:00:00-03:00",
      "active": true
    }
  ]
}
```

`apps/api/.local/dev/index.json`:

```json
{
  "topics": [
    {
      "key": "php",
      "name": "General PHP",
      "description": "Version-agnostic PHP topic fundamentals",
      "weight": 100,
      "created_at": "2026-01-01T00:00:00-03:00",
      "active": true
    }
  ]
}
```

`apps/api/.local/dev/en-US/index.json`:

```json
{
  "topics": [
    {
      "key": "php",
      "name": "General PHP",
      "description": "Version-agnostic PHP topic fundamentals"
    }
  ]
}
```

`apps/api/.local/dev/en-US/php/1/php-1-001.json`:

```json
{
  "prompt": "Which PHP tag starts a standard PHP code block?",
  "correctOptions": ["<?php"],
  "wrongOptions": ["<?", "<?=", "<script>"]
}
```

`apps/api/.local/dev/pt-BR/php/1/php-1-001.json`:

```json
{
  "prompt": "Qual tag PHP inicia um bloco PHP padrao?",
  "correctOptions": ["<?php"],
  "wrongOptions": ["<?", "<?=", "<script>"]
}
```

## Validation Checklist

- `themes.json` exists at the content root.
- Active theme IDs are non-empty and unique.
- `<theme>/index.json` exists for every active theme.
- Central topic keys are non-empty and unique.
- Active topic keys have matching topic folders in fallback locale when
  they contain playable content.
- Localized catalog keys exist in the central catalog.
- Difficulty directories are `1`, `2`, `3`, or `4`.
- Question files are valid JSON objects.
- Question files contain non-empty `prompt`.
- Question files contain at least one `correctOptions` item.
- Question files contain enough `wrongOptions` for the difficulty.
- Supported locales replicate fallback question package paths for active
  topics.
- Question files contain no manager-only metadata.
- Machine-readable IDs remain stable across locales.
