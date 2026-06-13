# Specs

This directory contains product and implementation specifications for
QuickQuiz Dev.

Specs are part of the public project history. They explain decisions,
constraints, and planned work before implementation.

## Active Specs

- `standard.md`: spec-driven development standard used for new specs.
- `001-manager-question-form-flow/spec.md`: manager manual and AI-assisted
  question authoring flow spec.
- `001-manager-question-form-flow/plan.md`: technical plan for implementing
  the manager question authoring flow spec.
- `001-manager-question-form-flow/tasks.md`: implementation and validation
  task list for the manager question authoring flow spec.

## Archive

Current implemented and historical specs live under `archive/`, preserving
their original grouping:

- `archive/api/`: backend and API foundation specs.
- `archive/architecture/`: repository-wide architecture and organization specs.
- `archive/manager/`: Symfony manager specs.
- `archive/mvp/`: MVP behavior and early refactor specs.
- `archive/spa-dev/`: Dev theme player SPA behavior and frontend refactor specs.
- `archive/draft/`: exploratory ideas that are not committed implementation
  plans.

## Use

Create specs before implementing changes that affect behavior, contracts,
architecture, or public documentation. Keep each spec small enough to implement
and verify independently.
