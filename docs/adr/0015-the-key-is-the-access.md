# ADR 0015: The key is the access

Date: 2026-10-16
In force since: v0.1.0 (the internal-source signal since v0.5.0)

## Status
Accepted

## Context
Customers call the proxy from home connections, servers and agents whose addresses change. Address-based rules lock out legitimate users and add support work, while a leaked key is just as dangerous from an allowed address. Maintainer decision: outside access is not blocked; the key is the access.

## Options considered
- Per-key address allowlists: brittle for dynamic addresses and shared NAT, and they create support load.
- Blocking whole regions or networks in the proxy: same problems, applied to everyone.
- Authorize by key alone; leave reachability to the firewall.

## Decision
The proxy authorizes every client request by its key and never by the client's address. Which networks can reach it at all is a firewall decision for the deployer. The web server may mark a caller as internal (the `VIRSILIPAS_SOURCE` server variable, set from the client address and never from anything the client sends); that only selects the caller's priority factor and busy pool (ADR 0016). It never grants access and never lifts a limit or cap (ADR 0012).

## Consequences
- A leaked key is handled by rotating it (`POST /admin/keys/rotate`), not by address rules.
- Running the proxy only for one network is a firewall setting, not a code change.

## References
src/Proxy.php (`authenticate`, `internalCaller`); invariants 16 and 17; README "Access control".
