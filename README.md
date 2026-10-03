# mcxVirsilipas

A small PHP proxy that gives every API key a prepaid token balance and meters each LLM request against it, streaming included.

- One key per user, one balance per key. The balance never expires and never resets.
- Your billing system adds tokens through an admin endpoint ("grants"). Each grant applies once, however often it is retried.
- Clients talk to it like any OpenAI-compatible endpoint (`/v1/chat/completions`, `/v1/models`), for text requests.
- A key never spends more than its balance, parallel requests included: each request reserves an upper bound first, the reservation never exceeds the balance, and the charge never exceeds the reservation.
- Works with OpenAI-compatible upstreams that honour `max_tokens` and report usage. You choose which models customers may use; check each one against the launch list below before enabling it.
- No dependencies beyond PHP 8.2 with the `curl` and `pdo_sqlite` extensions. Storage is one SQLite file.

**The name.** *Virsilipas* is the song-box of the Kalevala. The singer opens it before singing ("virsilippahan viritän", Runo I), and the giant Vipunen, "in whose mouth was great knowledge", opens it to sing out all he knows (Runo XVII). This proxy opens the box for as many words as a key has paid for.

## How metering works

1. **Admit.** Only text requests are accepted: message content must be a string or text parts; images, audio and files are refused, because their cost cannot be bounded before sending. Messages keep only role, content, name, tool_calls and tool_call_id, and text parts only their text; each tool call must carry a string id, type, function name and arguments (a JSON string), and a request with any other shape is refused. Only a fixed list of fields is forwarded (messages, tools, tool_choice, parallel_tool_calls, temperature, top_p, stop, seed, presence and frequency penalties, response_format, reasoning_effort). Everything else, including `n`, `store` and output-mode options, is dropped.
2. **Reserve.** The prompt bound is the byte length of the forwarded fields (a token is at least one byte). The proxy reserves `prompt bound + max_tokens` in one atomic transaction, with `max_tokens` clamped to what the balance can cover. Each reservation is a lease row, which also counts against the key's concurrency limit. Because the bound counts bytes, a long prompt temporarily holds roughly four times its real token count (a 45 kB agent prompt holds about 46,000 tokens for about 11,500 used); a key needs that much free balance to start the request, and the unused part comes back when it settles.
3. **Forward.** The request goes upstream with the alias replaced by the real model id and `stream_options` set by the proxy so the upstream reports usage.
4. **Settle.** When the response ends, the tokens the upstream reported are charged, never more than the reservation, and the rest is returned, exactly once per request, together with a usage row (token counts only). A report counts only with integer `prompt_tokens`, `completion_tokens` and `total_tokens`, the total at least the sum of the other two; anything else is charged as missing usage. Reported usage above the reservation is recorded as `over_reservation` so you can see an upstream that ignores `max_tokens`.

Details that matter in production:

