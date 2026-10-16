# Changelog

Versions are git tags `vX.Y.Z`. A release that changes the database schema says so here; `bin/virsilipas init` (run by `tools/deploy.php`) upgrades the ledger in place.

## v0.7.1 (2026-10-16)

No schema change.

- **A misspelt setting is refused.** Config now refuses a key it does not know at every level (top level, `models` entries, `upstreams` entries, route entries), naming the key; before, a typo such as `cap` for `caps` was ignored and the limit it meant to set silently did not exist. `params` and `stream_options` stay free maps (they are sent upstream). A config that uses only documented settings loads unchanged.
- **Key rotation for a leaked key:** `bin/virsilipas key-rotate <ref>` and `POST /admin/keys/rotate` replace a ref's key with a new one, returned once. The old key answers 401 at once; balance, limits, credit log and usage stay with the ref. Before, a leaked key could only be disabled, which locked its user out with the balance on it.
- **`deploy.php` checks the config with the new release before stopping anything.** A config the new release refuses now stops the deploy while the old release keeps serving, instead of failing at `init` after php-fpm stopped and rolling back. A failed `systemctl stop` is now inside the rollback too, so php-fpm is always started again.
- **`tools/bench.php` refuses plain http off this machine**, like the example clients: the key travels with every request.
- **Repository:** SECURITY.md (private vulnerability reports), CI runs with a read-only token. The test suite removes its temporary directories and starts its servers again on new ports when one could not bind (another process took the port), instead of testing against whatever answered there.
- **README:** the install steps run the CLI as the PHP user (run as root, they created a database php-fpm could not open), a health check, what failing closed means, that a settled cost above its bound can end a window above its cap, the SQLite 3.25 minimum, the `cap_log_error` log event, and one corrected pacing figure (63 ms, not 62).

## v0.7.0 (2026-10-16)

