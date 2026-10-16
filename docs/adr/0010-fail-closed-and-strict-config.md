# ADR 0010: Fail closed; the configuration refuses what it does not know

Date: 2026-10-16
In force since: v0.1.0 (unknown settings refused since v0.7.1)

## Status
Accepted

## Context
When the proxy is unsure whether a request is paid for, guessing in the customer's favour gives away upstream spend, and guessing silently in the operator's favour charges people wrongly. A misspelt setting is the same kind of doubt: before v0.7.1 a typo such as `cap` for `caps` was ignored, so the limit it meant to set did not exist.

## Options considered
- Fail open (serve when unsure, reconcile later): unbounded loss on any ledger or config fault.
- Fail closed everywhere, the throttle included: a throttle bug would become an outage.
- Fail closed on everything that authorizes or charges; let the throttle, which authorizes nothing, fail open.

## Decision
Any doubt about the key, the balance, the database or the configuration refuses the request without charging it: a missing or unknown key answers 401, a disabled one 403, a short balance 402, and a configuration or ledger that cannot be loaded or read answers 500. The configuration refuses any setting it does not know, at every level, and any invalid value, naming it; `tools/deploy.php` checks the new release against the live configuration before stopping anything (ADR 0017). The one exception is the throttle (ADR 0016): it only slows requests down, so when it cannot decide, the request goes ahead unslowed and the hard limits still decide.

## Consequences
- A fault becomes an outage, never a giveaway or an overcharge.
- New settings must be added to the config schema before use.

## References
AGENTS.md (Fail closed rule); src/Config.php (`known`); public/index.php (500 path); src/Proxy.php (`admission`).
