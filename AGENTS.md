# mcxVirsilipas: notes for contributors and coding agents

## What this is

A small PHP application: an OpenAI-compatible proxy that meters LLM tokens per API key against one prepaid, never-expiring balance. Billing pushes grants; the proxy never refills a balance by itself.

## Rules

- **License:** Apache-2.0. New files carry no per-file headers; LICENSE and NOTICE cover the project.
- **No dependencies.** PHP >= 8.2 with `curl` and `pdo_sqlite`. Do not add Composer packages.
- **Provider-agnostic.** No upstream vendor, hostname or product name in code, docs, tests or commit messages. The upstream and model list live only in the deployer's config.
- **Privacy.** Never log or store request or response bodies, not even in error paths. Usage rows hold counts only.
- **Fail closed.** Any doubt about the key, the balance or the database means the request is refused.
- **Keep it small.** Each feature has to earn its lines. If something can be done by the firewall, the web server or the billing system, leave it there.

## Invariants the tests protect

1. A key never spends more than its balance. Reservation clamps `max_tokens` and is atomic (`BEGIN IMMEDIATE`).
2. Every reservation is settled exactly once: returned in full, minus what the upstream reported.
3. A grant is applied once per `grant_id`.
4. No transaction or statement is open while a request streams.
5. The client never gets a 200 unless the upstream answered 2xx.
6. Lease ids are never reused (`AUTOINCREMENT`), so a late settle cannot close another request's lease.
7. Refunding expired leases happens in its own transaction, so a refused request cannot roll it back.

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

Run `php tests/run.php`. Add a test for any change to metering, settling, grants or error handling. Never weaken the invariants above to make a test pass.
