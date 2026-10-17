# ADR 0019: A key reads its own usage, and nothing else

Date: 2026-10-17
In force since: v0.9.0

## Status
Accepted

## Context
Customers want to see where their credits went: per day, per model, and which grants added to the balance. `GET /v1/balance` shows only the balance, holds and limits. The ledger already holds every number needed (usage rows and grants), and `Ledger::stats()` already sums usage rows per day and per model for the operator. Many customers share one proxy, so a usage view must never show anything about another key, not even that it exists.

## Options considered
- Leave it to the billing system: it does not see consumption, only the grants it pushed.
- An admin endpoint that billing relays to customers: another moving part, and billing would have to render consumption it does not own.
- A client endpoint authenticated by the customer's own key, scoped in SQL to that key's id.

## Decision
`GET /v1/usage?days=N` (default 30, 1 to 400) authenticates exactly like `/v1/balance` and returns the caller's balance, its usage over the last N UTC days (today included) as one row per day and one per model alias (requests, prompt, cached, completion and reasoning tokens, credits charged), and its newest 200 grants (`grant_id`, `credits`, `created_at`, `expires_at`). Every query is filtered by the authenticated key's id (`Ledger::stats()` with a key id, `Ledger::grants()`); nothing in the request can name another key. The response carries no ref, no upstream, no upstream cost and no limit beyond what `/v1/balance` already shows.

## Consequences
- Customers can check their own spending without the operator or billing.
- A key filter on `stats()` reuses the operator's query instead of a second one that could drift.
- From now on: a client endpoint reads only the authenticated key's rows (tests/KeyLifecycleTest.php, two keys).

## References
src/Proxy.php (`usage`); src/Ledger.php (`stats`, `grants`); ADR 0013 (the client contract); ADR 0014 (privacy); invariant 8; docs/API.md "GET /v1/usage".
