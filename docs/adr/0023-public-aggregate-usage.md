# ADR 0023: Public aggregate usage is an operator command, never a route

Date: 2026-10-18
In force since: v0.12.0

## Status
Accepted

## Context
A deployment may want to publish how much its proxy is used: credits, requests and tokens per window, per day, per hour and per model, as a status page or an open data feed. The ledger holds every number (`Ledger::stats()`), but the client endpoints read only the caller's own key (ADR 0019), and the admin routes are for billing, behind a token. Publishing anything about many keys has to keep three things out: which keys exist (a ref, a count of keys or customers), what the deployment pays (upstreams, upstream cost, grants) and the requests themselves (ADR 0014). Which keys count (for example only customer keys, not the operator's test keys) is the deployment's choice, and ADR 0002 keeps that choice out of the repository.

## Options considered
- A public HTTP route served by the proxy: a new unauthenticated surface on the metered service, with its own caching and load to bound, and an answer computed per request.
- An admin route that a publisher polls: a token on a machine that only needs aggregates, and still a route to keep off the internet.
- A CLI command the operator runs (for example hourly from cron) that writes a file; the web server serves the file as a static asset.

## Decision
`bin/virsilipas public-usage <ref-prefix> [file]` builds the aggregate (`src/PublicUsage.php`) from `stats('hour', ['key', 'model'])` over the keys whose ref starts with the prefix. The prefix is required: an empty one would publish every key, test keys included, without anyone having chosen that. The output is rebuilt from fixed fields, never copied from a row: windows of 24 hours, 7 and 30 days (credits, requests, the four token counts, the share of 2xx answers, the share of retried requests, the mean time to first byte), every model over 30 days with its credits, requests, tokens, shares and first-byte time, the last 30 UTC days with each day's top 5 models, every hour of the last 7 days split into the 5 largest models and the rest, and a weekday by hour heatmap over 30 days. Windows end at the end of the last complete UTC hour, so two runs in one hour agree. A model name outside `[A-Za-z0-9][A-Za-z0-9._/-]{0,79}` is published as `other`. The encoded output is refused if it carries any key's ref as a JSON string. With a file the output is written to a temporary file next to it, made readable by everyone (0644, against the CLI's private umask) and renamed into place, so a reader never sees half a file.

A route was not chosen: a file costs nothing per reader and adds no surface to the proxy. An admin route was not chosen: the publisher would hold a token it does not need.

## Consequences
- A deployment can publish usage without a new route, token or process, and choose whose usage counts.
- Model names are published as configured (aliases or upstream model names), so a deployment that wants them private names its aliases neutrally. Requests refused before a reservation (no credit, a limit, an unknown model) have no usage row and are not counted.
- From now on: the public usage carries no ref, no count of keys, no upstream, no cost and no grant (tests/PublicUsageTest.php); ADR 0019 is unchanged, as no client endpoint reads another key.

- v0.15.0 (2026-10-21): the models are also listed over the last 24 hours and 7 days (`models_24h`, `models_7d`, the same shape as `models_30d`). The top list of keys is a separate command and file under ADR 0026.

## References
src/PublicUsage.php; bin/virsilipas (`public-usage`); tests/PublicUsageTest.php; ADR 0002, ADR 0014, ADR 0018 (metrics in the ledger), ADR 0019; README "CLI".
- v0.16.0 (2026-10-22): rolling sums per window (`rolling_24h`, `rolling_7d`, `rolling_30d`) and heatmaps for the last 24 hours and 7 days (`heatmap_24h`, `heatmap_7d`). Both are totals over the same customer keys, so the rules above are unchanged.
