# QuickQuiz Dev SPA

Player SPA for the `dev` theme. This app is theme-specific; future player SPAs
should live beside it under `apps/`, such as `apps/spa-geography/`.

Configure the Quiz API through `VITE_API_BASE_URL` and the Ads API through
`VITE_ADS_API_BASE_URL`. Both values are compiled into the static bundle.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [SPA documentation](../../docs/spa/README.md)
- [OpenAPI contract](../../docs/openapi.yaml)

## Install the dependencies

```bash
cd apps/spa-dev
yarn
# or
npm install
```

### Start the app in development mode (hot-code reloading, error reporting, etc.)

```bash
quasar dev
```

### Lint the files

```bash
yarn lint
# or
npm run lint
```

### Format the files

```bash
yarn format
# or
npm run format
```

### Build the app for production

```bash
VITE_API_BASE_URL=http://localhost:8080 VITE_ADS_API_BASE_URL=http://localhost:8084 quasar build
```

### Customize the configuration

See [Configuring quasar.config.js](https://v2.quasar.dev/quasar-cli-vite/quasar-config-js).
