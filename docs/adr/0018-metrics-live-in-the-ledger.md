# ADR 0018: Performance and cost metrics live in the ledger

Date: 2026-10-16
In force since: v0.8.0 (schema 8)

## Status
Accepted

## Context
The people running the proxy want to watch it: request rate, latency, time to first byte, tokens, credits, upstream cost, failures and retries, live and over time. The ledger's usage table already holds one row per request with most of these numbers, written in the same transaction that settles the charge.

## Options considered
- A column store or time-series database beside the proxy: fast over billions of rows, but a second server to run, secure and back up, for a few requests a second (ADR 0003).
- A round-robin database: fixed size, but it averages old data away, so exact totals per key, day or month cannot be read back, and the billing figures already come exactly from the ledger.
- A metrics HTTP endpoint for an external scraper: a new endpoint (ADR 0013) and an external stack to read it.
- Keep the per-request row in the ledger, add the missing measures to it, and read views from it with the CLI.

## Decision
Metrics are columns of the usage row and nothing else. Schema 8 adds `first_byte_ms` (start of the upstream request to the first byte the client received) and `attempts` (upstream requests made, retries and failover included), and an index on `usage(key_id, ts)`. The CLI reads them: `stats` aggregates by time bucket and group, `keys` lists the keys, and `top` is a live terminal view of the last minute, 5 minutes and hour. A metric is a count, a duration, a status, a cost or a reference; never a prompt, an answer, a header or a client address (ADR 0014). No metrics endpoint, no second store.

## Consequences
- Metrics, billing and audit read the same rows, so they cannot disagree.
- `top` streams at most the last hour's rows (capped at 50,000 and marked when capped) into running totals: measured at the cap, about 0.1 s and 10 MB per view. A much busier deployment would need an aggregate table, added by a schema change.
- Rows written before schema 8 have no first-byte time and 0 attempts.
- Graphs and dashboards are built outside the proxy from `stats` output.

## References
src/Ledger.php (`settle`, `stats`, `recentUsage`, `inFlight`, `keys`); src/Top.php; src/Proxy.php (first byte, attempts); bin/virsilipas; tests/TopTest.php; ADR 0003, 0004, 0013, 0014; README "Usage over time" and "CLI".
