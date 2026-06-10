# Data Documentation

QuickQuiz Dev quiz content is stored as JSON quiz packs. The files are not
committed to the public repository, but their structure is part of the system
contract.

Canonical contract:

- [Quiz Pack Contract](../quiz-pack-contract.md)

## Local Content Root

The default local content root is:

```text
apps/api/.local
```

This folder is ignored by Git and simulates future object storage.

Expected structure:

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

## Question Files

Question files contain only:

```json
{
  "prompt": "Question text",
  "correctOptions": ["Correct answer"],
  "wrongOptions": ["Wrong answer A", "Wrong answer B"]
}
```

Do not write these fields into question files:

- `id`
- `theme`
- `locale`
- `topic`
- `difficulty`

The API derives those values from the path.

## Publication Rules

- `themes.json` publishes active themes.
- `<theme>/index.json` publishes active topics for a theme.
- `<theme>/<locale>/index.json` localizes topic names and descriptions only.
- `active: false` keeps files editable but unpublished.
- Only active themes and active topics are loaded by the API.

## Locale Rules

- Use BCP 47 tags such as `en-US` and `pt-BR`.
- `FALLBACK_LOCALE` is the canonical content locale.
- Every supported locale must replicate the fallback locale question package
  paths for active topics.
- Locales must not add exclusive question packages.
- Locales must not remove fallback question packages.
- Do not localize machine-readable IDs, API enum values, error codes, logs, or
  metrics.

## Common Manager Risks

Changing the manager can break API loading if it:

- writes question metadata that should come from paths;
- creates a translated question without a fallback-locale counterpart;
- deletes a translated question required by the fallback package set;
- writes publication fields into localized catalog overrides;
- publishes a topic without enough wrong options for its difficulty;
- changes topic keys or question IDs as display text;
- saves invalid locale tags.

When in doubt, run manager tests and backend tests after content-contract
changes.

