# ADR 0021: The Gemini API format is translated to chat completions

Date: 2026-10-17
In force since: v0.11.0

## Status
Accepted

## Context
Customers want to run Gemini CLI on their credits. It speaks only the Gemini API (`POST /v1beta/models/{model}:streamGenerateContent?alt=sse`, `:generateContent`, rarely `:countTokens`) and takes a base URL (`GOOGLE_GEMINI_BASE_URL`) and an API key (`GEMINI_API_KEY`, sent as `x-goog-api-key`). The proxy's upstreams speak chat completions, and some also the Anthropic Messages format (ADR 0020); none is assumed to serve this one. The metering, limits, caps, throttle and failover exist once, for chat completions (ADR 0007, 0011, 0012, 0016), and must not be built again. A capture of Gemini CLI 0.64.0 against a local server showed what it actually sends: text parts, function calls and responses (with ids), function declarations with a JSON Schema (`parametersJsonSchema`; the CLI's own JSON outputs use upper-case type names), a system instruction, and generation settings (temperature, topP, topK, thinkingConfig, sometimes maxOutputTokens and a JSON response schema). Its background tasks and two of its subagents name models of their own, which a user can point elsewhere in the CLI's `settings.json`.

## Options considered
- A pass-through like ADR 0020: needs an upstream that serves the format, and there is none.
- A full translation of the Gemini API (media parts, cached content, server tools, safety settings, candidate counts, thinking budgets): most of it cannot be priced by a token rate (ADR 0013) or has no chat counterpart, and every part of it would have to be kept in step with two formats.
- A translation of the subset the clients use (text, function calls and results, function declarations, a few generation settings) into a chat request on the alias's own route, with everything else refused or dropped, and the rebuilt chat answer translated back.
- A config map from Gemini model names to aliases for the client's internal calls: possible, but the CLI already lets the user point every call at an alias, the map would follow the CLI's model names release by release, and a silent catch-all would hide calls the customer did not mean to pay for.

## Decision
The subset translation. `Gemini::request()` turns a request into a chat request: contents into messages (user text, assistant text with tool calls, tool messages for function responses, paired by id or else by name in order; a call without an id gets `call_N`), the system instruction into a system message, function declarations into function tools (the schema lower-cased to JSON Schema), `toolConfig` into `tool_choice`, `generationConfig`'s temperature, topP, stopSequences, seed and penalties into their chat fields, `maxOutputTokens` into `max_tokens`, and a JSON response type into `response_format`. Inline data, files, code execution and any tool but function declarations are refused (400), as images and server tools are elsewhere. `thinkingConfig`, `topK`, `candidateCount`, safety settings and cached content are dropped: reasoning is the alias's own setting (`params`), the answer is always one candidate. The chat request then goes through chat's own validation (`normalizeMessages`), prompt bound, `params`, rewrite, reservation, attempts and settlement unchanged (`Proxy::serve` with the format `gemini`), on every alias. The rebuilt chat answer (`publicView`) becomes one candidate: reasoning as thought parts, text, function calls with object args, the finish reason, the charged report's five counts as `usageMetadata`, the alias as `modelVersion`. In a stream, text goes out as it arrives, and function calls, which chat streams in fragments while this format needs them whole, are sent complete in the last chunk at the chat stream's `[DONE]`; their bytes are held apart and count as delivered only then, so a stream cut off before them is not charged for them. Errors use the format's shape (`code`, `message`, `status`) with the proxy's fixed messages. The key comes as `x-goog-api-key`, a `key` query parameter or a bearer token. `:countTokens` answers the chat prompt bound of the translated request and goes nowhere (as ADR 0020 decided for its count route). The client's internal model names are the user's to point at an alias (README, "Gemini CLI"); a name that is not an alias answers 404.

## Consequences
- Gemini CLI runs on a customer's key with any alias, and every invariant of the chat path holds for it because it is the chat path.
- The translation is lossy by design: images and provider-side tools fail with a 400, thinking settings are ignored, and a function call in a stream arrives only at the end of the answer.
- From now on: invariants 5, 7 and 8 name the Gemini routes' rules, tested in tests/GeminiTest.php (including a stream cut off before its call is sent, and a mutation-checked request translation).

## References
src/Gemini.php; src/Proxy.php (`gemini`, `chatEvent`, `serve`, `forward`); src/Http.php (`googleKey`); src/HttpError.php (`sendGemini`); public/index.php; invariants 5, 7, 8; ADR 0013 (the client contract), ADR 0020 (the Messages pass-through); docs/API.md "POST /v1beta/models/{model}:generateContent"; README "Gemini CLI".
