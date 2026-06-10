# Service Map

QuickQuiz Dev contains three services plus an externalized data structure.

## Manager

Path: `apps/manager/`

The manager is a Symfony webapp for maintaining quiz pack files. It is the
safest place to edit content because it understands the path contract,
localized catalogs, supported locales, and publication flags.

Responsibilities:

- create and edit themes;
- create and edit topic catalogs;
- create and edit localized topic metadata;
- create and edit question files;
- validate quiz pack structure before saving;
- optionally use OpenAI-assisted question recommendation and localization.

The manager is not the player API. It may have admin authentication for editing
content, but that does not imply player accounts.

## API

Path: `apps/api/`

The API is a Go service that serves the player SPA. It loads active quiz pack
content from local files or S3-compatible storage and keeps MVP run/session
state in memory.

Responsibilities:

- expose catalog and session availability endpoints;
- start quiz runs;
- select questions and answer options;
- validate answers;
- enforce run and session rules;
- return run/session results.

## SPA

Path: `apps/spa-dev/`

The SPA is a Quasar/Vue application for Dev theme players. It is configured for
one theme and talks to the API with the current theme, session ID, and locale.

Responsibilities:

- render topic and difficulty selection;
- render active questions and answer options;
- submit answers;
- show run and session results;
- expose player settings such as locale and audio;
- show rules and interstitial screens.

## Quiz Pack Data

Path in local development: `apps/api/.local/`

Quiz pack data is not part of the public repository. It is documented because
the API and manager both depend on the same contract.

Responsibilities:

- publish active themes and topics;
- provide fallback-locale question packages;
- provide translated question packages for supported locales;
- keep machine-readable IDs stable.

See [Data Documentation](../data/README.md) and the
[Quiz Pack Contract](../quiz-pack-contract.md).
