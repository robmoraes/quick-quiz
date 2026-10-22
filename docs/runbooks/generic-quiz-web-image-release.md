# Generic Web image release

The Web uses independent Git tags `web/vMAJOR.MINOR.PATCH`. The image-release
workflow validates the source and publishes a Docker Hub multi-platform image
for `linux/amd64` and `linux/arm64`, with both `vMAJOR.MINOR.PATCH` and `latest`.
The default repository is `robmoraes/quick-quiz-web`.

## Release configuration

The workflow reads `DOCKERHUB_WEB_IMAGE` from the GitHub Environment
`production`. Set it to the full Docker Hub repository name, without a tag.
It uses the existing `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` secrets; the
credential must be able to push to that repository. An absent Web image variable
fails the Web release before login/build/push, without changing other releases.

The Web validation step uses Node 24 in Docker to run `npm ci`, lint,
typechecking, tests, the Nitro production build and file-configuration tests.
QEMU and Buildx then publish the image from `deploy/docker/web/Dockerfile`.
Environment URLs, locales and indexability are supplied at container startup;
no production configuration or secret is embedded in the image.

## Publish a version

Commit the implementation and workflow, review the PR and merge into `main`.
Confirm the intended version in `apps/web/package.json` and its lockfile, then
prepare the Web changelog: move the relevant
`Unreleased` entries into `## [<version>] - YYYY-MM-DD` with the actual release
date. Link the first release to its tag/release page and update `Unreleased`
to compare that tag with `HEAD`; later versions compare adjacent Web tags.
Include those changes in the release source before creating its tag. For example,
the initial `0.1.0` release uses `web/v0.1.0`. Follow the repository's existing
tag approval and push process; publishing a tag starts the workflow. The job
rejects a tag commit that is not reachable from `main`.

Verify the workflow gates and final published-image summary. Inspect the
registry manifest after a successful release to confirm both architectures.
Keep the published version/digest as the infrastructure deployment input.
Malformed Web tags and repository-wide `v*` tags are rejected.

For a deliberate manual build/push from a configured workstation:

```sh
make -C deploy web WEB_TAG=v0.1.0 \
  WEB_REPOSITORY=robmoraes/quick-quiz-web \
  PLATFORM=linux/amd64,linux/arm64 OUTPUT=push
```

`make -C deploy build-images` also includes Web. For a local single-platform
build, use `PLATFORM=linux/amd64 OUTPUT=load`; set `WEB_REPOSITORY=quickquiz-web`
if a local image name is preferred. Local Compose retains its own configurable
image name and build target.

## Deployment boundary

Publishing this image does not update a host or a domain. Production deployment
belongs to the infrastructure workspace under the
[deployment boundary](../deployment.md). Discovery requires a compatible API,
the additive `0005` view migration and explicit reader grants; the Web image
alone does not supply or activate those dependencies. Preserve existing SPAs,
data and sessions during any separate coordinated rollout.

Runtime settings and local verification are documented in the
[local Web runbook](generic-quiz-web-local.md).
