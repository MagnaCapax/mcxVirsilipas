# ADR 0011: No retry or failover after an attempt that may have produced output

Date: 2026-10-16
In force since: v0.4.0

## Status
Accepted

## Context
Upstreams fail, and retrying or moving to another upstream improves availability. But an upstream that already generated an answer has billed for it, whether or not the answer arrived.

## Options considered
- Retry every failure: pays twice for answers that timed out mid-stream.
- Never retry: gives up availability for failures that cost nothing.
- Retry only when the failed attempt cannot have generated output.

## Decision
A request is retried on the same upstream, or moved to the next one in its route, only before anything has been relayed and only after an attempt that cannot have produced output: the connection failed before sending, or the upstream refused the request (408, 409, 429, 502, 503, 504, and for failover also 401 to 404 and other 5xx). After a 2xx status, or a sent request that timed out or broke, the request ends. A request the upstream rejected as invalid ends too, since every upstream would reject it. All attempts share one reservation and one deadline, and only the delivered answer is charged.

## Consequences
- The proxy never pays twice for one answer.
- A mid-stream upstream failure reaches the client as an in-band error, not a silent retry.

## References
src/Proxy.php (`outcome`, `failure`, the attempt loop in `chat`); invariant 5; docs/API.md "Errors".
