# mcxVirsilipas

A small PHP proxy that gives every API key a prepaid credit balance and meters each LLM request against it, streaming included. Fresh input, cached input and output each cost their own number of credits per model, so the balance follows what requests cost you.

- One key per user, one balance per key. The balance never expires and never resets.
- Your billing system adds credits through an admin endpoint ("grants"). Each grant applies once, however often it is retried.
- Clients can read their remaining credits, their limits and their last minute's use, and their own usage per day and per model with the grants that added to the balance; you set per-key limits on concurrency, requests per minute and credits per minute.
- Clients talk to it like any OpenAI-compatible endpoint (`/v1/chat/completions`, `/v1/models`), for text requests. Two example clients are in `examples/`.
- Clients that speak only the Anthropic Messages API, such as Claude Code, use `/v1/messages`, passed through to upstreams that serve that format and metered the same way ([Claude Code](#claude-code)).
- Clients that speak only the Gemini API, such as Gemini CLI, use `/v1beta/models/{model}:generateContent` and its streamed form, translated to chat completions on the same aliases and metered the same way ([Gemini CLI](#gemini-cli)).
- Optional soft throttling: when the day's spend nears a budget, or the server is busy, requests slow down or are asked to retry, by a per-key priority weight (more credit and a larger bonus mean higher priority), instead of being refused.
- Optional hard caps for the whole server: upstream spend per hour, day and month, and requests per minute across all keys. Set a spend cap to 0 to stop everything at once.
- A key never spends more than its balance, parallel requests included: each request reserves an upper bound first, the reservation never exceeds the balance, and the charge never exceeds the reservation.
- Works with OpenAI-compatible upstreams that honour `max_tokens` and report usage. You choose which models customers may use; check each one against the launch list below before enabling it.
- No dependencies beyond PHP 8.2 with the `curl` and `pdo_sqlite` extensions (and `ctype`, which the CLI and tools use and almost every PHP build has), built against SQLite 3.25 or newer. Storage is one SQLite file.

**The name.** *Virsilipas* is the song-box of the Kalevala. The singer opens it before singing ("virsilippahan viritän", Runo I), and the giant Vipunen, "in whose mouth was great knowledge", opens it to sing out all he knows (Runo XVII). This proxy opens the box for as many words as a key has paid for.

## Where to find what

| You want to | Read |
|---|---|
| Try it on your own machine in five minutes, with no upstream account | [Quick start](#quick-start-five-minutes-on-your-own-machine) |
| Call a running proxy from code or a tool | [Call the API](#call-the-api), then [docs/API.md](docs/API.md) for every field, header and error |
| Find out why a request fails | [Troubleshooting](#troubleshooting) |
| Run it for real users | [Install](#install), [Configuration](#configuration-the-minimum-then-what-to-add), [php-fpm and nginx](#php-fpm-and-nginx), [Access control](#access-control), [Backups](#backups) |
| Understand what a request costs and what stops spending | [Credits](#credits), [How metering works](#how-metering-works), [Cost and rate controls at a glance](#cost-and-rate-controls-at-a-glance) |
| Upgrade a running copy | [Releasing and upgrading](#releasing-and-upgrading) |
| Open a support ticket with a key (served by the billing integration) | [docs/SUPPORT-TICKETS.md](docs/SUPPORT-TICKETS.md) |
| See why it is built this way | [docs/adr/](docs/adr/) |

## Quick start: five minutes on your own machine

This runs the proxy against the stand-in upstream the test suite uses (`tests/fake-upstream.php`), so it needs no upstream account and costs nothing. You need PHP 8.2 or newer with the `curl` and `pdo_sqlite` extensions, and `curl`, in a bash shell at the top of a checkout of this repository. Both servers listen on 127.0.0.1 only. If port 8080 or 8081 is taken, change it everywhere below.

**1. Write a config** in a new directory that only you can read:

```sh
DEMO=$(mktemp -d)
echo fake-upstream-key > "$DEMO/upstream.key"      # the stand-in does not check its key
ADMIN_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')   # for the admin API; only its sha256 goes in the config
cat > "$DEMO/config.php" <<EOF
<?php
return [
    'db_path' => '$DEMO/ledger.sqlite',
    'upstreams' => ['local' => ['url' => 'http://127.0.0.1:8081/v1/chat/completions', 'key_file' => '$DEMO/upstream.key']],
    'models' => ['default' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000,
        'route' => [['upstream' => 'local', 'model' => 'demo-model']]]],
    'admin_token_sha256' => '$(printf %s "$ADMIN_TOKEN" | php -r 'echo hash("sha256", stream_get_contents(STDIN));')',
];
EOF
export VIRSILIPAS_CONFIG="$DEMO/config.php"
```

**2. Start the stand-in upstream and the proxy** in the background, with their output in `$DEMO`:

```sh
FAKE_UPSTREAM_DIR="$DEMO" php -S 127.0.0.1:8081 tests/fake-upstream.php > "$DEMO/upstream.log" 2>&1 & UPSTREAM_PID=$!
php -S 127.0.0.1:8080 public/index.php > "$DEMO/proxy.log" 2>&1 & PROXY_PID=$!
```

**3. Create the database, a key and some credits:**

```sh
bin/virsilipas init                                      # ok
export VIRSILIPAS_KEY=$(bin/virsilipas key-create alice)  # a key is shown only once; this keeps it in the shell
bin/virsilipas grant alice 1000000 demo-1                # {"applied":true,"balance":1000000,"expires_at":null}
```

**4. Send a request:**

```sh
curl http://127.0.0.1:8080/v1/chat/completions \
  -H "Authorization: Bearer $VIRSILIPAS_KEY" -H 'Content-Type: application/json' \
  -d '{"model": "default", "messages": [{"role": "user", "content": "Say hello."}]}'
```

You get an OpenAI-style chat completion back. The answer is in `choices[0].message.content` (the stand-in always says "hello world") and its cost in `usage.credits`: 39 here, which is 11 prompt tokens at 1,000,000 credits per million plus 7 output tokens at 4,000,000 per million.

Things to try while it runs:

```sh
bin/virsilipas history alice    # the grant, the reservation the request took and the unused part it gave back
curl http://127.0.0.1:8080/v1/balance -H "Authorization: Bearer $VIRSILIPAS_KEY"
curl -N http://127.0.0.1:8080/v1/chat/completions \
  -H "Authorization: Bearer $VIRSILIPAS_KEY" -H 'Content-Type: application/json' \
  -d '{"model": "default", "stream": true, "messages": [{"role": "user", "content": "Count to ten."}]}'
VIRSILIPAS_URL=http://127.0.0.1:8080 php examples/client.php chat "one word, please"
curl http://127.0.0.1:8080/admin/grants \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' \
  -d '{"ref": "alice", "grant_id": "demo-2", "credits": 500000}'
bin/virsilipas top --once       # the requests, tokens, credits and timings so far
```

When you are done, stop both servers and delete the demo directory:

```sh
kill $UPSTREAM_PID $PROXY_PID && rm -r -- "$DEMO"
```

To serve real users, the steps are the same with a real upstream in place of the stand-in: its https chat completions URL and a file holding its API key in `upstreams`, and its model id in each `route`. [Install](#install) covers the production setup, and [Before enabling a model](#before-enabling-a-model) lists what to check about a model before customers can use it.

## Call the API

The proxy speaks the OpenAI chat completions format. A client needs four things:

| | |
|---|---|
| Base URL | your deployment's address plus `/v1`, for example `https://proxy.example/v1` |
| Authentication | `Authorization: Bearer <key>`; keys start with `vsk-` |
| Model | one of the aliases `GET /v1/models` lists, such as `default` |
| Credits left | `GET /v1/balance` |

The examples read the address and the key from the environment, so they never contain a key and are safe to copy and share:

```sh
export VIRSILIPAS_URL=https://proxy.example
read -rs VIRSILIPAS_KEY && export VIRSILIPAS_KEY   # paste the key and press Enter; it is not shown or kept in your shell history
```

### curl

```sh
curl "$VIRSILIPAS_URL/v1/chat/completions" \
  -H "Authorization: Bearer $VIRSILIPAS_KEY" -H 'Content-Type: application/json' \
  -d '{"model": "default", "messages": [{"role": "user", "content": "Say hello."}]}'
```

To stream, add `"stream": true` to the body and `-N` to curl. The answer arrives as `data: {...}` events, and the stream ends with `data: [DONE]`. The last event that carries `usage` shows the credits the request is charged, except in the cases the [streamed response](docs/API.md#post-v1chatcompletions) section of docs/API.md describes.

### JavaScript

Node 18 or newer, no packages. Save as `hello.mjs` and run `node hello.mjs`:

```js
const res = await fetch(`${process.env.VIRSILIPAS_URL}/v1/chat/completions`, {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${process.env.VIRSILIPAS_KEY}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({ model: 'default', messages: [{ role: 'user', content: 'Say hello.' }] }),
});
const reply = await res.json();
if (!res.ok) throw new Error(`${res.status} ${reply.error.code}: ${reply.error.message}`);
console.log(reply.choices[0].message.content);
console.log(`credits: ${reply.usage.credits}`);
```

### PHP: the example clients

```sh
php examples/hello-world.php "Say hello"
php examples/client.php models
php examples/client.php balance
php examples/client.php chat "one word, please" --model default
php examples/client.php chat "one word, please" --key-file /path/to/key   # the key from a file instead of VIRSILIPAS_KEY
```

`examples/hello-world.php` is the smallest client: one question to the `default` alias (or `VIRSILIPAS_MODEL`), the answer, and the credits it cost. `examples/client.php` is a fuller one with no dependencies beyond the `curl` and `json` extensions. The key comes from `--key-file` or `VIRSILIPAS_KEY`, never the command line, so it never shows in `ps` and is never printed. A key file holds either the key alone or, in the form some deployments hand out, a `key=<key>` line and an `endpoint=<base URL>` line (with or without the trailing `/v1`); the base URL is then `--url` if given, else the file's `endpoint`, else `VIRSILIPAS_URL`. `chat` streams the answer as it arrives (`--no-stream` for one JSON answer) and hides reasoning unless `--show-thinking` is given; it ends with a `usage:` line (prompt, completion, cached and reasoning tokens, and the credits charged). `models` prints each alias's rates; `balance` prints the balance, reservations, requests in flight, weight, bonus, limits and the last minute's use. On a 429 or 503 with `Retry-After` it waits and retries once; any other refusal prints the proxy's error (`message`, `type`, `code`) to stderr. Exit codes: 0 ok, 1 request failed, 2 usage error.

### OpenAI-compatible tools and libraries

A tool or library that lets you set an OpenAI-compatible base URL and API key can use the proxy: set the base URL to `https://proxy.example/v1`, the key to your `vsk-` key and the model to an alias from `GET /v1/models`. What it can expect:

- Under `/v1` the proxy serves `POST /v1/chat/completions`, `GET /v1/models`, `GET /v1/balance` and `GET /v1/usage`, plus the Anthropic-format `POST /v1/messages` and `POST /v1/messages/count_tokens` ([Claude Code](#claude-code)), and nothing else; the Gemini-format routes are under `/v1beta` ([Gemini CLI](#gemini-cli)). Any other path answers 404 `not found`.
- Message content must be text. An image, audio or file part answers 400 `unsupported_content`.
- Function tools (`tools`, `tool_choice`, `parallel_tool_calls`) are forwarded, and tool calls come back in the answer.
- Fields the proxy does not forward, such as `n` or `store`, are dropped without an error. The [request fields](docs/API.md#post-v1chatcompletions) table lists what is kept.

To check the settings before a real task, list the models with the same address and key; a 200 with your aliases means both are right:

```sh
curl "$VIRSILIPAS_URL/v1/models" -H "Authorization: Bearer $VIRSILIPAS_KEY"
```

### Claude Code

Claude Code speaks only the Anthropic Messages API. The proxy serves that format at `POST /v1/messages`, for the aliases whose route has an upstream that serves it natively (the operator gives that upstream a `messages_url`; see [config.example.php](config.example.php)), metered against the same balance as chat. Point Claude Code at the proxy with your key and an alias:

```sh
export ANTHROPIC_BASE_URL=https://proxy.example    # without /v1: Claude Code adds /v1/messages itself
export ANTHROPIC_AUTH_TOKEN="$VIRSILIPAS_KEY"      # sent as Authorization: Bearer
export ANTHROPIC_MODEL=default                      # an alias served in the Messages format
claude
```

`ANTHROPIC_API_KEY` in place of `ANTHROPIC_AUTH_TOKEN` works too (the key then goes as `x-api-key`). Claude Code also uses a model of its own for small background tasks; set that model's variable to an alias as well, or those calls answer 404. What to expect:

- `max_tokens` is clamped to the server's `max_tokens_cap` and to your balance, and a thinking budget that no longer fits is lowered to fit.
- Content must be text, tool use, tool results or thinking. An image (a pasted screenshot) answers 400 `unsupported_content`, and a server tool such as web search answers 400 `unsupported_tool`, so a feature that needs one fails.
- `POST /v1/messages/count_tokens` answers the proxy's own estimate, which counts high (a token per byte), so the context can look fuller than it is. It costs nothing.
- Errors come in the Anthropic error shape; [POST /v1/messages](docs/API.md#post-v1messages) in docs/API.md lists every field, event and error.

### Gemini CLI

Gemini CLI speaks only the Gemini API. No upstream serves that format here, so the proxy translates it: `POST /v1beta/models/{model}:generateContent` and `:streamGenerateContent` become chat completions requests on the alias's own route, metered against the same balance and in the same way as chat, and the answers are translated back. Every alias can be used; nothing has to be configured. Point Gemini CLI at the proxy with your key:

```sh
export GOOGLE_GEMINI_BASE_URL=https://proxy.example   # without /v1beta: the CLI adds /v1beta/models/... itself
export GEMINI_API_KEY="$VIRSILIPAS_KEY"               # sent as x-goog-api-key
gemini
```

and, in `~/.gemini/settings.json`, an alias for every model the CLI uses (here `default`):

```json
{
  "security": {"auth": {"selectedType": "gemini-api-key"}},
  "model": {"name": "default"},
  "modelConfigs": {"customOverrides": [{"match": {"overrideScope": "core"}, "modelConfig": {"model": "default"}}]},
  "agents": {"overrides": {"codebase_investigator": {"modelConfig": {"model": "inherit"}},
                           "cli_help": {"modelConfig": {"model": "inherit"}}}}
}
```

Checked with Gemini CLI 0.64.0, where each line is needed:

- `security.auth.selectedType`: with `GOOGLE_GEMINI_BASE_URL` set and no auth method chosen, the CLI picks one it then refuses (`Invalid auth method selected.`).
- `model.name` (or `-m default`, or `GEMINI_MODEL=default`): the alias for the conversation. With a fixed model the CLI also skips the extra routing call it otherwise makes on every turn.
- `modelConfigs.customOverrides`: the CLI's own background tasks (routing, summaries, loop and edit checks, history compression) name models of their own; this override sends them to the alias as well. The `core` override also covers the conversation itself, so to use another alias change both the override and `model.name` (checked: changing only `model.name` to `large` still sent the request to `default`).
- `agents.overrides`: two built-in subagents, on by default, name models of their own; `inherit` runs them on the conversation's model. Experimental agents and features that are off by default (the browser agent, automatic memory) name models of their own too and were not checked.

A call that still names a model that is not one of your aliases (a later CLI version may add one) answers 404 `model_not_found`. For headless use (`gemini -p "..."`) outside a folder the CLI trusts, add `--skip-trust` or `GEMINI_CLI_TRUST_WORKSPACE=true`. `GEMINI_API_KEY_AUTH_MECHANISM=bearer` sends the key as `Authorization: Bearer`, which the proxy also accepts. What to expect:

- Text, function calls and function results (the CLI's own tools: files, shell, search in files, subagents) work. A pasted image or file answers 400 `unsupported_content`, and a tool that runs on the provider's side (the CLI's web search, URL context) answers 400 `unsupported_tool`, so a feature that needs one fails.
- The CLI's thinking settings are not forwarded: how much an alias reasons is the deployment's setting (the model's `params`). Reasoning an upstream returns is shown as the CLI's thoughts and paid as output.
- `generationConfig.maxOutputTokens` is clamped like `max_tokens` on chat; without it a request asks for the server's `max_tokens_cap`.
- `:countTokens` answers the proxy's own estimate, which counts high (a token per byte). It costs nothing.
- Errors come in the Gemini error shape; [the Gemini routes](docs/API.md#post-v1betamodelsmodelgeneratecontent) in docs/API.md list every field, chunk and error.

## Endpoints

The full reference, with every request field, response shape, header and error, is [docs/API.md](docs/API.md).

| Method and path | Auth | What it does |
|---|---|---|
| `POST /v1/chat/completions` | key | OpenAI-compatible chat completions, streaming or not; `model` is one of your aliases |
| `POST /v1/messages` | key | the Anthropic Messages format, streaming or not, for aliases with a `messages_url` upstream; the key as `x-api-key` or a bearer token |
| `POST /v1/messages/count_tokens` | key | a conservative input-token estimate for a messages request; free, nothing goes upstream |
| `POST /v1beta/models/{model}:generateContent` | key | the Gemini API format, translated to chat completions, for any alias; the key as `x-goog-api-key`, a `key` parameter or a bearer token |
| `POST /v1beta/models/{model}:streamGenerateContent` | key | the same, streamed (`?alt=sse`) |
| `POST /v1beta/models/{model}:countTokens` | key | a conservative input-token estimate for a Gemini request; free, nothing goes upstream |
| `GET /v1/models` | key | the aliases with their rates, `context_length` and `max_completion_tokens`; upstream model ids and URLs are never shown |
| `GET /v1/balance` | key | the calling key's balance, reservations, limits, last minute's use and priority weight |
| `GET /v1/usage` | key | the calling key's own balance, usage per day and per model over the last `days` (default 30), and its newest `grants` grants (default 200) |
| `POST /admin/keys` | admin token | creates a user's key, returned once |
| `POST /admin/keys/rotate` | admin token | replaces a leaked key; the old one answers 401 at once |
| `POST /admin/keys/remove` | admin token | removes a key for good; its balance and history stay, the ref cannot be used again |
| `POST /admin/keys/active` | admin token | disables or re-enables a key |
| `POST /admin/keys/limits` | admin token | sets a key's limits, `bonus_pct` and `key_group` |
| `POST /admin/grants` | admin token | adds or removes credits, once per `grant_id`; an optional `expires_at` is stored, not enforced |

Client routes take `Authorization: Bearer <key>`; admin routes take `Authorization: Bearer <admin token>`. Use a long random admin token (32 bytes or more); only its sha256 is stored in the config.

## Troubleshooting

Every error answer is `{"error": {"message", "type", "param", "code"}}`. Look up its status and `code` below; [Errors](docs/API.md#errors) in docs/API.md lists every one.

**Calling the API**

| You get | It means | What to do |
|---|---|---|
| 401 `invalid_api_key` | no `Authorization: Bearer` header, or a key the proxy does not know (a rotated key is refused at once) | copy the key again; `echo ${#VIRSILIPAS_KEY}` prints 0 when the variable is not set in this shell |
| 403 `key_disabled` | the key has been disabled | ask whoever runs the proxy |
| 403 `key_expired` | the key's expiry has passed | ask whoever runs the proxy for a new key |
| 404 `model_not_found` | `model` is not one of this proxy's aliases | use an `id` from `GET /v1/models` |
| 404 `not found` (`code` is `null`) | a path or method the proxy does not serve | check that the base URL ends in `/v1`; only the routes under [Endpoints](#endpoints) exist |
| 400 `unsupported_content` | a message has an image, audio or file part | send text only |
| 402 `insufficient_quota` | the balance cannot cover the request's prompt, counted as one token per byte, plus one output token (see *Reserve* under [How metering works](#how-metering-works)) | add credits; `GET /v1/balance` shows what is left |
| 429 `rate_limit_exceeded`, `server_rate_limit` or `upstream_busy` | a limit of the key, the server's request rate, or a busy upstream | wait the `Retry-After` seconds, then retry (`examples/client.php` does this once) |
| 503 `server_busy`, `spend_cap_reached` or `upstream_unavailable` | the server is busy, the server's spending limit is reached, or no upstream could take the request | wait the `Retry-After` seconds; a spending limit holds until its hour, day or month ends |
| 502 `upstream_error` or 504 `upstream_timeout` | the upstream failed or took too long | try again later; when you report it, give the `X-Request-Id` response header |
| a stream arrives all at once at the end | something between you and the proxy buffers the response | whoever runs the proxy: see *Turn buffering off* under [php-fpm and nginx](#php-fpm-and-nginx) |

A refused request costs nothing, and neither does an upstream failure that delivered no answer. A stream that fails after it started is charged for the output that had already arrived.

**Running the proxy**

| You see | Likely cause | What to do |
|---|---|---|
| every request answers 500 `internal error` | the config does not load, or the database cannot be opened, read or written | run `bin/virsilipas verify` as the php-fpm user with the same `VIRSILIPAS_CONFIG`: it prints the error in full, which the proxy keeps out of its own answers and log |
| `config: <name> is not a setting here (a typo?)` | a misspelt or unknown setting | fix the name; the message lists the settings that level may have |
| `ledger directory ... is writable by group or others` or `ledger file ... is accessible to other users; chmod 600 it` | the database directory or files are not private to the PHP user | `chmod 700` the directory, `chmod 600` the files, owned by the php-fpm user |
| the CLI works but the proxy answers 500 | the database was created by another user (for example root) | run the CLI as the php-fpm user (`runuser -u www-data -- bin/virsilipas ...`); see [Install](#install) |
| `GET /v1/models` without a key answers a 5xx instead of 401 | php-fpm, the config or the database failed | the log says which; see the health check under [php-fpm and nginx](#php-fpm-and-nginx) |
| a model answers 503 `upstream_unavailable` on every request | its upstreams cannot be reached, or their key files are missing or empty | look for `upstream_skipped` and `upstream_attempt` lines in the log (level `warn`); see [Logging](#logging) |
| `bin/virsilipas verify` exits 1 | a balance changed outside the proxy | see *Credit log* under [How metering works](#how-metering-works) |

## Run your own

mcxVirsilipas is open source under the Apache License 2.0. Anyone may run their own copy for their own needs, read and review the code, change it, and build on it under the terms of the [License](LICENSE). The repository is the software only: your copy, its configuration, its upstream accounts and its bills are your own.

Nothing about a particular deployment is in this repository. Upstream providers, model names, prices, keys, hostnames and addresses live only in your `config.php` and key files, which are never committed (`.gitignore` covers them, and `tests/SecretGuardTest.php` fails if one is tracked). Keep it that way in your own fork.

The design decisions behind the code, with the options that were turned down, are in [docs/adr/](docs/adr/); the full HTTP API is in [docs/API.md](docs/API.md).

## No warranty: use at your own risk

The software is provided "as is", without warranties or conditions of any kind, and the authors are not liable for any damages arising from its use; sections 7 and 8 of the [License](LICENSE) are the binding text. Your mileage may vary. In particular:

- You are responsible for your upstream accounts, their terms of service (including whether they allow you to resell or share access), the prices you set, and the bills you receive.
- The credit metering and the caps below limit what the proxy lets through, but a cost the proxy computes from configured prices is an estimate. Your provider's own bill is the authoritative figure. Where your provider offers spending limits of its own, set them too.
- Check every model before enabling it (see *Before enabling a model*), test a client hang-up on your own stack, and back up the ledger.
- Nothing here is legal, tax or financial advice.

## Install

The packages, on Debian 13: `apt install php8.4-fpm php8.4-cli php8.4-curl php8.4-sqlite3 sqlite3 nginx` (`pdo_sqlite` is in php8.4-sqlite3). Then:

```sh
cp config.example.php config.php      # edit: upstream, models, admin token hash, paths
install -d -m 700 -o www-data -g www-data /var/lib/virsilipas     # the db_path directory, owned by the PHP user
runuser -u www-data -- bin/virsilipas init                    # creates the SQLite database
runuser -u www-data -- bin/virsilipas key-create customer-42  # prints the key once
runuser -u www-data -- bin/virsilipas grant customer-42 1000000 invoice-1001   # {"applied":true,"balance":1000000,"expires_at":null}
```

Run the CLI as the user php-fpm runs as (`www-data` here), and make `config.php` readable by it: the database files belong to whoever creates them, and the proxy cannot open a database another user owns. To try it on a test machine without root, point `db_path` at a directory you own and run `bin/virsilipas` and `php -S` as yourself.

Point your web server at `public/index.php` for every path; [php-fpm and nginx](#php-fpm-and-nginx) has a complete nginx site and php-fpm pool. The config path can be set with `VIRSILIPAS_CONFIG`. `config.example.php` lists every setting; the proxy refuses a setting it does not know, so a misspelt key stops it with a message naming the key instead of silently dropping a limit.

### Configuration: the minimum, then what to add

The quick start's config is a complete one. Four settings are required:

- `db_path`, the SQLite file;
- `admin_token_sha256`, the sha256 of the admin token your billing system sends;
- an upstream for every model: an entry in `upstreams` with its URL and `key_file` (config.example.php also shows the older single-upstream form);
- `models`, at least one alias with its three rates (`input`, `cached_input` and `output`, in credits per million tokens; see [Credits](#credits)) and a `route` naming the upstream and the model id sent there.

Everything else has a default: `upstream_timeout` 600 seconds, `lease_seconds` 900, `retries` 1, `max_tokens_cap` 16384, `max_concurrent_per_key` 4, no per-minute limits, `log_level` `warn` with the lines going to PHP's `error_log`, and `stream_options` `['include_usage' => true]`. [config.example.php](config.example.php) shows every setting with what it does.

| To add | Set | Read |
|---|---|---|
| a second upstream to fail over to | another `upstreams` entry, and a second entry in the model's `route` | *Retries and failover* under [How metering works](#how-metering-works) |
| the Anthropic Messages format (`POST /v1/messages`, for Claude Code) | `messages_url` on an upstream that serves that format natively; its aliases can then be used there | [Claude Code](#claude-code), config.example.php |
| default limits for every key | `max_concurrent_per_key`, `requests_per_minute`, `credits_per_minute` | *Limits* under [How metering works](#how-metering-works) |
| a hard stop on upstream spend, or on the server's request rate | `caps`; a spend cap needs a `cost` on every route entry | [Caps](#caps-hard-limits-for-the-whole-server) |
| slowing requests down as spend nears a daily budget, or when the server is busy | `throttle` | [Priority and throttling](#priority-and-throttling) |
| a request field always sent for a model, such as a reasoning level | `params` on the model or on a route entry | config.example.php |
| a description and context length in `GET /v1/models` | `description`, `context_length` | [GET /v1/models](docs/API.md#get-v1models) |
| one log file per UTC day | `log_dir`, `log_level` | [Logging](#logging) |

### php-fpm and nginx

- **One worker per open stream.** A streaming request holds a php-fpm worker for its whole duration. Size `pm.max_children` for your peak number of concurrent streams, and set `request_terminate_timeout` above `upstream_timeout`.
- **Turn buffering off**, or streams arrive all at once at the end. The proxy sends `X-Accel-Buffering: no`; also set `fastcgi_buffering off;` (or `proxy_buffering off;`) and a `fastcgi_read_timeout` above `upstream_timeout`. Keep `zlib.output_compression` off for this pool.
- **Database directory** must be writable by the PHP user (SQLite WAL creates files next to the database). The proxy creates its files private to its user, and refuses a database, WAL or shared-memory file that group or others can read, and a directory that group or others can write.
- **Health check:** `GET /v1/models` without a key answers 401 only when php-fpm runs, the config loads and the ledger database opens; any 5xx means one of them failed (the log says which). It does not read the ledger's tables (a wrong `db_path` in a writable directory opens a new, empty database); `GET /v1/balance` with a real key does. The proxy fails closed: while the config is invalid or the database cannot be read or written (a full or read-only disk), a request that cannot load the config or reserve in the ledger answers 500 and is not charged. At `log_level` `info` each probe logs a `refused` line. `bin/virsilipas verify` checks the balances themselves.
- **Before launch, test a client hang-up under your real php-fpm and nginx.** Abort detection depends on the server telling PHP that the connection closed; it is covered by the tests under the PHP built-in server, not under every stack.
- **Memory.** Decoding and checking a request takes a few times its size, but a crafted body of very many tiny messages takes about 67 times its size (measured: a 4 MiB body of tiny messages needed about 270 MB). Such a request needs a valid key; when the worker runs out of memory it fails with a 500 before anything is reserved or charged, but it ties up that worker meanwhile. Keep `client_max_body_size` modest and size `memory_limit` for it: with PHP's default of 128M, a `client_max_body_size` of `1m`; for the proxy's own 4 MiB limit, `memory_limit` 384M for this pool. `memory_limit` bounds one worker; `pm.max_children` times it is the worst case.
- **Version headers.** The proxy removes PHP's `X-Powered-By` header itself. Also set `expose_php = Off` in php.ini (Debian's production php.ini already does) and `server_tokens off;` in nginx, so that neither announces its version.
- **Never point `root` at a checkout,** or at a release's top directory: serve only `public/`, and run only `index.php`. A checkout holds `.git` and the test suite, whose stand-in upstream (`tests/fake-upstream.php`) writes every request it receives, its `Authorization` header included, to the temporary directory. Releases unpacked by `tools/deploy.php` (`git archive`) leave `tests/` and `.github/` out (`.gitattributes`).

A complete nginx site, as a starting point. The paths are the ones [Releasing and upgrading](#releasing-and-upgrading) uses (`/opt/virsilipas` is the live release), and `$virsilipas_source` comes from the `geo` block under [Access control](#access-control), which goes in the `http` block:

```nginx
server {
    listen 443 ssl;
    server_name proxy.example.com;
    # ssl_certificate and ssl_certificate_key as for any site
    root /opt/virsilipas/public;      # the live release's public/, never a checkout
    access_log off;                    # or a format without the client address (see Privacy)
    client_max_body_size 4m;           # the proxy refuses a chat body over 4 MiB anyway; see Memory above
    client_body_buffer_size 4m;        # the same value: request bodies stay in memory, never in a temporary file

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;   # the only script that ever runs
        fastcgi_param VIRSILIPAS_SOURCE $virsilipas_source;
        fastcgi_pass unix:/run/php/virsilipas.sock;
        fastcgi_buffering off;         # streams arrive as they are written
        fastcgi_read_timeout 660s;     # above upstream_timeout (600 by default)
    }

    location /admin/ {
        allow 192.0.2.10;              # your billing system
        deny all;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param VIRSILIPAS_SOURCE $virsilipas_source;
        fastcgi_pass unix:/run/php/virsilipas.sock;
    }
}
```

and its php-fpm pool:

```ini
; /etc/php/8.4/fpm/pool.d/virsilipas.conf
[virsilipas]
user = www-data
group = www-data
listen = /run/php/virsilipas.sock
listen.owner = www-data
listen.group = www-data
pm = static
pm.max_children = 32                    ; your peak of requests at once, streams included
request_terminate_timeout = 660s        ; above upstream_timeout
env[VIRSILIPAS_CONFIG] = /etc/virsilipas/config.php
php_admin_value[memory_limit] = 384M     ; see Memory above
php_admin_value[upload_tmp_dir] = /run/virsilipas-tmp   ; on tmpfs, mode 700, owned by www-data (see Privacy)
php_admin_flag[zlib.output_compression] = off
```

`/run` is a tmpfs on Debian and is emptied at boot; recreate the directory with a systemd-tmpfiles line such as `d /run/virsilipas-tmp 0700 www-data www-data -`.

### Access control

The proxy checks keys, not addresses. Decide who can reach it with your firewall. To stop all traffic at once, set a spend cap to 0 (see *Caps*), stop the php-fpm pool or block the port. To stop one customer, disable their key.

The web server tells the proxy where a request comes from: the FastCGI parameter `VIRSILIPAS_SOURCE` set to `internal` marks a caller from your own network; anything else, or no parameter, is external. Set it from the client address, never from a request header (the proxy reads only the server variable, so a client cannot send it). Behind a reverse proxy or load balancer `$remote_addr` is that proxy's address, which would make every caller internal: use the real client address there (nginx `realip` with your proxies listed in `set_real_ip_from`). With nginx:

```nginx
geo $virsilipas_source {
    default external;
    192.0.2.0/24 internal;   # your own networks
}
# in the location that passes to php-fpm:
fastcgi_param VIRSILIPAS_SOURCE $virsilipas_source;
```

The source sets the `internal_factor` in the weight and which `busy` line applies. Giving internal and external callers separate php-fpm pools (nginx can pick the pool by the same `geo`) keeps a flood from outside from taking the workers your own network uses.

### Backups

The database holds every balance, so back it up. Do not copy the file while the proxy runs: in WAL mode recent writes live in the `-wal` file, and a plain copy can miss them or be inconsistent. Use SQLite's online backup instead, which is safe while requests are running:

```sh
sqlite3 /var/lib/virsilipas/ledger.sqlite ".backup '/backup/ledger-$(date +%F).sqlite'"
```

If you back up with `.dump` (SQL text) instead, carry the schema version with it: `sqlite3 DB .dump "SELECT 'PRAGMA user_version = ' || user_version || ';' FROM pragma_user_version;"`. A plain `.dump` omits `user_version`: the proxy itself serves such a database, but the next `bin/virsilipas init` (which every deploy runs) refuses it.

To restore, stop the php-fpm pool, put the backup in place as the database file, delete any leftover `-wal` and `-shm` files next to it, and make it private to the PHP user (`chmod 600`): the proxy refuses a database that group or others can read, and the backup copy is usually created group-readable. Before starting php-fpm again, run `bin/virsilipas init` and `bin/virsilipas verify` as the PHP user: `init` upgrades a backup from an older release and refuses one it cannot read, and `verify` checks every balance.

## Credits

A credit is an abstract unit: you decide what it is worth and how many you grant for a payment. Each model alias has three rates, in credits per million tokens: `input` (fresh prompt tokens), `cached_input` (prompt tokens the upstream served from its prompt cache) and `output` (completion tokens, reasoning included). A request costs

```
ceil((fresh x input + cached x cached_input + output x output) / 1,000,000)
```

rounded up once per request. The counts come from the upstream's usage report: `cached` is `prompt_tokens_details.cached_tokens`, or a top-level `prompt_cache_hit_tokens` where an upstream reports it that way (the first present wins), `fresh` is the rest of the prompt side (`total_tokens - completion_tokens`), and a missing cached count, or one that is not an integer from 0 to `prompt_tokens`, is read as 0, which prices the whole prompt as fresh input. Rates are integers from 0 to 10^9 (`input` and `output` at least 1); `cached_input` may not exceed `input`, because reservations price the whole prompt at the input rate. A request is priced at the rates in force when it starts, for its reservation and its charge alike; a rate change applies to requests that start after it. Example: with `input` 1,000,000, `cached_input` 250,000 and `output` 4,000,000 (illustrative values, not a recommendation), a credit is one fresh input token, a cached token costs a quarter and an output token four.

## How metering works

1. **Admit.** Only text requests are accepted: message content must be a string or text parts; images, audio and files are refused, because their cost cannot be bounded before sending. Messages keep only role, content, name, tool_calls and tool_call_id, and text parts only their text; each tool call must carry a string id, type, function name and arguments (a JSON string), and a request with any other shape is refused. Only a fixed list of fields is forwarded (messages, tools, tool_choice, parallel_tool_calls, temperature, top_p, stop, seed, presence and frequency penalties, response_format, reasoning_effort). Everything else, including `n`, `store` and output-mode options, is dropped.
2. **Reserve.** The prompt bound is the byte length of the forwarded fields (a token is at least one byte). The proxy reserves the prompt bound at the input rate plus `max_tokens` at the output rate (rounded up once, like a charge), in one atomic transaction, with `max_tokens` clamped to what the balance can cover; if the balance cannot cover the prompt and one output token, the request is refused with 402. Each reservation is a lease row, which also counts against the key's limits. Because the bound counts bytes, a long prompt temporarily holds roughly four times its real token count (a 45 kB agent prompt holds about 46,000 tokens' worth of input credits for about 11,500 tokens used); a key needs that much free balance to start the request, and the unused part comes back when it settles.
3. **Forward.** The request goes upstream with the alias replaced by the real model id, the model's `params` applied over the client's fields, and `stream_options` set by the proxy so the upstream reports usage. Each alias has a route: the upstreams that serve it, in order, each named once in `upstreams` with its own URL and `key_file` (the older form, a global `upstream_url` or a model's own `url` with its own `key_file`, is a one-entry route). An upstream's key goes only to that upstream; if its key file is missing or empty that upstream is skipped, never served with another key. Upstream URLs must be https, or plain http to the local machine. See *Retries and failover* below.
4. **Settle.** When the response ends, the credits for the tokens the upstream reported are charged, never more than the reservation, and the rest is returned, exactly once per request, together with a usage row (token counts, cached count and credits, plus the tracking fields below). A report counts only with integer `prompt_tokens`, `completion_tokens` and `total_tokens`, the total at least the sum of the other two; anything else is charged as missing usage. A charge above the reservation is recorded as `over_reservation` so you can see an upstream that ignores `max_tokens`. Under php-fpm the client's connection is closed right after the settle, before the request log is written and the spend caps are checked, so the client waits for what decides its balance and limits and nothing else ([ADR 0027](docs/adr/0027-the-client-is-released-once-its-request-has-settled.md)).

Details that matter in production:

- **Client hangs up mid-stream.** The proxy stops relaying and keeps reading the upstream until a usage report covers what the client received, then stops the upstream request, so the charge follows the upstream's own count. With an upstream that reports usage on every chunk (configure it in `stream_options`) that is the next report, and the charge can include a few chunks the client never received. If such an upstream sends the cached count only with its final report, that next report has none, so a hang-up charges the cached part of the prompt as fresh input. With an upstream that reports usage only at the end, it is the final report: the upstream finishes the answer (at most `max_tokens`, capped at the reservation) and the whole generation is charged. If no report comes before the stream ends, the charge is an upper bound (the prompt bound at the input rate plus the bytes delivered at the output rate), capped at the reservation, and the usage row is flagged `estimated`.
- **Responses** are rebuilt from known fields only: id, object, created, choices (with their message or delta text, reasoning, tool calls and finish reason), the alias as the model, and as usage `prompt_tokens`, `completion_tokens`, `total_tokens`, `prompt_tokens_details.cached_tokens`, `completion_tokens_details.reasoning_tokens` (the output tokens spent thinking, 0 when the upstream reports none) and `credits` (what the reported usage costs, at most the reservation). In a stream, each event that carries usage shows the report the request is charged by, so the last one matches the charge unless text arrived after the last report that advanced the counts; a later equal or lower report does not cover that text, and the charge adds an upper bound for it (flagged `estimated`). Each kept field must also have its expected type (text fields strings, tool-call arguments a string); a value of any other type is dropped. Anything else an upstream adds (costs, account data, provider names, its model ids) is dropped.
- **Upstream errors** never produce a 200 before a stream has started; once it has (the 200 is already sent), an upstream error ends the stream with a generic error event and no `[DONE]`, and the output relayed until then is charged. A non-streaming answer must arrive complete and carry a usable answer: non-empty text, reasoning or refusal, or a function tool call with a non-empty id, name and arguments. A stream starts only with an event that carries a completion choice (a delta with at least one valid field); events dropped before that add nothing to the charge, and at `[DONE]` the proxy stops reading the upstream, so nothing after it is relayed or charged. Otherwise the client gets one of the proxy's own errors (see [Errors](docs/API.md#errors) in docs/API.md), no upstream text, and no charge; when the upstream still sent a usage report (it answered 2xx without a usable answer, and billed it), the usage row keeps its counts and cost, with nothing charged, so cost tracking sees what the upstream billed. A stream that ends without its final marker gets an error event at the end (`code` `upstream_error`).
- **Retries and failover.** Before anything has been sent to the client, a failed attempt is retried on the same upstream (`retries`, 0 to 2, default 1) only when the upstream cannot have done the work: the connection failed before the request was sent, or it answered 408, 409, 429, 502, 503 or 504. Between tries the proxy waits the upstream's `Retry-After` (or `retry-after-ms`) when it is at most 60 seconds, else 0.5 s, then 1 s. When the tries are used up, or the upstream answered 500 (or another 5xx), 401, 402, 403 or 404, the request moves to the next upstream in the alias's route. It ends at once, with no retry and no failover, when output may already exist (a 2xx status arrived, or a sent request timed out or broke) and when the upstream rejected the request itself (400, 413, 422 and other statuses), because every upstream would. All attempts together get `upstream_timeout`. One reservation covers them all, the customer pays the alias's rates whichever upstream answered, and only the answer delivered is charged. The usage row records the upstream that served the request (or was tried last), and each failed attempt is logged as `upstream_attempt` (warn).
- **The Anthropic Messages format** (`POST /v1/messages`) goes through the same admit, reserve, forward and settle steps, with that format's allowlists of fields, content blocks and tools ([POST /v1/messages](docs/API.md#post-v1messages)) and without the model's `params`. Its usage counts map onto the three rates: `input_tokens` and `cache_creation_input_tokens` are fresh input, `cache_read_input_tokens` is cached input and `output_tokens` (thinking included) is output. When `tools` are given, the prompt bound adds 1024 tokens for the tool-use prompt an upstream adds by itself.
- **The Gemini API format** (`POST /v1beta/models/{model}:generateContent` and `:streamGenerateContent`) is translated into a chat completions request (text, function calls and results, function declarations and a few generation settings; [the Gemini routes](docs/API.md#post-v1betamodelsmodelgeneratecontent)), which then goes through every step above as it is, the model's `params` included; the answer is translated back. In a stream a function call is sent whole at the end, and its bytes count as delivered only once it is sent.
- **Credit log.** Every change to a balance writes one row to `credit_log` in the same transaction: a grant (its `grant_id`), a reservation taken when a request starts and the unused part returned when it settles (both `lease:<id>`), or the `opening` balance carried over by a schema upgrade. Each row holds the change and the balance after it; a change of zero (a request that used its whole reservation) writes no row. The table is append-only: the database refuses UPDATE, DELETE and an INSERT that would replace a row on it. `bin/virsilipas history <ref> [N]` shows a key's last N changes; `bin/virsilipas verify` checks that every key's changes sum to its balance and that each row's balance is the running sum, and exits 1 on any mismatch, which means something changed a balance outside the proxy.
- **Usage over time.** Each usage row also carries `upstream` (the host of the route URL that served the request), `stream`, `duration_ms`, `reasoning_tokens` (the reasoning share of the output tokens, read from `completion_tokens_details.reasoning_tokens` or a top-level `reasoning_tokens`) and `upstream_cost` (the upstream's own `estimated_cost`, kept in millionths of its unit; when it reports none and the route entry that served the request sets `cost`, the cost computed from those prices, flagged `cost_computed`; otherwise `NULL`), `source` (internal or external, from the lease), `first_byte_ms` (from the first upstream request, after any pacing wait, to the first byte of the answer sent to the client: the first relayed chunk of a stream, or the whole answer otherwise; `NULL` when no answer reached the client, as for an error) and `attempts` (upstream requests made for it, retries and failover included; `0` on rows written before schema 8). None of these change what is charged; a request with none of this detail still gets a usage row. In a stream, a repeat report with the same three counts may add a detail the adopted report lacked (the cached split, the reasoning count, the cost) once; a later repeat cannot change or remove it. `bin/virsilipas stats <hour|day|week|month|all|hour_of_day|weekday> [by] [since] [until]` prints one JSON line per UTC time bucket and group. `week` is the year and SQLite's Monday-based week number (`2026-W41`), `hour_of_day` folds all days into 00 to 23, and `weekday` into 0 (Sunday) to 6. `by` is a comma list of `model`, `upstream`, `key`, `status`, `source` (internal or external), `group` (the key's group), or `-` for none; `since`/`until` are `YYYY-MM-DD` in UTC, `until` exclusive) with requests, prompt/cached/completion/reasoning tokens, credits charged, the upstream cost and how many requests carried one (and how many of those were computed from configured prices), hang-ups, estimated charges, failed requests (non-2xx), the mean duration, the mean time to first byte (`mean_first_byte_ms`), the requests that needed more than one upstream attempt (`retried`), and the requests that waited for spend pacing (`delayed`) with their mean wait (`mean_delay_ms`).
- **Crashed workers.** A lease outlives its request only if its worker died. When it expires it stops counting against concurrency, but its reservation stays charged, since the answer may already have been delivered (a worker that dies just before forwarding also leaves its reservation charged); if the request is settled late, the difference is returned. `bin/virsilipas status <ref>` counts such leases as `held`. `lease_seconds` must be longer than `upstream_timeout`.
- **Runaway output.** If the credits for a stream's reported usage pass the reservation, the proxy stops the upstream request there. Output delivered after the last usage report that advanced the count is charged as an upper bound (its bytes); a repeated or lower report does not cover it. Upstreams that report usage on every chunk often send the cached split only with the final report, whose counts repeat the last running report; such a repeat adds the cached split once and nothing else; a later repeat cannot change or remove it.
- **Limits.** Each key has three: `max_concurrent` (requests in flight; stops one key from using up a shared upstream rate limit), `requests_per_minute` and `credits_per_minute` (over the last 60 seconds, counting each request's charge, or its reservation while it runs). The config sets the defaults (`max_concurrent_per_key`, `requests_per_minute`, `credits_per_minute`; the last two unset means no limit) and the admin endpoint sets a key's own values, higher or lower. A request is refused (429) when the key is at a limit; the credits limit is checked when a request starts and a request is admitted while the window is below it, so a window holds at most the limit plus one request; the request that crosses it is served in full, never shortened. Further requests are refused until the window falls below the limit again, either because requests age out of it or because a running request settles for less than it reserved. The refusal order is disabled key, expired key, concurrency, requests, credits, the server's requests per minute, balance, spend caps. Refusals carry `Retry-After`: for the per-minute limits, the seconds until the oldest request in the window turns a minute old (the latest the window can start to free; a settling request can free it sooner); for concurrency, 1.

### Priority and throttling

Each key has a priority **weight** on the scale Linux uses for CPU and disk shares (cgroup `cpu.weight` and `io.weight`): 1 to 10,000, with 100 as the reference.

```
weight = 100 x sqrt(balance / credits_per_unit) x (1 + bonus_pct / 100) x (internal_factor, for internal callers)
```

rounded and clamped to 1..10,000. `credits_per_unit` (default 1,000,000) is the number of credits you treat as one unit of money, so a key holding one unit with no bonus has weight 100. The square root keeps a large balance from crowding everyone else out: four times the credit doubles the weight. `bonus_pct` (0 to 300, default 0) is the key's loyalty bonus, set with `bin/virsilipas bonus <ref> <pct>` or `POST /admin/keys/limits`; 300% multiplies the weight by 4. Internal callers (see *Access control*) are multiplied by `internal_factor` (default 2). A subscription that adds credits raises the weight through the balance. Clients see their weight in `/v1/balance` and in the `X-Virsilipas-Weight` response header.

With the defaults:

| Balance | Bonus | Weight, external | Weight, internal | `Retry-After` when shed |
|---|---|---|---|---|
| 0.25 unit | 0% | 50 | 100 | 10 s |
| 1 unit | 0% | 100 | 200 | not shed |
| 1 unit | 50% | 150 | 300 | not shed |
| 1 unit | 300% | 400 | 800 | not shed |
| 4 units | 0% | 200 | 400 | not shed |
| 25 units | 0% | 500 | 1000 | not shed |
| 25 units | 300% | 2000 | 4000 | not shed |

The weight changes no price and no limit. It only orders who slows down first, in two cases, both off until you configure them under `throttle`:

- **Spend pacing** (`daily_budget`, in credits over the last 24 hours). Once the credits charged across all keys in the last 24 hours (counted in whole UTC hours, so 24 to 25 hours) pass `pace_start` (default 0.8) of the budget, each chat request waits after its reservation and before it goes upstream: `base_delay_ms x pressure x 100 / weight`, at most `max_delay_ms` (defaults 1000 and 3000), where pressure rises from 0 at `pace_start` to 1 at the full budget and keeps rising past it. Nothing is refused: past the budget requests still run, just slower, and low weights wait longest. A waiting request holds its reservation, so it counts as in flight for its key's limits and for the busy line, and the upstream timeout and `duration_ms` start after the wait. A client that hangs up during the wait is not noticed until output is sent, so its request still runs and is charged; keep `max_delay_ms` well below your clients' timeouts. The wait is recorded in the usage row (`delay_ms`) and sent as `X-Virsilipas-Delay-Ms`. The spend is read from a small per-hour table that settling keeps, so it costs the same however busy the day was. With a budget of 1,000 credits:

  | Spent in 24 h | Pressure | Wait, weight 25 | Wait, weight 100 | Wait, weight 400 |
  |---|---|---|---|---|
  | 800 or less | 0 | 0 | 0 | 0 |
  | 850 | 0.25 | 1000 ms | 250 ms | 63 ms |
  | 900 | 0.5 | 2000 ms | 500 ms | 125 ms |
  | 1000 | 1 | 3000 ms | 1000 ms | 250 ms |
  | 1200 | 2 | 3000 ms | 2000 ms | 500 ms |

- **Busy shedding** (`busy`, requests in flight per source: `internal` and `external`). While a source has that many requests in flight, a request from a key whose weight is below `busy_min_weight` (default 100) gets 503 `server_busy` with `Retry-After` `ceil(5 x busy_min_weight / weight)` seconds (1 to 60) instead of a worker; keys at or above the line are served without a pacing wait (pacing applies only while the source is below its line), because a waiting request holds the worker another request needs. A request already in its pacing wait when the line is reached finishes that wait (at most `max_delay_ms`). The line is checked when a request arrives, outside the reservation, so a burst can overshoot it by the requests arriving together, and the unexpired lease of a crashed worker counts as in flight until it expires. Set each line below that pool's `pm.max_children` (for example three quarters of it) so that higher weights still find a free worker.

The hard limits (balance, concurrency, requests and credits per minute) are unchanged and still refuse. A throttle failure (for example a database error while reading the spend) never fails a request: the request goes ahead without a wait and the failure is logged as `throttle_error`.

### Caps: hard limits for the whole server

The optional `caps` block stops spending and request bursts across all keys, internal callers included. Unlike the throttle it refuses.

```php
'caps' => [
    'hour' => 1,      // upstream spend per UTC hour, in your upstream's currency unit (1 = 1.00)
    'day' => 5,       // per UTC day
    'month' => 50,    // per UTC calendar month
    'requests_per_minute' => 600,   // requests started across all keys in the last 60 seconds
],
```

Every setting is optional. A spend cap of `0` stops every request at once, which is the quickest way to pause the service without touching the web server. The config file is read on every request, so a change applies to the next request (with `opcache.validate_timestamps=0`, reload php-fpm).

- **What counts.** A spend window holds the upstream cost settled in it plus, for every request still running that started in it, an upper bound of its cost: its prompt bound and `max_tokens` priced at the highest `cost` of its route's upstreams. A new request is admitted only if its own bound still fits, checked in the same transaction as its reservation, so parallel requests cannot overshoot together. A request that does not fit is refused, never shortened: no one pays for an answer cut short by the server's budget.
- **Prices are required.** With any spend cap set, every route entry needs a `cost` (that upstream's prices in millionths of its currency unit per million tokens), or the config is refused: a request the proxy cannot price would escape the cap. A model without a route cannot carry prices, so give it a route. Keep every route's prices in one currency.
- **Settled cost.** When the upstream reports its own cost, that figure is recorded; otherwise the cost is computed from the configured prices and the counts charged (`cost_computed` in the usage row). If your configured prices are below what the upstream actually bills, the bounds and computed costs are low by the same factor, so keep them current. A settled cost is recorded as the upstream bills it even when it exceeds the request's bound (an upstream that ignores `max_tokens`, for example), so a window can end above its cap by that excess; the requests after it are refused.
- **Refusals.** A spend cap answers 503 `spend_cap_reached` with `Retry-After` until the window ends; `requests_per_minute` answers 429 `server_rate_limit` with `Retry-After` until the oldest request in the window is a minute old. Both are logged as `capped` (info). When a request's cost carries a window past 80% or 100% of its cap, a `spend_cap_crossed` line is logged at `warn`, once per crossing.
- **What runs across a boundary.** A request that started before a window began counts in that window only once it settles.
- `bin/virsilipas caps` shows each cap in force with what is settled, what running requests hold, what remains and when it resets.

### Cost and rate controls at a glance

| Control | Scope | Where you set it | When it is reached |
|---|---|---|---|
| Balance | one key | grants (`POST /admin/grants`, `bin/virsilipas grant`) | 402; `max_tokens` is first lowered to what the balance covers |
| `max_tokens_cap` | one request | config | `max_tokens` is lowered to it |
| `max_concurrent_per_key` | one key | config default; `bin/virsilipas limit` per key | 429 |
| `requests_per_minute`, `credits_per_minute` | one key | config defaults; `bin/virsilipas limit` per key | 429 with `Retry-After` |
| Disabled key | one key | `bin/virsilipas key-disable`, `POST /admin/keys/active` | 403 |
| Leaked key | one key | `bin/virsilipas key-rotate`, `POST /admin/keys/rotate` (the old key answers 401 at once) | 401 |
| Removed key | one key | `POST /admin/keys/remove` (final; balance and history stay) | 401 |
| `caps.requests_per_minute` | whole server | config `caps` | 429 `server_rate_limit` |
| `caps.hour`, `caps.day`, `caps.month` | whole server, upstream cost | config `caps` | 503 `spend_cap_reached` |
| `throttle.daily_budget` | whole server, credits | config `throttle` | requests wait, lower weights longer; nothing refused |
| `throttle.busy` | requests in flight per source | config `throttle` | keys below `busy_min_weight` get 503 `server_busy` |
| `limit_req` and `limit_conn` | client address | nginx | nginx answers 429 or 503 before the proxy runs |
| Spending limits at the provider | an upstream key | your provider | that upstream refuses (typically 402, 403 or 429); after retries the next upstream in the route is tried |

A sensible order: per-key limits and balances bound any one key; `throttle.daily_budget` (set below your day cap) slows everyone down, low priorities first, as spend rises; the caps are the hard stop; provider-side limits are the last line. Per client address, use the web server, for example:

```nginx
# in the http block:
limit_req_zone $binary_remote_addr zone=virsilipas:10m rate=10r/s;
# in the location that passes to php-fpm:
limit_req zone=virsilipas burst=20 nodelay;
limit_req_status 429;
```
### Before enabling a model

Check, with that model at its upstream (a model served by a second upstream is checked there), and again whenever the model or the upstream changes: it honours `max_tokens` (including reasoning tokens), its reported prompt tokens stay within the prompt bound (the request's byte count), it reports `usage` with all three counts, including `total_tokens`, on streams too (ideally on every chunk), its `prompt_tokens` include the cached tokens it reports in `prompt_tokens_details.cached_tokens` or `prompt_cache_hit_tokens` (send the same long prompt twice; on a cache hit `prompt_tokens` must not drop by the cached count), its cache writes, if it reports `cache_write_tokens` at all, cost no more than your `input` rate covers, and a client hang-up is detected under your real php-fpm and nginx (see [php-fpm and nginx](#php-fpm-and-nginx)). For an upstream with a `messages_url`, check the same on that endpoint with its Anthropic usage counts (`input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, `output_tokens`): the proxy charges cache writes at your `input` rate, so if the upstream charges more for a cache write, or a client's `anthropic-beta` switches on pricier features such as a longer context, price the alias for it. Do not enable a model that fails any of these. The proxy caps what it debits at the reservation; it cannot stop an upstream that generates more than it was asked for, so an upstream that ignores `max_tokens` costs you the difference (visible as `over_reservation` in the usage table).

## Releasing and upgrading

Releases are git tags `vX.Y.Z`, listed in [CHANGELOG.md](CHANGELOG.md). CI runs on every push to `main` and on every tag.

### Cutting a release

Add the version to CHANGELOG.md, commit, push, and tag only after CI on that commit is green:

```sh
git tag -a v0.2.0 -m "v0.2.0"
git push origin v0.2.0
```

A release that changes the schema raises `SCHEMA_VERSION` in `src/Ledger.php`, upgrades the previous schema in `migrate()` in one transaction, and is tested against a copy of the previous schema (`tests/fixtures/`).

### One-time host setup

The host pulls releases from a bare mirror over SSH with a read-only deploy key, so it holds no credential that can change the repository. As root:

```sh
apt install git sqlite3
ssh-keygen -t ed25519 -N '' -C virsilipas-deploy -f /root/.ssh/virsilipas_deploy
# Add /root/.ssh/virsilipas_deploy.pub to the repository as a deploy key, with write access off.
cat >> /root/.ssh/config <<'EOF'
Host virsilipas-git
    HostName github.com
    User git
    IdentityFile /root/.ssh/virsilipas_deploy
    IdentitiesOnly yes
EOF
git clone --mirror virsilipas-git:OWNER/REPOSITORY.git /opt/virsilipas.git
```

The web server serves `/opt/virsilipas/public/index.php`, and `/opt/virsilipas` becomes a symlink to the live release under `/opt/virsilipas-releases/`. `tools/deploy.php` ships inside each release, so the first time, unpack the release you are about to deploy and run its copy:

```sh
mkdir /root/virsilipas-boot
git --git-dir=/opt/virsilipas.git fetch --tags origin
git --git-dir=/opt/virsilipas.git archive v0.1.0 | tar -x -C /root/virsilipas-boot
php /root/virsilipas-boot/tools/deploy.php --url https://proxy.example.com v0.1.0
```

If `/opt/virsilipas` is still a plain directory from a manual install, the first deploy moves it to `/opt/virsilipas-releases/legacy-<revision>`.

### Upgrading

```sh
php /opt/virsilipas/tools/deploy.php --url https://proxy.example.com --log /var/log/virsilipas-deploy.log v0.2.0
runuser -u www-data -- env VIRSILIPAS_CONFIG=/etc/virsilipas/config.php \
    php /opt/virsilipas/tools/smoke.php --url https://proxy.example.com --model default
```

`deploy.php` fetches the mirror, unpacks the tag into its own directory (no `.git`, a `REVISION` file naming the tag and commit), lints it, checks that the new release accepts the config (if not, it stops there with the old release still serving), waits up to `--drain` seconds for requests in flight to finish, and stops php-fpm. It then backs up the ledger to `/var/lib/virsilipas-snapshots/pre-<tag>-<time>.sqlite`, checks the backup's integrity, runs `init` as the PHP user, switches the symlink, runs `verify`, checks `REVISION` through the symlink, and starts php-fpm. Its last check is that `GET /v1/models` without a key answers 401. `deploy.php --help` lists the paths and the service name it assumes.

If any step after php-fpm stopped fails, `deploy.php` points the symlink back at the previous release, puts the backup in place, starts php-fpm and exits 1. It restores the backup only if nothing but the migration has written to the ledger since the backup was taken. The CLI works while php-fpm is stopped, and a started release serves requests, so `deploy.php` compares every column the backup has, in every table except `credit_log`, with the backup first. If they differ, or cannot be compared, it keeps the ledger as it is; once the new schema is in place it also keeps the new release live, and it says so. php-fpm is stopped before that comparison, so the only write that can slip in between the check and the restore is a CLI command run in that moment.

`smoke.php` makes one small request with a new key (`smoke-<time>-<random>`; it costs a few dozen real tokens). It then checks the credit log rows, the balance, `GET /v1/balance`, `GET /v1/usage` and `verify`, and removes the key for good.

**Never run an older release against a newer ledger.** An older release's `init` refuses a newer schema, so `deploy.php` rolls such a deploy back. To return to the state before an upgrade, stop php-fpm, restore that upgrade's `pre-<tag>` backup as described under [Backups](#backups), and deploy the older tag. Everything written to the ledger since the upgrade is lost, so decide that consciously.

## Privacy

Request and response bodies are never stored or logged. The usage table holds token counts, credits, the model alias, the upstream, status, duration and timestamps. Keys are stored only as sha256 hashes. A request may be served by any upstream in its alias's route, so list there only upstreams whose terms and data handling you accept for your customers' prompts. The proxy's own log never holds client addresses, but your web server's access log does by default: turn it off for this site (`access_log off;` in nginx) or use a log format without the client address, or with only its network (for example the /24 or /48), if you do not need it. A Gemini-format client may send its key as a `key` query parameter (Gemini CLI sends a header); an access log that records query strings then holds that key, so leave the query string out of the log format too.

The proxy keeps no body, but the stack under it can write one to disk on its way through. To keep prompts and answers off disk entirely:

- **nginx** writes a request body larger than `client_body_buffer_size` (default 8 or 16 KB) to a temporary file. Set `client_body_buffer_size` to at least your `client_max_body_size` for this site so bodies stay in memory.
- **PHP** keeps a request body above 16 KB in a temporary file under its temporary directory (`upload_tmp_dir`, or `sys_temp_dir`, or the system default). Put that directory on a memory file system (tmpfs) for this pool.
- **Swap and core dumps** can carry a worker's memory to disk: run without swap or with encrypted swap, and keep core dumps off for the php-fpm user.
- **Upstreams** see every prompt they serve. Check each one's retention terms (and turn on its zero-retention option where it has one) before listing it in a route.

## Logging

Set `log_dir` and the proxy writes one JSON object per line to `<log_dir>/YYYYMMDD.jsonl`, a new file each UTC day, appended under a lock so parallel workers never interleave. Without `log_dir`, the same lines go to PHP's `error_log`. Every record has `ts`, `level` and `event`, plus counts, statuses and billing references; never bodies, prompts, keys, the admin token, upstream error text, request paths or client addresses. A failed log write never affects a request. Make `log_dir` writable by its owner only (for example mode 700, owned by the php-fpm user). New daily files are created with mode 600. If the directory is writable by group or others, or a daily file is readable by them or is a symlink, nothing is written there and the records go to `error_log` instead.

| `log_level` | Adds | Events |
|---|---|---|
| `error` | unexpected failures | `exception` (class and code location; run `bin/virsilipas` to see a configuration error in full) |
| `warn` (default) | problems with the upstream or its configuration | `request` that failed upstream, was charged an estimate, or reported usage above its reservation; `upstream_attempt` (a failed attempt before a retry or failover: the route entry, the try, the upstream host, its status and what followed); `upstream_skipped` (an upstream whose key file is missing or empty); `throttle_error` (the throttle could not decide; the request went ahead without a wait); `spend_cap_crossed` (a request's cost carried a spend window past 80% or 100% of its cap); `cap_log_error` (the crossing check itself failed; the request is unaffected); `admin_auth_failed` |
| `info` | every request and admin action | `request`, `refused` (status and the proxy's fixed reason), `throttled` (a delayed or shed request: its weight and the wait or `Retry-After`), `capped` (refused by a server-wide cap: the code and `Retry-After`), `key_create`, `key_rotate`, `key_remove`, `key_active`, `key_limits`, `grant` |
| `debug` | request admission | `admit` (alias, prompt bound, reservation) |

Each `request` record carries `settle_ms`, the milliseconds the charge and settle took after the answer was written (the client waits for them; ADR 0027); it grows when writers queue for the ledger's write lock. The usage table stays the per-request billing record; the log is for operating the service. At `info` the proxy writes about 260 bytes per request (about 2.2 GB a day at 100 requests a second), so `warn` is the default. Nothing is deleted automatically, and at `info` each line carries the key's ref, so keep the files only as long as you need them. logrotate does not fit names that change every day (it rotates a fixed name, and a pattern leaves each day's file to the next run, or never). Compress and expire them by age instead, for example from root's crontab:

```sh
15 0 * * * find /var/log/virsilipas -maxdepth 1 -name '*.jsonl' -mtime +1 -exec gzip -q {} + ; find /var/log/virsilipas -maxdepth 1 -name '*.jsonl.gz' -mtime +90 -delete
```

## Parallelism

Each request holds one php-fpm worker for its whole duration, so `pm.max_children` caps how many requests the proxy serves at once; `max_concurrent_per_key` stops one key from taking more than its share, and an upstream that is busy returns 429 to the client without a charge. The ledger is one SQLite file in WAL mode: every write is a short transaction, nothing is held open while a request streams, and other writers wait up to 10 seconds. Measured with the load tool below on the PHP built-in server: 30 concurrent streams on 6 keys, 600 requests, no errors (no locked-database failures among them), and every key debited exactly what was reported to it. Size `pm.max_children` for your own peak and measure it with the tool on your deployment.

## Load testing

```sh
php tools/bench.php --url=https://proxy.example --key-file=/path/to/key --model=default \
    --requests=500 --concurrency=20 --processes=4 --stream
```

Runs the requests with `--concurrency` in flight per process across `--processes` processes, then reports requests per second, latency and time to first byte (p50, p95, p99), errors by status, and whether the key's balance fell by exactly the credits the proxy reported (`usage.credits`; `ledger_exact`; the key must have no other traffic, or pass `--no-ledger-check`). The result is one line on stdout, `[virsilipas-bench] {...}` (schema `virsilipas.bench.v2`), and the exit code is non-zero on any error or ledger mismatch, or when the starting balance cannot be read. `ledger_exact` compares the balance with the usage reported to the client, so it also reads `false` when the proxy charged an estimate because the upstream did not report usage; the usage table's `estimated` flag tells the two apart. Keep `--concurrency` × `--processes` within the key's concurrency limit and the request rate within its per-minute limits, or the excess is refused with 429.

## CLI

```
virsilipas init | key-create <ref> [--expires=<when>] [--named] [--json] | key-rotate <ref> | key-disable <ref> | key-enable <ref> | key-remove <ref> | key-ref <sha256>
           | grant <ref> <credits> <grant_id> [expires_at]
           | limit <ref> <max_concurrent|requests_per_minute|credits_per_minute> <N|default> | status <ref>
           | bonus <ref> <0..300> | group <ref> <name|-> | history <ref> [N] | verify | caps
           | stats <hour|day|week|month|all|hour_of_day|weekday> [by] [since] [until] | health [since] [until]
           | public-usage <ref-prefix> [file] | public-top <ref-prefix> [file] | keys | top [--once] [--interval N]
```

`key-create` prints the new key alone, as before, unless given flags ([ADR 0024](docs/adr/0024-keys-can-expire-and-carry-their-ref.md)). `--expires=<when>` makes the key stop working at `<when>`: `+N` followed by `m`, `h` or `d` (minutes, hours or days from now, N from 1 to 999999), or a UTC time `YYYY-MM-DDTHH:MM:SSZ`; anything else, or a time not in the future, is refused. From that moment every client route answers 403 `key_expired`, also a request whose reservation comes just after it; a request already running finishes. `--named` makes a key that carries its ref, `vsk-<ref>-<48 hex digits>` (the same random part as a plain key, `vsk-<48 hex digits>`), so the owner can tell keys apart; the ref must then be 1 to 64 of `A-Z a-z 0-9 _ -`. `--json` prints one line `{"ref", "key", "expires_at", "named"}` instead of the bare key. `POST /admin/keys` makes plain keys that never expire. `key-rotate` keeps the ref's expiry and always gives a plain key. `key-remove` removes a key for good, as `POST /admin/keys/remove` does. `grant` takes an optional `expires_at` (unix seconds, stored, not enforced), as `POST /admin/grants` does. `--help` prints the usage without needing a config. `public-usage` prints the aggregate usage of the keys whose ref starts with the prefix, for a deployment that publishes it (a status page, an open data feed; [ADR 0023](docs/adr/0023-public-aggregate-usage.md)): credits, requests, tokens, the share of 2xx answers and of retried requests and the mean time to first byte over the last 24 hours, 7 and 30 days (complete UTC hours, ending at `as_of`), every model over 30 days, the last 30 days with each day's top 5 models, the last 7 days hour by hour, and a weekday by hour heatmap. It carries no ref, no count of keys, no upstream, no cost and no grant, and model names as configured. With a file it writes atomically and makes the file readable by everyone, for a web server to serve; for example hourly from cron as the php-fpm user: `bin/virsilipas public-usage cust- /var/www/status/usage.json`. It also lists every model over the last 24 hours and 7 days (`models_24h`, `models_7d`), in the same shape as `models_30d`. `public-top` prints the 10 keys with the most credits used in the last 24 hours, 7 and 30 days, each under an anonymous id that only the key's owner can link to themselves: SHA-256 applied 1000 times, first to `<ref>:<sha256 hex of the key>`, then to the previous hex digest ([ADR 0026](docs/adr/0026-public-top-list-under-anonymous-ids.md)), with credits, requests and the share of the window's credits. It carries no ref, no key digest and no model; a removed key is not listed. With a file it writes the same way as `public-usage`. `health [since] [until]` prints one JSON object for monitoring the service over that span (UTC days, `until` exclusive; default yesterday): `counts` of requests, failed (non-2xx), aborted, estimated, retried, `over_reservation`, `deadline_hits` (requests that ran to within 5 seconds of `upstream_timeout`) and `no_first_byte` (streamed requests that never sent the client a byte), and `buckets`, one per prompt size (`lt1k`, `1k-8k`, `8k-32k`, `32k-128k`, `ge128k` prompt tokens) and stream mode, each with its requests, the answered ones (2xx, not hung up, first byte known), their first-byte p50 and p95 in milliseconds (nearest rank) and, for streams, the median output tokens per second after the first byte. It carries no ref, model or content. `key-ref <sha256>` prints `{"ref": ...}` for the key whose sha256 hex digest that is, disabled keys included, or `{"ref": null}` for a rotated key's old digest, a removed key or none: for a deployment's own services that keep only a key's digest. `keys` prints one JSON line per key: ref, active, balance, group, bonus, when it was created, when it was last used, when it was removed (`null` while it lives) and when it expires (`null`: never); `status <ref>` shows `expires_at` too. `top` is a live view of the traffic, redrawn every `--interval` seconds (default 2, 1 to 60) until interrupted; `--once` prints one view without terminal control codes. It shows the requests in flight (internal and external, the oldest one's age, and the ten oldest by key ref, source, age and credits reserved), the caps against their limits, and for the last minute, 5 minutes and hour: requests and requests per second, failures, duration and time to first byte (p50 and p95, over successful requests), output tokens per second, tokens, credits charged, the upstream cost, retried requests and client hang-ups; then the last 5 minutes by model and upstream, and the most recent requests with their key ref, status and timings. It reads the ledger only, so it shows metadata and never a prompt or an answer. Run it as the php-fpm user (or root) with the same config.

## Tests

```sh
php tests/run.php            # unit and end-to-end tests, no network access needed
php tests/run.php integration
```

The end-to-end tests start a fake upstream and seven proxy processes with the PHP built-in server. Separate processes are needed because one built-in server process queues connections and serves them one at a time. They include a load smoke test that runs `tools/bench.php` with parallel streams across two processes, and runs of `tools/smoke.php` against the test proxy. Run the suite from a git checkout: `tests/SecretGuardTest.php` reads the tracked files and fails if a config file, key, certificate, database, log or credential value is committed, and it fails rather than passes when git cannot list the files. Words a deployment must never publish, such as its upstream provider, are not stored in the repository: list them in `.forbidden-words` (gitignored, one per line) or in `VIRSILIPAS_FORBIDDEN_WORDS` (comma-separated; CI reads a repository secret of that name), and the guard refuses them in any tracked file however they are spaced or cased.

CI (GitHub Actions) runs a syntax lint of every PHP file and the whole suite on PHP 8.2, 8.3, 8.4 and 8.5 with every error, notice and deprecation reported (a `Deprecated` or `Warning` line in a test server's log fails the run), keeps the test output when a run fails, and runs PHPStan as an advisory check that reports but does not fail the build.

## Contributing

Issues and pull requests are welcome. Read [AGENTS.md](AGENTS.md) first: it is the project's governing text, with the rules a change has to keep (no dependencies, nothing deployment-specific, no request bodies stored or logged, fail closed) and the invariants the tests protect, and it points to the design decisions in [docs/adr/](docs/adr/). Run `php tests/run.php` before you send a change, and add a test for anything that touches metering, grants, throttling or error handling.

Contributions are accepted under the Apache License 2.0, as its section 5 provides: what you submit is licensed under the same terms as the project. There is no contributor licence agreement and no sign-off to add. Report security issues privately as [SECURITY.md](SECURITY.md) says, not in a public issue.

## Reporting security issues

See [SECURITY.md](SECURITY.md): report vulnerabilities privately, not in a public issue.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

"OpenAI-compatible" describes the API format this proxy speaks. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
