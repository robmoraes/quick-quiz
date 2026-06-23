# Service Map

QuickQuiz Dev contains multiple services plus an externalized data structure.

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
- create and edit advertising entries through the Ads API;
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

The Quiz API does not serve advertising. Advertising delivery and management
live in `apps/ads-api/`.

## Ads API

Path: `apps/ads-api/`

The Ads API is a Go service dedicated to advertising delivery and management.
It reads and writes `ads/ads.json` under the configured content root and uses
theme/topic metadata from the same root to validate ad targets.

Responsibilities:

- expose active advertising for player SPAs;
- filter ads by theme, topic, active flag, expiration, and emphasis;
- expose open MVP administrative endpoints for the manager;
- preserve the current advertising file format while canonicalizing writes.

## SPA

Path: `apps/spa-dev/`

The SPAs are Quasar/Vue applications for theme-specific players. The current
apps are `apps/spa-dev/` and `apps/spa-dslab/`. Each SPA is configured for one
theme and talks to the Quiz API with the current theme, session ID, and locale.
Advertising requests use the Ads API base URL.

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
