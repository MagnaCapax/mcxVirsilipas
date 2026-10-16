# ADR 0004: One SQLite ledger, upgraded in place

Date: 2026-10-16
In force since: v0.1.0 (schema 3)

## Status
Accepted

## Context
Every request reserves credits in an atomic transaction, and every balance change must be auditable. The proxy runs on one host, and the people running it want as few moving parts as possible.

## Options considered
- A database server (MySQL, PostgreSQL): scales across hosts, but adds a service to run, secure and back up.
- An in-memory store: fast, but a crash loses money.
- One SQLite file in WAL mode.

## Decision
The ledger is one SQLite file in WAL mode. Money-moving statements run in `BEGIN IMMEDIATE` transactions. The schema version lives in `PRAGMA user_version`; `migrate()` upgrades each earlier released schema in place, and a release refuses a ledger newer than it knows. Every schema change raises the version, gets a fixture of the previous schema under `tests/fixtures/`, and a CHANGELOG entry.

## Consequences
- One file to back up and restore; backups must carry `user_version` (see README "Backups").
- Writes are serialized on one host. Spreading the proxy over several hosts would need a different store and a new ADR.

## References
src/Ledger.php (`migrate`, `UPGRADABLE_FROM`); tests/fixtures/; AGENTS.md (schema change rule); invariants 1 and 4.
