# ADR 0007: Reserve, then settle once

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
The cost of a request is known only after the upstream answers, and a stream may run for minutes. Charging afterwards alone would let a key spend more than its balance with parallel requests.

## Options considered
- Charge after the answer: overspend under concurrency.
- Pre-charge a flat fee: wrong for every request.
- Reserve an upper bound before forwarding, settle the real cost afterwards.

## Decision
Before forwarding, the proxy reserves, in one atomic transaction, the prompt bound (the byte length of everything forwarded, since a token is at least one byte) at the input rate plus `max_tokens` at the output rate, lowering `max_tokens` to what the balance covers. The reservation is a lease. When the response ends the lease is settled exactly once: the reported usage is charged, never more than the reservation, and the rest is returned. A lease whose worker died expires and stays charged at its reservation; a late settle returns the difference once. Tracking fields (upstream cost, reasoning count, source, group) never change a charge.

## Consequences
- A key can never spend past its balance, whatever the concurrency.
- A long prompt temporarily holds about four times its token count in credits (see README "How metering works").

## References
src/Ledger.php (`reserve`, `settle`); src/Proxy.php (`chat`, `promptBound`); invariants 1, 2, 9 and 12.
