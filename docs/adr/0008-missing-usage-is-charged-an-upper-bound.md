# ADR 0008: Missing or malformed usage is charged an upper bound

Date: 2026-10-16
In force since: v0.1.0 (stream refinements in v0.2.1)

## Status
Accepted

## Context
Some upstream answers carry no usage report, a partial one, or one whose numbers do not add up. Reading a missing report as zero would make every such answer free and invite abuse.

## Options considered
- Treat missing usage as zero: free answers.
- Refuse the answer: the client already received it in a stream.
- Charge an upper bound computed from what was actually delivered, capped at the reservation, and flag it.

## Decision
A usage report counts only with three integer counts and a total of at least prompt plus completion. Anything else is charged an upper bound: the prompt bound at the input rate plus the delivered bytes at the output rate, never more than the reservation, and the usage row is flagged `estimated`. In a stream, only a report that advances the total covers the text delivered before it.

## Consequences
- An upstream that drops usage cannot be used to get free answers.
- Estimated rows are visible in `stats` so a misbehaving upstream can be found.

## References
src/Proxy.php (`validUsage`, `charge`); invariant 6; README "Client hangs up mid-stream".
