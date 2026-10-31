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

## Consequences
- This is a net entitlement target, not an immediately available amount while reservations are outstanding. Late settlement can only reduce the reset entitlement by the actual charge.
- The proxy still never refills by itself; external scheduling, authorization and budgets belong to the deployment. A target push does not enable a disabled key or change a key's expiry.
- Administrators must not combine this mode with independent additive grants if they want a target to act as their allowance ceiling. This operation is not a per-day spend limit.

## References
`src/Ledger.php` (`grantDailyTarget`, `grant`, `migrate`); `bin/virsilipas`; `tests/DailyTargetTest.php`; ADRs 0004, 0006, 0007, 0009; invariants 1–4 and 13.
