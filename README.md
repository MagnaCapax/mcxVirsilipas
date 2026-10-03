# mcxVirsilipas

A small PHP proxy that gives every API key a prepaid token balance and meters each LLM request against it, streaming included.

- One key per user, one balance per key. The balance never expires and never resets.
- Your billing system adds tokens through an admin endpoint ("grants"). Each grant applies once, however often it is retried.
- Clients talk to it like any OpenAI-compatible endpoint (`/v1/chat/completions`, `/v1/models`).
- Requests are refused when the balance runs out. Parallel requests cannot overspend: each one reserves its tokens first, and the reservation never exceeds the balance. The settled charge is what the upstream reports, so a prompt larger than estimated can push a balance below zero; the key is then refused until the next grant.
- Any OpenAI-compatible upstream works. You choose which models customers may use.
- No dependencies beyond PHP 8.2 with the `curl` and `pdo_sqlite` extensions. Storage is one SQLite file.

**The name.** *Virsilipas* is the song-box of the Kalevala. The singer opens it before singing ("virsilippahan viritän", Runo I), and the giant Vipunen, "in whose mouth was great knowledge", opens it to sing out all he knows (Runo XVII). This proxy opens the box for as many words as a key has paid for.

## How metering works

1. **Reserve.** Before forwarding, the proxy estimates the prompt size and reserves `prompt estimate + max_tokens` from the balance in one atomic transaction. `max_tokens` is clamped to what the balance can cover, so the reservation can never exceed it. Each reservation is a lease row; leases also count against the key's concurrency limit.
2. **Forward.** The request goes upstream with the alias replaced by the real model id, `n` and other price-affecting parameters removed, and `stream_options` set by the proxy so the upstream always reports usage.
3. **Settle.** When the response ends, the reservation is returned and the tokens the upstream reported are debited, in one transaction. A usage row (token counts only) is written in the same transaction.

Details that matter in production:

- **Client hangs up mid-stream.** The proxy stops the upstream request and charges for what was generated up to that point. With an upstream that reports usage on every chunk (configure it in `stream_options`) this is exact to the upstream's own count; the client may be charged for a few chunks it never received, generated in the moment before the disconnect was noticed. Without per-chunk usage, the charge is the prompt estimate plus the number of streamed content chunks, and the usage row is flagged `estimated`.
- **Usage in responses** carries the standard token counts only. Extra fields an upstream adds to `usage` (costs, account data) are removed before the client sees them.
- **Upstream errors** never produce a 200. The client gets a generic error of the same class (429, 502, or the upstream's 4xx status). Upstream error bodies are not passed on, and nothing is charged.
- **Crashed workers.** A lease outlives its request only if the worker died. Expired leases are refunded the next time the key makes a request. `lease_seconds` must be longer than `upstream_timeout`.
- **Concurrency.** `max_concurrent_per_key` stops one key from using up a shared upstream rate limit.

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
- **Database directory** must be writable by the PHP user (SQLite WAL creates files next to the database).
- **Before launch, test a client hang-up under your real php-fpm and nginx.** Abort detection depends on the server telling PHP that the connection closed; it is covered by the tests under the PHP built-in server, not under every stack.

### Access control

The proxy checks keys, not addresses. Decide who can reach it with your firewall. To stop all traffic at once, stop the php-fpm pool or block the port. To stop one customer, disable their key.

## Endpoints

Customer side, authenticated with `Authorization: Bearer <key>`:

| Method and path | What it does |
|---|---|
| `POST /v1/chat/completions` | OpenAI-compatible chat completions, streaming or not. `model` must be one of your aliases. |
| `GET /v1/models` | Lists the aliases. |
| `GET /v1/balance` | `{"balance": N, "in_flight": N}` for the calling key. |

Errors use OpenAI's shape: `{"error": {"message": "...", "type": "..."}}`. 401 bad key, 402 balance exhausted, 403 key disabled, 404 unknown model, 429 too many concurrent requests or upstream busy.

Admin side, authenticated with `Authorization: Bearer <admin token>`. Use a long random token (32 bytes or more); only its sha256 is stored in the config. The grant response returns the new balance; `bin/virsilipas status <ref>` shows it at any time.

| Method and path | Body | What it does |
|---|---|---|
| `POST /admin/keys` | `{"ref": "customer-42"}` | Creates a key for your reference id. Returns it once (201). |
| `POST /admin/keys/active` | `{"ref": "...", "active": false}` | Disables or re-enables a key (for suspend and unsuspend). |
| `POST /admin/grants` | `{"ref": "...", "grant_id": "invoice-1001", "tokens": 1000000}` | Adds tokens once per `grant_id`. Negative tokens remove them (refunds). |

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

## License

Apache License 2.0. See LICENSE and NOTICE.
