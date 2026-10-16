# ADR 0005: Credits, three rates, one rounding

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
Customers buy a balance and spend it on models with different prices for fresh input, cached input and output. The unit has to be exact, independent of any currency, and impossible to round in the operator's or the customer's favour by accident.

## Options considered
- Money amounts in a currency: ties the ledger to one currency and to exchange rates.
- Raw tokens: ignores that models and token kinds cost differently.
- An integer credit with per-model rates.

## Decision
A credit is an abstract integer unit whose worth the operator decides. Each alias has three integer rates in credits per million tokens: `input`, `cached_input` and `output`, with `cached_input` never above `input`. A request costs the sum of its counts times the rates, divided by one million and rounded up once, by `Credits::of`. The rates in force when a request starts price both its reservation and its charge.

## Consequences
- Every amount is exact and comes from one function.
- Pricing the whole prompt at the input rate always bounds the charge, which the reservation relies on (ADR 0007).

## References
src/Credits.php; README "Credits"; invariant 11.
