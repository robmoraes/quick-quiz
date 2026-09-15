# QuickQuiz Dev SPA

Player SPA for the `dev` theme. This app is theme-specific; future player SPAs
should live beside it under `apps/`, such as `apps/spa-geography/`.

The Docker image reads `SPA_API_BASE_URL` and `SPA_ADS_API_BASE_URL` when the
container starts. Changing either endpoint does not require rebuilding the image.

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
npm run build
```

### Run the Docker image with runtime endpoints

```bash
docker run --rm -p 8082:8080 \
  -e SPA_API_BASE_URL=http://localhost:8080 \
  -e SPA_ADS_API_BASE_URL=http://localhost:8084 \
  quickquiz-dev:local
```

Direct `npm run dev` uses the development defaults in `public/runtime-config.js`.

### Customize the configuration

See [Configuring quasar.config.js](https://v2.quasar.dev/quasar-cli-vite/quasar-config-js).
