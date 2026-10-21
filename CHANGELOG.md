# Changelog

Versions are git tags `vX.Y.Z`. A release that changes the database schema says so here; `bin/virsilipas init` (run by `tools/deploy.php`) upgrades the ledger in place.

## v0.15.0 (2026-10-21)

No schema change.

- **Models per window in the public usage: `models_24h` and `models_7d`**, in the same shape as `models_30d` (credits, requests, tokens, shares of that window, first byte, generation speed and its sums), so a page can show the model mix for each window. `schema_version` stays 2: the fields are added, nothing else changes.
- **`bin/virsilipas public-top <ref-prefix> [file]`: a public top list under anonymous ids ([ADR 0026](docs/adr/0026-public-top-list-under-anonymous-ids.md)).** For the last 24 hours, 7 and 30 days, the 10 keys with the most credits used, each as `rank`, `id`, `credits`, `requests` and `credit_share`. The id is SHA-256 applied 1000 times, first to `<ref>:<sha256 hex of the key>`, then to the previous hex digest, so a key's owner can compute their own and nobody else can tell whose it is. No ref, key digest or model is published; a removed key is not listed. A list shorter than 10 shows how many keys used credits in that window.
- `Ledger::keyHashes($prefix)`: the current key digest per ref, removed keys left out. The `public-usage` file write is shared with `public-top`.
- Tests: the id against the published shell recipe run with coreutils `sha256sum`, ranking and windows, the ten-entry cut, removed keys, the leak guard, the CLI; the per-window model lists.

## v0.14.0 (2026-10-20)

No schema change.

- **Generation speed per model in the public usage: `generation_tokens_per_second` in `models_30d`.** Output tokens per second while an answer streams: the output tokens (reasoning included) of the streamed 2xx answers that finished on their own with counted usage, over the time from their first byte to their end, summed over the 30 days (token-weighted, not a mean of per-request rates), next to the two sums it comes from, `gen_tokens` and `gen_ms`, so a reader can pool several aliases of one model exactly. Answers that were not streamed are left out (their first byte is the whole answer), and so are hang-ups and estimated charges (an estimate is an upper bound, not a count). `null` with less than one minute of measured time. `schema_version` stays 2: the field is added, nothing else changes. Not the same number as `top`'s output tokens/s, which is the traffic rate (output tokens per second of the window).
- **`Ledger::stats()` rows carry `gen_tokens` and `gen_ms`**, the two sums behind it, so they add up across buckets.
- Tests: which rows count (streamed, 2xx, not aborted, not estimated, first byte known and before the end), the token weighting across hours, and the one-minute floor.

## v0.13.1 (2026-10-20)

No schema change.

- **An empty JSON object in a request is forwarded as `{}`.** Requests were decoded into arrays, so `{}` became `[]` on the way upstream: a tool with no parameters (`"properties": {}`), an empty `items` or `additionalProperties`, or a tool call with no arguments (`"input": {}` on `/v1/messages`) reached the upstream as `[]`, and an upstream that validates the schema refused the whole request with 400. It hit every request of a tools-using client that reached such an upstream, failover included. `Http::decode` now keeps empty objects as objects; the Gemini routes accept them for function parameters and response schemas.
- **AGENTS.md invariant 7:** forwarded JSON keeps its types.
- Tests: the request round trip on chat, `/v1/messages` and Gemini, and an end-to-end test whose fake upstream records the tools as they arrived (it decoded them the same lossy way before, so it could not see this).

## v0.13.0 (2026-10-18)

Schema 10 to 11: `keys` gains `expires_at` (NULL: never). `init` upgrades a schema-2 to 10 ledger in place; existing keys get NULL and never expire. An older release's `init` refuses a schema-11 ledger.

