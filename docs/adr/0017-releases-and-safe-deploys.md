# ADR 0017: Releases are tags; a deploy proves the new release before stopping the old one

Date: 2026-10-16
In force since: v0.1.0 (the configuration pre-check since v0.7.1)

## Status
Accepted

## Context
A deploy touches the live ledger. A failed upgrade must never leave the proxy down or the ledger half-migrated, and what runs must be identifiable.

## Options considered
- Deploy from a working tree: no record of what runs, and local changes ride along.
- Upgrade in place without a backup: an interrupted migration loses money data.
- Tagged releases, unpacked side by side, switched by symlink with backup and rollback.

## Decision
A release is a git tag `vX.Y.Z` with a CHANGELOG entry that says whether the schema changes. `tools/deploy.php` unpacks the tag from a git mirror into its own directory, lints it, and checks that the new release accepts the live configuration before anything stops; if it does not, the old release keeps serving. It then drains requests, stops php-fpm, backs up the ledger and checks the backup, runs `init` as the PHP user, switches the symlink, runs `verify`, starts php-fpm and checks that a keyless request answers 401. A failure after the stop puts the previous release back, restores the backup when nothing but the migration has written to the ledger since, and starts php-fpm again; an older release is never run against a newer ledger.

## Consequences
- Every running release is a known tag; a bad release is a one-step rollback.
- Documentation-only commits need no tag or deploy.

## References
tools/deploy.php; tools/smoke.php; CHANGELOG.md; README "Releasing and upgrading".
