# ADR 0012: Per-key limits and server-wide caps refuse; they never shorten

Date: 2026-10-16
In force since: v0.1.0 (per-key limits); v0.7.0 (server-wide caps)

## Status
Accepted

## Context
One key must not use up a shared upstream, and the server as a whole must not run up an upstream bill beyond what its operator set. A limit can either refuse a request or let it through with a smaller budget.

## Options considered
- Shorten a request to fit the remaining cap: the customer pays for an answer cut short by the server's own budget.
- Enforce caps outside the reservation: races between parallel requests overshoot.
- Check every limit and cap inside the reservation transaction and refuse what does not fit.

## Decision
Per-key limits (`max_concurrent`, `requests_per_minute`, `credits_per_minute`) and server-wide caps (spend per hour, day and month, and the server's requests per minute) are checked inside the reservation transaction. A request that does not fit is refused, with `Retry-After`, and reserves nothing; it is never shortened. The caps bind every caller, internal ones included. A spend cap is checked against the upper-bound cost of the new request plus the settled cost and the bounds of requests still running in the window, so it can be set only when every route entry has a `cost`; a cap of 0 admits nothing. The balance is different: when it cannot cover `max_tokens`, `max_tokens` is lowered to what it covers (ADR 0007), because that is the customer's own money, not the server's budget.

## Consequences
- An overloaded or over-budget server says so and recovers by itself as windows pass.
- Configuring caps requires prices on every route entry.

## References
src/Ledger.php (`reserve`); invariants 12 and 17; README "Caps".
