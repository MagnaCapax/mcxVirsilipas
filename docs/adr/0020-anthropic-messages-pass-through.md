# ADR 0020: The Anthropic Messages format is a metered pass-through

Date: 2026-10-17
In force since: v0.10.0

## Status
Accepted

## Context
Some clients speak only the Anthropic Messages API; Claude Code is the one customers ask for, and it takes only a base URL and a key. Some upstreams serve that format natively at their own URL, next to their chat completions endpoint. The proxy's metering, limits, caps, throttle and failover exist once, for chat completions (ADR 0007, 0011, 0012, 0016), and must not be built a second time.

## Options considered
- Translate Messages requests to chat completions and back: a large, lossy mapping (thinking blocks and their signatures, cache breakpoints, tool use, event names) that would have to follow two formats as they change.
- Relay the format untouched: unpriceable content (images, documents, server tools with per-use fees) and upstream details (model ids, provider fields) would pass, against ADR 0013.
- A metered pass-through to upstreams that serve the format, on the same reservation and settlement path as chat, with this format's allowlists and rebuilt top-level fields.

## Decision
`POST /v1/messages` serves the aliases whose route has an upstream with a `messages_url` (on the upstream, or on a model in the older single-upstream form beside its own `key_file`), on those route entries only, in route order with chat's retries and failover. The key comes as `x-api-key` or a bearer token. Requests keep only allowlisted fields, content block types (text, tool use, tool result, thinking) with their own fields, and client tools; images, documents and server tools are refused, because their cost is not bounded by their bytes or by a token rate. The model's `params` do not apply (they are chat fields). `max_tokens` is clamped as on chat, and a thinking budget that no longer fits under it is lowered to fit. `anthropic-version` and `anthropic-beta` are forwarded. The usage counts map onto the three rates: input and cache writes are fresh input, cache reads cached input, output (thinking included) output. Answers and stream events are rebuilt at their top level (the alias as the model, the standard usage counts, the stop fields) and content blocks pass as sent; the stream starts at the first content block event and ends at `message_stop`. Errors use the format's error shape with the proxy's fixed messages. `POST /v1/messages/count_tokens` answers the proxy's own prompt bound and goes nowhere: a pass-through would need an upstream that serves it, a reservation-free upstream call per estimate, and its own failure handling, for a number the client uses only as a hint, while the bound is never lower than the real count.

## Consequences
- Upstream `usage.estimated_cost` (or `usage.cost`) is retained only as internal cost metadata, with the same numeric bounds and micro-unit as chat. Stream reports with advancing counts adopt their own cost; identical counts may supply missing cost once, without replacing an accepted cost or covering newly delivered bytes. Charges and client usage fields remain count-based.
- Claude Code runs on a customer's key with no translation layer, and every invariant of the chat path (reservation, settlement, caps, throttle, failover, privacy) holds for it, enforced by the same code.
- Cache writes are priced as fresh input; an upstream that charges more for them, or for a beta feature a client switches on, must be priced into the alias's rates (README, "Before enabling a model").
- From now on: invariants 5, 6, 7, 8 and 14 name the Messages route's rules, tested in tests/MessagesTest.php.

## References
src/Messages.php; src/Proxy.php (`messages`, `countTokens`, `serve`, `forward`); src/Config.php (`messages_url`); invariants 5, 6, 7, 8, 14; ADR 0013 (the client contract); docs/API.md "POST /v1/messages".
