# ADR 0025: Support tickets with a key are opened by the billing integration, not the proxy

Date: 2026-10-20
In force since: this change (no proxy code changes)

## Status
Accepted

## Context
Customers use their key from agent harnesses and scripts. They should be able to open a support ticket from there
without a browser login. Billing is the most sensitive system of a deployment: nothing below it may hold a credential
that reaches into it or change its state, and opening a ticket must not let anyone read account data or change a
service. The proxy knows keys only by their sha256 (ADR 0015) and never stores request or response bodies (ADR 0014).

## Options considered
- The proxy takes the ticket and billing pulls it later: the proxy would store message bodies, which ADR 0014
  forbids, and it needs a queue, admin list and acknowledge routes, a pull job and asynchronous ticket numbers.
- A separate sealed intake beside the proxy, pulled by an operations host that opens the ticket through billing's
  admin API: more hops, and the ticket would be opened by staff on the customer's behalf instead of by the customer.
- Billing serves one narrow endpoint itself: it already escrows each account's key and already serves customer
  endpoints authenticated by a customer credential.

## Decision
The billing integration serves the endpoint. It authenticates the key against a PBKDF2-HMAC-SHA256 verifier it keeps
for every escrowed key, requires an email of the account owner or of a user allowed to open tickets, asks the proxy
whether the key is still valid (`GET /v1/usage` with that key, ADR 0019), and opens exactly one ticket for that
account. The proxy gains no route and stores nothing new. This repository documents the client contract in
[docs/SUPPORT-TICKETS.md](../SUPPORT-TICKETS.md) so harnesses can implement it without naming a deployment (ADR 0002).

## Consequences
- The proxy stays free of message bodies and of any billing credential; its threat surface is unchanged.
- The ticket channel is weaker than a browser login (a key can sit on a server), so tickets opened this way carry a
  fixed line saying so, and support asks for a client-area login before any account change requested in them.
- A key that is rotated, disabled, expired or removed at the proxy stops opening tickets at once, through the live check.

## References
docs/SUPPORT-TICKETS.md; ADR 0002, 0014, 0015, 0019; AGENTS.md "Provider-agnostic" and "Fail closed".