- **Keys can expire.** `bin/virsilipas key-create <ref> --expires=<when>`, where `<when>` is `+N` followed by `m`, `h` or `d` (N from 1 to 999999) or a UTC time `YYYY-MM-DDTHH:MM:SSZ`, in the future. From then on every client route answers 403 `key_expired` (`permission_error`); the check runs at authentication and again inside the reservation, so a request authenticated just before the expiry is refused when it reserves after it. A request already running finishes.
- **Named keys.** `key-create <ref> --named` makes `vsk-<ref>-<48 hex>`, the same random part as a plain key with the ref in front; the ref must be 1 to 64 of `A-Z a-z 0-9 _ -`, else 400 and nothing is created.
- **`key-create --json`** prints `{"ref", "key", "expires_at", "named"}`; without it the bare key is printed, as before. An unknown or repeated option, or a second argument, is refused.
- `key-rotate` keeps the expiry and always gives a plain key. `keys` and `status` show `expires_at`. `POST /admin/keys` is unchanged (plain keys that never expire); its `key_create` log record gains `expires_at` and `named`.
- The secret guard's key pattern is now `vsk-[A-Za-z0-9_-]{16,}`, so a named key committed to the tree is caught.
- **docs/adr/0024:** keys can expire and carry their ref. **README:** CLI, Troubleshooting. **docs/API.md:** `key_expired`.

## v0.12.1 (2026-10-18)

No schema change.

- **`bin/virsilipas key-ref <sha256>`: the ref of the key with this digest.** Disabled keys included; `null` for a rotated key's old digest, a removed key or none. Read-only, CLI only (no route). For a deployment's own services that keep only a key's sha256. **README:** CLI.

## v0.12.0 (2026-10-18)

No schema change.

- **`bin/virsilipas public-usage <ref-prefix> [file]`: the aggregate usage a deployment may publish.** Over the keys whose ref starts with the prefix (required): windows of 24 hours, 7 and 30 days ending at the last complete UTC hour (credits, requests, prompt, cached, completion and reasoning tokens, the share of 2xx answers, the share of retried requests, the mean time to first byte), every model over 30 days with its tokens, credits and shares of credits and requests, the last 30 UTC days with each day's top 5 models, the last 7 days hour by hour split into the 5 largest models and the rest, and a weekday by hour heatmap over 30 days. No ref, no count of keys, no upstream, no cost and no grant; an output that would carry a ref is refused. With a file, written atomically and readable by everyone (0644). No new route: a web server serves the file. **docs/adr/0023:** public aggregate usage is an operator command, never a route. **README:** CLI.

## v0.11.0 (2026-10-17)

No schema change.

