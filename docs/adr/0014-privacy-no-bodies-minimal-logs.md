# ADR 0014: Privacy: bodies are never stored or logged

Date: 2026-10-16
In force since: v0.1.0

## Status
Accepted

## Context
Prompts and answers are the customer's data. A proxy is a natural place to collect them, and anything collected can leak, be subpoenaed or be misused.

## Options considered
- Log bodies for debugging and support: the largest privacy risk the proxy could carry.
- Log bodies only on errors: errors are exactly when unusual data appears.
- Keep counts and events only.

## Decision
Request and response bodies are never stored or logged, including on error paths. Usage rows hold counts, credits, the alias, the upstream, status, duration and timestamps. Log records hold event names, counts, statuses and billing references only: never bodies, prompts, keys, the admin token, upstream error text, request paths, exception messages or client addresses. Logs are one JSONL file per UTC day with private permissions, and a failed log write never fails a request. The web server's own access log is outside the proxy; the README tells deployers to disable it or drop the client address.

## Consequences
- Support works from counts, statuses and the request id, never from content.
- Debugging a bad answer needs the client's own copy.

## References
AGENTS.md (Privacy rule); src/Log.php; invariant 10; README "Privacy" and "Logging".
