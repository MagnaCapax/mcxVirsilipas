<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The customer-facing side: OpenAI-compatible chat completions with per-key metering in credits.
 *
 * Flow per request: authenticate the key, admit only text requests built from known fields,
 * reserve an upper bound of credits (clamping max_tokens to the balance), forward to the
 * configured upstream, relay a rebuilt response, then settle against the usage the upstream
 * reported, priced at the alias's rates. Request and response bodies are never logged.
 */
final class Proxy
{
    /** Request fields forwarded upstream besides messages. Anything else is dropped. */
    private const FORWARD = ['tools', 'tool_choice', 'parallel_tool_calls', 'temperature', 'top_p', 'stop',
        'seed', 'presence_penalty', 'frequency_penalty', 'response_format', 'reasoning_effort'];
    /** Message fields forwarded upstream. */
    private const MESSAGE_FIELDS = ['role', 'content', 'name', 'tool_calls', 'tool_call_id'];
    private const MAX_COUNT = Credits::MAX_COUNT;
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;
    private const MAX_EVENT_BYTES = 1024 * 1024;

    public function __construct(private readonly Config $config, private readonly Ledger $ledger)
    {
    }

    public function models(): void
    {
        $this->authenticate();
        $data = [];
        foreach ($this->config->models as $alias => $m) {
            $data[] = ['id' => $alias, 'object' => 'model', 'owned_by' => 'virsilipas',
                'credits_per_million_tokens' => ['input' => $m['input'], 'cached_input' => $m['cached_input'], 'output' => $m['output']]];
        }
        Http::json(200, ['object' => 'list', 'data' => $data]);
    }

    public function balance(): void
    {
        $key = $this->authenticate();
        $s = $this->ledger->status($key['ref'], $this->config->limitDefaults());
        Http::json(200, ['balance' => $s['balance'], 'reserved' => $s['reserved'], 'in_flight' => $s['in_flight'],
            'limits' => $s['limits'], 'window' => $s['window']]);
    }

    public function chat(): void
    {
        $key = $this->authenticate();
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        $alias = $req['model'] ?? null;
        if (!is_string($alias) || !isset($this->config->models[$alias])) {
            throw new HttpError(404, 'unknown model; see /v1/models');
        }
        $req['messages'] = self::normalizeMessages($req['messages'] ?? null);
        $stream = ($req['stream'] ?? false) === true;
        $promptBound = self::promptBound($req);
        $rates = $this->config->models[$alias];

        $lease = $this->ledger->reserve(
            $key['id'],
            $promptBound,
            self::requestedMaxTokens($req),
            $this->config->maxTokensCap,
            $rates,
            $this->config->limitDefaults(),
            $this->config->leaseSeconds,
        );

        $result = ['status' => 0, 'usage' => null, 'aborted' => false, 'delivered' => false, 'completion_bound' => 0, 'unmetered' => 0];
        try {
            Log::write('debug', 'admit', ['ref' => $key['ref'], 'alias' => $alias, 'stream' => $stream,
                'prompt_bound' => $promptBound, 'reserved' => $lease['reserved'], 'max_tokens' => $lease['max_tokens']]);
            $route = $this->config->route($alias);
            $body = self::rewrite($req, $rates['model'], $lease['max_tokens'], $stream, $route['stream_options']);
            $result = $this->forward($route, $body, $stream, $alias, $rates, $lease['reserved']);
        } finally {
            [$used, $meta] = self::charge($result, $promptBound, $alias, $rates);
            $this->ledger->settle($lease['lease_id'], $key['id'], $used, $meta);
            self::logRequest($key['ref'], $stream, $lease['reserved'], $used, $meta, $result['delivered']);
        }
    }

    /**
     * One record per request, after it settled: counts and statuses only. An upstream failure,
     * an estimated charge or usage above the reservation is a warning (it points at the
     * upstream or its configuration); everything else, client hang-ups included, is info.
     * @param array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool} $meta
     */
    private static function logRequest(string $ref, bool $stream, int $reserved, int $used, array $meta, bool $delivered): void
    {
        $failed = !$delivered && !$meta['aborted'];
        $over = $used > $reserved;
        Log::write($failed || $meta['estimated'] || $over ? 'warn' : 'info', 'request', [
            'ref' => $ref, 'alias' => $meta['model'], 'stream' => $stream, 'upstream_status' => $meta['status'],
            'delivered' => $delivered, 'aborted' => $meta['aborted'], 'estimated' => $meta['estimated'], 'cached_tokens' => $meta['cached'],
            'reserved' => $reserved, 'reported' => $used, 'charged' => min($used, $reserved), 'over_reservation' => $over,
        ]);
    }

