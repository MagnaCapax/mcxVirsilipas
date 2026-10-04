# Changelog

Versions are git tags `vX.Y.Z`. A release that changes the database schema says so here; `bin/virsilipas init` (run by `tools/deploy.php`) upgrades the ledger in place.

## v0.1.0 (2026-10-04)

First tagged release. Schema 3.

- OpenAI-compatible chat completions proxy (streaming and non-streaming) that meters each API key against one prepaid, never-expiring balance in credits, priced per model for fresh input, cached input and output tokens.
- Per-request reservation and exactly-once settlement, idempotent grants (negative for refunds), per-key concurrency, requests-per-minute and credits-per-minute limits, and client queries for the balance, limits and models.
- **Credit log.** Every change to a balance writes one append-only `credit_log` row in the same transaction: the change, the balance after it, its kind (grant, reserve, refund, or opening) and its cause (the grant id or the lease). `bin/virsilipas history <ref>` lists a key's changes; `bin/virsilipas verify` checks that every balance equals the sum of its logged changes.
- **Schema 2 to 3:** `init` adds `credit_log` and gives each key with a nonzero balance one `opening` row carrying that balance, so `verify` holds from the upgrade on. Older releases refuse a schema-3 ledger.
- `tools/deploy.php` deploys a tag from a git mirror into its own release directory, backs up and migrates the ledger, switches a symlink and rolls back on failure; `tools/smoke.php` checks a live deployment end to end.
- The test suite fails if a credential, key, database, log or local config file is committed.
