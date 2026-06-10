# Feature: <feature name>

Use this template for specs that define behavior, constraints, contracts, or
user outcomes before implementation starts.

Remove sections that genuinely do not apply. Do not leave empty placeholders
that make the spec look more complete than it is.

## Intent

Problem:

Users or stakeholders:

Desired outcome:

Non-goals:

## Scope

In scope:

- <observable outcome or boundary>

Out of scope:

- <explicitly excluded behavior or responsibility>

Assumptions:

- <assumption that the plan and implementation may rely on>

Dependencies:

- <system, contract, decision, or external dependency>

## Behavior

Requirements must be observable, bounded, and written in product-domain
language. Each item should describe what must be true, not how it will be
implemented.

1. <Requirement.>
2. <Requirement.>
3. <Requirement.>

## Acceptance Examples

Cover the main successful path, expected failures, boundary values, permission
differences, data state transitions, and relevant time, locale, or concurrency
behavior.

### Scenario: <name>

Given <initial state>

And <additional condition>

When <action or event>

Then <expected result>

And <additional expected result>

## Data and Contracts

Inputs:

- <input, source, validation rule, or default>

Outputs:

- <output, representation, or user-visible result>

API, schema, event, or CLI changes:

- <contract change or "none">

Persistence changes:

- <data lifecycle, migration, storage, or "none">

Machine-readable contract:

- <OpenAPI/schema/file path or "not required">

## Quality Attributes

Security:

- <authorization, exposure, abuse case, or "not applicable">

Privacy:

- <personal data, retention, disclosure, or "not applicable">

Accessibility:

- <keyboard, screen reader, contrast, focus, or "not applicable">

Performance:

- <latency, throughput, payload size, or "not applicable">

Reliability:

- <failure mode, retry, timeout, recovery, or "not applicable">

Observability:

- <logs, metrics, traces, audit events, or "not applicable">

## Rollout and Operations

Migration:

- <migration step or "none">

Feature flag or configuration:

- <configuration name, default, or "none">

Rollback:

- <how to revert safely or "standard deploy rollback">

Monitoring:

- <signal to confirm expected behavior or detect failure>

## Verification

Planned checks:

- <unit test, integration test, build, lint, manual check, or contract check>

Evidence to record:

- <test command output, screenshot, demo note, review note, or "none">

## Open Questions

- <Question, owner, and deadline.>
