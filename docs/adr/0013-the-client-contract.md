# ADR 0013: The client contract: an OpenAI-compatible subset with aliases and allowlists

Date: 2026-10-16
In force since: v0.1.0 (routes with failover since v0.4.0)

## Status
Accepted

## Context
Clients should work with existing OpenAI-compatible tools. At the same time the proxy must not pass anything upstream it cannot price, nor return anything that discloses the upstream behind it.

## Options considered
- A transparent pass-through: unpriceable inputs, and upstream details leak to clients.
- A new API of our own: no existing client works with it.
- A small OpenAI-compatible subset, rebuilt on both sides from allowlists.

## Decision
The client API is chat completions (streaming or not), the model list and the balance. Clients name aliases; the deployment maps each alias to a route of upstream models, and clients never see upstream model ids or URLs. Only allowlisted request fields and text content go upstream; images, audio and files are refused because their cost cannot be bounded. Responses are rebuilt from allowlisted fields, each of its expected type. Errors use OpenAI's error shape with the proxy's own fixed messages and stable codes; from an upstream error the proxy uses its status and `Retry-After`, and from its body only the code `context_length_exceeded`. Other endpoints (such as embeddings or a responses API) are out of scope until a deployment needs one, and adding one needs its own ADR. docs/API.md is the reference.

## Consequences
- Existing clients work by changing the base URL and key.
- Upstream changes (models, providers) never reach clients.

## References
public/index.php (routes); src/Proxy.php (`normalizeMessages`, `rewrite`, `publicView`, `failure`); invariants 7 and 8; docs/API.md.
