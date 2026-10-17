# mcxVirsilipas HTTP API

This is the complete HTTP API of the proxy as the code in this repository implements it: every route, what each accepts and returns, every header it sets and every error it can answer. How credits, metering, limits and throttling work is explained in the [README](../README.md); this file links there instead of repeating it. `tests/DocsTest.php` fails when a route, error code, error message or response header in the code is missing here, so the two change together.

There are fifteen routes: nine for clients and six for your billing system.

| Method and path | Auth | What it does |
|---|---|---|
| [`POST /v1/chat/completions`](#post-v1chatcompletions) | key | OpenAI-compatible chat completions, streaming or not |
| [`POST /v1/messages`](#post-v1messages) | key | Anthropic Messages format, streaming or not, passed through to upstreams that serve it |
| [`POST /v1/messages/count_tokens`](#post-v1messagescount_tokens) | key | a free, conservative estimate of a messages request's input tokens |
| [`POST /v1beta/models/{model}:generateContent`](#post-v1betamodelsmodelgeneratecontent) | key | the Gemini API format, translated to chat completions |
| [`POST /v1beta/models/{model}:streamGenerateContent`](#post-v1betamodelsmodelgeneratecontent) | key | the same, streamed (`?alt=sse`) |
| [`POST /v1beta/models/{model}:countTokens`](#post-v1betamodelsmodelcounttokens) | key | a free, conservative estimate of a Gemini request's input tokens |
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

- **Authentication.** Every route needs `Authorization: Bearer <token>` (the two messages routes also take `x-api-key: <key>`, the Gemini routes `x-goog-api-key: <key>` or a `key` query parameter). The client routes take a customer key (keys start with `vsk-`); the admin routes take the admin token, whose sha256 is set in the config (`admin_token_sha256`). Keys are stored only as sha256 hashes.
- **Bodies.** Request bodies are a JSON object, at most 4 MiB for chat, messages and the Gemini routes and 64 KiB for the admin routes. Responses are JSON (`Content-Type: application/json`) except a streamed answer, which is `text/event-stream`.
- **Credits** are integers. What a credit is worth is your decision; the rates are per million tokens (see [Credits](../README.md#credits)).
- **Errors** use OpenAI's shape (the Anthropic shape on the messages routes, see [POST /v1/messages](#post-v1messages), and the Gemini shape under `/v1beta/`, see [the Gemini routes](#post-v1betamodelsmodelgeneratecontent)), with one of the proxy's fixed messages:

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

### POST /v1/messages

The Anthropic Messages format, for clients that speak only that API (such as Claude Code; see [Claude Code](../README.md#claude-code)). It is a metered pass-through, not a translation: the request goes to the `messages_url` of an upstream that serves this format natively, and is metered, limited, throttled and settled exactly like chat completions.

```sh
curl https://proxy.example/v1/messages \
  -H "x-api-key: $VIRSILIPAS_KEY" -H 'anthropic-version: 2023-06-01' -H 'Content-Type: application/json' \
  -d '{"model": "default", "max_tokens": 50, "messages": [{"role": "user", "content": "Say hello."}]}'
```

- **The key** comes as `x-api-key: <key>` or as `Authorization: Bearer <key>` (when both are sent, `x-api-key` wins). Neither: 401 `missing key: send x-api-key or Authorization: Bearer`.
- **The model** is an alias whose route has at least one upstream with a `messages_url` (see `config.example.php`). Only those route entries serve this route, in route order, with retries and failover as on chat. Any other alias, or an unknown one: 404 `unknown model, or not served in the Messages format; see /v1/models`.
- **Headers forwarded:** `anthropic-version` (when the client sends none, `2023-06-01`) and `anthropic-beta`, each when it is printable ASCII of at most 1 KiB. The upstream gets its own key as `x-api-key` and as `Authorization: Bearer`.
- **Errors** use the Anthropic shape, with the same statuses, messages and `Retry-After` as everywhere else, and the `type` chosen by status (400 `invalid_request_error`, 401 `authentication_error`, 402 `billing_error`, 403 `permission_error`, 404 `not_found_error`, 413 `request_too_large`, 429 `rate_limit_error`, 503 `overloaded_error`, 504 `timeout_error`, any other `api_error`). The OpenAI-style `code` and `param` are not part of this shape:

  ```json
  {"type": "error", "error": {"type": "billing_error", "message": "credit balance too low for this request"}}
  ```

**Request fields.** Only these are read or forwarded; any other field (`container`, `mcp_servers`, `service_tier` and so on) is dropped without an error. The deployment's `params` are not applied here: they are chat completions fields.

| Field | Rules |
|---|---|
| `model` | required; an alias as above. |
| `messages` | required; a non-empty array of objects with a string `role` and `content`: a string or a non-empty list of content blocks (below). Other message fields are dropped. Otherwise 400 `invalid_messages`. |
| `system` | a string, or a non-empty list of `text` blocks. |
| `max_tokens` | optional positive integer, otherwise 400 `invalid_value`. Clamped to the server's `max_tokens_cap` and to what the balance covers, as on chat; without it the request asks for the cap. |
| `stream` | `true` streams the answer; anything else does not. |
| `thinking` | forwarded as sent, except that a `budget_tokens` at or above the clamped `max_tokens` is lowered to `max_tokens - 1` (the upstream needs room for an answer). Thinking tokens are part of `output_tokens` and paid at the output rate. |
| `tools` | a list of client tool definitions, each keeping `type`, `name`, `description`, `input_schema` and `cache_control`. A server tool (a `type` other than `custom`, such as a web search) answers 400 `unsupported_tool` `only client tools are supported`: it carries fees no token rate covers. Not a list: 400 `tools must be a list of tool definitions`. |
| `stop_sequences`, `temperature`, `top_p`, `top_k`, `tool_choice`, `metadata` | forwarded as sent. |

Content blocks keep only their own fields; any other block type, such as an image or a document, answers 400 `unsupported_content` `only text, tool use, tool result and thinking content is supported`, and content that is neither a string nor a non-empty list of blocks answers 400 `content must be a string or a non-empty list of content blocks`:

| Block `type` | Fields kept |
|---|---|
| `text` | `text`, `cache_control` |
| `tool_use` | `id`, `name`, `input`, `cache_control` |
| `tool_result` | `tool_use_id`, `content` (a string or a list of `text` blocks), `is_error`, `cache_control` |
| `thinking` | `thinking`, `signature` |
| `redacted_thinking` | `data` |

The prompt bound is the byte length of every forwarded field plus 16, and when `tools` are given 1024 more for the tool-use prompt an upstream adds by itself.

**Response, not streamed.** 200 with the message rebuilt at the top level and its content blocks as the upstream sent them:

```json
{"id": "msg_1", "type": "message", "role": "assistant", "model": "default",
 "content": [{"type": "text", "text": "Hello!"}], "stop_reason": "end_turn", "stop_sequence": null,
 "usage": {"input_tokens": 11, "cache_creation_input_tokens": 0, "cache_read_input_tokens": 0, "output_tokens": 3}}
```

- `model` is always the alias; `id`, `stop_reason` and `stop_sequence` are passed on when they are strings.
- `usage` has the four standard counts of the report the request is charged by, all 0 when the upstream sent no valid report (the charge is then an upper bound, see [How metering works](../README.md#how-metering-works)). Anything else the upstream adds at the message or usage level is dropped.
- A 200 needs at least one usable content block: non-empty `text` or `thinking`, `redacted_thinking`, or a `tool_use` with an `id` and a `name`. Anything else answers 502 `upstream_error`, free.

**Response, streamed.** 200 with `Content-Type: text/event-stream`, `Cache-Control: no-cache` and `X-Accel-Buffering: no`, and the format's named events:

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_1","type":"message","role":"assistant","model":"default","content":[],"stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":11,"cache_creation_input_tokens":0,"cache_read_input_tokens":0,"output_tokens":1}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello!"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":3}}

event: message_stop
data: {"type":"message_stop"}
```

- `message_start` is rebuilt like a non-streamed message (no content yet). `content_block_start`, `content_block_delta` and `content_block_stop` keep `index` and their `content_block` or `delta` as the upstream sent them. `message_delta` keeps `stop_reason`, `stop_sequence` and, as `usage.output_tokens`, the output count the request is charged by. `message_stop` and `ping` are relayed as they are; any other event is dropped.
- The 200 is sent at the first content block event after a `message_start`, which is held until then. Until then, an upstream failure is a normal error response and costs nothing.
- `message_stop` ends the stream; nothing after it is relayed or charged. After the 200, an upstream error, or a stream that ends without `message_stop`, ends it with an error event; the output already relayed is charged:

  ```
  event: error
  data: {"type":"error","error":{"type":"api_error","message":"upstream stream ended early"}}
  ```

**Order of checks** is chat's: the key (401, 403), the body (413, 400 `invalid_json`), the model (404), the messages, system and tools (400), busy shedding, `max_tokens`, then the reservation in the same order.

**Charging.** As on chat (see [How metering works](../README.md#how-metering-works)), from the Anthropic usage counts: `input_tokens` and `cache_creation_input_tokens` are fresh input, `cache_read_input_tokens` is cached input and `output_tokens` is output (thinking included). A report counts only with integer `input_tokens` and `output_tokens` (and integer cache counts when present; absent or `null` is 0) and at least one input token. In a stream the input counts come from `message_start` and the cumulative output count from `message_delta`; a client that hangs up is charged by the upstream's own final counts when the upstream sends them, else by the last counts seen plus the bytes delivered after them. The usage row records the same fields as on chat; the reasoning count is 0, because the format reports none apart from `output_tokens`.

### POST /v1/messages/count_tokens

`{"model": "default", "messages": [...], "system": ..., "tools": [...]}` → 200 `{"input_tokens": 64}`

The proxy's own conservative estimate: the prompt bound a `POST /v1/messages` request with the same fields would reserve for (see above), never fewer tokens than the upstream would count. Nothing goes upstream and nothing is reserved or charged; it needs a valid key (as on `POST /v1/messages`), an alias served in the Messages format, and fields that `POST /v1/messages` would accept, with the same errors.

### POST /v1beta/models/{model}:generateContent

The Gemini API format, for clients that speak only that API (such as Gemini CLI; see [Gemini CLI](../README.md#gemini-cli)). No upstream serves it, so it is a translation: the request becomes a chat completions request on the alias's own route, goes through chat's checks, reservation, limits, throttle, retries, failover and settlement unchanged, and the rebuilt chat answer comes back in this format. Three methods are served, all `POST`:

| Method and path | What it does |
|---|---|
| `POST /v1beta/models/{model}:generateContent` | one answer, not streamed |
| `POST /v1beta/models/{model}:streamGenerateContent` | a streamed answer; needs `?alt=sse` (otherwise 400 `invalid_value` `streamGenerateContent needs alt=sse`) |
| `POST /v1beta/models/{model}:countTokens` | the proxy's own estimate, free (below) |

```sh
curl "https://proxy.example/v1beta/models/default:generateContent" \
  -H "x-goog-api-key: $VIRSILIPAS_KEY" -H 'Content-Type: application/json' \
  -d '{"contents": [{"role": "user", "parts": [{"text": "Say hello."}]}]}'
```

- **The model** is `{model}` in the path: one of your aliases, any alias (URL-encoded where needed). Unknown: 404 `model_not_found` `unknown model; see /v1/models`. A `model` field in the body is ignored. Any other path under `/v1beta/`, or another method, answers 404 `not found` in this format.
- **The key** comes as `x-goog-api-key: <key>`, as a `key` query parameter, or as `Authorization: Bearer <key>`, in that order of preference. None: 401 `missing key: send x-goog-api-key, a key parameter or Authorization: Bearer`. A key in the query string ends up in any access log that records query strings; prefer the header.
- **Errors** use this format's shape, with the same statuses, messages and `Retry-After` as everywhere else, `code` the HTTP status and `status` chosen by it (400 and 413 `INVALID_ARGUMENT`, 401 `UNAUTHENTICATED`, 402 `FAILED_PRECONDITION`, 403 `PERMISSION_DENIED`, 404 `NOT_FOUND`, 429 `RESOURCE_EXHAUSTED`, 500 `INTERNAL`, 502 and 503 `UNAVAILABLE`, 504 `DEADLINE_EXCEEDED`, any other `UNKNOWN`). The proxy's own `code` (such as `insufficient_quota`) and `param` are not part of this shape:

  ```json
  {"error": {"code": 402, "message": "credit balance too low for this request", "status": "FAILED_PRECONDITION"}}
  ```

**Request fields.** Only these are read; any other field (`safetySettings`, `cachedContent`, `labels` and so on) is dropped without an error. The chat request they become is bounded and forwarded like any chat request, the model's `params` included (they win).

| Field | Becomes |
|---|---|
| `contents` | required; a non-empty list of contents, each with a `role` of `user` (the default when absent; `function` is read as `user`) or `model` and a non-empty list of `parts`. Otherwise 400 `invalid_messages` (`contents must be a non-empty array`, `each content needs a role of user or model and a non-empty list of parts`). |
| `systemInstruction` | a string or a content whose parts are all text: the system message (parts joined by a newline). Otherwise 400 `unsupported_content` `systemInstruction must be text`. |
| `tools` | a list of tools with only `functionDeclarations`; each declaration needs a `name` (400 `invalid_value` `each function declaration needs a name`) and becomes a chat function tool with its `description` and its parameter schema (`parametersJsonSchema`, else `parameters`), whose upper-case type names (`OBJECT`, `STRING`, ...) are lower-cased. Any other tool (search, URL context, code execution, ...): 400 `unsupported_tool` `only function declarations are supported`; it runs on the provider's side and carries fees no token rate covers. |
| `toolConfig.functionCallingConfig` | `mode` `AUTO` or `VALIDATED`: `tool_choice` `auto`; `NONE`: `none`; `ANY`: `required`, or that one function when `allowedFunctionNames` lists exactly one. |
| `generationConfig` | `temperature`, `topP` (`top_p`), `stopSequences` (`stop`), `seed`, `presencePenalty` (`presence_penalty`) and `frequencyPenalty` (`frequency_penalty`) as sent; `maxOutputTokens` is `max_tokens`, a positive integer (else 400 `invalid_value` `generationConfig.maxOutputTokens must be a positive integer`), clamped as on chat; `responseMimeType` `application/json` is `response_format`, a `json_schema` with `responseJsonSchema` (else `responseSchema`) lower-cased as above, or `json_object` without one. `thinkingConfig`, `topK`, `candidateCount` (one answer, always) and the rest are dropped: reasoning is the alias's own setting (its `params`). |

Parts, each with exactly one of these (plus `thought` and `thoughtSignature`, which are dropped):

| Part | Becomes |
|---|---|
| `text` | text: a user content's text parts are one user message, a model content's one assistant message (joined by a newline). A part with `"thought": true` is not replayed. |
| `functionCall` (model contents) | a tool call of that assistant message: `name`, and `args` (an object, absent for `{}`) as its JSON arguments; a call without an `id` gets `call_1`, `call_2`, ... Otherwise 400 `invalid_messages` `a function call needs a name, and args that are an object`. |
| `functionResponse` (user contents) | a tool message with `response` (an object, absent for `{}`) as its JSON content, for the call with the same `id`, or without an id for the oldest unanswered call of that name. Otherwise 400 `invalid_messages` `a function response needs a name, and a response that is an object`. |

Any other part (`inlineData`, `fileData`, `executableCode`, ...), a function call in a user content or a function response in a model content answers 400 `unsupported_content` `only text, function call (model) and function response (user) parts are supported`, as images do on chat.

**Response, not streamed.** 200 with one candidate made from the rebuilt chat completion (see [POST /v1/chat/completions](#post-v1chatcompletions)):

```json
{"candidates": [{"content": {"role": "model", "parts": [{"text": "Hello!"}]}, "finishReason": "STOP", "index": 0}],
 "usageMetadata": {"promptTokenCount": 11, "candidatesTokenCount": 7, "totalTokenCount": 18, "cachedContentTokenCount": 0, "thoughtsTokenCount": 0},
 "modelVersion": "default", "responseId": "chatcmpl-1"}
```

- `parts`: the reasoning (`reasoning_content` or `reasoning`) as a text part with `"thought": true`, then the content and a refusal as text parts, then each tool call as a `functionCall` with its `id`, `name` and `args` (the arguments decoded; `{}` when they are not a JSON object). An empty text makes no part.
- `finishReason`: `stop` and `tool_calls` are `STOP`, `length` is `MAX_TOKENS`, `content_filter` is `SAFETY`, none is `STOP`, any other `OTHER`.
- `usageMetadata` is the report the request is charged by: `promptTokenCount` the prompt tokens, `cachedContentTokenCount` the cached part of them, `thoughtsTokenCount` the reasoning tokens, `candidatesTokenCount` the other output tokens, `totalTokenCount` the total. It is left out when the upstream sent no valid report (the charge is then an upper bound).
- `modelVersion` is always the alias; `responseId` is the chat answer's `id` when there is one. A 200 needs what chat needs; anything else answers 502 `upstream_error`, free.

**Response, streamed.** 200 with `Content-Type: text/event-stream`, `Cache-Control: no-cache` and `X-Accel-Buffering: no`; each chunk is a `data:` line with a response object, ended by a blank line (`\r\n\r\n`), and there is no end marker:

```
data: {"candidates":[{"content":{"role":"model","parts":[{"text":"Hel"}]},"index":0}],"modelVersion":"default","responseId":"chatcmpl-1"}

data: {"candidates":[{"content":{"role":"model","parts":[{"text":"lo!"}]},"index":0}],"modelVersion":"default","responseId":"chatcmpl-1"}

data: {"candidates":[{"content":{"role":"model","parts":[]},"finishReason":"STOP","index":0}],"usageMetadata":{"promptTokenCount":11,"candidatesTokenCount":2,"totalTokenCount":13,"cachedContentTokenCount":0,"thoughtsTokenCount":0},"modelVersion":"default","responseId":"chatcmpl-1"}
```

- Text and thoughts go out as they arrive. A function call is put together from its chat fragments and sent whole in the last chunk, which the chat stream's `[DONE]` triggers, with the `finishReason` and the `usageMetadata` the request is charged by.
- The 200 is sent, as on chat, at the first chat event with a usable choice; until then an upstream failure is a normal error response and costs nothing.
- After the 200, an upstream error, or a chat stream that ends without `[DONE]`, ends the stream with an error chunk, and function calls not yet sent are never sent; the output already relayed is charged:

  ```
  data: {"error":{"code":502,"message":"upstream stream ended early","status":"UNAVAILABLE"}}
  ```

**Order of checks** is chat's: the key (401, 403), the body (413, 400 `invalid_json`), the model (404), the contents, system instruction, tools and `maxOutputTokens` (400), then the translated messages as on chat, `alt=sse` for a stream (400), busy shedding, then the reservation in the same order.

**Charging.** Exactly as on chat (see [How metering works](../README.md#how-metering-works)): the upstream's chat usage report at the alias's rates, the prompt bound counted over the translated request, and without a report the bytes of text the client received, where a function call's bytes count only once it is sent.

### POST /v1beta/models/{model}:countTokens

`{"contents": [...], "systemInstruction": ..., "tools": [...]}`, or the same fields inside `generateContentRequest` → 200 `{"totalTokens": 64}`

The proxy's own conservative estimate: the prompt bound a `generateContent` request with the same fields would reserve for, never fewer tokens than the upstream would count. Nothing goes upstream and nothing is reserved or charged; it needs a valid key, a known alias and fields that `generateContent` would accept, with the same errors.
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

Every error the API can answer. The client-route errors have a `code`; the admin errors have `code: null` and are told apart by their message. On the two messages routes the same errors come in the Anthropic shape, without `code` and `param` (see [POST /v1/messages](#post-v1messages)), and on the Gemini routes in that shape (see [the Gemini routes](#post-v1betamodelsmodelgeneratecontent)); "gemini" below means generateContent and streamGenerateContent, and countTokens answers only the errors for the key, the body, the model and the content. "messages" below means `POST /v1/messages`; `POST /v1/messages/count_tokens` answers only its errors for the key, the body, the model and the content (it reserves nothing and goes nowhere).

| Status | `type` | `code` | Message | Retry-After | Where |
|---|---|---|---|---|---|
| 400 | `invalid_request_error` | `invalid_json` | request body must be a JSON object · request body has a number out of range (such as `1e999`, which no float holds) | | all POST routes |
| 400 | `invalid_request_error` | `invalid_messages` | messages must be a non-empty array · each message needs a role · message name must be a string · message tool_call_id must be a string · tool_calls must be a non-empty list · each tool call needs string id, type, function name and arguments (a JSON string) | | chat · messages (the first two) |
| 400 | `invalid_request_error` | `invalid_messages` | content must be a string or a non-empty list of content blocks | | messages |
| 400 | `invalid_request_error` | `unsupported_content` | only text content is supported · message content must be text | | chat |
| 400 | `invalid_request_error` | `unsupported_content` | only text, tool use, tool result and thinking content is supported | | messages |
| 400 | `invalid_request_error` | `unsupported_tool` | only client tools are supported | | messages |
| 400 | `invalid_request_error` | `invalid_messages` | contents must be a non-empty array · each content needs a role of user or model and a non-empty list of parts · a function call needs a name, and args that are an object · a function response needs a name, and a response that is an object | | gemini |
| 400 | `invalid_request_error` | `unsupported_content` | only text, function call (model) and function response (user) parts are supported · systemInstruction must be text | | gemini |
| 400 | `invalid_request_error` | `unsupported_tool` | only function declarations are supported | | gemini |
| 400 | `invalid_request_error` | `invalid_value` | each function declaration needs a name · generationConfig.maxOutputTokens must be a positive integer · streamGenerateContent needs alt=sse | | gemini |
| 400 | `invalid_request_error` | `invalid_value` | max_tokens must be a positive integer · days must be an integer from 1 to 400 · grants must be an integer from 1 to 1000 · tools must be a list of tool definitions | | chat · messages · usage |
| 400 | `invalid_request_error` | `context_length_exceeded` | the request is longer than the model accepts | | chat · messages · gemini, from the upstream |
| 400 | `invalid_request_error` | `upstream_rejected` | upstream rejected the request (HTTP 400, 413 or 422) | | chat · messages · gemini, from the upstream |
| 401 | `authentication_error` | `invalid_api_key` | missing bearer key · invalid key | | client routes |
| 401 | `authentication_error` | `invalid_api_key` | missing key: send x-api-key or Authorization: Bearer | | messages |
| 401 | `authentication_error` | `invalid_api_key` | missing key: send x-goog-api-key, a key parameter or Authorization: Bearer | | gemini |
| 402 | `insufficient_quota` | `insufficient_quota` | credit balance too low for this request | | chat · messages · gemini |
| 403 | `permission_error` | `key_disabled` | key is disabled | | client routes |
| 404 | `invalid_request_error` | `model_not_found` | unknown model; see /v1/models | | chat · gemini |
| 404 | `invalid_request_error` | `model_not_found` | unknown model, or not served in the Messages format; see /v1/models | | messages |
| 413 | `invalid_request_error` | `request_too_large` | request body too large | | all POST routes |
| 429 | `rate_limit_error` | `rate_limit_exceeded` | too many concurrent requests for this key · requests per minute limit reached for this key · credits per minute limit reached for this key | 1 for concurrency; else until the oldest request in the key's window is a minute old | chat · messages · gemini |
| 429 | `rate_limit_error` | `server_rate_limit` | the server is at its request rate limit; retry shortly | until the oldest request in the server's window is a minute old | chat · messages · gemini |
| 429 | `rate_limit_error` | `upstream_busy` | upstream is busy, retry shortly | the upstream's, 1 to 60 | chat · messages · gemini, from the upstream |
| 502 | `api_error` | `upstream_error` | upstream unavailable or returned an invalid response · upstream response too large | | chat · messages · gemini, from the upstream |
| 503 | `server_error` | `server_busy` | the server is busy; retry shortly | `ceil(5 x busy_min_weight / weight)`, 1 to 60 | chat · messages · gemini |
| 503 | `server_error` | `spend_cap_reached` | the server has reached its spending limit for this hour (or day, or month) | until that window ends | chat · messages · gemini |
| 503 | `api_error` | `upstream_unavailable` | upstream unavailable, retry shortly | 1 | chat · messages · gemini, from the upstream |
| 504 | `api_error` | `upstream_timeout` | upstream timed out | | chat · messages · gemini, from the upstream |
| 400 | `invalid_request_error` | `null` | ref must be a non-empty string (max 200 bytes) · active must be true or false · grant needs a string grant_id and an integer credits · grant needs a grant_id and nonzero credits of at most 1000000000000000 · grant would take the balance out of range · unknown limit; use max_concurrent, requests_per_minute, credits_per_minute · \<limit\> must be null (the default) or an integer from 1 to 1000000000000000 · bonus_pct must be an integer from 0 to 300 · key_group must be at most 64 of a-z, 0-9, dot, dash and underscore · expires_at must be null or a future unix time in seconds | | admin |
| 401 | `authentication_error` | `null` | invalid admin token | | admin |
| 404 | `invalid_request_error` | `null` | unknown ref | | admin |
| 409 | `invalid_request_error` | `null` | a key already exists for this ref · grant_id was already used for a different ref, amount or expires_at | | admin |
| 409 | `invalid_request_error` | `key_removed` | the key for this ref was removed; a removed ref cannot be changed or used again | | admin, after `POST /admin/keys/remove` |
| 404 | `invalid_request_error` | `null` | not found | | any other method or path (in the Gemini shape under `/v1beta/`) |
| 500 | `api_error` | `null` | internal error | | any route: an unexpected failure, such as a config that cannot be loaded or a ledger that cannot be read or written |

- **Upstream-derived errors.** The rows marked "from the upstream" are the proxy's own answers (on `POST /v1/messages` too, where an upstream's 529 is a 5xx like any other), chosen from the last failed attempt after retries and failover; the upstream's own text is never relayed. The last attempt decides:
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
| `X-Request-Id` | chat, `POST /v1/messages` and the Gemini generateContent and streamGenerateContent, once the reservation is made (an upstream error included) | the request's lease id, the same id as in its usage row and log lines; quote it to support |
| `X-Virsilipas-Weight` | chat, `POST /v1/messages` and the Gemini generateContent and streamGenerateContent, once it passes the key, body, model and message checks and is not shed as busy (a 400 for `max_tokens` and a refusal from the reservation included) | the key's priority weight |
| `X-Virsilipas-Delay-Ms` | as `X-Virsilipas-Weight` | the spend-pacing wait before the request went upstream, 0 when none |
| `Cache-Control` | a stream | `no-cache` |
| `X-Accel-Buffering` | a stream | `no`, so nginx does not buffer the stream |

## Not part of the HTTP API

- The command line (`bin/virsilipas`), which does everything the admin routes do (`key-remove` and `grant ... [expires_at]` included) and more (status, history, usage statistics, verify): see [CLI](../README.md#cli).
- The upstream side: which upstreams serve an alias, their URLs and keys live only in the deployment's config.
