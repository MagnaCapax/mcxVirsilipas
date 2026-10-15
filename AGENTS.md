# mcxVirsilipas: notes for contributors and coding agents

## What this is

A small PHP application: an OpenAI-compatible proxy that meters LLM usage per API key, in credits priced per model (fresh input, cached input, output), against one prepaid, never-expiring balance. Billing pushes grants; the proxy never refills a balance by itself.

## Rules

- **License:** Apache-2.0. New files carry no per-file headers; LICENSE and NOTICE cover the project.
- **No dependencies.** PHP >= 8.2 with `curl` and `pdo_sqlite`. Do not add Composer packages.
- **Provider-agnostic.** Never say which upstream a deployment uses: no upstream vendor, hostname, model id or product name in code, docs, tests or commit messages. The upstream and model list live only in the deployer's config. Naming the API format ("OpenAI-compatible") is fine; it is not a provider choice.
- **Privacy.** Never log or store request or response bodies, not even in error paths. Usage rows hold counts only.
- **Fail closed.** Any doubt about the key, the balance or the database means the request is refused.
- **Keep it small.** Each feature has to earn its lines. If something can be done by the firewall, the web server or the billing system, leave it there.

## Invariants the tests protect

1. A key never spends more than its balance. Reservation clamps `max_tokens` and is atomic (`BEGIN IMMEDIATE`); the charge never exceeds the reservation.
2. Every lease is settled at most once. An expired, unsettled lease stays charged at its reservation; a late settle returns the difference once.
3. A grant is applied once per `grant_id`; reusing a `grant_id` for anything else is a conflict. Balances stay far inside the 64-bit range.
4. No transaction or statement is open while a request streams.
5. The client never gets a 200 unless the upstream answered 2xx with a usable answer (non-empty text, reasoning or refusal, or a function tool call with non-empty id, name and arguments), or a stream whose first relayed event has a delta with a valid field. Nothing after a stream's [DONE] is relayed or charged, and streamed bytes are counted only from the rebuilt events the client receives.
6. Missing or malformed usage is never read as zero; it is charged an upper bound, capped and flagged. Usage is exact only with all three integer counts and a total at least prompt plus completion. In a stream, only a report that advances the total covers the text delivered before it. A repeat with the same counts may add a detail the adopted report lacked once (the cached split, the reasoning count, the upstream-reported cost; typically sent with the final report); nothing else, and a later repeat cannot change or remove it.
7. Only allowlisted request fields and text content go upstream; the prompt bound counts every forwarded byte.
8. Responses are rebuilt from allowlisted fields, each value of its expected type: the alias, the three standard usage counts, the cached count and the credits. Upstream error text, costs, provider fields and model ids never reach the client.
9. Lease ids are never reused (`AUTOINCREMENT`).
10. Log records hold event names, counts, statuses and billing references only: never request or response bodies, prompts, keys, the admin token, upstream error text, request paths, exception messages or client addresses. A failed log write never fails a request.
11. Every credit amount comes from `Credits` (one rounding, up, per request); counts and rates are clamped so no product overflows. `cached_input` never exceeds `input`, so pricing the whole prompt at the input rate bounds any charge.
12. Limits are checked inside the reservation transaction; a refused request reserves nothing. Only the fixed limit names reach SQL.
13. Every balance change writes one `credit_log` row in the same transaction (kind, delta, balance after, cause); zero changes write none. The log is append-only (triggers refuse UPDATE and DELETE). For every key the deltas sum to the balance and each `balance_after` is the running sum; `verify` checks both. A schema upgrade carries existing balances over as `opening` rows.
14. An upstream key goes only to its own upstream: a model with its own `url` must name its own `key_file`, a missing per-model key fails the request rather than falling back, and every upstream URL is https or plain http to the local machine.

## Layout

```
public/index.php   front controller and routes
src/Config.php     config loading and validation
src/Ledger.php     SQLite: keys, grants, leases, usage, per-key limits, credit log
src/Credits.php    credit arithmetic
src/Proxy.php      customer endpoints, request rewrite, streaming, charging
src/Admin.php      billing/provisioning endpoints
src/Http.php       request/response helpers; HttpError.php maps refusals to HTTP
bin/virsilipas     CLI
src/Log.php        leveled JSONL log, one file per UTC day
tools/bench.php    load test (parallel, multi-process) with a ledger exactness check
tools/deploy.php   deploy a release tag from a git mirror: backup, migrate, symlink switch, rollback
tools/smoke.php    live end-to-end check after a deploy (one request, credit log, verify)
CHANGELOG.md       releases (git tags vX.Y.Z) and schema changes
tests/             plain PHP runner (php tests/run.php), fake upstream, end-to-end harness,
                   secret guard (fails if a credential or local file is tracked), schema fixtures
```

## Before you change behaviour

Run `php tests/run.php` (CI runs the same suite on every push). Add a test for any change to metering, settling, grants or error handling. Never weaken the invariants above to make a test pass. When behaviour changes, update README.md in the same commit. A schema change raises `SCHEMA_VERSION`, upgrades the previous schema in place in `migrate()`, gets a fixture of the previous schema in `tests/fixtures/` and a CHANGELOG entry. Never commit config, keys, databases or logs; `.gitignore` covers them and `tests/SecretGuardTest.php` fails if one is tracked. Keep the provider-agnostic words list in your local `.forbidden-words`, never in the repo, not even hashed.
