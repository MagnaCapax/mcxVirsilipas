# ADR 0024: Keys can expire and carry their ref

Date: 2026-10-18
In force since: v0.13.0

## Status
Accepted

## Context
Until now a key worked until it was disabled, rotated or removed (ADR 0015). Some keys are meant to work only for a while: a trial, a key handed out for an event, a key for a contractor. The operator then has to remember to disable it, and a forgotten key stays open. A second need: an owner of several keys cannot tell from the key itself which ref it belongs to, and the key, the only thing the client holds, says nothing about it.

A key's expiry is not credit expiry. The balance never expires (ADR 0006); a grant's `expires_at` is stored and not enforced, and a key with a zero balance still authenticates (its requests are refused 402, but `/v1/models`, `/v1/balance` and `/v1/usage` answer).

## Options considered
- Expire keys by a cron job that disables them when their time has passed: fails open, since a cron that does not run, runs late or fails leaves the key working.
- Treat credit expiry as key expiry: grant expiry is not enforced, and a zero balance still authenticates, so it would not stop the key.
- Encode the expiry in the key (a signed time inside the token): the key could then never be extended without handing out a new one, and the proxy would need a signing secret.
- Store the expiry as a column of the key and check it wherever the key is used.

## Decision
A key may carry `expires_at`, an absolute UTC time in unix seconds (NULL: never), set when it is created (`bin/virsilipas key-create <ref> --expires=<when>`, either `+N` minutes, hours or days or a time `YYYY-MM-DDTHH:MM:SSZ`; it must be in the future). It is checked at authentication (`Proxy::authenticate`, every client route) and again inside the reservation transaction (`Ledger::reserve`), so a request that authenticated just before the expiry and reserves just after it is refused too. Once `expires_at <= now`, the key answers 403 `key_expired` (`permission_error`, after the disabled-key check). A request already running when the key expires finishes and is settled as usual: cutting it off would charge for an answer not delivered (ADR 0011). Existing keys get NULL on the upgrade to schema 11 and never expire.

A named key (`key-create <ref> --named`) is `vsk-<ref>-` followed by the same 48 hex digits of random as a plain key `vsk-<48 hex>`, so it is exactly as hard to guess; the ref is only a label and is never trusted from the key (the lookup is by the key's sha256, as for every key). The ref of a named key must be 1 to 64 of `A-Z a-z 0-9 _ -`: ASCII only, because bearer tokens are ASCII, and no `#` or `:`, because `#` starts a comment in many config files and a key cut there fails in a confusing way. At most 64 keeps the whole key within what PMSS accepts as a key, `vsk-` and 16 to 128 of the same characters (64 + 1 + 48 = 113). Anything else is refused with 400, and nothing is created.

Rotation gives a plain key and keeps `expires_at`: rotating a leaked key must not extend its life. `POST /admin/keys` is unchanged: it makes plain keys that never expire, and the admin API gains no parameter.

The cron option was not chosen because it fails open, credit expiry because it does not stop a key, and an expiry inside the key because it cannot be extended.

## Consequences
- A key meant to work for a while stops on time with nothing having to run: the check is part of every authentication and reservation, so it cannot fail open the way a missed cron job would (ADR 0010).
- An owner can tell named keys apart by looking at them. The ref becomes visible to anyone who sees a named key; refs that must stay private use plain keys.
- No command changes an expiry yet: an expired ref keeps its balance and history (ADR 0006, 0009), and rotating it does not revive it. Extending an expiry is a column update a later change can add without touching any key.
- `keys` and `status` show `expires_at`; the admin route's `key_create` log record carries `expires_at` and `named` (always null and false there), never the key (invariant 10).
- From now on: tests/KeyExpiryTest.php protects the expiry on every client route and at reserve, the boundary (`expires_at == now` refused), the named key shape and its ref rule, rotation, and the 10 to 11 upgrade; tests/SecretGuardTest.php catches a named key committed to the tree.

## References
src/Ledger.php (`createKey`, `rotateKey`, `findKey`, `reserve`, `keys`, `status`); src/Proxy.php (`authenticate`); src/KeyExpiry.php; bin/virsilipas (`key-create`); src/Admin.php; tests/KeyExpiryTest.php, tests/SecretGuardTest.php, tests/fixtures/schema-v10.sql; ADR 0006, 0010, 0011, 0015; AGENTS.md invariant 10; CHANGELOG v0.13.0; README "CLI" and "Troubleshooting"; docs/API.md "Errors".
