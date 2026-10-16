# ADR 0009: The append-only credit log is the audit trail

Date: 2026-10-16
In force since: v0.1.0 (schema 3)

## Status
Accepted

## Context
A balance is a single number. When a customer asks where their credits went, or a bug is suspected, a number alone proves nothing.

## Options considered
- Recompute balances from usage rows: misses grants, refunds and expired leases.
- Application-level history that code may rewrite: an auditor cannot trust it.
- One row per balance change, written in the same transaction and protected by the database.

## Decision
Every balance change writes one `credit_log` row in the same transaction: the delta, the balance after, the kind (grant, reserve, refund, opening) and the cause (the grant id or the lease). A zero change writes nothing. Triggers refuse UPDATE and DELETE on the table. `bin/virsilipas verify` checks that each key's deltas sum to its balance and that every `balance_after` is the running sum. A schema upgrade carries existing balances over as `opening` rows.

## Consequences
- Any balance can be explained line by line (`bin/virsilipas history`).
- The table only grows; it is small (one row per request and grant) and kept for good.

## References
src/Ledger.php (`logCredit`, `verify`, schema triggers); invariant 13.
