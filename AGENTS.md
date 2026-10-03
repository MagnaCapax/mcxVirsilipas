# mcxVirsilipas: notes for contributors and coding agents

## What this is

A small PHP application: an OpenAI-compatible proxy that meters LLM tokens per API key against one prepaid, never-expiring balance. Billing pushes grants; the proxy never refills a balance by itself.

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
6. Missing or malformed usage is never read as zero; it is charged an upper bound, capped and flagged. Usage is exact only with all three integer counts and a total at least prompt plus completion. In a stream, only a report that advances the total covers the text delivered before it.
7. Only allowlisted request fields and text content go upstream; the prompt bound counts every forwarded byte.
8. Responses are rebuilt from allowlisted fields, each value of its expected type: the alias and the three standard usage counts. Upstream error text, costs, provider fields and model ids never reach the client.
9. Lease ids are never reused (`AUTOINCREMENT`).

## Layout

```
public/index.php   front controller and routes
src/Config.php     config loading and validation
src/Ledger.php     SQLite: keys, grants, leases, usage
src/Proxy.php      customer endpoints, request rewrite, streaming, charging
src/Admin.php      billing/provisioning endpoints
src/Http.php       request/response helpers; HttpError.php maps refusals to HTTP
bin/virsilipas     CLI
tests/             plain PHP runner (php tests/run.php), fake upstream, end-to-end harness
```

## Before you change behaviour

Run `php tests/run.php` (CI runs the same suite on every push). Add a test for any change to metering, settling, grants or error handling. Never weaken the invariants above to make a test pass. When behaviour changes, update README.md in the same commit.
