# ADR 0006: A prepaid, never-expiring balance; billing pushes grants

Date: 2026-10-16
In force since: v0.1.0 (grant `expires_at` stored, not enforced, since v0.9.0)

## Status
Accepted

Extended by [ADR 0028](0028-explicit-daily-target-grants.md): an administrator may explicitly push one CLI target grant per UTC day; ordinary prepaid balances still never reset automatically.

## Context
Taking payments is a separate, regulated job with its own systems. The proxy has to know how much a user may spend, but it should not sell anything.

## Options considered
- A purchase flow, wallet or credit packs inside the proxy: duplicates the billing system and puts payment logic next to the request path.
- Credits that expire, or monthly allowances that reset: punish light users and add time-based state.
- One balance per user that only a trusted billing system changes.

## Decision
Each user (`ref`) has one prepaid balance that never expires. Requests spend from it; only grants add to it (or, negative, take from it), pushed by the billing system through `POST /admin/grants` or the CLI. A grant applies once per `grant_id`; a retry is a no-op and a reused id with another ref or amount is a conflict. Negative grants remove credits for refunds, and may take a balance below zero, after which requests answer 402 until a grant brings it back. The proxy never refills a balance by itself and has no purchase flow.

Each grant records when it was added (`created_at`) and may carry an `expires_at` (unix seconds; absent or null for never). The expiry is stored for a future expiry policy and is NOT enforced: nothing changes a balance when it passes, and the balance still never expires. It is part of the grant's identity, so a retry must repeat it and the same `grant_id` with another `expires_at` is a conflict. Enforcing it would need its own decision (how an expired remainder leaves the balance, in which order grants are spent) and its own ADR.

## Consequences
- Billing retries are safe; renewals need a unique `grant_id` per period.
- Subscriptions and promotions are billing's business: they become grants.
- An expiry policy can be added later without a schema change or lost history: every grant since v0.9.0 has its date and its intended expiry.

## References
src/Admin.php (`grant`); src/Ledger.php (`grant`, `grants`); invariant 3; tests/KeyLifecycleTest.php; docs/API.md "POST /admin/grants".
