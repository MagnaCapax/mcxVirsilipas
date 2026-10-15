# Changelog

Versions are git tags `vX.Y.Z`. A release that changes the database schema says so here; `bin/virsilipas init` (run by `tools/deploy.php`) upgrades the ledger in place.

## v0.3.0 (2026-10-15)

Schema 3 to 4: the usage table gains `upstream`, `stream`, `duration_ms`, `reasoning_tokens` and `upstream_cost` columns and an index on `ts`. `init` upgrades a schema-2 or schema-3 ledger in place; existing usage rows keep their counts and get the new columns' defaults. Older releases refuse a schema-4 ledger.

- **Usage tracked over time.** Settling a request now also records which upstream served it (the host of its route URL), whether it streamed, how long it took, the reasoning share of the output tokens and the cost the upstream itself reported (`estimated_cost`, kept in millionths of its unit). None of this changes a charge: `estimated_cost` is read for tracking only and is never relayed to a client. In a stream, a repeat report with the same three counts may add a detail the adopted report lacked (the cached split, the reasoning count, the cost) once; a later repeat cannot change or remove it.
- **`bin/virsilipas stats <hour|day|month|all> [by] [since] [until]`** prints one JSON line per UTC time bucket and group (`by` = comma list of `model`, `upstream`, `key`, `status`, or `-` for none) with requests, prompt/cached/completion/reasoning tokens, credits charged, upstream cost and costed requests, hang-ups, estimated charges, failed requests and the mean duration. CLI only, no HTTP route.
- The request log line gains `upstream`, `duration_ms`, prompt/completion/reasoning token counts and `upstream_cost`.

## v0.2.1 (2026-10-15)

No schema change; v0.2.0 configs load unchanged.

- **Usage shapes.** The cached input count is read from `prompt_tokens_details.cached_tokens` or, where an upstream reports it that way, a top-level `prompt_cache_hit_tokens`. Before, the second shape was read as 0, so cached input was charged and recorded as fresh.
- **Client hang-ups are charged by the upstream's count.** After a hang-up the proxy keeps reading the upstream, relaying nothing, until a usage report covers what the client received. Before, it stopped at once and, with an upstream that reports usage only at the end, charged byte upper bounds flagged `estimated`. Known gap: an upstream with running usage that sends the cached count only in its final report still has the cached part of a hung-up request charged as fresh input.

## v0.2.0 (2026-10-04)

No schema change; v0.1.0 configs load unchanged.

- **Per-model upstreams.** A `models` entry may name its own `url`, `key_file` and `stream_options`, so different aliases can be served by different upstreams; anything it leaves out comes from the global settings. A model with its own `url` must name its own `key_file`, so the global key is never sent to another upstream, and a missing or empty per-model key file fails the request instead of falling back.
- **Upstream URL check.** `upstream_url` and every per-model `url` must be https, or plain http only to 127.0.0.1, ::1 or localhost; anything else is a config error.

## v0.1.0 (2026-10-04)

First tagged release. Schema 3.

- OpenAI-compatible chat completions proxy (streaming and non-streaming) that meters each API key against one prepaid, never-expiring balance in credits, priced per model for fresh input, cached input and output tokens.
- Per-request reservation and exactly-once settlement, idempotent grants (negative for refunds), per-key concurrency, requests-per-minute and credits-per-minute limits, and client queries for the balance, limits and models.
- **Credit log.** Every change to a balance writes one append-only `credit_log` row in the same transaction: the change, the balance after it, its kind (grant, reserve, refund, or opening) and its cause (the grant id or the lease). `bin/virsilipas history <ref>` lists a key's changes; `bin/virsilipas verify` checks that every balance equals the sum of its logged changes.
- **Schema 2 to 3:** `init` adds `credit_log` and gives each key with a nonzero balance one `opening` row carrying that balance, so `verify` holds from the upgrade on. Older releases refuse a schema-3 ledger.
- `tools/deploy.php` deploys a tag from a git mirror into its own release directory, backs up and migrates the ledger, switches a symlink and rolls back on failure; `tools/smoke.php` checks a live deployment end to end.
- The test suite fails if a credential, key, database, log or local config file is committed.
