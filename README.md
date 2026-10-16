# mcxVirsilipas

A small PHP proxy that gives every API key a prepaid credit balance and meters each LLM request against it, streaming included. Fresh input, cached input and output each cost their own number of credits per model, so the balance follows what requests cost you.

- One key per user, one balance per key. The balance never expires and never resets.
- Your billing system adds credits through an admin endpoint ("grants"). Each grant applies once, however often it is retried.
- Clients can read their remaining credits, their limits and their last minute's use; you set per-key limits on concurrency, requests per minute and credits per minute.
- Clients talk to it like any OpenAI-compatible endpoint (`/v1/chat/completions`, `/v1/models`), for text requests. Two example clients are in `examples/`.
- Optional soft throttling: when the day's spend nears a budget, or the server is busy, requests slow down or are asked to retry, by a per-key priority weight (more credit and a larger bonus mean higher priority), instead of being refused.
- A key never spends more than its balance, parallel requests included: each request reserves an upper bound first, the reservation never exceeds the balance, and the charge never exceeds the reservation.
- Works with OpenAI-compatible upstreams that honour `max_tokens` and report usage. You choose which models customers may use; check each one against the launch list below before enabling it.
- No dependencies beyond PHP 8.2 with the `curl` and `pdo_sqlite` extensions. Storage is one SQLite file.

**The name.** *Virsilipas* is the song-box of the Kalevala. The singer opens it before singing ("virsilippahan viritän", Runo I), and the giant Vipunen, "in whose mouth was great knowledge", opens it to sing out all he knows (Runo XVII). This proxy opens the box for as many words as a key has paid for.

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
4. **Settle.** When the response ends, the credits for the tokens the upstream reported are charged, never more than the reservation, and the rest is returned, exactly once per request, together with a usage row (token counts, cached count and credits, plus the tracking fields below). A report counts only with integer `prompt_tokens`, `completion_tokens` and `total_tokens`, the total at least the sum of the other two; anything else is charged as missing usage. A charge above the reservation is recorded as `over_reservation` so you can see an upstream that ignores `max_tokens`.

Details that matter in production:

