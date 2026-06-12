# SPA Dev: app-scoped versioning and Docker Hub release

## Intent

Problem: the Dev SPA is independently deployable, but a repository-wide release
tag would make it unclear whether the SPA changed and could trigger unrelated
app image builds.

Users or stakeholders:

- project maintainer preparing release automation;
- SPA Dev maintainers;
- deployment operator publishing Docker Hub images;
- reviewers validating SPA Dev release history.

Desired outcome: define SPA Dev-only versioning and release behavior so SPA Dev
Docker images are built and pushed only when a SPA Dev-prefixed tag is pushed.

Non-goals:

- implement GitHub Actions in this spec;
- build or publish API or manager images;
- introduce automatic version bump commits;
- generate changelogs;
- change runtime API contracts;
- redesign frontend behavior;
- change Dockerfile contents unless a future implementation requires it.

## Scope

In scope:

- define the SPA Dev release tag format;
- define the SPA Dev Docker Hub build and push trigger;
- define image tag expectations for SPA Dev releases;
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
- the SPA Dev source path is `apps/spa-dev`;
- SPA Dev versions advance independently from `api` and `manager`;
- releases are cut from commits already merged to `main`;
- the first automation pass uses Git tag pushes as the only image-publishing
  trigger;
- one monorepo GitHub Actions release workflow is responsible for handling the
  `api`, `spa-dev`, and `manager` tag patterns;
- Docker Hub credentials are available as GitHub repository secrets when the
  workflow is implemented.

Dependencies:

- existing SPA Dev app under `apps/spa-dev`;
- existing SPA Dev Docker build assets;
- GitHub Actions;
- Docker Hub.

## Behavior

1. The SPA Dev release tag must use `spa-dev/vMAJOR.MINOR.PATCH`.
2. A valid SPA Dev release tag example is `spa-dev/v0.0.0`.
3. The monorepo release workflow must run when a supported app release tag is
   pushed.
4. The monorepo release workflow must route tags matching `spa-dev/v*` to the
   SPA Dev release path.
5. The SPA Dev release path must reject tags whose version segment is not
   valid semantic versioning in `vMAJOR.MINOR.PATCH` form.
6. Repository-wide tags such as `v0.0.0` must not build or push the SPA Dev
   image.
7. Other app tags such as `api/v0.0.0` or `manager/v0.0.0` must not build or
   push the SPA Dev image.
8. The SPA Dev release path must verify that the tagged commit is reachable
   from `main` before publishing.
9. A valid SPA Dev tag must build only the SPA Dev Docker image.
10. A valid SPA Dev tag must not build, publish, retag, or deploy API or manager
   images.
11. The Docker Hub image repository must be `robmoraes/quick-quiz-dev`.
12. The Docker image version tag must match the semantic version segment from
    the Git tag without the app prefix, such as `v0.0.0`.
13. The release workflow must also publish `latest` for the SPA Dev image.
14. The `latest` tag must always point to the most recently published SPA Dev
    release.
15. Re-running the workflow for the same SPA Dev tag should rebuild and push
    the same SPA Dev image tags without changing the version.
16. The workflow summary must show the released app, Git tag, Docker Hub image,
    and pushed image tags.

## Acceptance Examples

### Scenario: SPA Dev release tag

Given a SPA Dev change has been merged into `main`

When the maintainer creates
`git tag -a spa-dev/v0.0.0 -m "SPA-Dev Release v0.0.0"`

And pushes tags with `git push origin --tags`

Then the release workflow builds the SPA Dev Docker image

And pushes `robmoraes/quick-quiz-dev:v0.0.0`

And updates `robmoraes/quick-quiz-dev:latest`

And API and manager images are not built or pushed.

### Scenario: unrelated API tag

Given a tag `api/v0.0.0` is pushed

When release workflows evaluate the tag

Then the SPA Dev image is not built

And no SPA Dev Docker Hub tag is published.

### Scenario: invalid SPA Dev tag

Given a tag `spa-dev/0.0.0` is pushed

When the SPA Dev release workflow evaluates the tag

Then no image is published

And the workflow reports that SPA Dev release tags must use
`spa-dev/vMAJOR.MINOR.PATCH`.

## Data and Contracts

Inputs:

- Git tag in `spa-dev/vMAJOR.MINOR.PATCH` form;
- tagged commit reachable from `main`;
- SPA Dev Docker build context and Dockerfile configuration;
- SPA Dev Docker Hub image repository from the `DOCKERHUB_SPA_DEV_IMAGE`
  GitHub Environment variable in `production`;
- SPA Dev API base URL from the `SPA_DEV_API_BASE_URL` GitHub Environment
  variable in `production`;
- Docker Hub credentials from GitHub Environment secrets in `production`:
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

Outputs:

- SPA Dev Docker image pushed to `robmoraes/quick-quiz-dev`;
- versioned SPA Dev image tag such as `v0.0.0`;
- SPA Dev-only `latest` image tag;
- static SPA Dev bundle compiled with `VITE_API_BASE_URL` set to
  `https://dev.quickquiz.com.br`;
- workflow summary with release details.

API, schema, event, or CLI changes:

- no runtime API changes;
- Git tag naming becomes an operational contract for SPA Dev releases.

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

- no UI behavior changes are introduced by this release automation spec.

Performance:

- SPA Dev release runs should build only the SPA Dev image.

Reliability:

- malformed SPA Dev tags must fail before Docker build or push;
- unrelated app tags must not affect the SPA Dev image;
- SPA Dev Docker image builds must fail when `VITE_API_BASE_URL` is missing or
  empty;
- publishing the same valid tag again must be safe for retry.

Observability:

- workflow summaries must identify the SPA Dev release tag and Docker Hub
  image.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- The release workflow uses the `production` GitHub Environment.
- Docker Hub credentials are workflow configuration through the
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` GitHub Environment secrets.
- The SPA Dev Docker Hub repository is configured with the
  `DOCKERHUB_SPA_DEV_IMAGE` GitHub Environment variable. Current production
  value: `robmoraes/quick-quiz-dev`.
- The automated SPA Dev release workflow must build with
  `VITE_API_BASE_URL` set from the `SPA_DEV_API_BASE_URL` GitHub Environment
  variable. Current production value: `https://dev.quickquiz.com.br`.
- Manual SPA Dev image builds must provide `VITE_API_BASE_URL`; no default is
  allowed for image builds.

Rollback:

- publish a corrected SPA Dev image with a new SPA Dev version tag, or redeploy
  a previous known-good SPA Dev image tag.

Monitoring:

- confirm Docker Hub contains only the expected SPA Dev tags for the SPA Dev
  release.
- confirm `robmoraes/quick-quiz-dev:latest` points to the latest published SPA
  Dev release.

## Verification

Planned checks:

- workflow validation for accepted and rejected SPA Dev tag names;
- SPA Dev lint/build check before image publish;
- dry-run or non-publishing validation if supported by the workflow design.

Evidence to record:

- GitHub Actions run summary;
- Docker Hub image tag list for the released SPA Dev version.
