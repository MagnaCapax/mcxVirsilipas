# ADR 0001: ADRs and docs are governing law

Date: 2026-10-16
In force since: this change (docs/API.md drift test since b6c3b6b; the README-in-the-same-commit rule since v0.1.0)

## Status
Accepted

## Context
The project's decisions lived in AGENTS.md rules and invariants, the CHANGELOG and commit messages. The reasons behind them, and the options that were turned down, were written nowhere a contributor would read. Prose that is not checked drifts: writing docs/API.md from the code found three README statements that no longer matched it (the refusal order, the health check, the error table).

## Options considered
- Keep everything in AGENTS.md: short, but it would grow into an essay, and it has no place for rejected options.
- A wiki outside the repository: drifts further and is not reviewed with the code.
- Architecture decision records in `docs/adr/`, one decision per file, next to the code they govern.

## Decision
Accepted ADRs in `docs/adr/` and AGENTS.md together are the project's governing law. One ADR decides one subject. A significant change of behaviour, interface, security or workflow adds an ADR or amends one in the same commit. An ADR cites the AGENTS.md invariants and tests that enforce it rather than copying them. When code and documents disagree, the code is what runs: fix whichever is wrong, in the same commit. Behaviour changes update README.md, and docs/API.md when a route, field, response, header or error changes.

## Consequences
- A contributor can see why a rule exists before changing it, and what was already rejected.
- ADRs are not tested themselves; their rules are, through the tests each one names. An ADR whose claim the code does not meet is a bug in one or the other.

## References
AGENTS.md; docs/API.md and tests/DocsTest.php; 0000-template.md.
