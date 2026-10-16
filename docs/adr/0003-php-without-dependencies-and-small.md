# ADR 0003: PHP 8.2 without dependencies, kept small

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
The proxy holds money. Every dependency is code nobody here reviewed, a supply-chain risk and an upgrade chore, and a framework brings far more surface than a proxy with eight routes needs. Deploys unpack a tag with `git archive`, so anything not in the repository has to be fetched at deploy time.

## Options considered
- A web framework or Composer packages (HTTP client, router, ORM): less code to write, much more to trust.
- Another language: the surrounding tooling is PHP, and so are the people who maintain it.
- Plain PHP with the bundled `curl` and `pdo_sqlite` extensions.

## Decision
PHP 8.2 or later with `curl` and `pdo_sqlite`, and no Composer packages. Tests run on a plain PHP runner. Each feature has to earn its lines; what a firewall, the web server or the billing system already does stays there.

## Consequences
- The whole program can be read in an afternoon; deploys need nothing but PHP.
- Common helpers (routing, JSON bodies, HTTP errors) are small local classes instead of library calls.

## References
AGENTS.md (No dependencies, Keep it small); composer.json; tests/run.php.