- **Client hangs up mid-stream.** The proxy stops relaying and keeps reading the upstream until a usage report covers what the client received, then stops the upstream request, so the charge follows the upstream's own count. With an upstream that reports usage on every chunk (configure it in `stream_options`) that is the next report, and the charge can include a few chunks the client never received. If such an upstream sends the cached count only with its final report, that next report has none, so a hang-up charges the cached part of the prompt as fresh input. With an upstream that reports usage only at the end, it is the final report: the upstream finishes the answer (at most `max_tokens`, capped at the reservation) and the whole generation is charged. If no report comes before the stream ends, the charge is an upper bound (the prompt bound at the input rate plus the bytes delivered at the output rate), capped at the reservation, and the usage row is flagged `estimated`.
- **Responses** are rebuilt from known fields only: id, object, created, choices (with their message or delta text, reasoning, tool calls and finish reason), the alias as the model, and as usage `prompt_tokens`, `completion_tokens`, `total_tokens`, `prompt_tokens_details.cached_tokens`, `completion_tokens_details.reasoning_tokens` (the output tokens spent thinking, 0 when the upstream reports none) and `credits` (what the reported usage costs, at most the reservation). In a stream, each event that carries usage shows the report the request is charged by, so the last one matches the charge unless text arrived after the last report that advanced the counts; a later equal or lower report does not cover that text, and the charge adds an upper bound for it (flagged `estimated`). Each kept field must also have its expected type (text fields strings, tool-call arguments a string); a value of any other type is dropped. Anything else an upstream adds (costs, account data, provider names, its model ids) is dropped.
- **Upstream errors** never produce a 200 before a stream has started; once it has (the 200 is already sent), an upstream error ends the stream with a generic error event and no `[DONE]`, and the output relayed until then is charged. A non-streaming answer must arrive complete and carry a usable answer: non-empty text, reasoning or refusal, or a function tool call with a non-empty id, name and arguments. A stream starts only with an event that carries a completion choice (a delta with at least one valid field); events dropped before that add nothing to the charge, and at `[DONE]` the proxy stops reading the upstream, so nothing after it is relayed or charged. Otherwise the client gets one of the proxy's own errors (see *Errors* under Endpoints), no upstream text, and no charge. A stream that ends without its final marker gets an error event at the end (`code` `upstream_error`).
- **Retries and failover.** Before anything has been sent to the client, a failed attempt is retried on the same upstream (`retries`, 0 to 2, default 1) only when the upstream cannot have done the work: the connection failed before the request was sent, or it answered 408, 409, 429, 502, 503 or 504. Between tries the proxy waits the upstream's `Retry-After` (or `retry-after-ms`) when it is at most 60 seconds, else 0.5 s, then 1 s. When the tries are used up, or the upstream answered 500 (or another 5xx), 401, 402, 403 or 404, the request moves to the next upstream in the alias's route. It ends at once, with no retry and no failover, when output may already exist (a 2xx status arrived, or a sent request timed out or broke) and when the upstream rejected the request itself (400, 413, 422 and other statuses), because every upstream would. All attempts together get `upstream_timeout`. One reservation covers them all, the customer pays the alias's rates whichever upstream answered, and only the answer delivered is charged. The usage row records the upstream that served the request (or was tried last), and each failed attempt is logged as `upstream_attempt` (warn).
- **Credit log.** Every change to a balance writes one row to `credit_log` in the same transaction: a grant (its `grant_id`), a reservation taken when a request starts and the unused part returned when it settles (both `lease:<id>`), or the `opening` balance carried over by a schema upgrade. Each row holds the change and the balance after it; a change of zero (a request that used its whole reservation) writes no row. The table is append-only: the database refuses UPDATE and DELETE on it. `bin/virsilipas history <ref> [N]` shows a key's last N changes; `bin/virsilipas verify` checks that every key's changes sum to its balance and that each row's balance is the running sum, and exits 1 on any mismatch, which means something changed a balance outside the proxy.
- **Usage over time.** Each usage row also carries `upstream` (the host of the route URL that served the request), `stream`, `duration_ms`, `reasoning_tokens` (the reasoning share of the output tokens, read from `completion_tokens_details.reasoning_tokens` or a top-level `reasoning_tokens`) and `upstream_cost` (the upstream's own `estimated_cost`, kept in millionths of its unit; `NULL` when it reports none). None of these change what is charged; a request with none of this detail still gets a usage row. In a stream, a repeat report with the same three counts may add a detail the adopted report lacked (the cached split, the reasoning count, the cost) once; a later repeat cannot change or remove it. `bin/virsilipas stats <hour|day|month|all> [by] [since] [until]` prints one JSON line per UTC time bucket and group (`by` is a comma list of `model`, `upstream`, `key`, `status`, or `-` for none; `since`/`until` are `YYYY-MM-DD` in UTC, `until` exclusive) with requests, prompt/cached/completion/reasoning tokens, credits charged, the upstream's reported cost and how many requests carried one, hang-ups, estimated charges, failed requests (non-2xx), the mean duration, and the requests that waited for spend pacing (`delayed`) with their mean wait (`mean_delay_ms`).
- **Crashed workers.** A lease outlives its request only if its worker died. When it expires it stops counting against concurrency, but its reservation stays charged, since the answer may already have been delivered (a worker that dies just before forwarding also leaves its reservation charged); if the request is settled late, the difference is returned. `bin/virsilipas status <ref>` counts such leases as `held`. `lease_seconds` must be longer than `upstream_timeout`.
- **Runaway output.** If the credits for a stream's reported usage pass the reservation, the proxy stops the upstream request there. Output delivered after the last usage report that advanced the count is charged as an upper bound (its bytes); a repeated or lower report does not cover it. Upstreams that report usage on every chunk often send the cached split only with the final report, whose counts repeat the last running report; such a repeat adds the cached split once and nothing else; a later repeat cannot change or remove it.
- **Limits.** Each key has three: `max_concurrent` (requests in flight; stops one key from using up a shared upstream rate limit), `requests_per_minute` and `credits_per_minute` (over the last 60 seconds, counting each request's charge, or its reservation while it runs). The config sets the defaults (`max_concurrent_per_key`, `requests_per_minute`, `credits_per_minute`; the last two unset means no limit) and the admin endpoint sets a key's own values, higher or lower. A request is refused (429) when the key is at a limit; the credits limit is checked when a request starts and a request is admitted while the window is below it, so a window holds at most the limit plus one request; the request that crosses it is served in full, never shortened. Further requests are refused until the window falls below the limit again, either because requests age out of it or because a running request settles for less than it reserved. The refusal order is disabled key, concurrency, requests, credits, balance. Refusals carry `Retry-After`: for the per-minute limits, the seconds until the oldest request in the window turns a minute old (the latest the window can start to free; a settling request can free it sooner); for concurrency, 1.

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
  | 850 | 0.25 | 1000 ms | 250 ms | 62 ms |
  | 900 | 0.5 | 2000 ms | 500 ms | 125 ms |
  | 1000 | 1 | 3000 ms | 1000 ms | 250 ms |
  | 1200 | 2 | 3000 ms | 2000 ms | 500 ms |

- **Busy shedding** (`busy`, requests in flight per source: `internal` and `external`). While a source has that many requests in flight, a request from a key whose weight is below `busy_min_weight` (default 100) gets 503 `server_busy` with `Retry-After` `ceil(5 x busy_min_weight / weight)` seconds (1 to 60) instead of a worker; keys at or above the line are served without a pacing wait (pacing applies only while the source is below its line), because a waiting request holds the worker another request needs. A request already in its pacing wait when the line is reached finishes that wait (at most `max_delay_ms`). The line is checked when a request arrives, outside the reservation, so a burst can overshoot it by the requests arriving together, and the unexpired lease of a crashed worker counts as in flight until it expires. Set each line below that pool's `pm.max_children` (for example three quarters of it) so that higher weights still find a free worker.

The hard limits (balance, concurrency, requests and credits per minute) are unchanged and still refuse. A throttle failure (for example a database error while reading the spend) never fails a request: the request goes ahead without a wait and the failure is logged as `throttle_error`.

### Before enabling a model

Check, with that model at its upstream (a model served by a second upstream is checked there), and again whenever the model or the upstream changes: it honours `max_tokens` (including reasoning tokens), its reported prompt tokens stay within the prompt bound (the request's byte count), it reports `usage` with all three counts, including `total_tokens`, on streams too (ideally on every chunk), its `prompt_tokens` include the cached tokens it reports in `prompt_tokens_details.cached_tokens` or `prompt_cache_hit_tokens` (send the same long prompt twice; on a cache hit `prompt_tokens` must not drop by the cached count), its cache writes, if it reports `cache_write_tokens` at all, cost no more than your `input` rate covers, and a client hang-up is detected under your real php-fpm and nginx (see below). Do not enable a model that fails any of these. The proxy caps what it debits at the reservation; it cannot stop an upstream that generates more than it was asked for, so an upstream that ignores `max_tokens` costs you the difference (visible as `over_reservation` in the usage table).

## Install

```sh
cp config.example.php config.php      # edit: upstream, models, admin token hash, paths
bin/virsilipas init                    # creates the SQLite database
bin/virsilipas key-create customer-42  # prints the key once
bin/virsilipas grant customer-42 1000000 invoice-1001   # adds 1,000,000 credits
```

Point your web server at `public/index.php` for every path. The config path can be set with `VIRSILIPAS_CONFIG`.

### php-fpm and nginx

- **One worker per open stream.** A streaming request holds a php-fpm worker for its whole duration. Size `pm.max_children` for your peak number of concurrent streams, and set `request_terminate_timeout` above `upstream_timeout`.
- **Turn buffering off**, or streams arrive all at once at the end. The proxy sends `X-Accel-Buffering: no`; also set `fastcgi_buffering off;` (or `proxy_buffering off;`) and a `fastcgi_read_timeout` above `upstream_timeout`. Keep `zlib.output_compression` off for this pool.
- **Database directory** must be writable by the PHP user (SQLite WAL creates files next to the database). The proxy creates its files private to its user, and refuses a database, WAL or shared-memory file that group or others can read, and a directory that group or others can write.
- **Before launch, test a client hang-up under your real php-fpm and nginx.** Abort detection depends on the server telling PHP that the connection closed; it is covered by the tests under the PHP built-in server, not under every stack.

### Access control

The proxy checks keys, not addresses. Decide who can reach it with your firewall. To stop all traffic at once, stop the php-fpm pool or block the port. To stop one customer, disable their key.

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

To restore, stop the php-fpm pool, put the backup in place as the database file, delete any leftover `-wal` and `-shm` files next to it, and make it private to the PHP user (`chmod 600`): the proxy refuses a database that group or others can read, and the backup copy is usually created group-readable.

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

`deploy.php` fetches the mirror, unpacks the tag into its own directory (no `.git`, a `REVISION` file naming the tag and commit), lints it, waits up to `--drain` seconds for requests in flight to finish, and stops php-fpm. It then backs up the ledger to `/var/lib/virsilipas-snapshots/pre-<tag>-<time>.sqlite`, checks the backup's integrity, runs `init` as the PHP user, switches the symlink, runs `verify`, checks `REVISION` through the symlink, and starts php-fpm. Its last check is that `GET /v1/models` without a key answers 401. `deploy.php --help` lists the paths and the service name it assumes.

If any step after php-fpm stopped fails, `deploy.php` points the symlink back at the previous release, puts the backup in place, starts php-fpm and exits 1. It restores the backup only if nothing but the migration has written to the ledger since the backup was taken. The CLI works while php-fpm is stopped, and a started release serves requests, so `deploy.php` compares every table except `credit_log` with the backup first. If they differ, or cannot be compared, it keeps the ledger as it is; once the new schema is in place it also keeps the new release live, and it says so. php-fpm is stopped before that comparison, so the only write that can slip in between the check and the restore is a CLI command run in that moment.

`smoke.php` makes one small request with a new key (`smoke-<time>-<random>`; it costs a few dozen real tokens). It then checks the credit log rows, the balance and `verify`, and disables the key.

**Never run an older release against a newer ledger.** An older release's `init` refuses a newer schema, so `deploy.php` rolls such a deploy back. To return to the state before an upgrade, stop php-fpm, restore that upgrade's `pre-<tag>` backup as described under [Backups](#backups), and deploy the older tag. Everything written to the ledger since the upgrade is lost, so decide that consciously.

## Endpoints

Customer side, authenticated with `Authorization: Bearer <key>`:

| Method and path | What it does |
|---|---|
| `POST /v1/chat/completions` | OpenAI-compatible chat completions, streaming or not. `model` must be one of your aliases. |
| `GET /v1/models` | Lists the aliases: `id`, `object`, `created`, `owned_by`, the configured `description` and `context_length` when set, `max_completion_tokens` (the `max_tokens_cap`) and `credits_per_million_tokens` (`input`, `cached_input`, `output`). Upstream model ids and URLs are never shown. |
| `GET /v1/balance` | For the calling key: `balance` (credits left, already net of running requests), `reserved` (credits held by running or unsettled requests), `in_flight`, `limits` (`max_concurrent`, `requests_per_minute`, `credits_per_minute`; `null` = no limit), `window` (`requests` and `credits` over the last 60 seconds), `bonus_pct` and `weight` (the key's priority, for the caller's source; see *Priority and throttling*). |

Errors use OpenAI's shape: `{"error": {"message": "...", "type": "...", "param": ..., "code": "..."}}`, with fixed messages and these codes:

| Status | `code` | When |
|---|---|---|
| 400 | `invalid_json`, `invalid_messages`, `unsupported_content`, `invalid_value` | the request itself (`param` names the field) |
| 400 | `context_length_exceeded` | the upstream said the request is longer than the model accepts (`param` `messages`) |
| 400 | `upstream_rejected` | the upstream rejected the request as invalid |
| 401 | `invalid_api_key` | missing or unknown key |
| 402 | `insufficient_quota` | credit balance too low for this request |
| 403 | `key_disabled` | the key is disabled |
| 404 | `model_not_found` | unknown alias (`param` `model`; see `/v1/models`) |
| 413 | `request_too_large` | request body over 4 MiB |
| 429 | `rate_limit_exceeded` | a key limit (concurrency, requests or credits per minute; the message says which) |
| 429 | `upstream_busy` | the upstreams were busy |
| 502 | `upstream_error` | an upstream failed (500, or rejected its own key or model id) or answered without a usable answer |
| 503 | `server_busy` | the server is busy and the key's weight is below the busy line (see *Priority and throttling*) |
| 503 | `upstream_unavailable` | no upstream could take the request: unreachable, without its key, or still busy or down (502, 503, 504) after its retries |
| 504 | `upstream_timeout` | `upstream_timeout` passed before an answer |

429 and 503 carry `Retry-After`. A chat request that got past its key, model and balance checks carries `X-Request-Id` in its response, an upstream error included: the lease id of its usage row and log lines, for support. Every admitted chat request also carries `X-Virsilipas-Weight` and `X-Virsilipas-Delay-Ms` (the wait before it started, 0 when none). Only `context_length_exceeded` is ever taken from an upstream, and only as that code; nothing else an upstream said reaches the client.

Admin side, authenticated with `Authorization: Bearer <admin token>`. Use a long random token (32 bytes or more); only its sha256 is stored in the config. The grant response returns the new balance; `bin/virsilipas status <ref>` shows it at any time.

| Method and path | Body | What it does |
|---|---|---|
| `POST /admin/keys` | `{"ref": "customer-42"}` | Creates a key for a user. `ref` must be your billing system's stable id for the user (not a per-service id), so all of the user's purchases add to one balance. Returns the key once (201). |
| `POST /admin/keys/active` | `{"ref": "...", "active": false}` | Disables or re-enables a key (for suspend and unsuspend). |
| `POST /admin/grants` | `{"ref": "...", "grant_id": "invoice-1001", "credits": 1000000}` | Adds credits once per `grant_id`; a retry returns `applied: false`, a `grant_id` reused for another ref or amount returns 409. Negative credits remove credits (refunds). At most 10^15 per grant. |
| `POST /admin/keys/limits` | `{"ref": "...", "requests_per_minute": 60}` | Sets any of `max_concurrent`, `requests_per_minute` and `credits_per_minute` for a key: an integer from 1 to 10^15, or `null` to follow the config default again. Also sets `bonus_pct`, an integer from 0 to 300 (the bonus in the key's weight). Fields left out are unchanged; any other field is refused (400), and nothing changes when any field is invalid. Returns the key's own settings (`overrides`), the limits in force (`limits`) and `bonus_pct`. |

Use a `grant_id` that is unique per payment, for example the invoice number or the subscription id plus its billing period, so that a retried push never credits twice and a renewal is never dropped.

## Privacy

Request and response bodies are never stored or logged. The usage table holds token counts, credits, the model alias, the upstream, status, duration and timestamps. Keys are stored only as sha256 hashes. A request may be served by any upstream in its alias's route, so list there only upstreams whose terms and data handling you accept for your customers' prompts.

## Logging

Set `log_dir` and the proxy writes one JSON object per line to `<log_dir>/YYYYMMDD.jsonl`, a new file each UTC day, appended under a lock so parallel workers never interleave. Without `log_dir`, the same lines go to PHP's `error_log`. Every record has `ts`, `level` and `event`, plus counts, statuses and billing references; never bodies, prompts, keys, the admin token, upstream error text, request paths or client addresses. A failed log write never affects a request. Make `log_dir` writable by its owner only (for example mode 700, owned by the php-fpm user). New daily files are created with mode 600. If the directory is writable by group or others, or a daily file is readable by them or is a symlink, nothing is written there and the records go to `error_log` instead.

| `log_level` | Adds | Events |
|---|---|---|
| `error` | unexpected failures | `exception` (class and code location; run `bin/virsilipas` to see a configuration error in full) |
| `warn` (default) | problems with the upstream or its configuration | `request` that failed upstream, was charged an estimate, or reported usage above its reservation; `upstream_attempt` (a failed attempt before a retry or failover: the route entry, the try, the upstream host, its status and what followed); `upstream_skipped` (an upstream whose key file is missing or empty); `throttle_error` (the throttle could not decide; the request went ahead without a wait); `admin_auth_failed` |
| `info` | every request and admin action | `request`, `refused` (status and the proxy's fixed reason), `throttled` (a delayed or shed request: its weight and the wait or `Retry-After`), `key_create`, `key_active`, `key_limits`, `grant` |
| `debug` | request admission | `admit` (alias, prompt bound, reservation) |

The usage table stays the per-request billing record; the log is for operating the service. At `info` the proxy writes about 260 bytes per request (about 2.2 GB a day at 100 requests a second), so `warn` is the default. Nothing is deleted automatically: rotate or compress old daily files with your usual tooling.

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
virsilipas init | key-create <ref> | key-disable <ref> | key-enable <ref> | grant <ref> <credits> <grant_id>
           | limit <ref> <max_concurrent|requests_per_minute|credits_per_minute> <N|default> | status <ref>
           | bonus <ref> <0..300> | history <ref> [N] | verify | stats <hour|day|month|all> [by] [since] [until]
```

## Example clients

```sh
VIRSILIPAS_URL=https://proxy.example VIRSILIPAS_KEY=... php examples/hello-world.php "Say hello"

export VIRSILIPAS_URL=https://proxy.example
php examples/client.php models --key-file /path/to/key
php examples/client.php balance --key-file /path/to/key
php examples/client.php chat "one word, please" --model default --key-file /path/to/key
```

`examples/hello-world.php` is the smallest client: one question to the `default` alias (or `VIRSILIPAS_MODEL`), the answer, and the credits it cost. `examples/client.php` is a fuller one with no dependencies beyond the `curl` and `json` extensions. The key comes from `--key-file` or `VIRSILIPAS_KEY`, never the command line, so it never shows in `ps` and is never printed. `chat` streams the answer as it arrives (`--no-stream` for one JSON answer) and hides reasoning unless `--show-thinking` is given; it ends with a `usage:` line (prompt, completion, cached and reasoning tokens, and the credits charged). `models` prints each alias's rates; `balance` prints the balance, reservations, requests in flight, weight, bonus, limits and the last minute's use. On a 429 or 503 with `Retry-After` it waits and retries once; any other refusal prints the proxy's error (`message`, `type`, `code`) to stderr. Exit codes: 0 ok, 1 request failed, 2 usage error.

## Tests

```sh
php tests/run.php            # unit and end-to-end tests, no network access needed
php tests/run.php integration
```

The end-to-end tests start a fake upstream and seven proxy processes with the PHP built-in server. Separate processes are needed because one built-in server process queues connections and serves them one at a time. They include a load smoke test that runs `tools/bench.php` with parallel streams across two processes, and runs of `tools/smoke.php` against the test proxy. Run the suite from a git checkout: `tests/SecretGuardTest.php` reads the tracked files and fails if a config file, key, certificate, database, log or credential value is committed, and it fails rather than passes when git cannot list the files. Words a deployment must never publish, such as its upstream provider, are not stored in the repository: list them in `.forbidden-words` (gitignored, one per line) or in `VIRSILIPAS_FORBIDDEN_WORDS` (comma-separated; CI reads a repository secret of that name), and the guard refuses them in any tracked file however they are spaced or cased.

CI (GitHub Actions) runs a syntax lint of every PHP file and the whole suite on PHP 8.2, 8.3 and 8.4, keeps the test output when a run fails, and runs PHPStan as an advisory check that reports but does not fail the build.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

"OpenAI-compatible" describes the API format this proxy speaks. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
