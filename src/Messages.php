<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The Anthropic Messages format, for upstreams that serve it natively at their own `messages_url`: a metered
 * pass-through, not a translation. Requests keep only allowlisted fields and content blocks whose cost the prompt bound
 * covers; responses and stream events are rebuilt at the message and event level (the alias as the model, the standard
 * usage counts) while content blocks pass as the upstream sent them. Proxy does the reservation, retries and settling.
 */
final class Messages
{
    /** Request fields forwarded upstream besides model, max_tokens and stream (which the proxy sets). Anything else is dropped. */
    private const FORWARD = ['messages', 'system', 'stop_sequences', 'temperature', 'top_p', 'top_k', 'tools', 'tool_choice', 'thinking', 'metadata'];
    /**
     * The content block types a request may carry, each with the fields kept. Images and documents are refused, as on
     * chat: their cost cannot be bounded by their bytes. Server tool blocks are refused with server tools (below).
     */
    private const BLOCKS = [
        'text' => ['type', 'text', 'cache_control'],
        'tool_use' => ['type', 'id', 'name', 'input', 'cache_control'],
        'tool_result' => ['type', 'tool_use_id', 'content', 'is_error', 'cache_control'],
        'thinking' => ['type', 'thinking', 'signature'],
        'redacted_thinking' => ['type', 'data'],
    ];
    /** The fields of a client tool definition. Server tools (any other `type`) are refused: they carry fees no token rate covers. */
    private const TOOL_FIELDS = ['type', 'name', 'description', 'input_schema', 'cache_control'];
    /** Prompt tokens an upstream adds on its own when tools are given (its tool-use system prompt), on top of the bytes. */
    public const TOOLS_OVERHEAD = 1024;
    /** The API version sent upstream when the client sends none. */
    private const DEFAULT_VERSION = '2023-06-01';
    /** The usage counts an Anthropic usage object carries, all of which a client sees. */
    private const USAGE_FIELDS = ['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens'];
    /** The stream events relayed once the stream has started; any other is dropped. */
    private const EVENTS = ['message_start', 'content_block_start', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop', 'ping'];

    /**
     * The forwarded fields of a client request, validated: messages and system rebuilt from allowlisted blocks, tools
     * from allowlisted fields, the other fields as sent. The model, max_tokens and stream are not among them.
     * @param array<string, mixed> $req
     * @return array<string, mixed>
     */
    public static function fields(array $req): array
    {
        $f = array_intersect_key($req, array_flip(self::FORWARD));
        $f['messages'] = self::messages($req['messages'] ?? null);
        if (array_key_exists('system', $f) && !is_string($f['system'])) {
            $f['system'] = self::blocks($f['system'], ['text'], 'system');
        }
        if (array_key_exists('tools', $f)) {
            $f['tools'] = self::tools($f['tools']);
        }
        return $f;
    }

    /**
     * The upstream request: the operator's model id, the forwarded fields, the clamped max_tokens and the stream flag.
     * A thinking budget the clamped max_tokens no longer leaves room for is lowered to fit (the upstream requires
     * budget_tokens below max_tokens), so a request clamped to the balance still runs instead of being rejected upstream.
     * @param array<string, mixed> $fields from fields()
     * @return array<string, mixed>
     */
    public static function rewrite(array $fields, string $upstreamModel, int $maxTokens, bool $stream): array
    {
        $out = ['model' => $upstreamModel] + $fields + ['max_tokens' => $maxTokens, 'stream' => $stream];
        if (is_array($out['thinking'] ?? null) && is_int($out['thinking']['budget_tokens'] ?? null) && $out['thinking']['budget_tokens'] >= $maxTokens) {
            $out['thinking']['budget_tokens'] = max(1, $maxTokens - 1);
        }
        return $out;
    }

    /**
     * Upper bound on the prompt tokens: the byte length of every forwarded field, plus the tool-use system prompt an
     * upstream adds when tools are given. Also the answer of POST /v1/messages/count_tokens.
     * @param array<string, mixed> $fields from fields()
     */
    public static function promptBound(array $fields): int
    {
        return strlen(Proxy::encode($fields)) + 16 + (isset($fields['tools']) ? self::TOOLS_OVERHEAD : 0);
    }

    /**
     * The headers of the upstream request: the upstream's own key (as x-api-key and as a bearer token, the two ways such
     * endpoints read it), and the client's anthropic-version (else the default) and anthropic-beta when well-formed.
     * @return list<string>
     */
    public static function headers(string $key): array
    {
        $h = ['Content-Type: application/json', 'x-api-key: ' . $key, 'Authorization: Bearer ' . $key];
        $version = self::clientHeader('HTTP_ANTHROPIC_VERSION');
        $h[] = 'anthropic-version: ' . ($version ?? self::DEFAULT_VERSION);
        $beta = self::clientHeader('HTTP_ANTHROPIC_BETA');
        if ($beta !== null) {
            $h[] = 'anthropic-beta: ' . $beta;
        }
        return $h;
    }

    /** A client header made of printable ASCII (no line breaks, at most 1 KiB), else null. */
    private static function clientHeader(string $name): ?string
    {
        $v = $_SERVER[$name] ?? null;
        return is_string($v) && preg_match('/^[\x20-\x7E]{1,1024}$/', $v) === 1 ? trim($v) : null;
    }

    /**
     * Token counts from an Anthropic usage object in the shape Proxy charges by, or null unless input_tokens and
     * output_tokens are integers in range (and so are the cache counts when present; absent or null is 0) and the prompt
     * side has at least one token. The prompt is input + cache_creation_input_tokens + cache_read_input_tokens; only the
     * cache reads are cached input, cache writes are priced as fresh input. Reasoning is inside output_tokens and not
     * reported apart (0). The raw split is kept for the client's view.
     * An upstream-reported cost is tracking metadata only, in the same micro-unit as chat; it never sets the charge.
     * @return array{prompt: int, completion: int, total: int, cached: int, reasoning: int, cost: ?int, input: int, creation: int}|null
     */
    public static function usage(mixed $u): ?array
    {
        if (!is_array($u)) {
            return null;
        }
        $count = static fn (mixed $n): ?int => is_int($n) && $n >= 0 && $n <= Credits::MAX_COUNT ? $n : null;
        [$in, $out] = [$count($u['input_tokens'] ?? null), $count($u['output_tokens'] ?? null)];
        [$write, $read] = [$count($u['cache_creation_input_tokens'] ?? 0), $count($u['cache_read_input_tokens'] ?? 0)];
        if ($in === null || $out === null || $write === null || $read === null) {
            return null;
        }
        $prompt = $in + $write + $read;
        if ($prompt < 1 || $prompt > Credits::MAX_COUNT) {
            return null;
        }
        $cost = $u['estimated_cost'] ?? $u['cost'] ?? null;
        $cost = (is_int($cost) || is_float($cost)) && is_finite((float) $cost) && $cost >= 0 && $cost <= 1e6
            ? (int) round($cost * 1e6) : null;
        return ['prompt' => $prompt, 'completion' => $out, 'total' => $prompt + $out, 'cached' => $read, 'reasoning' => 0, 'cost' => $cost,
            'input' => $in, 'creation' => $write];
    }

    /**
     * The usage a client sees: the four standard counts of the report the request is charged by, or zeros when there is
     * none (the format has no null usage; the charge then is the upper bound, see Proxy::charge).
     * @param ?array{completion: int, cached: int, input: int, creation: int} $u
     * @return array<string, int>
     */
    private static function usageView(?array $u): array
    {
        return array_combine(self::USAGE_FIELDS, $u === null ? [0, 0, 0, 0] : [$u['input'], $u['creation'], $u['cached'], $u['completion']]);
    }

    /**
     * A non-stream answer the client may get as a 200, rebuilt, or null: a message whose content has at least one usable
     * block (non-empty text or thinking, redacted thinking, or a tool call with an id and a name). The message keeps its
     * id, role, stop reason and stop sequence, the alias as the model and the standard usage counts; the content blocks
     * pass as sent.
     * @return ?array<string, mixed>
     */
    public static function answer(mixed $j, string $alias): ?array
    {
        if (!is_array($j) || ($j['type'] ?? null) !== 'message' || !is_array($j['content'] ?? null) || !array_is_list($j['content'])) {
            return null;
        }
        $content = array_values(array_filter($j['content'], static fn (mixed $b): bool => self::isObject($b) && is_string($b['type'] ?? null)));
        foreach ($content as $b) {
            if (match ($b['type']) {
                'text' => is_string($b['text'] ?? null) && $b['text'] !== '',
                'thinking' => is_string($b['thinking'] ?? null) && $b['thinking'] !== '',
                'redacted_thinking' => is_string($b['data'] ?? null) && $b['data'] !== '',
                'tool_use' => is_string($b['id'] ?? null) && $b['id'] !== '' && is_string($b['name'] ?? null) && $b['name'] !== '',
                default => false,
            }) {
                return self::message($j, $alias, $content, self::usage($j['usage'] ?? null));
            }
        }
        return null;
    }

    /**
     * A message object rebuilt from its standard fields.
     * @param array<mixed> $j
     * @param list<mixed> $content
     * @param ?array{completion: int, cached: int, input: int, creation: int} $u
     * @return array<string, mixed>
     */
    private static function message(array $j, string $alias, array $content, ?array $u): array
    {
        $str = static fn (string $k): ?string => is_string($j[$k] ?? null) ? $j[$k] : null;
        return array_filter(['id' => $str('id')], static fn (?string $v): bool => $v !== null)
            + ['type' => 'message', 'role' => 'assistant', 'model' => $alias, 'content' => $content,
                'stop_reason' => $str('stop_reason'), 'stop_sequence' => $str('stop_sequence'), 'usage' => self::usageView($u)];
    }

    /**
     * Bytes of text in what the client received: every string inside the content blocks or deltas. The upper-bound
     * charge for output without a usage report covering it is derived from this.
     */
    public static function bytes(mixed $v): int
    {
        if (is_string($v)) {
            return strlen($v);
        }
        $n = 0;
        foreach (is_array($v) ? $v : [] as $x) {
            $n += self::bytes($x);
        }
        return $n;
    }

    /** One server-sent event in the Anthropic form: its name line and its data. */
    public static function sse(string $type, array $data): string
    {
        return "event: $type\ndata: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /** The in-band error that ends a started stream the upstream did not finish. */
    public static function earlyEnd(): string
    {
        return self::sse('error', ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'upstream stream ended early']]);
    }

