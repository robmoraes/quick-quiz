# Deployment responsibilities

This public repository owns the application, its runtime contracts and its
reusable packaging. The maintainer's production configuration, infrastructure
state and operational release records are maintained in a separate private
infrastructure workspace.

## Application artifacts

- Source, migrations and administrative application commands under `apps/`.
- Dockerfiles and configuration incorporated into images under `deploy/docker/`.
- Multi-platform image builds in `deploy/Makefile` and publication through
  `.github/workflows/release-docker-images.yml`.
- Local Compose, synthetic demo content and sanitized examples under
  `deploy/compose.local/`; reusable file-secret mounts in `deploy/compose.secrets.yml`.
- OpenAPI, specs, application procedures and synthetic validation evidence.

The image workflow builds and publishes images for application release tags.
It does not deploy containers, run Terraform or connect to a production host.
Publishing a release and deploying that release are separate operations.

The infrastructure workspace selects published versions/digests and owns its
environment's Compose configuration, routing, DNS, persistence, secrets,
infrastructure as code, backups and deployment/recovery records. Deployment
should not require rebuilding images to change environment configuration.

## Running your own environment

Start with the [local deployment guide](../deploy/README.md), then maintain
your own environment configuration and infrastructure independently. Treat
example credentials as placeholders and keep real secrets and Terraform state
outside Git, including private repositories.

The [PostgreSQL runtime guide](runbooks/postgresql-quiz-runtime.md) documents
application configuration, reader grants, compatibility, export and rollback.
Its disposable local examples are not a production deployment recipe. The
[generic Web guide](runbooks/generic-quiz-web-local.md) covers the optional
local Web profile. The [Web image release guide](runbooks/generic-quiz-web-image-release.md)
documents `web/vX.Y.Z` publication; it does not deploy a production Web service.

Maintainer-specific cloud files were transferred out of this working tree on
2026-10-22. Older document paths retain relocation notices for existing links.
This transfer did not deploy an application or rewrite Git history.
