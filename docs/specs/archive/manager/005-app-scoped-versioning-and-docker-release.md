# Manager: app-scoped versioning and Docker Hub release

## Intent

Problem: the manager app is independently deployable, but a repository-wide
release tag would make it unclear whether the manager changed and could trigger
unrelated app image builds.

Users or stakeholders:

- project maintainer preparing release automation;
- manager app maintainers;
- deployment operator publishing Docker Hub images;
- reviewers validating manager release history.

Desired outcome: define manager-only versioning and release behavior so manager
Docker images are built and pushed only when a manager-prefixed tag is pushed.

Non-goals:

- implement GitHub Actions in this spec;
- build or publish API or SPA Dev images;
- introduce automatic version bump commits;
- generate changelogs;
- change manager product behavior;
- change Dockerfile contents unless a future implementation requires it.

## Scope

In scope:

- define the manager release tag format;
- define the manager Docker Hub build and push trigger;
- define image tag expectations for manager releases;
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
- the manager source path is `apps/manager`;
- manager versions advance independently from `api` and `spa-dev`;
- releases are cut from commits already merged to `main`;
- the first automation pass uses Git tag pushes as the only image-publishing
  trigger;
- one monorepo GitHub Actions release workflow is responsible for handling the
  `api`, `spa-dev`, and `manager` tag patterns;
- Docker Hub credentials are available as GitHub repository secrets when the
  workflow is implemented.

Dependencies:

- existing manager app under `apps/manager`;
- existing manager Docker build assets;
- GitHub Actions;
- Docker Hub.

## Behavior

1. The manager release tag must use `manager/vMAJOR.MINOR.PATCH`.
2. A valid manager release tag example is `manager/v0.0.0`.
3. The monorepo release workflow must run when a supported app release tag is
   pushed.
4. The monorepo release workflow must route tags matching `manager/v*` to the
   manager release path.
5. The manager release path must reject tags whose version segment is not
   valid semantic versioning in `vMAJOR.MINOR.PATCH` form.
6. Repository-wide tags such as `v0.0.0` must not build or push the manager
   images.
7. Other app tags such as `api/v0.0.0` or `spa-dev/v0.0.0` must not build or
   push the manager images.
8. The manager release path must verify that the tagged commit is reachable
   from `main` before publishing.
9. A valid manager tag must build only the manager FPM and web Docker images.
10. A valid manager tag must not build, publish, retag, or deploy API or SPA Dev
   images.
11. The manager release must publish the FPM image to
    `robmoraes/quick-quiz-manager-fpm`.
12. The manager release must publish the web image to
    `robmoraes/quick-quiz-manager-web`.
13. Each Docker image version tag must match the semantic version segment from
    the Git tag without the app prefix, such as `v0.0.0`.
14. The release workflow must also publish `latest` for both manager images.
15. The `latest` tag must always point to the most recently published manager
    release for both manager image repositories.
16. Re-running the workflow for the same manager tag should rebuild and push
    the same manager image tags without changing the version.
17. The workflow summary must show the released app, Git tag, Docker Hub
    images, and pushed image tags.

## Acceptance Examples

### Scenario: manager release tag

Given a manager change has been merged into `main`

When the maintainer creates
`git tag -a manager/v0.0.0 -m "Manager Release v0.0.0"`

And pushes tags with `git push origin --tags`

Then the release workflow builds the manager FPM and web Docker images

And pushes `robmoraes/quick-quiz-manager-fpm:v0.0.0`

And pushes `robmoraes/quick-quiz-manager-web:v0.0.0`

And updates `robmoraes/quick-quiz-manager-fpm:latest`

And updates `robmoraes/quick-quiz-manager-web:latest`

And API and SPA Dev images are not built or pushed.

### Scenario: unrelated API tag

Given a tag `api/v0.0.0` is pushed

When release workflows evaluate the tag

Then the manager FPM and web images are not built

And no manager Docker Hub tag is published.

### Scenario: invalid manager tag

Given a tag `manager/0.0.0` is pushed

When the manager release workflow evaluates the tag

Then no image is published

And the workflow reports that manager release tags must use
`manager/vMAJOR.MINOR.PATCH`.

## Data and Contracts

Inputs:

- Git tag in `manager/vMAJOR.MINOR.PATCH` form;
- tagged commit reachable from `main`;
- manager FPM and web Docker build contexts and Dockerfile configuration;
- manager FPM Docker Hub image repository from the
  `DOCKERHUB_MANAGER_FPM_IMAGE` GitHub Environment variable in `production`;
- manager web Docker Hub image repository from the
  `DOCKERHUB_MANAGER_WEB_IMAGE` GitHub Environment variable in `production`;
- Docker Hub credentials from GitHub Environment secrets in `production`:
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

Outputs:

- manager FPM Docker image pushed to `robmoraes/quick-quiz-manager-fpm`;
- manager web Docker image pushed to `robmoraes/quick-quiz-manager-web`;
- versioned manager image tags such as `v0.0.0`;
- manager-only `latest` image tags for both repositories;
- workflow summary with release details.

API, schema, event, or CLI changes:

- no runtime API changes;
- Git tag naming becomes an operational contract for manager releases.

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

- manager release runs should build only the manager FPM and web images.

Reliability:

- malformed manager tags must fail before Docker build or push;
- unrelated app tags must not affect the manager images;
- publishing the same valid tag again must be safe for retry.

Observability:

- workflow summaries must identify the manager release tag and Docker Hub
  images.

## Rollout and Operations

Migration:

- none.

Feature flag or configuration:

- The release workflow uses the `production` GitHub Environment.
- Docker Hub credentials are workflow configuration through the
  `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` GitHub Environment secrets.
- The manager FPM Docker Hub repository is configured with the
  `DOCKERHUB_MANAGER_FPM_IMAGE` GitHub Environment variable. Current
  production value: `robmoraes/quick-quiz-manager-fpm`.
- The manager web Docker Hub repository is configured with the
  `DOCKERHUB_MANAGER_WEB_IMAGE` GitHub Environment variable. Current
  production value: `robmoraes/quick-quiz-manager-web`.

Rollback:

- publish corrected manager images with a new manager version tag, or redeploy
  previous known-good manager image tags.

Monitoring:

- confirm Docker Hub contains only the expected manager tags for the manager
  release.
- confirm `robmoraes/quick-quiz-manager-fpm:latest` points to the latest
  published manager release.
- confirm `robmoraes/quick-quiz-manager-web:latest` points to the latest
  published manager release.

## Verification

Planned checks:

- workflow validation for accepted and rejected manager tag names;
- manager build check before image publish;
- dry-run or non-publishing validation if supported by the workflow design.

Evidence to record:

- GitHub Actions run summary;
- Docker Hub image tag lists for the released manager version.