    /**
     * One upstream stream event: updates the usage and byte bounds in Proxy::forward()'s state and returns the text to
     * relay ('' = nothing). The stream starts (the 200) at the first content block event after a message_start; the
     * message_start is held until then, so an upstream failure before any content is a normal error and free.
     * message_stop is the terminal marker: nothing after it is relayed or counted. The input counts come from
     * message_start and the cumulative output count from message_delta (either may update the other's fields); as on
     * chat, only a report that advances the total covers the text delivered before it.
     * @param array<string, mixed> $s
     * @param array{input: int, cached_input: int, output: int} $rates
     */
    public static function event(array &$s, string $event, string $alias, array $rates, int $reserved): string
    {
        $e = Proxy::parseEvent($event);
        if ($e === null || $e['done'] || $s['done'] || $s['failed']) {
            return '';
        }
        $j = $e['json'];
        $type = $j['type'] ?? null;
        $begun = $s['started'] || $s['start'];
        if (isset($j['error']) || $type === 'error') {
            $s[$begun ? 'failed' : 'bad'] = true; // before the start: a refusal; after: the stream ends with the generic in-band error
            return '';
        }
        if (!in_array($type, self::EVENTS, true)) {
            $s['bad'] = !$begun; // not an Anthropic stream event; ignored once the stream has begun
            return '';
        }
        $usage = match ($type) {
            'message_start' => self::isObject($j['message'] ?? null) ? ($j['message']['usage'] ?? null) : null,
            'message_delta' => $j['usage'] ?? null,
            default => null,
        };
        if (is_array($usage)) {
            foreach (self::USAGE_FIELDS as $k) {
                if (isset($usage[$k])) {
                    $s['raw'][$k] = $usage[$k]; // later counts replace earlier ones; message_delta's are cumulative
                }
            }
            // Counts accumulate across events; cost belongs only to this report, never to a previous token total.
            $u = self::usage($s['raw'] + array_intersect_key($usage, ['estimated_cost' => true, 'cost' => true]));
            if ($u !== null && ($s['usage'] === null || $u['total'] > $s['usage']['total'])) {
                $s['usage'] = $u;
                $s['unmetered'] = 0;
                $s['over'] = Proxy::usageCredits($u, 0, $rates) > $reserved;
            } elseif ($u !== null && [$u['prompt'], $u['completion'], $u['total']]
                === [$s['usage']['prompt'], $s['usage']['completion'], $s['usage']['total']]) {
                // As on chat, a final repeat may supply missing cost once. It covers no newly delivered bytes.
                $s['usage']['cost'] ??= $u['cost'];
            }
        }
        if ($type === 'message_start') {
            if (!$begun && $s['held'] === '') {
                if (!self::isObject($j['message'] ?? null)) {
                    $s['bad'] = true;
                    return '';
                }
                $s['held'] = self::sse('message_start', ['type' => 'message_start', 'message' => self::message($j['message'], $alias, [], $s['usage'])]);
            }
            return '';
        }
        $block = in_array($type, ['content_block_start', 'content_block_delta'], true);
        if (!$begun) {
            if (!$block) {
                $s['done'] = $type === 'message_stop'; // an answer without content: stop reading, a 502, no charge
                return '';
            }
            if ($s['held'] === '') {
                $s['bad'] = true; // content without a message_start is not a stream this format knows
                return '';
            }
            $s['start'] = true;
        }
        if ($s['aborted']) {
            return ''; // after a hang-up nothing is relayed or counted as delivered; usage is still read above
        }
        $out = '';
        if (!$s['started'] && $s['held'] !== '') {
            [$out, $s['held']] = [$s['held'], ''];
        }
        $int = static fn (string $k): array => is_int($j[$k] ?? null) ? [$k => $j[$k]] : [];
        $data = match ($type) {
            'content_block_start' => ['type' => $type] + $int('index') + ['content_block' => self::isObject($j['content_block'] ?? null) ? $j['content_block'] : new \stdClass()],
            'content_block_delta' => ['type' => $type] + $int('index') + ['delta' => self::isObject($j['delta'] ?? null) ? $j['delta'] : new \stdClass()],
            'content_block_stop' => ['type' => $type] + $int('index'),
            'message_delta' => ['type' => $type, 'delta' => self::stopFields($j['delta'] ?? null), 'usage' => ['output_tokens' => $s['usage']['completion'] ?? 0]],
            default => ['type' => $type], // message_stop, ping
        };
        if ($block) {
            $n = self::bytes($data['content_block'] ?? $data['delta'] ?? null);
            $s['bytes'] += $n;
            $s['unmetered'] += $n;
        }
        if ($type === 'message_stop') {
            $s['done'] = true;
        }
        return $out . self::sse($type, $data);
    }

