# mcxVirsilipas HTTP API

This is the complete HTTP API of the proxy as the code in this repository implements it: every route, what each accepts and returns, every header it sets and every error it can answer. How credits, metering, limits and throttling work is explained in the [README](../README.md); this file links there instead of repeating it. `tests/DocsTest.php` fails when a route, error code, error message or response header in the code is missing here, so the two change together.

There are ten routes: four for clients and six for your billing system.

| Method and path | Auth | What it does |
|---|---|---|
| [`POST /v1/chat/completions`](#post-v1chatcompletions) | key | OpenAI-compatible chat completions, streaming or not |
| [`GET /v1/models`](#get-v1models) | key | the model aliases and their rates |
| [`GET /v1/balance`](#get-v1balance) | key | the calling key's balance, limits and priority |
| [`GET /v1/usage`](#get-v1usage) | key | the calling key's own usage per day and per model, and its grants |
| [`POST /admin/keys`](#post-adminkeys) | admin token | create a key for a user |
| [`POST /admin/keys/rotate`](#post-adminkeysrotate) | admin token | replace a user's key |
| [`POST /admin/keys/remove`](#post-adminkeysremove) | admin token | remove a user's key for good |
| [`POST /admin/keys/active`](#post-adminkeysactive) | admin token | disable or re-enable a key |
| [`POST /admin/keys/limits`](#post-adminkeyslimits) | admin token | set a key's limits, bonus and group |
| [`POST /admin/grants`](#post-admingrants) | admin token | add or remove credits, once per grant id |

Any other method or path answers 404 `not found`.

## Conventions

- **Authentication.** Every route needs `Authorization: Bearer <token>`. The client routes take a customer key (keys start with `vsk-`); the admin routes take the admin token, whose sha256 is set in the config (`admin_token_sha256`). Keys are stored only as sha256 hashes.
- **Bodies.** Request bodies are a JSON object, at most 4 MiB for chat and 64 KiB for the admin routes. Responses are JSON (`Content-Type: application/json`) except a streamed chat answer, which is `text/event-stream`.
- **Credits** are integers. What a credit is worth is your decision; the rates are per million tokens (see [Credits](../README.md#credits)).
- **Errors** use OpenAI's shape, with one of the proxy's fixed messages:

  ```json
  {"error": {"message": "credit balance too low for this request", "type": "insufficient_quota", "param": null, "code": "insufficient_quota"}}
  ```

  `code` is a stable machine-readable reason, or `null` for the admin routes (except 409 `key_removed`) and the two catch-all errors, where the message identifies the error. `param` names the request field when there is one. Nothing an upstream said is ever passed on, except the code `context_length_exceeded`. The full list is under [Errors](#errors).
- **Retry-After** (seconds) comes with every 429 and 503 (see [Headers](#headers)).

## Client API

### POST /v1/chat/completions

```sh
curl https://proxy.example/v1/chat/completions \
  -H "Authorization: Bearer $VIRSILIPAS_KEY" -H 'Content-Type: application/json' \
  -d '{"model": "default", "messages": [{"role": "user", "content": "Say hello."}], "max_tokens": 50}'
```

**Request fields.** Only these are read or forwarded; any other field (`n`, `store`, `logprobs`, `user`, output-mode options and so on) is dropped without an error.

| Field | Rules |
|---|---|
| `model` | required; one of your aliases (see `GET /v1/models`). Unknown: 404 `model_not_found`. |
| `messages` | required; a non-empty array of message objects (below). |
| `max_completion_tokens` or `max_tokens` | optional positive integer (`max_completion_tokens` wins when both are sent; the upstream gets `max_tokens`); otherwise 400 `invalid_value`. Clamped to the server's `max_tokens_cap` and to what the balance covers; without it the request asks for the cap. |
| `stream` | `true` streams the answer; anything else does not. The proxy sets `stream_options` itself, so a client's own `stream_options` is dropped. |
| `tools`, `tool_choice`, `parallel_tool_calls`, `temperature`, `top_p`, `stop`, `seed`, `presence_penalty`, `frequency_penalty`, `response_format`, `reasoning_effort` | forwarded as sent, except where the deployment sets the same field in the model's `params`, which wins. |

Each message keeps only these fields; others are dropped:

| Field | Rules |
|---|---|
| `role` | required string. |
| `content` | a string, `null`, or an array of text parts `{"type": "text", "text": "..."}`. Images, audio, files or any other part type: 400 `unsupported_content`. |
| `name`, `tool_call_id` | strings. |
| `tool_calls` | a non-empty array; each call needs a string `id`, `type`, `function.name` and `function.arguments` (a JSON string). |

The request goes to the upstream with the alias replaced by the real model id, `n` left out (one choice), the clamped `max_tokens` and, for a stream, the deployment's `stream_options`.

**Response, not streamed.** 200 with the completion rebuilt from known fields:

```json
{
  "id": "chatcmpl-1",
  "object": "chat.completion",
  "created": 1760600000,
  "model": "default",
  "choices": [{"index": 0, "finish_reason": "stop", "message": {"role": "assistant", "content": "Hello!"}}],
  "usage": {
    "prompt_tokens": 11, "completion_tokens": 7, "total_tokens": 18,
    "prompt_tokens_details": {"cached_tokens": 0},
    "completion_tokens_details": {"reasoning_tokens": 0},
    "credits": 39
  }
}
```

- `id`, `object` and `created` are passed on when the upstream sends them with the right type (strings, and an integer `created`).
- `model` is always the alias.
- Each choice keeps `index`, `finish_reason` and `message` (or `delta` in a stream) with `role`, `content`, `reasoning_content` or `reasoning` (upstreams use either name for the reasoning text; it is passed on under the name the upstream used), `refusal` and `tool_calls` (`index`, `id`, `type`, `function.name`, `function.arguments`). A value of the wrong type is dropped.
- `usage` has the three standard counts, `prompt_tokens_details.cached_tokens`, `completion_tokens_details.reasoning_tokens` (0 when the upstream reports none) and `credits`, which is what this report costs at the alias's rates, at most the reservation. It is `null` when the upstream sent a usage object that is not a valid report.
- Anything else the upstream sends is dropped.

**Response, streamed.** 200 with `Content-Type: text/event-stream`, `Cache-Control: no-cache` and `X-Accel-Buffering: no`. Each event is a rebuilt `chat.completion.chunk` (same fields as above, with `delta` in place of `message`); the stream ends with `data: [DONE]`:

```
data: {"id":"chatcmpl-1","object":"chat.completion.chunk","model":"default","choices":[{"index":0,"delta":{"content":"Hel"}}]}

data: {"id":"chatcmpl-1","object":"chat.completion.chunk","model":"default","choices":[{"index":0,"delta":{"content":"lo!"}}]}

data: {"id":"chatcmpl-1","object":"chat.completion.chunk","model":"default","choices":[],"usage":{"prompt_tokens":11,"completion_tokens":2,"total_tokens":13,"prompt_tokens_details":{"cached_tokens":0},"completion_tokens_details":{"reasoning_tokens":0},"credits":19}}

data: [DONE]
```

- An event carries `usage` when the upstream sent one with it. Depending on the deployment's `stream_options`, that is only the last event or every event. Each one shows the report the request is charged by, so the last one matches the charge, unless text arrived after the last report that advanced the counts (see [How metering works](../README.md#how-metering-works)).
- The 200 is sent only when the first event with a usable choice arrives. Until then, an upstream failure is a normal error response.
- After the 200, an upstream error, or a stream that ends without its final marker, ends the stream with an error event and no `[DONE]`; the output already relayed is charged:

  ```
  data: {"error":{"message":"upstream stream ended early","type":"api_error","param":null,"code":"upstream_error"}}
  ```

**Order of checks.** A request is refused at the first check that fails:
1. the key: 401, 403;
2. the body: 413, 400 `invalid_json`;
3. the model: 404;
4. the messages: 400 `invalid_messages`, `unsupported_content`;
5. busy shedding: 503 `server_busy`;
6. `max_tokens`: 400 `invalid_value`;
7. the reservation, in this order:
   - key disabled meanwhile: 403;
   - the key's concurrency, then its requests per minute, then its credits per minute: 429 `rate_limit_exceeded`;
   - the server's requests per minute: 429 `server_rate_limit`;
   - the balance: 402;
   - the spend caps: 503 `spend_cap_reached`.

After the reservation, the request may wait for spend pacing, then goes upstream with retries and failover (see [How metering works](../README.md#how-metering-works) and [Priority and throttling](../README.md#priority-and-throttling)). The upstream errors are listed under [Errors](#errors).

**Charging.**
- A refusal before the reservation costs nothing.
- An upstream failure with no usable answer costs nothing. Its reservation is returned in full.
- A delivered answer is charged by the upstream's usage report, at most the reservation. A client hang-up and an answer with no usage report are covered under [How metering works](../README.md#how-metering-works).

### GET /v1/models

```json
{"object": "list", "data": [
  {"id": "default", "object": "model", "created": 0, "owned_by": "virsilipas",
   "description": "general use", "context_length": 128000, "max_completion_tokens": 8192,
   "credits_per_million_tokens": {"input": 1000000, "cached_input": 250000, "output": 4000000}}
]}
```

- There is one entry per alias, in config order.
- `description` and `context_length` appear only when the config sets them.
- `max_completion_tokens` is the server's `max_tokens_cap`.
- Upstream model ids and URLs are never shown.

Without a key this route answers 401, which is the health check: it needs php-fpm running, the config loaded and the ledger database opening ([php-fpm and nginx](../README.md#php-fpm-and-nginx)). It does not read the ledger's tables; `GET /v1/balance` with a real key does.

### GET /v1/balance

```json
{"balance": 49999961, "reserved": 0, "in_flight": 0,
 "limits": {"max_concurrent": 5, "requests_per_minute": null, "credits_per_minute": null},
 "window": {"requests": 1, "credits": 39},
 "bonus_pct": 0, "weight": 707}
```

| Field | Meaning |
|---|---|
| `balance` | credits left, already net of running requests; can be negative after a negative grant |
| `reserved` | credits held by running or unsettled requests |
| `in_flight` | requests running now |
| `limits` | the limits in force; `null` means no limit |
| `window` | `requests` and `credits` over the last 60 seconds |
| `bonus_pct` | the key's loyalty bonus, 0 to 300 |
| `weight` | the key's priority (1 to 10,000) for this caller's source; see [Priority and throttling](../README.md#priority-and-throttling) |

### GET /v1/usage

`GET /v1/usage?days=7` → 200 (`days` and `grants` are optional query parameters)

```json
{"balance": 49999922, "days": 7, "since": 1791676800,
 "daily": [{"day": "2026-10-17", "requests": 3, "prompt_tokens": 30, "cached_tokens": 0, "completion_tokens": 12, "reasoning_tokens": 0, "credits": 78}],
 "models": [{"model": "default", "requests": 3, "prompt_tokens": 30, "cached_tokens": 0, "completion_tokens": 12, "reasoning_tokens": 0, "credits": 78}],
 "grants": [{"grant_id": "invoice-1001", "credits": 50000000, "created_at": 1792224000, "expires_at": null}]}
```

The calling key's own data, and nothing about any other key: every number comes from that key's rows only, and the response has no ref, no upstream and no upstream cost.

| Field | Meaning |
|---|---|
| `balance` | as in `GET /v1/balance` |
| `days` | the window asked for: `days`, an integer from 1 to 400, default 30; anything else answers 400 `invalid_value` `days must be an integer from 1 to 400` |
| `since` | the window's start (unix seconds): midnight UTC `days - 1` days ago, so `days=1` is today so far |
| `daily` | one row per UTC day with requests in the window, oldest first; days without requests are left out |
| `models` | one row per model alias over the whole window, by alias |
| `requests`, `prompt_tokens`, `cached_tokens`, `completion_tokens`, `reasoning_tokens` | the requests settled and their token counts as charged (`cached_tokens` is part of `prompt_tokens`, `reasoning_tokens` part of `completion_tokens`); failed requests count as requests |
| `credits` | credits charged |
| `grants` | the key's newest grants, newest first, as many as the `grants` parameter asks for: an integer from 1 to 1000, default 200; anything else answers 400 `invalid_value` `grants must be an integer from 1 to 1000`. Each has `grant_id`, `credits` (negative for a refund), `created_at` (when it was added) and `expires_at` (`null`: never; stored, not enforced, see [POST /admin/grants](#post-admingrants)) |

## Admin API

For your billing system. Use a long random admin token (32 bytes or more) and keep these routes off the public internet (see [Access control](../README.md#access-control)). Every admin route can answer:
- 401 `invalid admin token`;
- 413 for a body over 64 KiB;
- 400 `invalid_json`;
- 400 `ref must be a non-empty string (max 200 bytes)`.

Every route except `POST /admin/keys` also answers 404 `unknown ref`. Every route except `POST /admin/keys/remove` answers 409 `key_removed` `the key for this ref was removed; a removed ref cannot be changed or used again` when the ref's key was removed (a retried grant applied before the removal still answers as before). Each call is logged with its ref (`key_create`, `key_rotate`, `key_remove`, `key_active`, `key_limits`, `grant`).

`ref` is your billing system's stable id for the user (not a per-service id), so all of a user's purchases add to one balance.

### POST /admin/keys

`{"ref": "customer-42"}` → 201 `{"ref": "customer-42", "key": "vsk-..."}`

Creates the user's key. The key is returned only this once; the proxy keeps only its hash. A second key for the same ref: 409 `a key already exists for this ref`.

The call is not idempotent, so treat it as done only once the key is stored or delivered to the user. If the response is lost (a timeout, a crash before the key was saved), a retry answers 409; call `POST /admin/keys/rotate` for the ref instead, which returns a new key and voids the lost one. There is one key per ref: create keys for a ref from one place at a time.

### POST /admin/keys/rotate

`{"ref": "customer-42"}` → 200 `{"ref": "customer-42", "key": "vsk-..."}`

Replaces the ref's key with a new one, returned once: for a leaked key. The old key answers 401 at once. The balance, limits, credit log and usage stay with the ref.

### POST /admin/keys/remove

`{"ref": "customer-42"}` → 200 `{"ref": "customer-42", "applied": true, "removed_at": 1792224000, "balance": 1234}`

Removes the ref's key for good, for a closed account: the key answers 401 on every client route from then on, and the key is disabled. Removal is final: the ref cannot be rotated, re-enabled, given limits, granted to or created again (409 `key_removed`). The balance (returned for your records), grants, credit log and usage stay in the ledger. Removing a removed key changes nothing and answers `"applied": false` with the original `removed_at`.

### POST /admin/keys/active

`{"ref": "customer-42", "active": false}` → 200 `{"ref": "customer-42", "active": false}`

Disables (`false`) or re-enables (`true`) the key, for suspend and unsuspend. A disabled key answers 403 `key_disabled`. Any other value: 400 `active must be true or false`.

### POST /admin/keys/limits

```json
{"ref": "customer-42", "requests_per_minute": 60, "bonus_pct": 50, "key_group": "plan-a"}
```

→ 200

```json
{"ref": "customer-42",
 "overrides": {"max_concurrent": null, "requests_per_minute": 60, "credits_per_minute": null},
 "limits": {"max_concurrent": 5, "requests_per_minute": 60, "credits_per_minute": null},
 "bonus_pct": 50, "key_group": "plan-a"}
```

All fields besides `ref` are optional; fields left out are unchanged. If any field is invalid the request answers 400 and nothing changes.
- `max_concurrent`, `requests_per_minute` and `credits_per_minute` each take an integer from 1 to 10^15, or `null` to follow the config default again.
- `bonus_pct` is an integer from 0 to 300.
- `key_group` is up to 64 of a-z, 0-9, `.`, `_` and `-`, or empty for none. It groups keys in `bin/virsilipas stats`.

The response shows:
- `overrides`, the key's own settings;
- `limits`, the limits in force;
- `bonus_pct` and `key_group`.

The 400s:
- `unknown limit; use max_concurrent, requests_per_minute, credits_per_minute` for any other field;
- `<limit> must be null (the default) or an integer from 1 to 1000000000000000`;
- `bonus_pct must be an integer from 0 to 300`;
- `key_group must be at most 64 of a-z, 0-9, dot, dash and underscore`.

### POST /admin/grants

`{"ref": "customer-42", "grant_id": "invoice-1001", "credits": 1000000}` → 200 `{"applied": true, "balance": 1000000, "expires_at": null}`

Adds credits once per `grant_id`.
- **Retries:** sending the same `grant_id` for the same ref, amount and `expires_at` again changes nothing and answers `{"applied": false, "balance": ..., "expires_at": ...}`.
- **`expires_at`** (optional): a unix time in seconds, in the future (at most 9999-12-31), or absent or `null` for never. It is stored with the grant for a future expiry policy and is **not enforced**: nothing happens to the balance when it passes. `created_at`, shown in `GET /v1/usage`, records when the grant was added.
- **Refunds:** negative credits remove credits. A balance can go below zero this way, and the key's requests then answer 402 until a grant brings it back up.
- **grant_id choice:** use one that is unique per payment, such as the invoice number, or the subscription id plus its billing period. Then a retried push never credits twice and a renewal is never dropped.

Errors:
- 400 `grant needs a string grant_id and an integer credits`, when `grant_id` is not a string of 1 to 200 bytes or `credits` is not an integer;
- 400 `grant needs a grant_id and nonzero credits of at most 1000000000000000` (10^15 either way);
- 400 `expires_at must be null or a future unix time in seconds`;
- 409 `grant_id was already used for a different ref, amount or expires_at`;
- 400 `grant would take the balance out of range`, past ±10^18.

## Errors

Every error the API can answer. The client-route errors have a `code`; the admin errors have `code: null` and are told apart by their message.

| Status | `type` | `code` | Message | Retry-After | Where |
|---|---|---|---|---|---|
| 400 | `invalid_request_error` | `invalid_json` | request body must be a JSON object · request body has a number out of range (such as `1e999`, which no float holds) | | all POST routes |
| 400 | `invalid_request_error` | `invalid_messages` | messages must be a non-empty array · each message needs a role · message name must be a string · message tool_call_id must be a string · tool_calls must be a non-empty list · each tool call needs string id, type, function name and arguments (a JSON string) | | chat |
| 400 | `invalid_request_error` | `unsupported_content` | only text content is supported · message content must be text | | chat |
| 400 | `invalid_request_error` | `invalid_value` | max_tokens must be a positive integer · days must be an integer from 1 to 400 · grants must be an integer from 1 to 1000 | | chat · usage |
| 400 | `invalid_request_error` | `context_length_exceeded` | the request is longer than the model accepts | | chat, from the upstream |
| 400 | `invalid_request_error` | `upstream_rejected` | upstream rejected the request (HTTP 400, 413 or 422) | | chat, from the upstream |
| 401 | `authentication_error` | `invalid_api_key` | missing bearer key · invalid key | | client routes |
| 402 | `insufficient_quota` | `insufficient_quota` | credit balance too low for this request | | chat |
| 403 | `permission_error` | `key_disabled` | key is disabled | | client routes |
| 404 | `invalid_request_error` | `model_not_found` | unknown model; see /v1/models | | chat |
| 413 | `invalid_request_error` | `request_too_large` | request body too large | | all POST routes |
| 429 | `rate_limit_error` | `rate_limit_exceeded` | too many concurrent requests for this key · requests per minute limit reached for this key · credits per minute limit reached for this key | 1 for concurrency; else until the oldest request in the key's window is a minute old | chat |
| 429 | `rate_limit_error` | `server_rate_limit` | the server is at its request rate limit; retry shortly | until the oldest request in the server's window is a minute old | chat |
| 429 | `rate_limit_error` | `upstream_busy` | upstream is busy, retry shortly | the upstream's, 1 to 60 | chat, from the upstream |
| 502 | `api_error` | `upstream_error` | upstream unavailable or returned an invalid response · upstream response too large | | chat, from the upstream |
| 503 | `server_error` | `server_busy` | the server is busy; retry shortly | `ceil(5 x busy_min_weight / weight)`, 1 to 60 | chat |
| 503 | `server_error` | `spend_cap_reached` | the server has reached its spending limit for this hour (or day, or month) | until that window ends | chat |
| 503 | `api_error` | `upstream_unavailable` | upstream unavailable, retry shortly | 1 | chat, from the upstream |
| 504 | `api_error` | `upstream_timeout` | upstream timed out | | chat, from the upstream |
| 400 | `invalid_request_error` | `null` | ref must be a non-empty string (max 200 bytes) · active must be true or false · grant needs a string grant_id and an integer credits · grant needs a grant_id and nonzero credits of at most 1000000000000000 · grant would take the balance out of range · unknown limit; use max_concurrent, requests_per_minute, credits_per_minute · \<limit\> must be null (the default) or an integer from 1 to 1000000000000000 · bonus_pct must be an integer from 0 to 300 · key_group must be at most 64 of a-z, 0-9, dot, dash and underscore · expires_at must be null or a future unix time in seconds | | admin |
| 401 | `authentication_error` | `null` | invalid admin token | | admin |
| 404 | `invalid_request_error` | `null` | unknown ref | | admin |
| 409 | `invalid_request_error` | `null` | a key already exists for this ref · grant_id was already used for a different ref, amount or expires_at | | admin |
| 409 | `invalid_request_error` | `key_removed` | the key for this ref was removed; a removed ref cannot be changed or used again | | admin, after `POST /admin/keys/remove` |
| 404 | `invalid_request_error` | `null` | not found | | any other method or path |
| 500 | `api_error` | `null` | internal error | | any route: an unexpected failure, such as a config that cannot be loaded or a ledger that cannot be read or written |

- **Upstream-derived errors.** The rows marked "from the upstream" are the proxy's own answers, chosen from the last failed attempt after retries and failover; the upstream's own text is never relayed. The last attempt decides:
  - an upstream error code `context_length_exceeded`: 400 `context_length_exceeded`;
  - the time limit passed: 504 `upstream_timeout`;
  - a response or stream event too large: 502 `upstream_error`;
  - 429: 429 `upstream_busy`;
  - 400, 413 or 422: 400 `upstream_rejected`;
  - no answer at all (the connection failed, before or after the request was sent), the upstream was skipped because its key file is missing, or it still answered 408, 409, 502, 503 or 504 after its retries: 503 `upstream_unavailable`;
  - anything else, such as a 500 or other 5xx, a 401 to 404 (the upstream rejected its own key, credit or model id) or a 2xx without a usable answer: 502 `upstream_error`.
- **The 403.** It comes before the reservation for a disabled key, and from the reservation when the key was disabled in between.
- **The 500.** It fails closed: nothing is reserved or charged, and the log names the cause.

## Headers

| Header | On | Value |
|---|---|---|
| `Content-Type` | every response | `application/json`, or `text/event-stream` for a stream |
| `Retry-After` | every 429 and 503 | seconds (see [Errors](#errors)) |
| `X-Request-Id` | chat, once the reservation is made (an upstream error included) | the request's lease id, the same id as in its usage row and log lines; quote it to support |
| `X-Virsilipas-Weight` | chat, once it passes the key, body, model and message checks and is not shed as busy (a 400 for `max_tokens` and a refusal from the reservation included) | the key's priority weight |
| `X-Virsilipas-Delay-Ms` | as `X-Virsilipas-Weight` | the spend-pacing wait before the request went upstream, 0 when none |
| `Cache-Control` | a stream | `no-cache` |
| `X-Accel-Buffering` | a stream | `no`, so nginx does not buffer the stream |

## Not part of the HTTP API

- The command line (`bin/virsilipas`), which does everything the admin routes do (`key-remove` and `grant ... [expires_at]` included) and more (status, history, usage statistics, verify): see [CLI](../README.md#cli).
- The upstream side: which upstreams serve an alias, their URLs and keys live only in the deployment's config.