    /**
     * Validates and rebuilds the messages: a non-empty list of objects with a role, whose content
     * is text (a string, null, or a list of {"type": "text", "text": ...} parts), keeping only the
     * known message fields. Images, audio and files are refused: their cost cannot be bounded.
     * @return list<array<string, mixed>>
     */
    public static function normalizeMessages(mixed $messages): array
    {
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            throw new HttpError(400, 'messages must be a non-empty array');
        }
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null)) {
                throw new HttpError(400, 'each message needs a role');
            }
            $m = array_intersect_key($m, array_flip(self::MESSAGE_FIELDS));
            foreach (['name', 'tool_call_id'] as $k) {
                if (array_key_exists($k, $m) && !is_string($m[$k])) {
                    throw new HttpError(400, "message $k must be a string");
                }
            }
            if (array_key_exists('tool_calls', $m)) {
                $m['tool_calls'] = self::toolCalls($m['tool_calls'], true);
            }
            $content = $m['content'] ?? null;
            if (is_array($content)) {
                $parts = [];
                foreach ($content as $part) {
                    if (!is_array($part) || ($part['type'] ?? null) !== 'text' || !is_string($part['text'] ?? null)) {
                        throw new HttpError(400, 'only text content is supported');
                    }
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                }
                $m['content'] = $parts;
            } elseif ($content !== null && !is_string($content)) {
                throw new HttpError(400, 'message content must be text');
            }
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Builds the upstream request from the normalized messages and allowlisted fields:
     * operator-chosen model, n=1 (by omission), clamped max_tokens, and server-side
     * stream_options so usage is reported.
     * @param array<string, mixed> $req
     * @param array<string, mixed> $streamOptions
     * @return array<string, mixed>
     */
    public static function rewrite(array $req, string $upstreamModel, int $maxTokens, bool $stream, array $streamOptions): array
    {
        $out = ['model' => $upstreamModel] + self::promptFields($req);
        $out['max_tokens'] = $maxTokens;
        $out['stream'] = $stream;
        if ($stream) {
            $out['stream_options'] = $streamOptions;
        }
        return $out;
    }

    /**
     * Upper bound on the prompt tokens: the byte length of every forwarded prompt field.
     * A token is at least one byte, and the JSON quoting outweighs chat-template tokens.
     * @param array<string, mixed> $req
     */
    public static function promptBound(array $req): int
    {
        return strlen(json_encode(self::promptFields($req), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') + 16;
    }

    /**
     * @param array<string, mixed> $req
     * @return array<string, mixed>
     */
    private static function promptFields(array $req): array
    {
        return array_intersect_key($req, array_flip(['messages', ...self::FORWARD]));
    }

    /** @param array<string, mixed> $req */
    public static function requestedMaxTokens(array $req): ?int
    {
        $v = $req['max_completion_tokens'] ?? $req['max_tokens'] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_int($v) || $v < 1) {
            throw new HttpError(400, 'max_tokens must be a positive integer');
        }
        return $v;
    }

    /**
     * Token counts from an upstream usage object, or null unless all three counts are integers,
     * in range and consistent (the total is at least prompt plus completion). Anything else is
     * not a report: missing usage is never read as zero, and a malformed report is charged as
     * missing (an upper bound), never as exact. Cached input is part of prompt_tokens. Upstreams name
     * it differently: prompt_tokens_details.cached_tokens, or a top-level prompt_cache_hit_tokens (with
     * prompt_tokens_details null); the first present wins. It counts only as an
     * integer from 0 to prompt_tokens; otherwise it is 0, which prices the whole prompt as fresh input.
     * @return array{prompt: int, completion: int, total: int, cached: int}|null
     */
    public static function validUsage(mixed $u): ?array
    {
        if (!is_array($u)) {
            return null;
        }
        $p = $u['prompt_tokens'] ?? null;
        $c = $u['completion_tokens'] ?? null;
        $t = $u['total_tokens'] ?? null;
        // Every completion has at least one prompt token; a zero prompt count is not a real report.
        if (!is_int($p) || !is_int($c) || !is_int($t) || $p < 1 || $c < 0 || $p > self::MAX_COUNT || $c > self::MAX_COUNT
            || $t < $p + $c || $t > 2 * self::MAX_COUNT) {
            return null;
        }
        $d = $u['prompt_tokens_details'] ?? null;
        $hit = (is_array($d) ? ($d['cached_tokens'] ?? null) : null) ?? ($u['prompt_cache_hit_tokens'] ?? null);
        $cached = is_int($hit) && $hit >= 0 && $hit <= $p ? $hit : 0;
        return ['prompt' => $p, 'completion' => $c, 'total' => $t, 'cached' => $cached];
    }

    /**
     * Credits for a usage report plus text delivered after it: the prompt side (total minus
     * completion) split into cached and fresh input, and the completion plus unmetered bytes as output.
     * @param array{prompt: int, completion: int, total: int, cached: int} $u
     * @param array{input: int, cached_input: int, output: int} $rates
     */
    public static function usageCredits(array $u, int $unmetered, array $rates): int
    {
        $promptSide = $u['total'] - $u['completion'];
        return Credits::of($promptSide - $u['cached'], $u['cached'], $u['completion'] + $unmetered, $rates);
    }

    /**
     * Turns a forward() result into the settle() arguments: (used credits, usage-row metadata).
     * Reported usage wins, plus an upper bound for anything delivered after the last report.
     * Without any usage, a delivered response is charged an upper bound (the prompt bound at the
     * input rate plus the bytes delivered at the output rate). Bounds are flagged as estimated;
     * the ledger caps any charge at the reservation.
     * @param array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_bound: int, unmetered: int} $r
     * @param array{input: int, cached_input: int, output: int} $rates
     * @return array{0: int, 1: array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool}}
     */
    public static function charge(array $r, int $promptBound, string $alias, array $rates): array
    {
        $u = $r['usage'];
        if ($u !== null) {
            [$prompt, $completion, $cached] = [$u['prompt'], $u['completion'] + $r['unmetered'], $u['cached']];
            [$used, $estimated] = [self::usageCredits($u, $r['unmetered'], $rates), $r['unmetered'] > 0];
        } elseif ($r['delivered']) {
            [$prompt, $completion, $cached, $estimated] = [$promptBound, $r['completion_bound'], 0, true];
            $used = Credits::of($promptBound, 0, $r['completion_bound'], $rates);
        } else {
            [$prompt, $completion, $cached, $used, $estimated] = [0, 0, 0, 0, false];
        }
        return [$used, ['model' => $alias, 'prompt' => $prompt, 'completion' => $completion, 'cached' => $cached,
            'status' => $r['status'], 'aborted' => $r['aborted'], 'estimated' => $estimated]];
    }

    /**
     * The response the client sees, rebuilt from known fields only: the alias as the model,
     * choices with their standard fields, and as usage the three standard token counts, the cached
     * input count and, given the alias's rates, the credits the reported usage costs (at most the
     * reservation). Anything an upstream adds (costs, account data, provider names, model ids) is dropped.
     * @param array<string, mixed> $j
     * @param ?array{input: int, cached_input: int, output: int} $rates
     * @return array<string, mixed>
     */
    public static function publicView(array $j, string $alias, ?array $rates = null, int $reserved = 0): array
    {
        $out = self::typed($j, ['id' => 'string', 'object' => 'string', 'created' => 'int']);
        $out['model'] = $alias;
        if (array_key_exists('choices', $j)) {
            $out['choices'] = self::choices($j['choices']);
            foreach ($out['choices'] as &$c) {
                foreach (['message', 'delta'] as $k) {
                    if (($c[$k] ?? null) === []) {
                        $c[$k] = new \stdClass(); // an empty object stays {} in JSON, not []
                    }
                }
            }
            unset($c);
        }
        if (array_key_exists('usage', $j)) {
            $u = self::validUsage($j['usage']);
            $out['usage'] = $u === null ? null : self::usageView($u, $rates, $reserved);
        }
        return $out;
    }

    /**
     * The usage object a client sees for a report: the standard counts, the cached count and, given
     * rates, the credits the report costs (at most the reservation).
     * @param array{prompt: int, completion: int, total: int, cached: int} $u
     * @param ?array{input: int, cached_input: int, output: int} $rates
     * @return array<string, mixed>
     */
    private static function usageView(array $u, ?array $rates, int $reserved): array
    {
        $view = ['prompt_tokens' => $u['prompt'], 'completion_tokens' => $u['completion'], 'total_tokens' => $u['total'],
            'prompt_tokens_details' => ['cached_tokens' => $u['cached']]];
        if ($rates !== null) {
            $view['credits'] = min(self::usageCredits($u, 0, $rates), $reserved);
        }
        return $view;
    }

    /**
     * Choices rebuilt from their standard fields, each value of its expected type. A choice
     * without a message or delta object is not a choice and is dropped.
     * @return list<array<string, mixed>>
     */
    private static function choices(mixed $choices): array
    {
        $out = [];
        foreach (is_array($choices) && array_is_list($choices) ? $choices : [] as $c) {
            if (!self::isObject($c)) {
                continue;
            }
            $choice = self::typed($c, ['index' => 'int', 'finish_reason' => '?string']);
            foreach (['message', 'delta'] as $k) {
                if (self::isObject($c[$k] ?? null)) {
                    $choice[$k] = self::typed($c[$k], ['role' => 'string', 'content' => '?string', 'reasoning_content' => '?string', 'refusal' => '?string']);
                    $calls = self::toolCalls($c[$k]['tool_calls'] ?? null, false);
                    if ($calls !== []) {
                        $choice[$k]['tool_calls'] = $calls; // no usable call: no tool_calls, so it cannot make a choice usable
                    }
                }
            }
            if (isset($choice['message']) || isset($choice['delta'])) {
                $out[] = $choice;
            }
        }
        return $out;
    }

    /**
     * Tool calls with only their standard fields, each of its expected type: index, id, type and
     * function name and arguments (a JSON string). Strict mode (requests) refuses anything else;
     * otherwise (responses) wrong-typed values are dropped.
     * @return list<array<string, mixed>>
     */
    private static function toolCalls(mixed $calls, bool $strict): array
    {
        if (!is_array($calls) || !array_is_list($calls) || ($strict && $calls === [])) {
            if ($strict) {
                throw new HttpError(400, 'tool_calls must be a non-empty list');
            }
            return [];
        }
        $out = [];
        foreach ($calls as $t) {
            $dropped = 0;
            $call = self::isObject($t) ? self::typed($t, ['index' => 'int', 'id' => 'string', 'type' => 'string'], $dropped) : [];
            $fn = self::isObject($t['function'] ?? null) ? self::typed($t['function'], ['name' => 'string', 'arguments' => 'string'], $dropped) : [];
            if ($fn !== []) {
                $call['function'] = $fn; // an empty or invalid function object is omitted, never relayed as []
            }
            // A request replays a complete call; a response (or stream fragment) needs at least one valid field.
            if ($strict && ($dropped > 0 || !isset($call['id'], $call['type'], $fn['name'], $fn['arguments']))) {
                throw new HttpError(400, 'each tool call needs string id, type, function name and arguments (a JSON string)');
            }
            if ($call !== []) {
                $out[] = $call;
            }
        }
        return $out;
    }

    /**
     * The listed fields of $a whose values have the listed type ('string', 'int', or '?string'
     * for the few fields the protocol allows to be null, such as content); any other field or
     * value is dropped, and each dropped listed field is counted in $dropped.
     * @param array<mixed> $a
     * @param array<string, string> $types
     * @return array<string, mixed>
     */
    private static function typed(array $a, array $types, int &$dropped = 0): array
    {
        $out = [];
        foreach ($types as $k => $type) {
            if (!array_key_exists($k, $a)) {
                continue;
            }
            $v = $a[$k];
            if ((is_string($v) && $type !== 'int') || ($v === null && $type === '?string') || (is_int($v) && $type === 'int')) {
                $out[$k] = $v;
            } else {
                $dropped++;
            }
        }
        return $out;
    }

    /**
     * What a 200 must carry. A stream starts on a choice whose delta has at least one valid field
     * (a role-only first delta counts). A non-stream answer must be complete: a message with
     * non-empty text, reasoning or refusal, or a tool call with id, type, function name and
     * arguments. Reasoning counts because an answer whose max_tokens went to reasoning is real,
     * paid output.
     */
    private static function hasChoices(mixed $j, bool $complete): bool
    {
        if (!is_array($j) || isset($j['error'])) {
            return false;
        }
        foreach (self::choices($j['choices'] ?? null) as $c) {
            if (!$complete) {
                if (($c['delta'] ?? []) !== []) {
                    return true; // a stream starts only on a delta, never on a non-stream message
                }
                continue;
            }
            $m = $c['message'] ?? [];
            foreach (['content', 'reasoning_content', 'refusal'] as $k) {
                if (($m[$k] ?? '') !== '') {
                    return true; // typed() already guarantees string or null
                }
            }
            foreach ($m['tool_calls'] ?? [] as $t) {
                if (($t['id'] ?? '') !== '' && ($t['type'] ?? '') === 'function'
                    && ($t['function']['name'] ?? '') !== '' && ($t['function']['arguments'] ?? '') !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /** A decoded JSON object (json_decode turns both {} and [] into an empty array; a non-empty list is not an object). */
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    /**
     * Bytes of text a rebuilt response or event delivers to the client, over every choice's
     * message and delta. The upper-bound charge is derived from this, so it covers exactly what
     * was relayed: neither discarded upstream fields nor relayed text outside a delta escape it.
     * @param array<string, mixed> $view
     */
    private static function viewBytes(array $view): int
    {
        $n = 0;
        foreach ($view['choices'] ?? [] as $c) {
            $n += self::textBytes($c['message'] ?? null) + self::textBytes($c['delta'] ?? null);
        }
        return $n;
    }

    /** Bytes of text a message or delta carries: content, reasoning, refusal and tool-call names and arguments. */
    private static function textBytes(mixed $part): int
    {
        if (!is_array($part)) {
            return 0;
        }
        $n = 0;
        foreach (['content', 'reasoning_content', 'refusal'] as $k) {
            $n += is_string($part[$k] ?? null) ? strlen($part[$k]) : 0;
        }
        foreach (is_array($part['tool_calls'] ?? null) ? $part['tool_calls'] : [] as $t) {
            $fn = is_array($t) ? ($t['function'] ?? null) : null;
            $n += is_array($fn) ? strlen(json_encode($fn, JSON_UNESCAPED_UNICODE) ?: '') : 0;
        }
        return $n;
    }

    /**
     * Reads one SSE event (lines up to a blank line). Returns null if it carries no usable data.
     * @return array{json: array<string, mixed>|null, done: bool}|null
     */
    public static function parseEvent(string $event): ?array
    {
        $data = [];
        $name = '';
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            } elseif (str_starts_with($line, 'event:')) {
                $name = trim(substr($line, 6));
            }
        }
        if ($name === 'error') {
            // A named error frame is an error whatever its data says (the data is never relayed).
            return ['json' => ['error' => ['message' => 'upstream error event']], 'done' => false];
        }
        if ($data === []) {
            return null;
        }
        $payload = implode("\n", $data);
        if (trim($payload) === '[DONE]') {
            return ['json' => null, 'done' => true];
        }
        $j = json_decode($payload, true);
        return is_array($j) ? ['json' => $j, 'done' => false] : null;
    }

    /** @return array{id: int, ref: string, balance: int, active: bool} */
    private function authenticate(): array
    {
        $key = $this->ledger->findKey(Http::bearer());
        if ($key === null) {
            throw new HttpError(401, 'invalid key', 'authentication_error');
        }
        if (!$key['active']) {
            throw new HttpError(403, 'key is disabled', 'permission_error');
        }
        return $key;
    }

    /**
     * Forwards to the upstream. The client gets a 200 only once the upstream has answered 2xx
     * with a completion that has choices (non-stream) or a first event with choices (stream);
     * otherwise a generic error of the same class, with nothing charged (upstream bodies are not
     * relayed). A client that hangs up stops the upstream request, and so does a stream whose
     * reported usage passes the reservation; what was delivered is still charged.
     * @param array{url: string, key: string, stream_options: array<string, mixed>} $route
     * @param array<string, mixed> $body
     * @param array{model: string, input: int, cached_input: int, output: int} $rates
     * @return array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_bound: int, unmetered: int}
     */
    private function forward(array $route, array $body, bool $stream, string $alias, array $rates, int $reserved): array
    {
        // Shared by reference with the curl callbacks below, which change it during the transfer.
        /** @var array{status: int, usage: ?array, aborted: bool, started: bool, start: bool, done: bool, bad: bool, failed: bool, too_big: bool, over: bool, bytes: int, unmetered: int, buf: string, full: string} $s */
        $s = ['status' => 0, 'usage' => null, 'aborted' => false, 'started' => false, 'start' => false, 'done' => false, 'bad' => false, 'failed' => false,
            'too_big' => false, 'over' => false, 'bytes' => 0, 'unmetered' => 0, 'buf' => '', 'full' => ''];

        // One event: update usage and the byte bounds, return the text to relay ('' = drop).
        $handle = static function (string $event) use (&$s, $alias, $rates, $reserved): string {
            $e = self::parseEvent($event);
            if ($e === null || $s['done'] || $s['failed']) {
                return ''; // nothing after the terminal marker, or after an upstream error, is relayed or counted
            }
            if ($e['done']) {
                $s['done'] = true; // a [DONE] before any choice leaves the stream unstarted: a 502, no charge
                return "data: [DONE]\n\n";
            }
            $j = $e['json'];
            $begun = $s['started'] || $s['start'];
            if (isset($j['error'])) {
                // Before the stream starts: a refusal (generic non-200). After: the stream ends here
                // with the generic in-band error and no [DONE]; output already relayed is charged.
                $s[$begun ? 'failed' : 'bad'] = true;
                return '';
            }
            if (!isset($j['choices']) && !isset($j['usage'])) {
                $s['bad'] = !$begun; // not a completion event; ignored once the stream has begun
                return '';
            }
            if (self::hasChoices($j, false)) {
                $s['start'] = true;
            }
            // Nothing is relayed before the first event with a usable delta; such events add no
            // bytes to the charge (their usage, if valid, is still kept). Bytes are counted from
            // the rebuilt event the client receives, never from raw upstream text, so nothing read
            // after the client hung up is counted as delivered.
            $relay = ($s['started'] || $s['start']) && !$s['aborted'];
            $view = self::publicView($j, $alias, $rates, $reserved);
            $n = $relay ? self::viewBytes($view) : 0;
            $s['bytes'] += $n;
            $s['unmetered'] += $n;
            $u = self::validUsage($j['usage'] ?? null);
            // Usage is cumulative. Only a report that advances covers the text delivered since the
            // last one; an equal or lower report changes nothing, so that text stays charged as bytes.
            if ($u !== null && ($s['usage'] === null || $u['total'] > $s['usage']['total'])) {
                $s['usage'] = $u;
                $s['unmetered'] = 0;
                $s['over'] = self::usageCredits($u, 0, $rates) > $reserved;
            } elseif ($u !== null && $s['usage']['cached'] === 0
                && array_diff_key($u, ['cached' => 1]) === array_diff_key($s['usage'], ['cached' => 1])) {
                // The same counts again with a cached split: upstreams that report running usage send it only
                // with the final report. It is added once and never changed or removed by a later repeat; it
                // covers no text delivered since the last advance.
                $s['usage']['cached'] = $u['cached'];
            }
            // A relayed usage object shows the report the request is charged by, not a repeat or a lower
            // report the proxy did not adopt, so the client sees the same credits as the ledger.
            if ($s['usage'] !== null && ($view['usage'] ?? null) !== null) {
                $view['usage'] = self::usageView($s['usage'], $rates, $reserved);
            }
            return $relay ? 'data: ' . json_encode($view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n" : '';
        };

        $ch = curl_init($route['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $route['key']],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->config->upstreamTimeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$s): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $s['status'] = (int) $m[1]; // the last status line wins (e.g. after 100 Continue)
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$s, $stream, $handle): int {
                $len = strlen($chunk);
                if ($s['status'] < 200 || $s['status'] > 299) {
                    return $len; // error body: read and discard
                }
                if (!$stream) {
                    if (strlen($s['full']) + $len > self::MAX_RESPONSE_BYTES) {
                        $s['too_big'] = true;
                        return 0;
                    }
                    $s['full'] .= $chunk;
                    return $len;
                }
                $s['buf'] = str_replace("\r\n", "\n", $s['buf'] . $chunk);
                $out = '';
                while (($p = strpos($s['buf'], "\n\n")) !== false) {
                    $event = substr($s['buf'], 0, $p);
                    $s['buf'] = substr($s['buf'], $p + 2);
                    $out .= $handle($event);
                    if ($s['bad']) {
                        return 0; // a 2xx that is not a completion stream: nothing has been sent yet
                    }
                    if ($s['over'] || $s['failed']) {
                        $s['buf'] = ''; // reservation used up, or upstream error: nothing queued behind it is relayed
                        break;
                    }
                }
                if (strlen($s['buf']) > self::MAX_EVENT_BYTES) {
                    $s['too_big'] = true;
                    return 0;
                }
                if (!$s['started']) {
                    if (!$s['start']) {
                        return $s['done'] ? 0 : $len; // nothing to relay yet ([DONE] first: stop, it is a 502)
                    }
                    $s['started'] = true;
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    http_response_code(200);
                    header('Content-Type: text/event-stream');
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');
                }
                if ($out !== '' && !$s['aborted']) {
                    echo $out;
                    flush();
                }
                if (!$s['aborted'] && connection_aborted()) {
                    $s['aborted'] = true;
                }
                // After a hang-up nothing more is relayed, but the upstream is read until a usage report
                // covers the text the client received, so the charge is the upstream's own count even
                // where it reports usage only at the end. Then a short write stops the upstream request.
                if ($s['aborted'] && $s['unmetered'] === 0) {
                    return 0;
                }
                // Usage past the reservation: stop paying for more. [DONE]: the answer is complete, so
                // do not hold the worker and the key's concurrency slot while the upstream lingers.
                return ($s['over'] || $s['done'] || $s['failed']) ? 0 : $len;
            },
        ]);
        curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        $status = $s['status'];
        $result = ['status' => $status, 'usage' => null, 'aborted' => $s['aborted'], 'delivered' => false, 'completion_bound' => 0, 'unmetered' => 0];

        if ($stream && $s['started']) {
            $tail = trim($s['buf']) === '' ? '' : $handle($s['buf']); // after a hang-up too: it may hold the final report
            if (!$s['aborted']) {
                if (!$s['done']) {
                    $tail .= 'data: ' . json_encode(['error' => ['message' => 'upstream stream ended early', 'type' => 'api_error']]) . "\n\n";
                }
                echo $tail;
                flush();
            }
            return ['usage' => $s['usage'], 'delivered' => true, 'completion_bound' => $s['bytes'], 'unmetered' => $s['unmetered']] + $result;
        }

        $json = (!$stream && $curlErr === 0 && $status >= 200 && $status <= 299) ? json_decode($s['full'], true) : null;
        if (self::hasChoices($json, true)) {
            http_response_code(200);
            header('Content-Type: application/json');
            $view = self::publicView($json, $alias, $rates, $reserved);
            echo json_encode($view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $bound = self::viewBytes($view); // what the client received, never discarded upstream fields
            return ['usage' => self::validUsage($json['usage'] ?? null), 'delivered' => true, 'completion_bound' => $bound] + $result;
        }

        if ($s['too_big']) {
            (new HttpError(502, 'upstream response too large', 'api_error'))->send();
        } elseif ($status === 429) {
            (new HttpError(429, 'upstream is busy, retry shortly', 'rate_limit_error', 1))->send();
        } elseif ($status >= 400 && $status <= 499) {
            (new HttpError($status, "upstream rejected the request (HTTP $status)"))->send();
        } else {
            (new HttpError(502, 'upstream unavailable or returned an invalid response', 'api_error'))->send();
        }
        return $result;
    }
}
