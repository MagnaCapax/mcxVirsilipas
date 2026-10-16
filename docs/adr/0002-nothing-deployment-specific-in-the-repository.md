# ADR 0002: Nothing deployment-specific in the repository

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
Anyone may run their own copy. A deployment's choice of upstream providers, its prices, keys, hostnames and addresses are its own business, change often, and in some cases are confidential. A public repository is read by competitors and attackers as well as users.

## Options considered
- Ship a working default configuration for one provider: convenient, but it ties the project to that provider and discloses the maintainer's own setup.
- Keep secrets out but name providers in docs and examples: still a disclosure, and stale whenever a deployment changes.
- Keep the repository free of every deployment detail.

## Decision
The repository holds the software only. No upstream vendor, hostname, model id or product name appears in code, docs, tests or commit messages; naming the API format ("OpenAI-compatible") is fine. No configuration, key, database or log is ever tracked. `config.example.php` shows the shape of a configuration with placeholders. Each deployment keeps a local list of its own words that must never appear, outside the repository and never committed, not even hashed.

## Consequences
- Forks start from a neutral base; nothing private leaks through history.
- `tests/SecretGuardTest.php` fails when a tracked file holds a credential shape, a local file, or a word from the local list. It sees only tracked files, so new files are covered once they are added.

## References
AGENTS.md (Provider-agnostic rule); README "Run your own"; tests/SecretGuardTest.php; .gitignore.
