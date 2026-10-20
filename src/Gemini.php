<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The Gemini API format (generateContent, streamGenerateContent, countTokens), translated to chat completions and back.
 * No upstream serves it, so a request becomes a chat request (text, function calls and results, function declarations
 * and a few generation settings), runs through the chat path's checks, reservation, attempts and settlement on the
 * alias's own route, and the rebuilt chat answer or stream events become this format's response. Proxy does the metering.
 */
final class Gemini
{
    /** The methods served at /v1beta/models/{model}:{method}. */
    public const METHODS = ['generateContent', 'streamGenerateContent', 'countTokens'];
    /** generationConfig fields sent on as chat fields. Anything else there (topK, thinkingConfig, candidateCount, ...) is dropped. */
    private const SETTINGS = ['temperature' => 'temperature', 'topP' => 'top_p', 'stopSequences' => 'stop', 'seed' => 'seed',
        'presencePenalty' => 'presence_penalty', 'frequencyPenalty' => 'frequency_penalty'];
    /** The keys a part may have: one of the three kinds below, plus the thought flag and its signature (dropped). */
    private const PART_KEYS = ['text', 'functionCall', 'functionResponse', 'thought', 'thoughtSignature'];
    /** Schema keys whose value is a map: an empty one stays {} upstream (a decoded {} is an empty PHP array). */
    private const MAP_KEYS = ['properties', 'patternProperties', '$defs', 'definitions'];
    /** Chat finish reasons in this format; any other is OTHER, and an answer without one is STOP. */
    private const FINISH = ['stop' => 'STOP', 'tool_calls' => 'STOP', 'function_call' => 'STOP', 'length' => 'MAX_TOKENS', 'content_filter' => 'SAFETY'];

    /**
     * The model and method of a Gemini path, /v1beta/models/{model}:{method}, or null for any other path.
     * @return ?array{0: string, 1: string}
     */
    public static function target(string $path): ?array
    {
        if (preg_match('#^/v1beta/models/([^/]+):(' . implode('|', self::METHODS) . ')$#', $path, $m) !== 1) {
            return null;
        }
        return [rawurldecode($m[1]), $m[2]];
    }