Schema 6 to 7: `leases` gains `cost_reserved` (the upper bound of a running request's upstream cost) and an index on `created_at`; `spend_hourly` gains `cost` (upstream cost settled per UTC hour, filled from the existing usage rows on upgrade). `init` upgrades a schema-2 to 6 ledger in place. An older release's `init` refuses a schema-7 ledger. Configs without a `caps` block load unchanged and behave as before.

- **Hard caps for the whole server** (`caps`). Upstream spend per UTC hour, day and calendar month, in the upstream's currency unit, and requests started per minute across all keys. They bind every request, internal callers included, in the reservation transaction: settled cost plus the cost bounds of running requests plus the new request's bound must fit. A request that does not fit is refused (503 `spend_cap_reached`, 429 `server_rate_limit`, with `Retry-After`), never shortened. A spend cap of 0 stops everything. With a spend cap set, every route entry needs a `cost`.
- **Cost for answers delivered without a usage report.** When the upstream reports neither usage nor cost, the cost is computed from the estimated counts charged and flagged `cost_computed`.
- **`bin/virsilipas caps`** shows each cap, what is settled, what running requests hold, what remains and when the window resets. Refusals are logged as `capped` (info); a cost that carries a window past 80% or 100% of its cap logs `spend_cap_crossed` (warn).
- **README:** running your own copy, a no-warranty section, and one table of every cost and rate control (per key, whole server, web server, provider).

## v0.6.1 (2026-10-16)

No schema change.

- **Upstream usage of unusable answers is recorded.** When an upstream answers 2xx without a usable answer (the client gets a 502 and pays nothing), its usage report is now kept in the usage row (counts and cost, `charged` 0) instead of being dropped, so cost tracking includes what the upstream billed for it. Found live: a reasoning model that spent all of a small `max_tokens` on thinking returned no answer.

## v0.6.0 (2026-10-16)

Schema 5 to 6: `usage` gains `source` and `cost_computed`, `keys` gains `key_group`. `init` upgrades a schema-2 to 5 ledger in place; existing usage rows count as external with a reported (or no) cost, and keys have no group. An older release's `init` refuses a schema-6 ledger.

- **More ways to cut usage.** `stats` buckets add `week`, `hour_of_day` (all days folded into 00 to 23 UTC) and `weekday`; grouping adds `source` (internal or external) and `group`.
- **Key groups.** `bin/virsilipas group <ref> <name|->` and `key_group` in `POST /admin/keys/limits` put a key in an operator-chosen group, for example its plan; `status` shows it.
- **Cost where the upstream reports none.** A route entry may set `cost` (that upstream's prices in millionths of its currency unit per million tokens). When the upstream that served a request reports no cost of its own, the usage row records the cost computed from those prices and flags it `cost_computed`; `stats` counts such rows as `computed_cost_requests`. An upstream's own reported cost always wins. Clients never see either.
## v0.5.0 (2026-10-16)

Schema 4 to 5: `keys` gains `bonus_pct`, `leases` gains `source`, `usage` gains `delay_ms`, plus an index on open leases by source and a `spend_hourly` table (credits charged per UTC hour, kept by settling and filled from the existing usage rows on upgrade). `init` upgrades a schema-2, 3 or 4 ledger in place; existing rows get the defaults (no bonus, external, no wait). An older release's `init` refuses a schema-5 ledger. Configs without a `throttle` block load unchanged and behave as before.

- **Priority weight per key.** `100 x sqrt(balance / credits_per_unit) x (1 + bonus_pct / 100)`, doubled (`internal_factor`) for internal callers, clamped to 1..10,000: the scale of cgroup CPU and disk weights. `bonus_pct` (0 to 300) is set with `bin/virsilipas bonus <ref> <pct>` or `POST /admin/keys/limits`. `/v1/balance` shows `bonus_pct` and `weight`; chat responses carry `X-Virsilipas-Weight`. The weight changes no price and no limit.
- **Spend pacing** (`throttle.daily_budget`). Past `pace_start` of the budget, charged over the last 24 hours across all keys, each chat request waits up to `max_delay_ms` after its reservation and before going upstream, longer for lower weights; nothing is refused. No pacing wait starts while the source is busy. The wait is in the usage row (`delay_ms`), the `X-Virsilipas-Delay-Ms` header and `stats` (`delayed`, `mean_delay_ms`).
- **Busy shedding** (`throttle.busy`, requests in flight per source). While a source is at its line, keys below `busy_min_weight` get 503 `server_busy` with a weight-scaled `Retry-After`; others are served at once.
- **Source.** The web server marks internal callers with the FastCGI parameter `VIRSILIPAS_SOURCE=internal`; anything else is external. A client header cannot set it.
- **Reasoning count in usage.** Responses' `usage` now carries `completion_tokens_details.reasoning_tokens` (0 when the upstream reports none).
- **Example clients.** `examples/hello-world.php` (one question, the answer and its credits) and `examples/client.php` (models, balance, streaming chat), both covered by the tests.
- A throttle failure never fails a request; it is logged as `throttle_error` (warn). Delays and sheds are logged as `throttled` (info).
## v0.4.0 (2026-10-15)

No schema change; v0.3.0 configs load unchanged (a global `upstream_url` or a model's own `url` is a one-entry route).

- **Named upstreams and failover routes.** `upstreams` names each upstream once (URL, `key_file`, optional `stream_options`); a model's `route` lists the upstreams that serve it in order, each with the model id sent there. The route's length is the failover depth. Each upstream's key goes to that upstream only; an upstream whose key file is missing or empty is skipped (logged `upstream_skipped`), never served with another key. The global `upstream_url`/`upstream_key_file` are now optional.
- **Retries.** `retries` (0 to 2, default 1) extra attempts on the same upstream, only after a failure that cost nothing there: no connection, or 408, 409, 429, 502, 503, 504. The wait is the upstream's `Retry-After`/`retry-after-ms` (up to 60 s), else 0.5 s then 1 s. A 500, other 5xx, 401, 402, 403 or 404 moves on to the next upstream. Nothing is retried or failed over once output may exist (a 2xx status, or a sent request that timed out or broke) or when the upstream rejected the request itself. `upstream_timeout` now bounds all attempts of a request together. Failed attempts are logged as `upstream_attempt`.
- **Model params, descriptions, context length.** A model may set `params`, request fields always sent for it that override the client (a fixed reasoning level, for example), and a route entry may add its own on top; the proxy's own fields are refused. `description` and `context_length` are shown in `/v1/models`, which also gains `created` and `max_completion_tokens`. The prompt bound counts the params too.
- **Errors.** Every error body has `message`, `type`, `param` and `code`, from a fixed table (see the README). Changed statuses: an upstream that rejects its own key (401, 403) or does not know the model (404) is no longer passed on as the client's 401/403/404; after failover it is a 502 `upstream_error`. An upstream 400, 413 or 422 is now a 400 `upstream_rejected` (422 and 413 used to be passed through), or `context_length_exceeded` when the upstream said so in its error code. An upstream that stays unreachable, keyless, or busy or down (502, 503, 504) through its retries and the rest of the route is a 503 `upstream_unavailable` with `Retry-After` (was 502); a 500 is a 502 `upstream_error` as before, and running out of `upstream_timeout` a 504 `upstream_timeout` (was 502). An upstream 402 (its account out of credit) also moves on to the next upstream. An error after a stream started carries `code` `upstream_error`.
- **Request id.** An admitted chat request's response carries `X-Request-Id`, the lease id; request log lines carry `lease`.

## v0.3.0 (2026-10-15)

Schema 3 to 4: the usage table gains `upstream`, `stream`, `duration_ms`, `reasoning_tokens` and `upstream_cost` columns and an index on `ts`. `init` upgrades a schema-2 or schema-3 ledger in place; existing usage rows keep their counts and get the new columns' defaults. Older releases refuse a schema-4 ledger.

- **Usage tracked over time.** Settling a request now also records which upstream served it (the host of its route URL), whether it streamed, how long it took, the reasoning share of the output tokens and the cost the upstream itself reported (`estimated_cost`, kept in millionths of its unit). None of this changes a charge: `estimated_cost` is read for tracking only and is never relayed to a client. In a stream, a repeat report with the same three counts may add a detail the adopted report lacked (the cached split, the reasoning count, the cost) once; a later repeat cannot change or remove it.
- **`bin/virsilipas stats <hour|day|month|all> [by] [since] [until]`** prints one JSON line per UTC time bucket and group (`by` = comma list of `model`, `upstream`, `key`, `status`, or `-` for none) with requests, prompt/cached/completion/reasoning tokens, credits charged, upstream cost and costed requests, hang-ups, estimated charges, failed requests and the mean duration. CLI only, no HTTP route.
- The request log line gains `upstream`, `duration_ms`, prompt/completion/reasoning token counts and `upstream_cost`.
- `tools/deploy.php` compares the ledger before and after a failed deploy by the rows of the schema-3 columns, not by the schema text, so a failed deploy of a release that adds columns is still rolled back. The tool runs from the live release, so deploy v0.3.0 with its own copy: `git --git-dir=/opt/virsilipas.git show v0.3.0:tools/deploy.php`.

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