    /** @return array{stop_reason: ?string, stop_sequence: ?string} */
    private static function stopFields(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        return ['stop_reason' => is_string($d['stop_reason'] ?? null) ? $d['stop_reason'] : null,
            'stop_sequence' => is_string($d['stop_sequence'] ?? null) ? $d['stop_sequence'] : null];
    }

    /**
     * The messages: a non-empty list of objects with a string role and content that is a string or a non-empty list of
     * allowed blocks; only role and content are kept.
     * @return list<array{role: string, content: mixed}>
     */
    private static function messages(mixed $messages): array
    {
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            throw new HttpError(400, 'messages must be a non-empty array', code: 'invalid_messages', param: 'messages');
        }
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null)) {
                throw new HttpError(400, 'each message needs a role', code: 'invalid_messages', param: 'messages');
            }
            $c = $m['content'] ?? null;
            $out[] = ['role' => $m['role'], 'content' => is_string($c) ? $c : self::blocks($c, array_keys(self::BLOCKS), 'messages')];
        }
        return $out;
    }

    /**
     * A non-empty list of content blocks of the given types, each keeping its type's fields; a tool result's content is
     * a string or such a list of text blocks.
     * @param list<string> $types
     * @return list<array<string, mixed>>
     */
    private static function blocks(mixed $blocks, array $types, string $param): array
    {
        if (!is_array($blocks) || $blocks === [] || !array_is_list($blocks)) {
            throw new HttpError(400, 'content must be a string or a non-empty list of content blocks', code: 'invalid_messages', param: $param);
        }
        $out = [];
        foreach ($blocks as $b) {
            $type = is_array($b) ? ($b['type'] ?? null) : null;
            if (!is_string($type) || !in_array($type, $types, true)) {
                throw new HttpError(400, 'only text, tool use, tool result and thinking content is supported', code: 'unsupported_content', param: $param);
            }
            $b = array_intersect_key($b, array_flip(self::BLOCKS[$type]));
            if ($type === 'tool_result' && array_key_exists('content', $b) && !is_string($b['content'])) {
                $b['content'] = self::blocks($b['content'], ['text'], $param);
            }
            $out[] = $b;
        }
        return $out;
    }

    /**
     * Client tool definitions with their known fields. A server tool (any `type` but custom) is refused.
     * @return list<array<string, mixed>>
     */
    private static function tools(mixed $tools): array
    {
        if (!is_array($tools) || !array_is_list($tools)) {
            throw new HttpError(400, 'tools must be a list of tool definitions', code: 'invalid_value', param: 'tools');
        }
        $out = [];
        foreach ($tools as $t) {
            if (!is_array($t) || ($t['type'] ?? 'custom') !== 'custom') {
                throw new HttpError(400, 'only client tools are supported', code: 'unsupported_tool', param: 'tools');
            }
            $out[] = array_intersect_key($t, array_flip(self::TOOL_FIELDS));
        }
        return $out;
    }

    /** A decoded JSON object (json_decode turns both {} and [] into an empty array; a non-empty list is not an object). */
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }
}