- **Client hangs up mid-stream.** The proxy stops the upstream request and charges for what was generated up to that point. With an upstream that reports usage on every chunk (configure it in `stream_options`) the charge follows the upstream's own count; it can include a few chunks the client never received, generated before the disconnect was noticed. Without usage, the charge is an upper bound (the prompt bound plus the bytes delivered), capped at the reservation, and the usage row is flagged `estimated`.
- **Responses** are rebuilt from known fields only: id, object, created, choices (with their message or delta text, reasoning, tool calls and finish reason), the alias as the model, and `prompt_tokens`, `completion_tokens` and `total_tokens` as usage. Each kept field must also have its expected type (text fields strings, tool-call arguments a string); a value of any other type is dropped. Anything else an upstream adds (costs, account data, provider names, its model ids) is dropped.
- **Upstream errors** never produce a 200. A non-streaming answer must arrive complete and carry a usable answer: non-empty text, reasoning or refusal, or a tool call with id, type, function name and arguments. A stream starts only with an event that carries a completion choice (a delta with at least one valid field), and nothing after its `[DONE]` is relayed or charged. Otherwise the client gets a generic error (429, the upstream's 4xx status, or 502), no upstream text, and no charge. A stream that ends without its final marker gets an error event at the end.
- **Crashed workers.** A lease outlives its request only if its worker died. When it expires it stops counting against concurrency, but its reservation stays charged, since the answer may already have been delivered (a worker that dies just before forwarding also leaves its reservation charged); if the request is settled late, the difference is returned. `bin/virsilipas status <ref>` counts such leases as `held`. `lease_seconds` must be longer than `upstream_timeout`.
- **Runaway output.** If a stream's reported usage passes the reservation, the proxy stops the upstream request there. Output delivered after the last usage report that advanced the count is charged as an upper bound (its bytes); a repeated or lower report does not cover it.
- **Concurrency.** `max_concurrent_per_key` stops one key from using up a shared upstream rate limit.

### Before enabling a model

Check, with that model at your upstream, and again whenever the model or the upstream changes: it honours `max_tokens` (including reasoning tokens), its reported prompt tokens stay within the prompt bound (the request's byte count), it reports `usage` with all three counts, including `total_tokens`, on streams too (ideally on every chunk), and a client hang-up is detected under your real php-fpm and nginx (see below). Do not enable a model that fails any of these. The proxy caps what it debits at the reservation; it cannot stop an upstream that generates more than it was asked for, so an upstream that ignores `max_tokens` costs you the difference (visible as `over_reservation` in the usage table).

## Install

```sh
cp config.example.php config.php      # edit: upstream, models, admin token hash, paths
bin/virsilipas init                    # creates the SQLite database
bin/virsilipas key-create customer-42  # prints the key once
bin/virsilipas grant customer-42 1000000 invoice-1001
```

Point your web server at `public/index.php` for every path. The config path can be set with `VIRSILIPAS_CONFIG`.

### php-fpm and nginx

- **One worker per open stream.** A streaming request holds a php-fpm worker for its whole duration. Size `pm.max_children` for your peak number of concurrent streams, and set `request_terminate_timeout` above `upstream_timeout`.
- **Turn buffering off**, or streams arrive all at once at the end. The proxy sends `X-Accel-Buffering: no`; also set `fastcgi_buffering off;` (or `proxy_buffering off;`) and a `fastcgi_read_timeout` above `upstream_timeout`. Keep `zlib.output_compression` off for this pool.
- **Database directory** must be writable by the PHP user (SQLite WAL creates files next to the database). The proxy creates its files private to its user, and refuses a database, WAL or shared-memory file that group or others can read, and a directory that group or others can write.
- **Before launch, test a client hang-up under your real php-fpm and nginx.** Abort detection depends on the server telling PHP that the connection closed; it is covered by the tests under the PHP built-in server, not under every stack.

### Access control

The proxy checks keys, not addresses. Decide who can reach it with your firewall. To stop all traffic at once, stop the php-fpm pool or block the port. To stop one customer, disable their key.

### Backups

The database holds every balance, so back it up. Do not copy the file while the proxy runs: in WAL mode recent writes live in the `-wal` file, and a plain copy can miss them or be inconsistent. Use SQLite's online backup instead, which is safe while requests are running:

```sh
sqlite3 /var/lib/virsilipas/ledger.sqlite ".backup '/backup/ledger-$(date +%F).sqlite'"
```

To restore, stop the php-fpm pool, put the backup in place as the database file, delete any leftover `-wal` and `-shm` files next to it, and make it private to the PHP user (`chmod 600`): the proxy refuses a database that group or others can read, and the backup copy is usually created group-readable.

## Endpoints

Customer side, authenticated with `Authorization: Bearer <key>`:

| Method and path | What it does |
|---|---|
| `POST /v1/chat/completions` | OpenAI-compatible chat completions, streaming or not. `model` must be one of your aliases. |
| `GET /v1/models` | Lists the aliases. |
| `GET /v1/balance` | `{"balance": N, "in_flight": N}` for the calling key. |

Errors use OpenAI's shape: `{"error": {"message": "...", "type": "..."}}`. 400 invalid request or non-text content, 401 bad key, 402 balance too low for this request, 403 key disabled, 404 unknown model, 429 too many concurrent requests or upstream busy, 502 upstream failed.

Admin side, authenticated with `Authorization: Bearer <admin token>`. Use a long random token (32 bytes or more); only its sha256 is stored in the config. The grant response returns the new balance; `bin/virsilipas status <ref>` shows it at any time.

| Method and path | Body | What it does |
|---|---|---|
| `POST /admin/keys` | `{"ref": "customer-42"}` | Creates a key for a user. `ref` must be your billing system's stable id for the user (not a per-service id), so all of the user's purchases add to one balance. Returns the key once (201). |
| `POST /admin/keys/active` | `{"ref": "...", "active": false}` | Disables or re-enables a key (for suspend and unsuspend). |
| `POST /admin/grants` | `{"ref": "...", "grant_id": "invoice-1001", "tokens": 1000000}` | Adds tokens once per `grant_id`; a retry returns `applied: false`, a `grant_id` reused for another ref or amount returns 409. Negative tokens remove tokens (refunds). At most 10^15 per grant. |

Use a `grant_id` that is unique per payment, for example the invoice number or the subscription id plus its billing period, so that a retried push never credits twice and a renewal is never dropped.

## Privacy

Request and response bodies are never stored or logged. The usage table holds token counts, the model alias, status and timestamps. Keys are stored only as sha256 hashes.

## CLI

```
virsilipas init | key-create <ref> | key-disable <ref> | key-enable <ref> | grant <ref> <tokens> <grant_id> | status <ref>
```

## Tests

```sh
php tests/run.php            # unit and end-to-end tests, no network access needed
php tests/run.php integration
```

The end-to-end tests start a fake upstream and seven proxy processes with the PHP built-in server. Separate processes are needed because one built-in server process queues connections and serves them one at a time.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

"OpenAI-compatible" describes the API format this proxy speaks. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