- **The Gemini API format, for Gemini CLI: `POST /v1beta/models/{model}:generateContent` and `:streamGenerateContent?alt=sse`.** A translation, not a pass-through (no upstream serves the format): the request becomes a chat completions request on the alias's own route (any alias, the model's `params` included) and goes through chat's validation, prompt bound, reservation, limits, caps, throttle, retries, failover and settlement unchanged; the answer comes back as one candidate. Text, function calls and function responses (paired by id, else by name in order) and the system instruction are translated; function declarations become function tools (`parametersJsonSchema`, else `parameters`, with upper-case type names lower-cased); `toolConfig` becomes `tool_choice`; `temperature`, `topP`, `stopSequences`, `seed`, the penalties, `maxOutputTokens` (clamped like `max_tokens`) and a JSON response type are mapped, and `thinkingConfig`, `topK`, `candidateCount`, safety settings and cached content are dropped. Inline data, files and any tool but function declarations are refused (400). Answers carry text, thoughts (the reasoning, as `thought` parts), function calls with object args, the finish reason and `usageMetadata` from the charged report; in a stream a function call is sent whole in the last chunk, and its bytes count as delivered only then. The key comes as `x-goog-api-key`, a `key` query parameter or `Authorization: Bearer`; errors use the Gemini error shape with the proxy's fixed messages.
- **`POST /v1beta/models/{model}:countTokens`** answers `{"totalTokens": N}`, the chat prompt bound of the translated request, free and without an upstream call.
- **README:** a *Gemini CLI* section (`GOOGLE_GEMINI_BASE_URL`, `GEMINI_API_KEY`, and the `settings.json` that points the CLI's own model names at an alias, checked with Gemini CLI 0.64.0); a key in a query string and the access log. **docs/API.md:** the three routes. **docs/adr/0021:** the Gemini format is translated to chat completions; ADR 0013 amended. AGENTS.md invariants 5, 7 and 8 name the routes' rules.

## v0.10.0 (2026-10-17)

No schema change.

- **`POST /v1/messages`: the Anthropic Messages format, for Claude Code.** A metered pass-through to upstreams that serve the format natively, not a translation: an upstream (or a model in the older single-upstream form, beside its own `key_file`) may set `messages_url`, called with that upstream's own key; only the aliases routed to such an upstream can be used, on those route entries only, in route order with the usual retries and failover. Any other alias answers 404 `not_found_error`. The key comes as `x-api-key` or `Authorization: Bearer`. Only allowlisted fields go upstream (model, messages, system, max_tokens, stop_sequences, stream, temperature, top_p, top_k, tools, tool_choice, thinking, metadata), with text, tool use, tool result and thinking blocks and client tools; images, documents and server tools are refused (400). `max_tokens` is clamped as on chat, a thinking budget that no longer fits is lowered to fit, and `anthropic-version` and `anthropic-beta` are forwarded. Metering from the Anthropic counts: input and cache writes as fresh input, cache reads as cached input, output (thinking included) as output, streamed or not, with a client hang-up charged by the upstream's own final counts as on chat. Answers and stream events are rebuilt at their top level (the alias as the model, the standard usage counts) and content blocks pass as sent; errors use the Anthropic error shape with the proxy's fixed messages. The model's `params` do not apply on this route.
- **`POST /v1/messages/count_tokens`** answers `{"input_tokens": N}`, the proxy's own conservative estimate (the prompt bound a messages request would reserve for), free and without an upstream call.
- **README:** a *Claude Code* section (`ANTHROPIC_BASE_URL`, `ANTHROPIC_AUTH_TOKEN`, `ANTHROPIC_MODEL`). **docs/API.md:** both routes. **docs/adr/0020:** the Messages format is a metered pass-through; ADR 0013 amended. AGENTS.md invariants 5, 6, 7, 8 and 14 name the route's rules.

## v0.9.1 (2026-10-17)

Schema 9 to 10: an index on `grants(key_id)` and a trigger `credit_log_no_replace`. `init` upgrades a schema-2 to 9 ledger in place; no row changes. An older release's `init` refuses a schema-10 ledger.

- **Reasoning under the name `reasoning`.** Some upstreams send the reasoning text as `message.reasoning` and `delta.reasoning` instead of `reasoning_content`. Both names are now kept, passed on under the name the upstream used and counted in the byte bound. Before, an answer that spent `max_tokens` on reasoning looked empty and answered 502 (while the upstream billed it), a stream dropped the reasoning text, and a hang-up during reasoning ended without the upstream's usage report.
- **A number JSON cannot carry is refused.** A request body with a number no float holds (`1e999`) decoded to infinity, could not be encoded again, and went upstream as an empty body. It now answers 400 `invalid_json` `request body has a number out of range` before anything is reserved, and the proxy refuses to bound or forward any body that fails to encode.
- **The credit log refuses `INSERT OR REPLACE`** of an existing row, which the update and delete triggers did not catch (schema 10).
- **`GET /v1/usage?grants=M`** lists the newest M grants, 1 to 1000 (default 200, as before); anything else answers 400 `invalid_value`. The new index keeps the grants lookup off a table scan.
- **No `X-Powered-By` header**, whatever `expose_php` says.
- **CLI:** `key-remove <ref>`, an optional `expires_at` on `grant`, and `--help` without a config (exit 0).
- **`tools/smoke.php`** also checks `GET /v1/balance` and `GET /v1/usage`, and removes its key for good instead of disabling it.
- **`tools/deploy.php`** compares every column the backup has, in every table except `credit_log`, before it restores a backup; it compared only the schema-3 columns, so a CLI change to a later column (a bonus or group) made during a failed deploy was lost on restore.
- **`examples/client.php --key-file`** also reads a file of `key=` and `endpoint=` lines; the endpoint is the base URL unless `--url` is given.
- **A missing ledger directory** is named in the error, with the `db_path`.
- **PHP 8.5:** the `curl_close()` calls (no-ops since PHP 8.0, deprecated in 8.5) are gone; CI runs 8.2 to 8.5 with every error and deprecation reported, and the suite fails on a `Deprecated` or `Warning` line in a test server's log. CI passes on pull requests from forks (the forbidden-word list is required only where the secret exists), pins the third-party PHP setup action to a commit and checks the PHPStan download's checksum.
- **Releases leave out `tests/` and `.github/`** (`.gitattributes`), so the test suite's stand-in upstream never lands on a host.
- **composer.json** requires `ext-ctype` and suggests `ext-posix`.
- **README:** a complete nginx site and php-fpm pool; memory sizing for large bodies; version headers; never serving a checkout; the Debian packages; log retention by age (logrotate does not fit daily names); the restore steps end with `init` and `verify`, and a plain `.dump` is refused by `init`, not by the proxy; a Contributing section. **docs/API.md:** the example credits match the example rates; `POST /admin/keys` is not idempotent (on a lost response, rotate). ADR 0009 and 0019 amended.

## v0.9.0 (2026-10-17)

Schema 8 to 9: `keys` gains `removed_at` and `grants` gains `expires_at` (both `NULL` by default). `init` upgrades a schema-2 to 8 ledger in place; existing keys are not removed and existing grants never expire. An older release's `init` refuses a schema-9 ledger. Backups by `.backup` carry the schema version as before; a `.dump` must still add `PRAGMA user_version` (README "Backups").

- **`POST /admin/keys/remove {"ref"}`** removes a key for good: it never authenticates again, it is disabled and its removal time is kept. The balance, grants, credit log and usage rows stay. Removing again answers `applied: false`. The removal is final: rotating, re-enabling, setting limits, a new grant and creating a key for the ref again answer 409 `key_removed`; a retried grant applied before the removal still answers as before. `bin/virsilipas keys` shows `removed_at`.
- **Grants can carry `expires_at`** (unix seconds, in the future; absent or `null` for never), stored with the grant and returned by `POST /admin/grants`. It is stored for a future expiry policy and **not enforced**: the balance does not change when it passes. A retry must repeat it: the same `grant_id` with another `expires_at` is a conflict (409), like another ref or amount. `created_at` already records when each grant was added.
- **`GET /v1/usage?days=N`** (customer key; `days` 1 to 400, default 30): the caller's own balance, its usage over the last N UTC days (today included) per day and per model alias (requests, prompt, cached, completion and reasoning tokens, credits), and its newest 200 grants with their dates and expiries. Only the caller's own rows; no ref, no other key, no upstream cost.
- **README: a getting-started path at the top:** a five-minute local quick start against the test suite's stand-in upstream, a *Call the API* section (curl, JavaScript, the PHP example clients, OpenAI-compatible tools), troubleshooting for clients and operators, and the minimum configuration; the endpoint list and the example clients (now *PHP: the example clients*) moved up; every other section keeps its name and anchor.
- **docs/adr/0019:** a key reads its own usage, and nothing else. ADR 0006 (grant expiry stored, not enforced), 0013 (the usage endpoint) and 0015 (key removal) amended.

## v0.8.2 (2026-10-16)

No schema change.

- **`top` streams the hour's usage rows into running totals** instead of loading them all: at its 50,000-row cap a view took about 140 MB and failed under a 128 MB PHP memory limit; it now takes about 10 MB and half the time.
- **`top` lists the requests in flight**, the ten oldest by key ref, source, age and credits reserved, not only their count.

## v0.8.1 (2026-10-16)

No schema change.

- **`top` shows the upstream cost to six decimals**, the millionth it is stored in; with four, a real but small cost read as 0.0000.

## v0.8.0 (2026-10-16)

Schema 7 to 8: `usage` gains `first_byte_ms` and `attempts`, plus an index on `(key_id, ts)`. `init` upgrades a schema-2 to 7 ledger in place; existing usage rows get no first-byte time and 0 attempts. An older release's `init` refuses a schema-8 ledger.

- **Time to first byte and upstream attempts per request.** Each usage row records when the client got its first byte (`first_byte_ms`, from the start of the upstream request; `NULL` when nothing reached the client) and how many upstream requests it took (`attempts`, retries and failover included). `stats` adds `mean_first_byte_ms` and `retried`. Neither changes a charge.
- **`bin/virsilipas top`:** a live terminal view of the traffic (in flight, caps, and for the last minute, 5 minutes and hour: rate, failures, duration and first-byte percentiles, output tokens per second, tokens, credits, upstream cost, retries, hang-ups; per model and upstream; the most recent requests). `--once` prints one view, `--interval N` sets the redraw. Metadata only: the ledger holds no prompt or answer.
- **`bin/virsilipas keys`:** one JSON line per key (ref, active, balance, group, bonus, created, last used).
- **README:** how to keep request bodies off disk under the proxy (nginx body buffer, PHP's temporary directory, swap and core dumps, upstream retention).
- **docs/adr/0018:** performance and cost metrics live in the ledger.

## v0.7.2 (2026-10-16)

No schema change.

- **docs/API.md:** the complete HTTP API in one place: every route, request field, response shape, header and error, with the order of checks for a chat request. The README keeps a route list and links to it. `tests/DocsTest.php` fails when the code has a route, error code, error message or response header the doc does not mention.
- **A JSON array as the request body answers 400 `invalid_json`**, as its message ("request body must be a JSON object") always said; before, it was read as an object with numbered fields and refused by a later check (for chat, 404 `model_not_found`). An empty object is accepted as before.
- **README:** the refusal order for a chat request now includes the server-wide requests per minute and the spend caps, as the code checks them.
- **docs/adr/:** seventeen architecture decision records; together with AGENTS.md, which indexes them, they are the project's governing law. The README's access-log advice now also names keeping only the client's network.

## v0.7.1 (2026-10-16)

No schema change.

- **A misspelt setting is refused.** Config now refuses a key it does not know at every level (top level, `models` entries, `upstreams` entries, route entries), naming the key; before, a typo such as `cap` for `caps` was ignored and the limit it meant to set silently did not exist. `params` and `stream_options` stay free maps (they are sent upstream). A config that uses only documented settings loads unchanged.
- **Key rotation for a leaked key:** `bin/virsilipas key-rotate <ref>` and `POST /admin/keys/rotate` replace a ref's key with a new one, returned once. The old key answers 401 at once; balance, limits, credit log and usage stay with the ref. Before, a leaked key could only be disabled, which locked its user out with the balance on it.
- **`deploy.php` checks the config with the new release before stopping anything.** A config the new release refuses now stops the deploy while the old release keeps serving, instead of failing at `init` after php-fpm stopped and rolling back. A failed `systemctl stop` is now inside the rollback too, so php-fpm is always started again.
- **`tools/bench.php` refuses plain http off this machine**, like the example clients: the key travels with every request.
- **Repository:** SECURITY.md (private vulnerability reports), CI runs with a read-only token and current action versions (Node 24). The test suite removes its temporary directories and starts its servers again on new ports when one could not bind (another process took the port), instead of testing against whatever answered there; every test server starts through one helper. The spend-pacing test no longer compares two wall-clock durations, which failed on a busy CI runner.
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
