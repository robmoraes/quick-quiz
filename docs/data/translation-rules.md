# Translation Rules

QuickQuiz Dev separates locale from topic, language/framework, timezone, and
machine-readable IDs.

## Locale Tags

Use BCP 47 locale tags:

- `en-US`
- `pt-BR`

Use the API field name `locale` for localized content.

## Canonical Locale

`FALLBACK_LOCALE` is the canonical content locale. The default is:

```text
en-US
```

The fallback locale defines the package set for active content. Translated
locales must follow that package set.

## Required Parity

For every active fallback question package:

```text
<topic>/<difficulty>/<question-id>.json
```

every supported locale must provide the same path.

Translated locales must not:

- add locale-exclusive question packages;
- remove fallback question packages;
- change question IDs;
- change topic keys;
- change difficulty values.

## Do Not Localize

Keep these values stable across locales:

- API error codes;
- enum values;
- topic keys;
- question IDs;
- difficulty numbers;
- locale tags;
- logs;
- metrics;
- traces.

