# ADR 0016: The throttle slows requests down; it does not block them

Date: 2026-10-16
In force since: v0.5.0

## Status
Accepted

## Context
When the server is busy, or the day's spend is running high, hard limits that refuse requests are a blunt tool. Maintainer decision: slow down when heavily busy or when spending is high, instead of full blockers, with per-key priority weights that work like CPU and IO priority.

## Options considered
- Only hard limits: an outage for everyone at the limit.
- A queueing daemon: a new moving part and new failure modes.
- A small in-request throttle: a weight per key, a short wait when spend is high, and fast shedding of low weights when busy.

## Decision
Each key has a weight: 100 times the square root of its balance in units (`credits_per_unit`), times its loyalty bonus (`1 + bonus_pct/100`, bonus 0 to 300), times `internal_factor` for internal callers, clamped to 1..10,000. Once the last 24 hours' spend passes `pace_start` of `daily_budget`, each request waits in proportion to the pressure and inversely to its weight, at most `max_delay_ms`; nothing is refused for spend. While a source has its `busy` line of requests in flight, keys below `busy_min_weight` get a fast 503 `server_busy` with `Retry-After` instead of a wait. The weight changes no price and no limit. When the throttle cannot decide, the request goes ahead without a wait (ADR 0010).

## Consequences
- Under load, heavy prepaid and loyal users keep working while the rest slow down.
- Pacing is soft by design; the hard ceiling on spend is the server-wide cap (ADR 0012).

## References
src/Throttle.php; src/Proxy.php (`admission`); invariant 15; README "Priority and throttling".
