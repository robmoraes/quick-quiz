# Local Storage

Local development uses `apps/api/.local` as a file-backed content root.

The API reads this path when:

```text
QUESTION_STORAGE_PROVIDER=local
QUESTION_SOURCE=.local
```

The manager writes to the same content root through:

```text
MANAGER_CONTENT_ROOT=../api/.local
```

In manager Docker Compose, the host directory is mounted into the container and
exposed as:

```text
MANAGER_CONTENT_ROOT=/content
```

## Git Policy

Do not commit local quiz banks or generated content roots. Keep
`apps/api/.local` ignored by Git.

Small artificial examples may be committed only when they are explicitly test
fixtures and do not contain real private content.
