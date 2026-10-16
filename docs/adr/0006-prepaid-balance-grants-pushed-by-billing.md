# ADR 0006: A prepaid, never-expiring balance; billing pushes grants

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
Taking payments is a separate, regulated job with its own systems. The proxy has to know how much a user may spend, but it should not sell anything.

## Options considered
- A purchase flow, wallet or credit packs inside the proxy: duplicates the billing system and puts payment logic next to the request path.
- Credits that expire, or monthly allowances that reset: punish light users and add time-based state.
- One balance per user that only a trusted billing system changes.

## Decision
Each user (`ref`) has one prepaid balance that never expires. Only grants change it, pushed by the billing system through `POST /admin/grants` or the CLI. A grant applies once per `grant_id`; a retry is a no-op and a reused id with another ref or amount is a conflict. Negative grants remove credits for refunds, and may take a balance below zero, after which requests answer 402 until a grant brings it back. The proxy never refills a balance by itself and has no purchase flow.

## Consequences
- Billing retries are safe; renewals need a unique `grant_id` per period.
- Subscriptions and promotions are billing's business: they become grants.

## References
src/Admin.php (`grant`); src/Ledger.php (`grant`); invariant 3; docs/API.md "POST /admin/grants".
