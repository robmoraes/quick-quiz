# QuickQuiz DSLab SPA

Player SPA for the `dslab` theme. This app serves quizzes for Distributed
Systems Lab viewers, starting with the Docker Swarm high-availability lab
playlist.

Channel: <https://www.youtube.com/@DistributedSystemsLab>

The API theme is fixed to `dslab` in the SPA client. The Docker image reads
`SPA_API_BASE_URL` and `SPA_ADS_API_BASE_URL` when the container starts, so
endpoint changes do not require an image rebuild.

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
make -C ../../deploy spa-dslab SPA_DSLAB_TAG=v0.1.0 OUTPUT=oci
```

### Run the Docker image with runtime endpoints

```bash
docker run --rm -p 8083:8080 \
  -e SPA_API_BASE_URL=http://localhost:8080 \
  -e SPA_ADS_API_BASE_URL=http://localhost:8084 \
  quickquiz-dslab:local
```

Direct `npm run dev` uses the development defaults in `public/runtime-config.js`.

### Customize the configuration

See [Configuring quasar.config.js](https://v2.quasar.dev/quasar-cli-vite/quasar-config-js).
