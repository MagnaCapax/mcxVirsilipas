# ADR 0027: The client is released once its request has settled

Date: 2026-10-24
In force since: v0.17.0

## Status
Accepted. Changes when the client's connection closes; ADR 0007's settle rules and every limit are unchanged.

## Context
The proxy sends no Content-Length, for a stream or for a plain answer, so the client sees the end of a response only when the PHP script ends. After the answer is written the script charges and settles the lease, writes the request log record and checks the spend caps against their 80% and 100% lines; until v0.16.1 the client waited for all of it. Settling takes the ledger's write lock, which a writer can wait up to 10 seconds for (busy_timeout); the log write and the cap check (a read per spend window) do not.

## Options considered
- Keep the order: the client also waits for the log write and the cap check, which are not its business.
- Release the client before the settle (tried in the v0.17.0 work and rejected on its release acceptance run, php-fpm and nginx on a disposable machine): under a held write lock the client closed at about 2 s instead of 5.7 s, but the still-open lease kept counting against the key's limits. A key with `max_concurrent` 1 was refused 429 for the request it had just finished in 3 of 120 back-to-back requests (0 on v0.16.1). Limiting the early release to keys with concurrency room fixed that, but with a limit of 2 the client was released late in 4 of 15 runs under a held lock, and one next request failed with 500 after waiting more than 10 s for the write lock (0 of 10 on v0.16.1). An open lease also counts its whole reservation against `credits_per_minute` until it settles. Each patch left another limit or contention behaviour changed.
- Settle in a queue processed later: the balance would lag behind spending, so a key could spend past its balance with parallel requests (ADR 0007 forbids this).
- Send a Content-Length: only possible for plain answers; streams cannot have one.
- Settle, then release the client, then log and check the caps: the client waits for what decides its balance and limits, and nothing else.

## Decision
In `Proxy::serve()`, after the answer or the proxy's error has been written and the lease is settled, the proxy calls `fastcgi_finish_request()` when the server API has it (php-fpm). The request log record and the cap check follow, in the same worker. On an exception the error response is written by `public/index.php` after `serve()` returns, so the release is not made then. Under other server APIs (the PHP built-in server used by the tests, CLI) the call does not exist and nothing changes. The request log record gains `settle_ms`: the milliseconds the charge and settle took after the response was written, which the client still waits for.

## Consequences
- The client no longer waits for the request log write or the cap check. Its limits, balance and credit log are exactly as in v0.16.1 when it sends its next request.
- The client still waits for the settle, and so for the write lock under contention. `settle_ms` measures that wait in production; releasing before the settle would need its own answer for every limit that counts open leases.
- From now on: the release is called in one place, after the settle and before the request log and the cap check, and only once the response was written (tests/ProxyTest.php); the request log carries `settle_ms` (tests/IntegrationTest.php). The tests run under the PHP built-in server, where the call does not exist; the release itself is checked on php-fpm when a release is accepted.

## References
src/Proxy.php (`serve`, `logRequest`, `logCapCrossing`); public/index.php; src/Ledger.php (`reserve`, `settle`); ADR 0007, ADR 0012, ADR 0013, ADR 0014; README "How metering works", "Logging".
