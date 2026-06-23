# QuickQuiz DSLab SPA

Player SPA for the `dslab` theme. This app serves quizzes for Distributed
Systems Lab viewers, starting with the Docker Swarm high-availability lab
playlist.

Channel: <https://www.youtube.com/@DistributedSystemsLab>

The API theme is fixed to `dslab` in the SPA client. Configure the Quiz API
through `VITE_API_BASE_URL` and the Ads API through `VITE_ADS_API_BASE_URL`.
Both values are compiled into the static bundle.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [SPA documentation](../../docs/spa/README.md)
- [OpenAPI contract](../../docs/openapi.yaml)

## Install the dependencies

```bash
cd apps/spa-dslab
yarn
# or
npm install
```

### Start the app in development mode (hot-code reloading, error reporting, etc.)

```bash
npm run dev
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

### Build the Docker image

```bash
make -C ../../deploy spa-dslab \
  SPA_DSLAB_TAG=v0.1.0 \
  SPA_DSLAB_API_BASE_URL=https://api.quickquiz.com.br \
  SPA_DSLAB_ADS_API_BASE_URL=https://ads.quickquiz.com.br \
  OUTPUT=oci
```

### Customize the configuration

See [Configuring quasar.config.js](https://v2.quasar.dev/quasar-cli-vite/quasar-config-js).
