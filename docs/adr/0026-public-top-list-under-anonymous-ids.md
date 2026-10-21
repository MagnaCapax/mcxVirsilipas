# ADR 0026: A public top list of keys under anonymous ids

Date: 2026-10-21
In force since: v0.15.0

## Status
Accepted. Amends ADR 0023 for the top list only; `public-usage` is unchanged.

## Context
ADR 0023 publishes aggregate usage and keeps out which keys exist: no ref and no count of keys. A deployment may also want to show who uses it most, without saying who they are: a leaderboard visitors can watch and the owner of a key can find themselves on, for example to run a game or a reward on it later. That needs a stable public id per key that only the key's owner can link to themselves.

The ledger stores the ref (only a label, ADR 0024) and the sha256 of the key; the key itself is never stored (ADR 0015). The id must therefore be computed from those two, and the owner, who has the key, must be able to compute it too.

## Options considered
- The top list inside `public-usage`: one file, but the file ADR 0023 promises carries no key count would carry one, and any consumer of that file would inherit the change.
- A random id per key, stored in the ledger: unlinkable, but the owner cannot compute it and the ledger gains a column for a display feature.
- A keyed hash with a server secret: the owner cannot compute it.
- A public hash of the ref and the key digest, many rounds: the owner computes it with any SHA-256 tool; without the key nobody can.

## Decision
`bin/virsilipas public-top <ref-prefix> [file]` builds the list (`src/PublicTop.php`) from the same `stats('hour', ['key', 'model'])` rows as `public-usage`, over the keys whose ref starts with the prefix (required, as in ADR 0023). For each window of 24 hours, 7 and 30 days (complete UTC hours, ending at `as_of`) it lists the 10 keys with the most credits used, each as `rank`, `id`, `credits`, `requests` and `credit_share` (of all credits of the prefixed keys in that window), ties broken by requests and then id. The id is SHA-256 applied 1000 times: first to `<ref>:<sha256 hex of the key>`, then to the previous digest in lowercase hex; the output carries this recipe in its `id` field. `:` cannot occur in a ref (ADR 0024), so the input is unambiguous. A removed key has no digest left (its digest field is overwritten), so it is not listed; its credits still count in the window total. The encoded output is refused if it carries any ref as a JSON string or any key digest. With a file it is written like `public-usage`'s: a temporary file, mode 0644, renamed into place.

## Consequences
- A key's owner can find themselves: `k=$(printf %s "$KEY" | sha256sum | cut -d' ' -f1)`, `h=$(printf %s "$REF:$k" | sha256sum | cut -d' ' -f1)`, then 999 times `h=$(printf %s "$h" | sha256sum | cut -d' ' -f1)`. Nobody else can tell whose id it is: the input contains the key's digest, and the key is 48 random hex digits.
- Rotating a key changes its digest and therefore its id, for the whole history; the list follows the current key.
- When fewer than 10 keys used credits in a window, the list is shorter, and its length shows how many did. This is the one place a count of keys is published, by design, and only for the keys with use in that window.
- Credits and requests per anonymous key are public. Per-model use per key is not published.
- From now on: the top list carries no ref, no key digest, no model, no upstream, no cost and no grant (tests/PublicTopTest.php).

## References
src/PublicTop.php; src/Ledger.php (`keyHashes`); bin/virsilipas (`public-top`); tests/PublicTopTest.php; ADR 0015, ADR 0023, ADR 0024; README "CLI".
