# QuickQuiz Dev

[Leia em portugues do Brasil](README.pt-BR.md)

QuickQuiz Dev is an open source fullstack quiz platform for developers who want
to test what they already know and find the next thing worth studying.

It is not designed as a course, tutorial, or replacement for books, classes,
documentation, or practice. The goal is different: present focused challenges,
make knowledge gaps visible, and motivate the player to go back to reliable
learning sources before returning to beat the next challenge.

The project was also built as a portfolio monorepo. It combines a player SPA, a
Go API, a Symfony content manager, a documented JSON content contract, and
optional AI-assisted content production in one coherent product.

## Product Demo

### AI-Assisted Content Manager

The manager helps create, validate, localize, and publish quiz content. Its
AI-assisted flows can recommend candidate questions and help localize content,
while the quiz pack contract remains the source of truth.

> Video placeholder: manager creating quiz content with AI assistance.
>
> Add the demo video link here when the repository is ready for publication.

### Player Quiz Experience

The SPA lets a player choose a topic and difficulty, answer timed quiz
questions, and review the run result. The experience is meant to feel like a
challenge loop: try, fail, study, return, and win.

> Video placeholder: player completing a quiz run.
>
> Add the demo video link here when the repository is ready for publication.

## Why This Exists

AI is becoming part of intellectual and knowledge work. That makes it even more
important to keep exercising judgment, memory, fundamentals, and technical
reasoning.

QuickQuiz Dev is a small answer to that pressure: a tool for active recall and
self-assessment. When a challenge is not beaten, the intended next step is not
passive scrolling or guessing. The intended next step is to study through
courses, books, documentation, source code, mentors, and any reliable place
where knowledge can be found, then come back and beat the challenge.

## What Is In The Monorepo

- `apps/spa-dev/`: Quasar/Vue player SPA for the `dev` theme.
- `apps/api/`: Go API for catalogs, runs, answers, and results.
- `apps/manager/`: Symfony app for quiz pack editing, validation, localization, and
  optional AI-assisted content workflows.
- `docs/`: architecture, service notes, data contracts, OpenAPI, specs, and
  timeline.

## Engineering Standards

This project follows conventions influenced by my
[Engineering Playbook](https://github.com/robmoraes/engineering-playbook):
explicit contracts, small implementation specs, locale-safe data modeling, low
coupling between services, and clear boundaries between product content, API
behavior, and UI orchestration.

## Documentation

Technical details live outside this root README:

- [Documentation index](docs/README.md)
- [Architecture overview](docs/architecture/overview.md)
- [Service map](docs/architecture/services.md)
- [Quiz pack contract](docs/quiz-pack-contract.md)
- [API documentation](docs/api/README.md)
- [SPA documentation](docs/spa/README.md)
- [Manager documentation](docs/manager/README.md)
- [Data documentation](docs/data/README.md)
- [Product and implementation specs](docs/specs/README.md)

Service entry points:

- [apps/api/README.md](apps/api/README.md)
- [apps/spa-dev/README.md](apps/spa-dev/README.md)
- [apps/manager/README.md](apps/manager/README.md)

## Open Source

QuickQuiz Dev is being prepared for public GitHub release as an open source
project. The source code is licensed under the [MIT License](LICENSE).

Real question banks, secrets, private credentials, and generated local
databases should not be committed to this repository.
