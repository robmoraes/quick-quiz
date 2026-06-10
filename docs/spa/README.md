# SPA Documentation

The player SPA lives in `apps/spa-dev/` and is implemented with Quasar, Vue, and
TypeScript.

## Responsibilities

- Render the player quiz flow.
- Select topic and difficulty.
- Start runs through the API.
- Render active questions and options.
- Submit answers.
- Show run/session results.
- Manage local player preferences such as locale and audio.
- Send session, theme, and locale headers to the API.

## Local Development

```sh
cd apps/spa-dev
npm install
npm run dev
```

The Quasar dev server prints the local URL.

## Validation

```sh
cd apps/spa-dev
npm run lint
npm run build
```

## Service Contracts

- API client: `apps/spa-dev/src/services/api.ts`
- Theme config: `apps/spa-dev/src/services/theme-config.ts`
- Locale handling: `apps/spa-dev/src/i18n/`
- OpenAPI contract: [docs/openapi.yaml](../openapi.yaml)

## Refactor Notes

The current SPA is being prepared for public review and long-term maintenance.
The route page should become an orchestrator while large UI sections move into
components and run/catalog/preference logic moves into composables.

See the SPA refactor specs:

- [IndexPage refactor boundaries](../specs/spa-dev/003-indexpage-refactor-boundaries.md)
- [Final SPA refactor acceptance](../specs/spa-dev/008-final-spa-refactor-acceptance.md)

Final acceptance evidence:

- [SPA refactor acceptance](./refactor-acceptance.md)
