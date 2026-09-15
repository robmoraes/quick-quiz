# QuickQuiz PHP base

This image is the shared PHP-FPM runtime for the QuickQuiz Manager development
and production images. It contains OPcache, PDO PostgreSQL, PDO SQLite, and
SimpleXML.

Publishing is intentionally manual and is not part of the GitHub Actions
workflow or the `build-images` target. Tags follow
`<php>-alpine<alpine>-r<revision>`; create a new revision instead of
overwriting an existing versioned tag.

```sh
docker login
make -C deploy php-base BUILDER=multiarch OUTPUT=push
```

A security update consists of reviewing the upstream PHP/Alpine image, updating
its tag and digest in the Dockerfile, incrementing `PHP_BASE_TAG`, updating the
Manager default image references, building both architectures, and verifying
the published manifest.

Detailed update and publishing procedure:
[Manager PHP base multi-architecture release runbook](../../../docs/runbooks/manager-php-base-multiarch-release.md).
