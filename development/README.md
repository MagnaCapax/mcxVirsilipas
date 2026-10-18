# Development

Start here if you change this repository, by hand or with a coding agent.

## Read first

1. [`AGENTS.md`](../AGENTS.md): the project's governing text, with its rules and the invariants the tests protect.
2. [Engineering principles](engineering-principles.md): the organisation-wide rules with their reasons and the mistakes that taught them, copied from [mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies/blob/main/development/engineering-principles.md), the canonical copy.
3. [Decision records](../docs/adr/): accepted ADRs are binding. A significant change ships with a new one or amends one in the same commit ([template](../docs/adr/0000-template.md)). [ADR 0022](../docs/adr/0022-architecture-layout-and-languages.md) maps the layout and the languages.
4. [README](../README.md), [HTTP API](../docs/API.md), [example configuration](../config.example.php) and [CHANGELOG](../CHANGELOG.md): how the proxy works, is run and is released.
5. [Security policy](../SECURITY.md): report vulnerabilities privately, not in a public issue.

## Before you commit

- `php tests/run.php` passes (no network access needed; run it from a git checkout, because the secret guard reads the tracked files). Read the summary line and the exit code; do not pipe it through anything that hides them. CI runs the same suite on PHP 8.2, 8.3, 8.4 and 8.5, after a syntax lint of every PHP file.
- New behaviour has a test that fails when the behaviour is broken (break it once and see). Never weaken an AGENTS.md invariant to make a test pass.
- A behaviour change updates README.md in the same commit, and docs/API.md when a route, field, response, header or error changes (`tests/DocsTest.php` checks the API doc against the code). A schema change follows the schema rule in AGENTS.md.
- Review the change with the [review unit in mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies/tree/main/pikkumies/tools/development/code-review): correctness, an adversarial pass, weakness classes, and conformance to `AGENTS.md`, the ADRs and the principles. Any agent can follow its `skill.md`.
- No upstream provider, model or vendor name anywhere, and nothing that is not meant for the public: this repository is public. Keep your deployment's own words in a local `.forbidden-words` file (never committed); the secret guard in the test suite refuses them in tracked files.

## Other MagnaCapax repositories

Each has its own `AGENTS.md`, decision records and docs. When your change touches another project, read its records first.

| Repository | What it is | Docs | Decisions |
|---|---|---|---|
| [PMSS](https://github.com/MagnaCapax/PMSS) | Pulsed Media Seedbox Software, the platform Pikkumies ships on | [docs](https://github.com/MagnaCapax/PMSS/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/PMSS/tree/main/docs/adr) |
| [mcxPikkumies](https://github.com/MagnaCapax/mcxPikkumies) | Lightweight helper agent that runs on the server it manages; holds the canonical engineering principles | [docs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxPikkumies/tree/main/docs/adr) |
| [mcxVirsilipas](https://github.com/MagnaCapax/mcxVirsilipas) | This repository | [docs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxVirsilipas/tree/main/docs/adr) |
| [mcxForge](https://github.com/MagnaCapax/mcxForge) | Bare-metal qualification, repair and installation suite | [docs](https://github.com/MagnaCapax/mcxForge/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxForge/tree/main/docs/adr) |
| [mcxRescue](https://github.com/MagnaCapax/mcxRescue) | Rescue live system for bare-metal servers | [docs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxRescue/tree/main/docs/adr) |
| [mcxTemplate](https://github.com/MagnaCapax/mcxTemplate) | Templating engine for distribution installs | [docs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxTemplate/tree/main/docs/adr) |
| [nodeAgent](https://github.com/MagnaCapax/nodeAgent) | Server telemetry and hardware health agent | [docs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/nodeAgent/tree/main/docs/adr) |
| [mcxSerp](https://github.com/MagnaCapax/mcxSerp) | Search results API client for PHP | [docs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSerp/tree/main/docs/adr) |
| [mcxSauces](https://github.com/MagnaCapax/mcxSauces) | Scripts and configs for running systems and services | [docs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxSauces/tree/main/docs/adr) |
| [mcxBMCView](https://github.com/MagnaCapax/mcxBMCView) | BMC console screenshot and keyboard automation | [docs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs) | [ADRs](https://github.com/MagnaCapax/mcxBMCView/tree/main/docs/adr) |
