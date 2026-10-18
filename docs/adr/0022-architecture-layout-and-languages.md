# ADR 0022: Architecture, layout and languages

Date: 2026-10-18
In force since: this change (it records the layout as of v0.11.0)

## Status
Accepted

## Context
ADRs 0002 to 0021 each decide one subject, and AGENTS.md lists the files, but no record says how the parts fit together, what each directory owns, which language each part is written in and why, or how the MagnaCapax engineering principles ([development/engineering-principles.md](../../development/engineering-principles.md), copied on 2026-10-18) apply here. A contributor or coding agent coming from a sibling repository needs that map before changing code.

## Options considered
- Leave it to the Layout list in AGENTS.md: it names files, but it is the short form and has no room for ownership boundaries, the language reasons or the principles.
- One ADR per principle: ten records that decide nothing new.
- One record of the architecture as it is, citing the ADRs that decided each part.

## Decision
This record describes the code as it stands and changes none of it.

**What it is.** A small PHP application behind a web server and php-fpm. Every request enters through `public/index.php`. Each API key has one prepaid credit balance in one SQLite ledger (ADR 0004, 0006). A client request reserves an upper bound, is forwarded to the upstream route of the model alias it names, and is settled from the reported usage (ADR 0007, 0008). The deployment's billing system adds credits through the admin endpoints; operators use the CLI and the tools.

**Layout and what each part owns.**

| Path | Owns |
|---|---|
| `public/index.php` | The only web entry point: maps each method and path to `Proxy` or `Admin`, picks the error format by path, fails closed on any uncaught error (ADR 0010). |
| `src/` | The application, one class per file in the `MagnaCapax\Virsilipas` namespace. `bootstrap.php` holds the autoloader (no Composer needed; its PSR-4 mapping works too), the private umask and the config path. |
| `src/Proxy.php` | Client endpoints, request rewrite, streaming, retries and failover, charging (ADR 0011, 0013). |
| `src/Messages.php` and the class beside it for ADR 0021 | The two further client formats (ADR 0020, 0021): allowlists, translation or pass-through, rebuilt answers. `Proxy` still does the reservation, retries and settling for both. |
| `src/Ledger.php` | The SQLite schema and its in-place upgrades, keys, grants, leases, usage, limits, caps, the credit log and the metrics reads (ADR 0004, 0009, 0012, 0018). |
| `src/Config.php`, `src/Credits.php`, `src/Throttle.php` | Configuration loading and validation (ADR 0010); credit arithmetic (ADR 0005); the soft throttle (ADR 0016). |
| `src/Admin.php` | The billing and provisioning endpoints (ADR 0006, 0015). |
| `src/Http.php`, `src/HttpError.php`, `src/Log.php`, `src/Top.php` | Request and response helpers; refusals mapped to HTTP; the JSONL log (ADR 0014); the live view behind `bin/virsilipas top` (ADR 0018). |
| `bin/virsilipas` | The operator CLI, in PHP: `init` (create or upgrade the ledger), keys, grants, limits, `verify`, `stats`, `top`. |
| `tools/` | Operator tools, never served: `deploy.php` (ADR 0017), `smoke.php` (live check after a deploy), `bench.php` (load test). |
| `examples/` | Two example clients; `tests/ExamplesTest.php` runs them against the test proxy. |
| `tests/` | The plain test runner, a fake upstream, the end-to-end harness, the secret guard and schema fixtures. |
| `docs/`, `README.md`, `CHANGELOG.md`, `config.example.php` | The HTTP API reference, the ADRs, the user documentation, the releases, the shape of a configuration. |
| `development/` | The engineering principles and the contributor index. |

A deployment's `config.php`, key files, ledger and logs are never in the repository (ADR 0002).

**Languages.** PHP 8.2 or later is the only language of the program, its CLI, its tools and its tests, with the `curl`, `pdo_sqlite` and `ctype` extensions and no Composer packages (ADR 0003). The tree holds no shell script; shell appears only as command lines in the README and in the CI workflow's `run` steps, which is the thin glue principle 1 allows. SQL lives inside `src/Ledger.php`. There is no Python. Principle 1 therefore holds with no exception to record: the runtime is PHP, so no capability reason calls for another language.

**Build, run and test.** There is no build step: a release is a git tag `vX.Y.Z` with a CHANGELOG entry, unpacked with `git archive` and switched in by `tools/deploy.php` (ADR 0017). The web server sends every path to `public/index.php` through php-fpm; the configuration is read from `VIRSILIPAS_CONFIG`, else `config.php` in the project root, and `bin/virsilipas init` creates or upgrades the ledger. On a workstation, the README quick start runs the proxy with the PHP built-in server against `tests/fake-upstream.php`. The tests run with `php tests/run.php` and need no network access; CI lints every PHP file and runs the suite on PHP 8.2 to 8.5 with every error, notice and deprecation reported, and runs PHPStan at level 5 as an advisory check.

**Conventions found.** `declare(strict_types=1)` in every PHP file. Methods and variables in camelCase, class files named after their class. One global helper, `virsilipas_config_path()` in `src/bootstrap.php`, is in snake_case. Wire-format fields, configuration keys, ledger columns and log event names are snake_case: they are external formats that clients, configurations and ledgers already depend on. Tests are functions named `test*` in `tests/*Test.php`. A schema change raises `SCHEMA_VERSION`, upgrades the previous schema in `migrate()` and adds a fixture. New files carry no license header.

**How the principles apply.**
1. PHP for logic: met in full, as above.
2. Files around 150 lines: of the 18 runtime PHP files (`src/`, `public/`, `bin/`, `tools/`), 9 are longer; the largest are `src/Proxy.php` (1151 lines) and `src/Ledger.php` (942). As the principle says, they are split when they are being changed anyway, never mass-split, and a split keeps the AGENTS.md invariants and their tests unchanged.
3. Names: camelCase in code. The snake_case external formats stay as they are, because renaming them would break existing clients, configurations and ledgers (principle 7).
4. and 5. Keep it small and delete first: AGENTS.md "Keep it small" and ADR 0003 say the same. What the firewall, the web server or the billing system already does stays there, and credit arithmetic is made in one place (invariant 11).
6. Pit of success: the money path fails closed and the configuration refuses settings it does not know (ADR 0010); a deploy proves the new release before stopping the old one (ADR 0017).
7. Never break old users: each earlier released schema is upgraded in place and tested against a fixture (ADR 0004); the client contract is stable (ADR 0013).
8. Tests that can fail: the invariants in AGENTS.md are protected by tests; `tests/run.php` exits 1 on any failure, including a warning in a test server's log, and CI keeps that exit code through its output log (`set -o pipefail`).
9. Decisions written down: already the rule here (ADR 0001).
10. Fail soft: the boundary the principle draws, stop when going on would damage data, is where this project puts it. Anything that authorizes or charges fails closed (ADR 0010); the throttle (ADR 0016) and logging (a failed log write never fails a request, invariant 10) fail soft.

## Consequences
- A contributor from a sibling repository finds the whole map in one place, with the ADR behind each part.
- Nine files are over the size target. This record does not order a split; it makes the target visible for the next change to those files.
- A change to the layout or the languages (a new directory, a new language, a file taking a new job) amends this ADR in the same commit (ADR 0001).

## References
AGENTS.md (Rules, Layout, invariants 10 and 11); [development/engineering-principles.md](../../development/engineering-principles.md); ADR 0001 to 0021; composer.json; .github/workflows/ci.yml; tests/run.php; README "Quick start", "Install", "Releasing and upgrading" and "Tests".
