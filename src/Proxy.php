<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The customer-facing side: OpenAI-compatible chat completions with per-key metering.
 *
 * Flow per request: authenticate the key, admit only text requests made of known fields,
 * reserve an upper bound of tokens (clamping max_tokens to the balance), forward to the
 * configured upstream, relay the response, then settle against the usage the upstream
 * reported. Request and response bodies are never logged.
 */
final class Proxy
{
    /** The only request fields forwarded upstream. Anything else is dropped, so it can neither cost nor leak. */
    private const FORWARD = ['messages', 'tools', 'tool_choice', 'parallel_tool_calls', 'temperature', 'top_p', 'stop',
        'seed', 'presence_penalty', 'frequency_penalty', 'response_format', 'reasoning_effort'];
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
        foreach (array_keys($this->config->models) as $alias) {
            $data[] = ['id' => $alias, 'object' => 'model', 'owned_by' => 'virsilipas'];
        }
        Http::json(200, ['object' => 'list', 'data' => $data]);
    }

    public function balance(): void
    {
        $key = $this->authenticate();
        $s = $this->ledger->status($key['ref']);
        Http::json(200, ['balance' => $s['balance'], 'in_flight' => $s['in_flight']]);
    }

    public function chat(): void
    {
        $key = $this->authenticate();
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        $alias = $req['model'] ?? null;
        if (!is_string($alias) || !isset($this->config->models[$alias])) {
            throw new HttpError(404, 'unknown model; see /v1/models');
        }
        self::checkMessages($req['messages'] ?? null);
        $stream = ($req['stream'] ?? false) === true;
        $promptBound = self::promptBound($req);

        $lease = $this->ledger->reserve(
            $key['id'],
            $promptBound,
            self::requestedMaxTokens($req),
            $this->config->maxTokensCap,
            $this->config->maxConcurrentPerKey,
            $this->config->leaseSeconds,
        );

        $result = ['status' => 0, 'usage' => null, 'aborted' => false, 'delivered' => false, 'completion_bound' => 0];
        try {
            $body = self::rewrite($req, $this->config->models[$alias], $lease['max_tokens'], $stream, $this->config->streamOptions);
            $result = $this->forward($body, $stream, $alias);
        } finally {
            $this->ledger->settle($lease['lease_id'], $key['id'], ...self::charge($result, $promptBound, $alias));
        }
    }

    /**
     * Messages must be a non-empty list of objects whose content is text: a string, null, or a
     * list of {"type": "text"} parts. Images, audio and files are refused: their cost cannot be
     * bounded before the request is sent.
     */
    public static function checkMessages(mixed $messages): void
    {
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            throw new HttpError(400, 'messages must be a non-empty array');
        }
        foreach ($messages as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null)) {
                throw new HttpError(400, 'each message needs a role');
            }
            $content = $m['content'] ?? null;
            if ($content === null || is_string($content)) {
                continue;
            }
            if (!is_array($content)) {
                throw new HttpError(400, 'message content must be text');
            }
            foreach ($content as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'text' || !is_string($part['text'] ?? null)) {
                    throw new HttpError(400, 'only text content is supported');
                }
            }
        }
    }

    /**
     * Builds the upstream request from the allowlisted fields: operator-chosen model, n=1 (by
     * omission), clamped max_tokens, and server-side stream_options so usage is reported.
     * @param array<string, mixed> $req
     * @param array<string, mixed> $streamOptions
     * @return array<string, mixed>
     */
    public static function rewrite(array $req, string $upstreamModel, int $maxTokens, bool $stream, array $streamOptions): array
    {
        $out = ['model' => $upstreamModel] + array_intersect_key($req, array_flip(self::FORWARD));
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
        $fields = array_intersect_key($req, array_flip(self::FORWARD));
        return strlen(json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') + 16;
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
     * Token counts from an upstream usage object, or null if they are missing or not
     * non-negative integers (missing usage is never read as zero).
     * @return array{prompt: int, completion: int, total: int}|null
     */
    public static function validUsage(mixed $u): ?array
    {
        if (!is_array($u)) {
            return null;
        }
        $p = $u['prompt_tokens'] ?? null;
        $c = $u['completion_tokens'] ?? null;
        if (!is_int($p) || !is_int($c) || $p < 0 || $c < 0) {
            return null;
        }
        $t = $u['total_tokens'] ?? null;
        return ['prompt' => $p, 'completion' => $c, 'total' => is_int($t) ? max($t, $p + $c) : $p + $c];
    }

    /**
     * Turns a forward() result into the settle() arguments: (used tokens, usage-row metadata).
     * Reported usage wins. Without it, a delivered response is charged an upper bound (the
     * prompt bound plus the bytes of what was delivered) and flagged; the ledger caps any charge
     * at the reservation.
     * @param array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_bound: int} $r
     * @return array{0: int, 1: array{model: string, prompt: int, completion: int, status: int, aborted: bool, estimated: bool}}
     */
    public static function charge(array $r, int $promptBound, string $alias): array
    {
        $u = $r['usage'];
        if ($u !== null) {
            [$prompt, $completion, $used, $estimated] = [$u['prompt'], $u['completion'], $u['total'], false];
        } elseif ($r['delivered']) {
            [$prompt, $completion, $estimated] = [$promptBound, $r['completion_bound'], true];
            $used = $prompt + $completion;
        } else {
            [$prompt, $completion, $used, $estimated] = [0, 0, 0, false];
        }
        return [$used, ['model' => $alias, 'prompt' => $prompt, 'completion' => $completion,
            'status' => $r['status'], 'aborted' => $r['aborted'], 'estimated' => $estimated]];
    }

    /**
     * What the client sees of an upstream chunk or completion: the alias as the model and only
     * the three standard token counts as usage. Upstreams add their own fields (costs, account
     * data, model ids) that are the operator's business, not the customer's.
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    public static function publicView(array $j, string $alias): array
    {
        if (array_key_exists('model', $j)) {
            $j['model'] = $alias;
        }
        if (isset($j['usage'])) {
            $u = self::validUsage($j['usage']);
            $j['usage'] = $u === null ? null : ['prompt_tokens' => $u['prompt'], 'completion_tokens' => $u['completion'], 'total_tokens' => $u['total']];
        }
        return $j;
    }

    /**
     * Reads one SSE event (lines up to a blank line). Returns null if it carries no usable data.
     * @return array{json: array<string, mixed>|null, done: bool}|null
     */
    public static function parseEvent(string $event): ?array
    {
        $data = [];
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
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
     * with a valid completion (non-stream) or a valid first event (stream); otherwise a generic
     * error of the same class, with nothing charged (upstream bodies are not relayed).
     * A client that hangs up stops the upstream request; what was delivered is still charged.
     * @param array<string, mixed> $body
     * @return array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_bound: int}
     */
    private function forward(array $body, bool $stream, string $alias): array
    {
        $s = ['status' => 0, 'usage' => null, 'aborted' => false, 'started' => false, 'done' => false,
            'bad' => false, 'too_big' => false, 'bytes' => 0, 'buf' => '', 'full' => ''];

        // One event: update usage and the delivered-bytes bound, return the text to relay ('' = drop).
        $handle = static function (string $event) use (&$s, $alias): string {
            $e = self::parseEvent($event);
            if ($e === null) {
                return '';
            }
            if ($e['done']) {
                $s['done'] = true;
                return "data: [DONE]\n\n";
            }
            $j = $e['json'];
            if (isset($j['error']) || (!isset($j['choices']) && !isset($j['usage']))) {
                $s['bad'] = !$s['started'];
                return '';
            }
            $u = self::validUsage($j['usage'] ?? null);
            if ($u !== null) {
                $s['usage'] = $u; // cumulative when the upstream reports continuously
            }
            foreach (($j['choices'] ?? []) as $choice) {
                $s['bytes'] += strlen(json_encode($choice['delta'] ?? [], JSON_UNESCAPED_UNICODE) ?: '');
            }
            return 'data: ' . json_encode(self::publicView($j, $alias), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        };

        $ch = curl_init($this->config->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->upstreamKey()],
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
                }
                if (strlen($s['buf']) > self::MAX_EVENT_BYTES) {
                    $s['too_big'] = true;
                    return 0;
                }
                if ($out !== '') {
                    if (!$s['started']) {
                        $s['started'] = true;
                        while (ob_get_level() > 0) {
                            ob_end_flush();
                        }
                        http_response_code(200);
                        header('Content-Type: text/event-stream');
                        header('Cache-Control: no-cache');
                        header('X-Accel-Buffering: no');
                    }
                    echo $out;
                    flush();
                }
                if ($s['started'] && connection_aborted()) {
                    $s['aborted'] = true;
                    return 0; // a short write makes curl stop the upstream request
                }
                return $len;
            },
        ]);
        curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        $status = $s['status'];
        $result = ['status' => $status, 'usage' => null, 'aborted' => $s['aborted'], 'delivered' => false, 'completion_bound' => 0];

        if ($stream && $s['started']) {
            if (!$s['aborted']) {
                $tail = trim($s['buf']) === '' ? '' : $handle($s['buf']);
                if (!$s['done']) {
                    $tail .= 'data: ' . json_encode(['error' => ['message' => 'upstream stream ended early', 'type' => 'api_error']]) . "\n\n";
                }
                echo $tail;
                flush();
            }
            return ['usage' => $s['usage'], 'delivered' => true, 'completion_bound' => $s['bytes']] + $result;
        }

        $json = (!$stream && $curlErr === 0 && $status >= 200 && $status <= 299) ? json_decode($s['full'], true) : null;
        if (is_array($json) && is_array($json['choices'] ?? null) && !isset($json['error'])) {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(self::publicView($json, $alias), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $bound = strlen(json_encode($json['choices'], JSON_UNESCAPED_UNICODE) ?: '');
            return ['usage' => self::validUsage($json['usage'] ?? null), 'delivered' => true, 'completion_bound' => $bound] + $result;
        }

        if ($s['too_big']) {
            (new HttpError(502, 'upstream response too large', 'api_error'))->send();
        } elseif ($status === 429) {
            (new HttpError(429, 'upstream is busy, retry shortly', 'rate_limit_error'))->send();
        } elseif ($status >= 400 && $status <= 499) {
            (new HttpError($status, "upstream rejected the request (HTTP $status)"))->send();
        } else {
            (new HttpError(502, 'upstream unavailable or returned an invalid response', 'api_error'))->send();
        }
        return $result;
    }
}
