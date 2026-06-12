# API: app-scoped versioning and Docker Hub release

## Intent

Problem: the API is independently deployable, but a repository-wide release tag
would make it unclear whether the API changed and could trigger unrelated app
image builds.

Users or stakeholders:

- project maintainer preparing release automation;
- API maintainers;
- deployment operator publishing Docker Hub images;
- reviewers validating API release history.

Desired outcome: define API-only versioning and release behavior so API Docker
images are built and pushed only when an API-prefixed tag is pushed.

Non-goals:

- implement GitHub Actions in this spec;
- build or publish manager or SPA Dev images;
- introduce automatic version bump commits;
- generate changelogs;
- change runtime API contracts;
- change Dockerfile contents unless a future implementation requires it.

## Scope

In scope:

- define the API release tag format;
- define the API Docker Hub build and push trigger;
- define image tag expectations for API releases;
- define validation and safety rules for malformed or unrelated tags.

Out of scope:

- exact workflow YAML implementation;
- pull request path-based CI optimization;
- production deployment after image publishing;
- Docker Hub repository provisioning;
- secret creation;
- semantic-release or changelog automation.

Assumptions:

- the monorepo remains the source of truth;
- the API source path is `apps/api`;
- API versions advance independently from `manager` and `spa-dev`;
- releases are cut from commits already merged to `main`;
- the first automation pass uses Git tag pushes as the only image-publishing
  trigger;
- one monorepo GitHub Actions release workflow is responsible for handling the
  `api`, `spa-dev`, and `manager` tag patterns;
- Docker Hub credentials are available as GitHub repository secrets when the
  workflow is implemented.

Dependencies:

- existing API app under `apps/api`;
- existing API Docker build assets;
- GitHub Actions;
- Docker Hub.

## Behavior

1. The API release tag must use `api/vMAJOR.MINOR.PATCH`.
2. A valid API release tag example is `api/v0.0.0`.
3. The monorepo release workflow must run when a supported app release tag is
   pushed.
4. The monorepo release workflow must route tags matching `api/v*` to the API
   release path.
5. The API release path must reject tags whose version segment is not
   valid semantic versioning in `vMAJOR.MINOR.PATCH` form.
6. Repository-wide tags such as `v0.0.0` must not build or push the API image.
7. Other app tags such as `manager/v0.0.0` or `spa-dev/v0.0.0` must not build
   or push the API image.
8. The API release path must verify that the tagged commit is reachable
   from `main` before publishing.
9. A valid API tag must build only the API Docker image.
10. A valid API tag must not build, publish, retag, or deploy manager or SPA Dev
   images.
11. The Docker Hub image repository must be `robmoraes/quick-quiz-api`.
12. The Docker image version tag must match the semantic version segment from
    the Git tag without the app prefix, such as `v0.0.0`.
13. The release workflow must also publish `latest` for the API image.
14. The `latest` tag must always point to the most recently published API
    release.
15. Re-running the workflow for the same API tag should rebuild and push the
    same API image tags without changing the version.
16. The workflow summary must show the released app, Git tag, Docker Hub image,
    and pushed image tags.

## Acceptance Examples

### Scenario: API release tag

Given an API change has been merged into `main`

When the maintainer creates `git tag -a api/v0.0.0 -m "API Release v0.0.0"`

And pushes tags with `git push origin --tags`

Then the release workflow builds the API Docker image

And pushes `robmoraes/quick-quiz-api:v0.0.0`

And updates `robmoraes/quick-quiz-api:latest`

And manager and SPA Dev images are not built or pushed.

### Scenario: unrelated SPA Dev tag

Given a tag `spa-dev/v0.0.0` is pushed

When release workflows evaluate the tag

Then the API image is not built

And no API Docker Hub tag is published.

### Scenario: invalid API tag

Given a tag `api/0.0.0` is pushed

When the API release workflow evaluates the tag

Then no image is published

And the workflow reports that API release tags must use
`api/vMAJOR.MINOR.PATCH`.

## Data and Contracts

Inputs:

- Git tag in `api/vMAJOR.MINOR.PATCH` form;
- tagged commit reachable from `main`;
- API Docker build context and Dockerfile configuration;
- Docker Hub image repository from the `DOCKERHUB_API_IMAGE` GitHub
  Environment variable in `production`;
- Docker Hub credentials from GitHub Environment secrets in `production`:
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

Outputs:

- API Docker image pushed to `robmoraes/quick-quiz-api`;
- versioned API image tag such as `v0.0.0`;
- API-only `latest` image tag;
- workflow summary with release details.

API, schema, event, or CLI changes:

- no runtime API changes;
- Git tag naming becomes an operational contract for API releases.

Persistence changes:

- none.

Machine-readable contract:

- one GitHub Actions monorepo release workflow with tag routing for `api`,
  `spa-dev`, and `manager` release tags.
- workflow file: `.github/workflows/release-docker-images.yml`.

## Quality Attributes

Security:

- release jobs must use least-privilege GitHub token permissions;
- Docker Hub credentials must come from GitHub secrets;
- pull request workflows must not publish images or expose Docker Hub
  credentials.

Privacy:

- no personal data is introduced.

Accessibility:

- not applicable.

Performance:

- API release runs should build only the API image.

Reliability:

- malformed API tags must fail before Docker build or push;
- unrelated app tags must not affect the API image;
- publishing the same valid tag again must be safe for retry.

Observability:

- workflow summaries must identify the API release tag and Docker Hub image.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- The release workflow uses the `production` GitHub Environment.
- Docker Hub credentials are workflow configuration through the
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` GitHub Environment secrets.
- The API Docker Hub repository is configured with the
  `DOCKERHUB_API_IMAGE` GitHub Environment variable. Current production value:
  `robmoraes/quick-quiz-api`.

Rollback:

- publish a corrected API image with a new API version tag, or redeploy a
  previous known-good API image tag.

Monitoring:

- confirm Docker Hub contains only the expected API tags for the API release.
- confirm `robmoraes/quick-quiz-api:latest` points to the latest published API
  release.

## Verification

Planned checks:

- workflow validation for accepted and rejected API tag names;
- API build check before image publish;
- dry-run or non-publishing validation if supported by the workflow design.

Evidence to record:

- GitHub Actions run summary;
- Docker Hub image tag list for the released API version.