    /**
     * The chat completions request a Gemini request stands for (messages and allowlisted fields, for Proxy to validate,
     * bound and rewrite like any chat request), and its max_tokens for the reservation (generationConfig.maxOutputTokens).
     * @param array<string, mixed> $req
     * @return array{0: array<string, mixed>, 1: array{max_tokens: ?int}}
     */
    public static function request(array $req): array
    {
        $messages = isset($req['systemInstruction']) ? [['role' => 'system', 'content' => self::systemText($req['systemInstruction'])]] : [];
        $chat = ['messages' => array_merge($messages, self::messages($req['contents'] ?? null))];
        $tools = isset($req['tools']) ? self::tools($req['tools']) : [];
        if ($tools !== []) {
            $chat['tools'] = $tools;
        }
        $choice = self::toolChoice($req['toolConfig'] ?? null);
        if ($choice !== null) {
            $chat['tool_choice'] = $choice;
        }
        $g = is_array($req['generationConfig'] ?? null) ? $req['generationConfig'] : [];
        foreach (self::SETTINGS as $from => $to) {
            if (array_key_exists($from, $g)) {
                $chat[$to] = $g[$from];
            }
        }
        if (($g['responseMimeType'] ?? null) === 'application/json') {
            $schema = $g['responseJsonSchema'] ?? $g['responseSchema'] ?? null;
            $chat['response_format'] = is_array($schema) || $schema instanceof \stdClass // {} arrives as an empty object
                ? ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'schema' => self::schema((array) $schema)]] : ['type' => 'json_object'];
        }
        $max = $g['maxOutputTokens'] ?? null;
        if ($max !== null && (!is_int($max) || $max < 1)) {
            throw new HttpError(400, 'generationConfig.maxOutputTokens must be a positive integer', code: 'invalid_value', param: 'generationConfig.maxOutputTokens');
        }
        return [$chat, ['max_tokens' => $max]];
    }

    /**
     * The contents as chat messages. A user content's function responses become tool messages and its text one user
     * message; a model content's text and function calls become one assistant message. Function calls without an id get
     * one (call_N), and a response without an id answers the oldest unanswered call of its name. Thought parts are not
     * replayed. Any other part (inline data, files, code execution) is refused, as images are on chat.
     * @return list<array<string, mixed>>
     */
    private static function messages(mixed $contents): array
    {
        if (!is_array($contents) || $contents === [] || !array_is_list($contents)) {
            throw new HttpError(400, 'contents must be a non-empty array', code: 'invalid_messages', param: 'contents');
        }
        [$out, $pending, $n] = [[], [], 0];
        foreach ($contents as $c) {
            $role = is_array($c) ? ($c['role'] ?? 'user') : null;
            $parts = is_array($c) ? ($c['parts'] ?? null) : null;
            if (!in_array($role, ['user', 'model', 'function'], true) || !is_array($parts) || $parts === [] || !array_is_list($parts)) {
                throw new HttpError(400, 'each content needs a role of user or model and a non-empty list of parts', code: 'invalid_messages', param: 'contents');
            }
            [$texts, $calls] = [[], []];
            foreach ($parts as $p) {
                $kinds = is_array($p) ? array_intersect(array_keys($p), ['text', 'functionCall', 'functionResponse']) : [];
                $kind = count($kinds) === 1 && array_diff(array_keys($p), self::PART_KEYS) === [] ? reset($kinds) : null;
                if ($kind === 'text' && is_string($p['text'])) {
                    if (($p['thought'] ?? false) !== true) {
                        $texts[] = $p['text'];
                    }
                } elseif ($kind === 'functionCall' && $role === 'model') {
                    [$name, $args] = self::named($p['functionCall'], 'args', 'a function call needs a name, and args that are an object');
                    $id = is_string($p['functionCall']['id'] ?? null) && $p['functionCall']['id'] !== '' ? $p['functionCall']['id'] : 'call_' . ++$n;
                    $pending[$name][] = $id;
                    $calls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => Proxy::encode($args)]];
                } elseif ($kind === 'functionResponse' && $role !== 'model') {
                    [$name, $response] = self::named($p['functionResponse'], 'response', 'a function response needs a name, and a response that is an object');
                    $given = $p['functionResponse']['id'] ?? null;
                    $id = is_string($given) && $given !== '' ? $given : ($pending[$name][0] ?? 'call_' . ++$n);
                    $pending[$name] = array_values(array_diff($pending[$name] ?? [], [$id]));
                    $out[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => Proxy::encode($response)];
                } else {
                    throw new HttpError(400, 'only text, function call (model) and function response (user) parts are supported', code: 'unsupported_content', param: 'contents');
                }
            }
            if ($role === 'model' && ($texts !== [] || $calls !== [])) {
                $out[] = ['role' => 'assistant', 'content' => $texts === [] ? null : implode("\n", $texts)] + ($calls === [] ? [] : ['tool_calls' => $calls]);
            } elseif ($role !== 'model' && $texts !== []) {
                $out[] = ['role' => 'user', 'content' => implode("\n", $texts)];
            }
        }
        return $out;
    }

    /**
     * A function call's or response's name and its object field ($field, absent for {}), or a 400 with $message.
     * @return array{0: string, 1: array<mixed>|\stdClass}
     */
    private static function named(mixed $f, string $field, string $message): array
    {
        $v = is_array($f) ? ($f[$field] ?? []) : null;
        if (!is_array($f) || !is_string($f['name'] ?? null) || $f['name'] === '' || !is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new HttpError(400, $message, code: 'invalid_messages', param: 'contents');
        }
        return [$f['name'], $v === [] ? new \stdClass() : $v];
    }

    /** The system instruction's text: a string, or a content whose parts are all text. */
    private static function systemText(mixed $s): string
    {
        if (is_string($s)) {
            return $s;
        }
        $parts = is_array($s) ? ($s['parts'] ?? null) : null;
        $texts = [];
        foreach (is_array($parts) && array_is_list($parts) && $parts !== [] ? $parts : [null] as $p) {
            if (!is_array($p) || !is_string($p['text'] ?? null) || array_diff(array_keys($p), ['text', 'thought', 'thoughtSignature']) !== []) {
                throw new HttpError(400, 'systemInstruction must be text', code: 'unsupported_content', param: 'systemInstruction');
            }
            $texts[] = $p['text'];
        }
        return implode("\n", $texts);
    }

    /**
     * The function declarations as chat function tools: name, description and the parameter schema
     * (parametersJsonSchema, else parameters). Any other tool (search, URL context, code execution) is refused: it
     * runs on the provider's side, which no upstream here has, and carries fees no token rate covers.
     * @return list<array<string, mixed>>
     */
    private static function tools(mixed $tools): array
    {
        if (!is_array($tools) || !array_is_list($tools)) {
            throw new HttpError(400, 'only function declarations are supported', code: 'unsupported_tool', param: 'tools');
        }
        $out = [];
        foreach ($tools as $t) {
            $decls = is_array($t) ? ($t['functionDeclarations'] ?? []) : null;
            if (!is_array($t) || array_diff(array_keys($t), ['functionDeclarations']) !== [] || !is_array($decls) || !array_is_list($decls)) {
                throw new HttpError(400, 'only function declarations are supported', code: 'unsupported_tool', param: 'tools');
            }
            foreach ($decls as $d) {
                if (!is_array($d) || !is_string($d['name'] ?? null) || $d['name'] === '') {
                    throw new HttpError(400, 'each function declaration needs a name', code: 'invalid_value', param: 'tools');
                }
                $schema = $d['parametersJsonSchema'] ?? $d['parameters'] ?? null;
                $out[] = ['type' => 'function', 'function' => ['name' => $d['name']]
                    + (is_string($d['description'] ?? null) ? ['description' => $d['description']] : [])
                    + (is_array($schema) || $schema instanceof \stdClass ? ['parameters' => self::schema((array) $schema)] : [])];
            }
        }
        return $out;
    }

    /**
     * A schema as JSON Schema: this format's upper-case type names (OBJECT, STRING, ...) lower-cased, and an empty map
     * (properties, $defs) kept an object.
     * @param array<mixed> $s
     * @return array<mixed>|\stdClass
     */
    private static function schema(array $s): array|\stdClass
    {
        if ($s === []) {
            return new \stdClass();
        }
        $out = [];
        foreach ($s as $k => $v) {
            $out[$k] = match (true) {
                $k === 'type' && is_string($v) => strtolower($v),
                $k === 'type' && is_array($v) && array_is_list($v) => array_map(static fn (mixed $t): mixed => is_string($t) ? strtolower($t) : $t, $v),
                $v === [] && in_array($k, self::MAP_KEYS, true) => new \stdClass(),
                is_array($v) && $v !== [] => self::schema($v),
                default => $v,
            };
        }
        return $out;
    }

    /**
     * toolConfig.functionCallingConfig as a chat tool_choice: AUTO (or VALIDATED) auto, NONE none, ANY required, or the
     * one function when ANY allows exactly one; null when absent or unknown.
     * @return string|array<string, mixed>|null
     */
    private static function toolChoice(mixed $c): string|array|null
    {
        $f = is_array($c) && is_array($c['functionCallingConfig'] ?? null) ? $c['functionCallingConfig'] : [];
        $names = $f['allowedFunctionNames'] ?? null;
        return match ($f['mode'] ?? null) {
            'AUTO', 'VALIDATED' => 'auto',
            'NONE' => 'none',
            'ANY' => is_array($names) && count($names) === 1 && is_string($names[0] ?? null)
                ? ['type' => 'function', 'function' => ['name' => $names[0]]] : 'required',
            default => null,
        };
    }

    /**
     * A non-stream answer from a rebuilt chat completion (Proxy::publicView): one candidate with the text, thoughts and
     * function calls of the choices, the finish reason, and the usage report the request is charged by.
     * @param array<string, mixed> $view
     * @param ?array{prompt: int, completion: int, total: int, cached: int, reasoning: int} $usage
     * @return array<string, mixed>
     */
    public static function answer(array $view, string $alias, ?array $usage): array
    {
        [$parts, $finish] = [[], null];
        foreach ($view['choices'] ?? [] as $c) {
            $m = is_array($c['message'] ?? null) ? $c['message'] : [];
            array_push($parts, ...self::parts($m), ...array_map(self::call(...), $m['tool_calls'] ?? []));
            $finish = $c['finish_reason'] ?? $finish;
        }
        return self::response($view, $alias, $parts, $finish === null ? 'STOP' : (self::FINISH[$finish] ?? 'OTHER'), $usage);
    }

    /**
     * One chat stream event as relayed by Proxy::chatEvent() (the rebuilt event, true for the terminal marker, null for
     * nothing to relay), as this format's stream text. Text and thoughts go out at once. Function calls are put together
     * from their fragments and sent whole in the last chunk, with the finish reason and the usage the request is charged
     * by; their bytes, held back by chatEvent, count as delivered only then.
     * @param array<string, mixed> $s Proxy::forward()'s state
     * @param array<string, mixed>|bool|null $r
     */
    public static function event(array &$s, array|bool|null $r, string $alias): string
    {
        if ($r === true) {
            if (!($s['started'] || $s['start']) || $s['aborted']) {
                return '';
            }
            [$s['bytes'], $s['unmetered']] = [$s['bytes'] + $s['call_bytes'], $s['unmetered'] + $s['call_unmetered']];
            $calls = array_filter($s['calls'], static fn (array $t): bool => $t['function']['name'] !== '');
            return self::sse(self::response(['id' => $s['response_id']], $alias, array_map(self::call(...), array_values($calls)),
                $s['finish'] === null ? 'STOP' : (self::FINISH[$s['finish']] ?? 'OTHER'), $s['usage']));
        }
        if (!is_array($r)) {
            return '';
        }
        $parts = [];
        foreach ($r['choices'] ?? [] as $c) {
            foreach (['message', 'delta'] as $k) {
                $d = is_array($c[$k] ?? null) ? $c[$k] : [];
                array_push($parts, ...self::parts($d));
                foreach ($d['tool_calls'] ?? [] as $t) {
                    $at = $t['index'] ?? count($s['calls']);
                    $call = $s['calls'][$at] ?? ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    $call['id'] = $call['id'] !== '' ? $call['id'] : ($t['id'] ?? '');
                    $call['function']['name'] = $call['function']['name'] !== '' ? $call['function']['name'] : ($t['function']['name'] ?? '');
                    $call['function']['arguments'] .= $t['function']['arguments'] ?? '';
                    $s['calls'][$at] = $call;
                }
            }
            $s['finish'] = $c['finish_reason'] ?? $s['finish'];
        }
        $s['response_id'] ??= $r['id'] ?? null;
        return $parts === [] ? '' : self::sse(self::response($r, $alias, $parts, null, null));
    }

    /** The in-band error that ends a started stream the upstream did not finish. */
    public static function earlyEnd(): string
    {
        return self::sse(['error' => ['code' => 502, 'message' => 'upstream stream ended early', 'status' => 'UNAVAILABLE']]);
    }

    /**
     * Text parts of a rebuilt chat message or delta: the reasoning as a thought, then the content and a refusal. Empty
     * strings make no part (this format's clients reject an empty text part).
     * @param array<mixed> $m
     * @return list<array<string, mixed>>
     */
    private static function parts(array $m): array
    {
        $parts = [];
        foreach (['reasoning_content' => true, 'reasoning' => true, 'content' => false, 'refusal' => false] as $k => $thought) {
            if (is_string($m[$k] ?? null) && $m[$k] !== '') {
                $parts[] = ['text' => $m[$k]] + ($thought ? ['thought' => true] : []);
            }
        }
        return $parts;
    }

    /**
     * A rebuilt chat tool call as a function call part: its id, name and arguments as an object ({} when the arguments
     * are not a JSON object).
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private static function call(array $t): array
    {
        $args = json_decode((string) ($t['function']['arguments'] ?? ''), true);
        $args = is_array($args) && $args !== [] && !array_is_list($args) ? $args : new \stdClass();
        $id = is_string($t['id'] ?? null) && $t['id'] !== '' ? ['id' => $t['id']] : [];
        return ['functionCall' => $id + ['name' => (string) ($t['function']['name'] ?? ''), 'args' => $args]];
    }

    /**
     * A response object: one candidate (role model, its parts, the finish reason when there is one), the usage counts
     * when there is a report, the alias as the model version and the answer's id.
     * @param array<string, mixed> $view
     * @param list<array<string, mixed>> $parts
     * @param ?array{prompt: int, completion: int, total: int, cached: int, reasoning: int} $u
     * @return array<string, mixed>
     */
    private static function response(array $view, string $alias, array $parts, ?string $finish, ?array $u): array
    {
        $candidate = ['content' => ['role' => 'model', 'parts' => $parts]] + ($finish === null ? [] : ['finishReason' => $finish]) + ['index' => 0];
        return ['candidates' => [$candidate]]
            + ($u === null ? [] : ['usageMetadata' => ['promptTokenCount' => $u['prompt'], 'candidatesTokenCount' => $u['completion'] - $u['reasoning'],
                'totalTokenCount' => $u['total'], 'cachedContentTokenCount' => $u['cached'], 'thoughtsTokenCount' => $u['reasoning']]])
            + ['modelVersion' => $alias] + (is_string($view['id'] ?? null) ? ['responseId' => $view['id']] : []);
    }

    /** @param array<string, mixed> $data */
    private static function sse(array $data): string
    {
        return 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
    }
}
