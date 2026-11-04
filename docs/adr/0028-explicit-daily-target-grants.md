# ADR 0028: Explicit administrative daily target grants

Date: 2026-10-31
In force since: this change (schema 12)

## Status
Accepted; extends ADR 0006 for an explicit CLI operation only.

## Context
An administrator may need to replenish a service allowance without carrying unused credit forward. Reading a balance and then issuing an additive grant races reservations and settlement. A repeated job must not replenish credit spent since its first run.

## Options considered
- An additive grant each day: unused credit accumulates.
- Read then grant the difference: races usage, and retries compute a different amount.
- A target grant in the existing ledger transaction, keyed to the server's UTC day.

## Decision
`grant <ref> <credits> --daily-target` explicitly sets the key's net entitlement: available balance plus all unsettled reservations, including expired holds. It computes the delta inside `BEGIN IMMEDIATE`, records it as a grant and writes its credit-log row atomically (invariants 3 and 13). A zero delta records the daily operation but no credit-log row. Negative available balances are possible when outstanding reservations exceed the target; settlement still returns unused reservations once (invariant 2).

The server derives the UTC day after acquiring the transaction. One target operation is allowed per key per day, enforced by a unique index. The same target retries without changing the balance, even after intervening usage; another target that day conflicts. The CLI accepts no date, grant id or expiry in this mode, so a caller cannot obtain repeated refills by supplying a new id or a missed date. Missed days never accumulate. Existing maximum grant and balance bounds still apply.

Schema 12 adds nullable target and day columns to grants; existing additive grants retain both NULL. Their CLI/API arguments, responses and retry behavior stay unchanged. A target grant cannot be replayed as an additive grant. No new HTTP route is introduced.

Amended 2026-11-04: holds an hour past their expiry are final and no longer counted. The maintainer asked for "a cron to reset to [a fixed amount of] available credits per day" (2026-10-31; the amount is the deployment's own and stays out of the repository, ADR 0002). Counting every unsettled reservation did not do that. A held lease (its worker died: a crash, or a deploy that cut the request) is never settled and never removed, so its reservation was subtracted from every later day's target, and each new one shrank all the days after it for good. Options weighed: settle such holds as fully charged when the target runs (no balance change, but it would drop their cost bounds from the spend caps' in-flight sum and from `status`, so a month cap would admit spend that may already have been incurred upstream); leave them out of the target only (a settle that came after all would then return credits above the target); or one bound used in both places. The decision is the third. `HELD_FINAL_SECONDS` (one hour) after its `expires_at`, a lease is final at its reservation: `settle()` refuses it (returns false, writes no refund and no usage row) and the target sum leaves it out. Both read the clock inside their `BEGIN IMMEDIATE`, with the same strict comparison, so a lease a target left out can never be settled later; one the target counted either settles (returning only its unused part) or turns final (returning nothing). Balance plus counted reservations therefore never rises above the day's target, and a final reservation is never refunded. The bound: upstream time ends at `upstream_timeout`, which `lease_seconds` must exceed, after a pacing wait of at most 60 seconds; the rest of a worker's life (writing to its client, the settle's 10-second lock wait) ends with the web server's timeouts (README: `request_terminate_timeout` above `upstream_timeout`). An hour is far past all of them (any bound keeps the invariant; a shorter one means a hold costs at most the next target pushed within the hour after it expires). A forward clock jump can make a live lease final early, which keeps its full reservation charged (fails closed). Should a worker outlive it anyway, its request stays charged at its reservation, which is what an expired lease already means (invariant 2), and its cost bound still counts against the spend caps. The leases table and `status` are unchanged: a final lease stays unsettled and is still shown as `held`. No schema change.

## Consequences
- This is a net entitlement target, not an immediately available amount while reservations are outstanding. Late settlement can only reduce the reset entitlement by the actual charge.
- The proxy still never refills by itself; external scheduling, authorization and budgets belong to the deployment. A target push does not enable a disabled key or change a key's expiry.
- A held lease takes its reservation from at most the targets pushed within an hour of its expiry; after that it is final and later days start at the full target.
- Administrators must not combine this mode with independent additive grants if they want a target to act as their allowance ceiling. This operation is not a per-day spend limit.

## References
`src/Ledger.php` (`grantDailyTarget`, `grant`, `migrate`, `settle`, `HELD_FINAL_SECONDS`); `bin/virsilipas`; `tests/DailyTargetTest.php`; ADRs 0004, 0006, 0007, 0009; invariants 1–4 and 13.
