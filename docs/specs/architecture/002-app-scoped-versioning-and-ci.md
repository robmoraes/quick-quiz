# Architecture: app-scoped versioning and CI

This combined architecture spec was split into app-scoped implementation specs
so each deployable layer can be prepared, reviewed, and implemented
independently.

Active specs:

- [API: app-scoped versioning and Docker Hub release](../api/003-app-scoped-versioning-and-docker-release.md)
- [SPA Dev: app-scoped versioning and Docker Hub release](../spa-dev/010-app-scoped-versioning-and-docker-release.md)
- [Manager: app-scoped versioning and Docker Hub release](../manager/005-app-scoped-versioning-and-docker-release.md)

The first automation pass is intentionally simple: after a feature branch is
merged into `main`, a maintainer creates and pushes an annotated app-prefixed
tag such as `spa-dev/v0.0.0`. That tag push is the release trigger that builds
and pushes only that app's Docker image to Docker Hub.

The release automation must be implemented as one monorepo GitHub Actions
workflow responsible for recognizing the three supported tag families:

- `api/vMAJOR.MINOR.PATCH`
- `spa-dev/vMAJOR.MINOR.PATCH`
- `manager/vMAJOR.MINOR.PATCH`

The workflow must route each valid tag to the matching app release path and
must not publish images for unrelated apps.
