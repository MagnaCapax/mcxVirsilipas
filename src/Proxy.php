<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The customer-facing side: OpenAI-compatible chat completions with per-key metering in credits, and the same metering
 * for the Anthropic Messages format passed through to upstreams that serve it (see Messages) and for the Gemini API
 * format translated to chat completions (see Gemini).
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
    /** Upstream error bodies are read this far, for their machine code only. */
    private const MAX_ERROR_BYTES = 64 * 1024;
    /** Upstream statuses that mean nothing was generated and the same upstream may take the request again. */
    private const RETRY_STATUSES = [408, 409, 429, 502, 503, 504];
    /** Upstream statuses that are this upstream's problem (its key, its account's credit, its model id): the next one may answer. */
    private const NEXT_STATUSES = [401, 402, 403, 404];
    /** The text fields of a message or delta: what makes an answer usable, and what the byte bound counts. */
    private const TEXT_FIELDS = ['content', 'reasoning_content', 'reasoning', 'refusal'];
    /** Upstream error codes passed on to the client, because the client can act on them. */
    private const PASSED_CODES = ['context_length_exceeded'];
    /** GET /v1/usage: the longest window in days, and how many of the newest grants it lists by default and at most. */
    private const USAGE_MAX_DAYS = 400;
    private const USAGE_GRANTS = 200;
    private const USAGE_MAX_GRANTS = 1000;

    public function __construct(private readonly Config $config, private readonly Ledger $ledger)
    {
    }

    public function models(): void
    {
        $this->authenticate();
        $data = [];
        foreach ($this->config->models as $alias => $m) {
            // The standard model fields, the alias's prices in credits, and what agent clients read to size requests.
            $info = $this->config->info[$alias];
            $data[] = ['id' => $alias, 'object' => 'model', 'created' => 0, 'owned_by' => 'virsilipas']
                + ($info['description'] !== '' ? ['description' => $info['description']] : [])
                + ($info['context_length'] !== null ? ['context_length' => $info['context_length']] : [])
                + ['max_completion_tokens' => $this->config->maxTokensCap,
                    'credits_per_million_tokens' => ['input' => $m['input'], 'cached_input' => $m['cached_input'], 'output' => $m['output']]];
        }
        Http::json(200, ['object' => 'list', 'data' => $data]);
    }

    public function balance(): void
    {
        $key = $this->authenticate();
        $s = $this->ledger->status($key['ref'], $this->config->limitDefaults());
        Http::json(200, ['balance' => $s['balance'], 'reserved' => $s['reserved'], 'in_flight' => $s['in_flight'],
            'limits' => $s['limits'], 'window' => $s['window'], 'bonus_pct' => $s['bonus_pct'],
            'weight' => $this->config->throttle->weight($s['balance'], $s['bonus_pct'], self::internalCaller())]);
    }

    /**
     * GET /v1/usage?days=N&grants=M: the caller's own balance, its usage over the last N UTC days (today included;
     * default 30, 1 to 400) per day and per model alias, and its newest M grants (default 200, 1 to 1000). Only the
     * authenticated key's rows are read; no other key, no ref and no upstream cost appear.
     */
    public function usage(): void
    {
        $key = $this->authenticate();
        $days = self::queryInt('days', 30, self::USAGE_MAX_DAYS);
        $grants = self::queryInt('grants', self::USAGE_GRANTS, self::USAGE_MAX_GRANTS);
        $since = intdiv(time(), 86400) * 86400 - ($days - 1) * 86400;
        $view = static fn (array $r): array => ['requests' => $r['requests'], 'prompt_tokens' => $r['prompt_tokens'],
            'cached_tokens' => $r['cached_tokens'], 'completion_tokens' => $r['completion_tokens'], 'reasoning_tokens' => $r['reasoning_tokens'],
            'credits' => $r['credits']];
        $daily = array_map(static fn (array $r): array => ['day' => $r['bucket']] + $view($r), $this->ledger->stats('day', [], $since, null, $key['id']));
        $models = array_map(static fn (array $r): array => ['model' => $r['model']] + $view($r), $this->ledger->stats('all', ['model'], $since, null, $key['id']));
        Http::json(200, ['balance' => $key['balance'], 'days' => $days, 'since' => $since, 'daily' => $daily, 'models' => $models,
            'grants' => $this->ledger->grants($key['id'], $grants)]);
    }

    /** A query parameter that must be an integer from 1 to $max (plain digits, no sign or spaces), else 400 invalid_value. */
    private static function queryInt(string $name, int $default, int $max): int
    {
        $v = $_GET[$name] ?? (string) $default;
        if (!is_string($v) || preg_match('/^[0-9]{1,' . strlen((string) $max) . '}$/', $v) !== 1 || (int) $v < 1 || (int) $v > $max) {
            throw new HttpError(400, "$name must be an integer from 1 to $max", code: 'invalid_value', param: $name);
        }
        return (int) $v;
    }

    public function chat(): void
    {
        $key = $this->authenticate();
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        $alias = $req['model'] ?? null;
        if (!is_string($alias) || !isset($this->config->models[$alias])) {
            throw new HttpError(404, 'unknown model; see /v1/models', code: 'model_not_found', param: 'model');
        }
        $req['messages'] = self::normalizeMessages($req['messages'] ?? null);
        $stream = ($req['stream'] ?? false) === true;
        // Every forwarded byte counts, the operator's params included; one bound must cover whichever upstream answers.
        $promptBound = max(array_map(static fn (array $h): int => self::promptBound($req, $h['params']), $this->config->routes[$alias]));
        $build = static fn (array $route, int $maxTokens): array => [$route['url'], ['Content-Type: application/json', 'Authorization: Bearer ' . $route['key']],
            self::encode(self::rewrite($req, $route['model'], $maxTokens, $stream, $route['stream_options'], $route['params']))];
        $this->serve($key, $alias, array_keys($this->config->routes[$alias]), $promptBound, $req, $stream, 'chat', $build);
    }

    /**
     * POST /v1beta/models/{model}:generateContent, :streamGenerateContent (with alt=sse) and :countTokens: the Gemini API
     * format, translated to a chat request on the alias's own route (Gemini::request), checked, bounded, metered and
     * rewritten exactly like one, its answer translated back. The key may come as x-goog-api-key, a key query parameter
     * or a bearer token; errors use that format's shape (public/index.php). countTokens answers the prompt bound a
     * generateContent request with these fields would reserve for (of the request itself or its generateContentRequest),
     * and nothing goes upstream.
     */
    public function gemini(string $alias, string $method): void
    {
        $key = $this->authenticate(Http::googleKey());
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        if (!isset($this->config->models[$alias])) {
            throw new HttpError(404, 'unknown model; see /v1/models', code: 'model_not_found', param: 'model');
        }
        $count = $method === 'countTokens';
        [$chat, $maxReq] = Gemini::request($count && is_array($req['generateContentRequest'] ?? null) ? $req['generateContentRequest'] : $req);
        $chat['messages'] = self::normalizeMessages($chat['messages']);
        $promptBound = max(array_map(static fn (array $h): int => self::promptBound($chat, $h['params']), $this->config->routes[$alias]));
        if ($count) {
            Http::json(200, ['totalTokens' => $promptBound]);
            return;
        }
        $stream = $method === 'streamGenerateContent';
        if ($stream && ($_GET['alt'] ?? null) !== 'sse') {
            throw new HttpError(400, 'streamGenerateContent needs alt=sse', code: 'invalid_value', param: 'alt');
        }
        $build = static fn (array $route, int $maxTokens): array => [$route['url'], ['Content-Type: application/json', 'Authorization: Bearer ' . $route['key']],
            self::encode(self::rewrite($chat, $route['model'], $maxTokens, $stream, $route['stream_options'], $route['params']))];
        $this->serve($key, $alias, array_keys($this->config->routes[$alias]), $promptBound, $maxReq, $stream, 'gemini', $build);
    }

    /**
     * POST /v1/messages: the Anthropic Messages format, passed through to the upstreams of the alias's route that have a
     * messages_url (in route order), metered like chat. The key may come as x-api-key or as a bearer token; errors use
     * that format's shape (public/index.php). The operator's params are not applied: they are chat completions fields.
     */
    public function messages(): void
    {
        $key = $this->authenticate(Http::apiKey());
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        [$alias, $hops] = $this->messagesAlias($req);
        $fields = Messages::fields($req);
        $stream = ($req['stream'] ?? false) === true;
        $build = static fn (array $route, int $maxTokens): array => [(string) $route['messages_url'], Messages::headers($route['key']),
            self::encode(Messages::rewrite($fields, $route['model'], $maxTokens, $stream))];
        $this->serve($key, $alias, $hops, Messages::promptBound($fields), ['max_tokens' => $req['max_tokens'] ?? null], $stream, 'messages', $build);
    }

    /**
     * POST /v1/messages/count_tokens: the proxy's own conservative estimate, the prompt bound a request with these fields
     * would reserve for (never fewer tokens than the upstream would count). Nothing goes upstream, nothing is reserved
     * or charged.
     */
    public function countTokens(): void
    {
        $this->authenticate(Http::apiKey());
        $req = Http::jsonBody(self::MAX_BODY_BYTES);
        $this->messagesAlias($req);
        Http::json(200, ['input_tokens' => Messages::promptBound(Messages::fields($req))]);
    }

    /**
     * The requested alias and the indexes of its route entries that serve the Messages format, or a 404 when the alias is
     * unknown or none of its upstreams has a messages_url.
     * @param array<string, mixed> $req
     * @return array{0: string, 1: list<int>}
     */
    private function messagesAlias(array $req): array
    {
        $alias = $req['model'] ?? null;
        $hops = is_string($alias) && isset($this->config->routes[$alias])
            ? array_keys(array_filter($this->config->routes[$alias], static fn (array $h): bool => $h['messages_url'] !== null)) : [];
        if (!is_string($alias) || $hops === []) {
            throw new HttpError(404, 'unknown model, or not served in the Messages format; see /v1/models', code: 'model_not_found', param: 'model');
        }
        return [$alias, $hops];
    }

    /**
     * The metered part of a request, shared by chat completions, messages and the Gemini format: admission, the reservation, the attempts
     * over the alias's route entries $hops in order (retries and failover), and the settlement. $maxReq holds the
     * client's max_tokens fields, read after admission as docs/API.md's order of checks says. $build gives, for one
     * route entry and the clamped max_tokens, the upstream URL, the request headers and the body. $format is the client's
     * format: chat, messages or gemini (a chat upstream request, its answer and errors in the Gemini shape).
     * @param array{id: int, ref: string, balance: int, active: bool, bonus_pct: int} $key
     * @param list<int> $hops
     * @param array<string, mixed> $maxReq
     * @param \Closure(array{url: string, messages_url: ?string, key: string, stream_options: array<string, mixed>, model: string, params: array<string, mixed>}, int): array{0: string, 1: list<string>, 2: string} $build
     */
    private function serve(array $key, string $alias, array $hops, int $promptBound, array $maxReq, bool $stream, string $format, \Closure $build): void
    {
        $rates = $this->config->models[$alias];
        $internal = self::internalCaller();
        [$weight, $delayMs] = $this->admission($key, $internal);

        try {
            $lease = $this->ledger->reserve(
                $key['id'],
                $promptBound,
                self::requestedMaxTokens($maxReq),
                $this->config->maxTokensCap,
                $rates,
                $this->config->limitDefaults(),
                $this->config->leaseSeconds,
                $internal ? 1 : 0,
                $this->config->caps,
                $this->config->costBounds[$alias],
            );
        } catch (HttpError $e) {
            if (in_array($e->errorCode, ['spend_cap_reached', 'server_rate_limit'], true)) {
                Log::write('info', 'capped', ['ref' => $key['ref'], 'code' => $e->errorCode, 'retry_after' => $e->retryAfter]);
            }
            throw $e;
        }

        $result = self::attemptResult(0);
        [$upstream, $servedHop, $started, $attempts, $responded] = ['', $hops[0], hrtime(true), 0, false];
        $deadline = $started + $this->config->upstreamTimeout * 1_000_000_000;
        try {
            // The pacing wait holds the lease, so a waiting request counts as in flight for the busy line and the key's limits.
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
                // The upstream time and duration start after the wait (delay_ms records the wait itself).
                $started = hrtime(true);
                $deadline = $started + $this->config->upstreamTimeout * 1_000_000_000;
            }
            if (!headers_sent()) {
                header('X-Request-Id: ' . $lease['lease_id']); // the client can quote it; it is the lease id in the usage rows and the log
            }
            Log::write('debug', 'admit', ['ref' => $key['ref'], 'lease' => $lease['lease_id'], 'alias' => $alias, 'stream' => $stream,
                'prompt_bound' => $promptBound, 'reserved' => $lease['reserved'], 'max_tokens' => $lease['max_tokens']]);
            // The alias's upstreams in order. Each gets the first try plus `retries` more when a failure cost nothing
            // upstream; anything that may have generated output, or that the client caused, ends the request.
            foreach ($hops as $hop) {
                try {
                    $route = $this->config->route($alias, $hop);
                } catch (\RuntimeException) {
                    Log::write('warn', 'upstream_skipped', ['ref' => $key['ref'], 'lease' => $lease['lease_id'], 'alias' => $alias, 'hop' => $hop, 'reason' => 'key file missing or empty']);
                    $result = self::attemptResult(0, 'next');
                    continue;
                }
                [$url, $headers, $body] = $build($route, $lease['max_tokens']);
                [$upstream, $servedHop] = [(string) parse_url($url, PHP_URL_HOST), $hop]; // which upstream served it, for tracking per upstream
                for ($try = 0; $try <= $this->config->retries; $try++) {
                    if (connection_aborted()) {
                        break 2; // the client is gone: no further attempt is made for it
                    }
                    $left = intdiv($deadline - hrtime(true), 1_000_000_000);
                    if ($left < 1) {
                        $result = array_replace(self::attemptResult(0), ['timed_out' => true]);
                        break 2;
                    }
                    $attempts++;
                    $result = $this->forward($url, $headers, $body, $stream, $format, $alias, $rates, $lease['reserved'], $left);
                    if ($result['delivered'] || $result['kind'] === 'final') {
                        break 2;
                    }
                    Log::write('warn', 'upstream_attempt', ['ref' => $key['ref'], 'lease' => $lease['lease_id'], 'alias' => $alias, 'hop' => $hop,
                        'try' => $try, 'upstream' => $upstream, 'upstream_status' => $result['status'], 'outcome' => $result['kind']]);
                    if ($result['kind'] !== 'retry' || $try === $this->config->retries) {
                        break; // on to the next upstream
                    }
                    // The upstream's own wait when it gives one (up to a minute), else 0.5 s doubling. A wait that
                    // would run past the deadline is not taken: the next upstream gets the remaining time instead.
                    $waitNs = (int) (($result['retry_after'] !== null && $result['retry_after'] <= 60 ? $result['retry_after'] : min(0.5 * 2 ** $try, 8)) * 1e9);
                    if (hrtime(true) + $waitNs >= $deadline) {
                        break;
                    }
                    usleep(intdiv($waitNs, 1000));
                }
            }
            if (!$result['delivered']) {
                self::failure($result)->sendAs($format);
            }
            $responded = true; // the whole answer or its error is written; on an exception the router writes it later
        } finally {
            $settleStarted = hrtime(true);
            [$used, $meta] = self::charge($result, $promptBound, $alias, $rates);
            $meta += ['upstream' => $upstream, 'stream' => $stream, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'delay_ms' => $delayMs,
                'first_byte_ms' => $result['first_byte_ns'] === null ? null : intdiv($result['first_byte_ns'] - $started, 1_000_000), 'attempts' => $attempts];
            // An upstream that reports no cost of its own is costed from the route entry's configured prices, flagged as
            // computed: from its usage report, or, for an answer delivered without one, from the counts charged for it.
            $price = $this->config->routes[$alias][$servedHop]['cost'];
            if ($meta['cost'] === null && $price !== null && $result['usage'] !== null) {
                $meta = array_replace($meta, ['cost' => self::usageCredits($result['usage'], $result['unmetered'], $price), 'cost_computed' => true]);
            } elseif ($meta['cost'] === null && $price !== null && $meta['estimated']) {
                $meta = array_replace($meta, ['cost' => Credits::of($meta['prompt'], 0, $meta['completion'], $price), 'cost_computed' => true]);
            }
            $this->ledger->settle($lease['lease_id'], $key['id'], $used, $meta);
            $settleMs = intdiv(hrtime(true) - $settleStarted, 1_000_000);
            // The lease is settled, so the key's limits see what v0.16.1 saw: release the client before the logging and
            // the cap check below (ADR 0027). Not on an exception: its error response is written after this method returns.
            if ($responded && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::logRequest($key['ref'], $lease['lease_id'], $stream, $lease['reserved'], $used, $meta, $result['delivered'], $settleMs);
            $this->logCapCrossing($meta['cost'] ?? 0);
        }
    }

    /**
     * A warn line when this request's cost carried a spend window past 80% or 100% of its cap: one line per crossing,
     * from the spend before and after it, with no state of its own. A failure here never fails the request.
     */
    private function logCapCrossing(int $cost): void
    {
        if ($cost <= 0) {
            return;
        }
        try {
            foreach ($this->config->caps['spend'] as $window => $limit) {
                if ($limit === null || $limit === 0) {
                    continue;
                }
                $after = $this->ledger->costSince(Ledger::capWindow($window, time())[0]);
                foreach ([80, 100] as $pct) {
                    $line = intdiv($limit * $pct, 100);
                    if ($after - $cost < $line && $after >= $line) {
                        Log::write('warn', 'spend_cap_crossed', ['window' => $window, 'percent' => $pct, 'spent' => $after, 'limit' => $limit]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::write('warn', 'cap_log_error', ['error' => $e::class]);
        }
    }

    /**
     * A forward() result before (or without) a usable answer. $kind says what the failure allows:
     * retry (the same upstream again: nothing was generated), next (the next upstream), final (stop).
     * @return array{status: int, usage: null, aborted: bool, delivered: bool, completion_bound: int, unmetered: int, kind: string, retry_after: ?int, code: ?string, timed_out: bool, too_big: bool, first_byte_ns: ?int}
     */
    private static function attemptResult(int $status, string $kind = 'final'): array
    {
        return ['status' => $status, 'usage' => null, 'aborted' => false, 'delivered' => false, 'completion_bound' => 0, 'unmetered' => 0,
            'kind' => $kind, 'retry_after' => null, 'code' => null, 'timed_out' => false, 'too_big' => false, 'first_byte_ns' => null];
    }

    /**
     * What a failed attempt allows. Retry or move on only when the upstream cannot have generated (and billed)
     * an answer: the request never left, or the upstream refused it before working on it. Once a 2xx status has
     * arrived, or a sent request timed out or broke, output may exist, so the request ends: a second try could
     * pay for the same answer twice. A request the upstream rejected as invalid ends too; another upstream would
     * reject it as well.
     */
    public static function outcome(int $status, int $curlErr, bool $sent, bool $tooBig): string
    {
        return match (true) {
            $tooBig, $status >= 200 && $status <= 299 => 'final',
            $curlErr !== 0 && !$sent => 'retry',
            $curlErr !== 0 && $status === 0 => 'final',
            in_array($status, self::RETRY_STATUSES, true) => 'retry',
            $status >= 500 || in_array($status, self::NEXT_STATUSES, true) => 'next',
            default => 'final',
        };
    }

    /** A recognised machine code in an upstream error body (error.code, error.type or error.metadata.error_type), or null. */
    public static function errorCode(string $body): ?string
    {
        $e = $body === '' ? null : (json_decode($body, true)['error'] ?? null);
        if (!is_array($e)) {
            return null;
        }
        foreach ([$e['code'] ?? null, $e['type'] ?? null, is_array($e['metadata'] ?? null) ? ($e['metadata']['error_type'] ?? null) : null] as $c) {
            if (is_string($c) && in_array($c, self::PASSED_CODES, true)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * What the client is told when no upstream gave a usable answer: one of the proxy's fixed errors,
     * chosen from the last attempt's status class. Nothing the upstream said is passed on except a
     * recognised machine code for an over-long request, which the client can act on.
     * @param array{status: int, kind: string, retry_after: ?int, code: ?string, timed_out: bool, too_big: bool} $r
     */
    public static function failure(array $r): HttpError
    {
        $s = $r['status'];
        return match (true) {
            $r['code'] === 'context_length_exceeded' => new HttpError(400, 'the request is longer than the model accepts', 'invalid_request_error', null, 'context_length_exceeded', 'messages'),
            $r['timed_out'] => new HttpError(504, 'upstream timed out', 'api_error', null, 'upstream_timeout'),
            $r['too_big'] => new HttpError(502, 'upstream response too large', 'api_error', null, 'upstream_error'),
            $s === 429 => new HttpError(429, 'upstream is busy, retry shortly', 'rate_limit_error', max(1, min($r['retry_after'] ?? 1, 60)), 'upstream_busy'),
            in_array($s, [400, 413, 422], true) => new HttpError(400, "upstream rejected the request (HTTP $s)", 'invalid_request_error', null, 'upstream_rejected'),
            $r['kind'] === 'retry' || $s === 0 || $s === 503 => new HttpError(503, 'upstream unavailable, retry shortly', 'api_error', 1, 'upstream_unavailable'),
            default => new HttpError(502, 'upstream unavailable or returned an invalid response', 'api_error', null, 'upstream_error'),
        };
    }

    /**
     * One record per request, after it settled: counts and statuses only. An upstream failure,
     * an estimated charge or usage above the reservation is a warning (it points at the
     * upstream or its configuration); everything else, client hang-ups included, is info.
     * @param array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool, reasoning: int, cost: ?int, upstream: string, duration_ms: int} $meta
     * $settleMs is the time spent charging and settling after the response was written, before the client is released.
     */
    private static function logRequest(string $ref, int $lease, bool $stream, int $reserved, int $used, array $meta, bool $delivered, int $settleMs): void
    {
        $failed = !$delivered && !$meta['aborted'];
        $over = $used > $reserved;
        Log::write($failed || $meta['estimated'] || $over ? 'warn' : 'info', 'request', [
            'ref' => $ref, 'lease' => $lease, 'alias' => $meta['model'], 'upstream' => $meta['upstream'], 'stream' => $stream, 'upstream_status' => $meta['status'],
            'duration_ms' => $meta['duration_ms'], 'settle_ms' => $settleMs, 'delivered' => $delivered, 'aborted' => $meta['aborted'], 'estimated' => $meta['estimated'],
            'prompt_tokens' => $meta['prompt'], 'cached_tokens' => $meta['cached'], 'completion_tokens' => $meta['completion'],
            'reasoning_tokens' => $meta['reasoning'], 'upstream_cost' => $meta['cost'],
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
            throw new HttpError(400, 'messages must be a non-empty array', code: 'invalid_messages', param: 'messages');
        }
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m) || !is_string($m['role'] ?? null)) {
                throw new HttpError(400, 'each message needs a role', code: 'invalid_messages', param: 'messages');
            }
            $m = array_intersect_key($m, array_flip(self::MESSAGE_FIELDS));
            foreach (['name', 'tool_call_id'] as $k) {
                if (array_key_exists($k, $m) && !is_string($m[$k])) {
                    throw new HttpError(400, "message $k must be a string", code: 'invalid_messages', param: 'messages');
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
                        throw new HttpError(400, 'only text content is supported', code: 'unsupported_content', param: 'messages');
                    }
                    $parts[] = ['type' => 'text', 'text' => $part['text']];
                }
                $m['content'] = $parts;
            } elseif ($content !== null && !is_string($content)) {
                throw new HttpError(400, 'message content must be text', code: 'unsupported_content', param: 'messages');
            }
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Builds the upstream request from the normalized messages and allowlisted fields:
     * operator-chosen model, the operator's params for this model and upstream (they win over what the
     * client sent; Config refuses params naming the fields set below), n=1 (by omission), clamped
     * max_tokens, and server-side stream_options so usage is reported.
     * @param array<string, mixed> $req
     * @param array<string, mixed> $streamOptions
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function rewrite(array $req, string $upstreamModel, int $maxTokens, bool $stream, array $streamOptions, array $params = []): array
    {
        $out = ['model' => $upstreamModel] + array_replace(self::promptFields($req), $params);
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
    public static function promptBound(array $req, array $params = []): int
    {
        return strlen(self::encode(array_replace(self::promptFields($req), $params))) + 16;
    }

    /**
     * JSON for the upstream, or a RuntimeException (a 500 before anything is sent) when the value cannot be encoded:
     * a body that fails to encode must never be forwarded empty or bounded as if it were.
     * @param array<mixed>|\stdClass $v
     */
    public static function encode(array|\stdClass $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('a request field cannot be encoded as JSON');
        }
        return $json;
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
            throw new HttpError(400, 'max_tokens must be a positive integer', code: 'invalid_value', param: 'max_tokens');
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
     * Two details are kept for tracking only, never for charging: the reasoning part of the output
     * (completion_tokens_details.reasoning_tokens or a top-level reasoning_tokens, an integer from 0 to
     * completion_tokens, else 0) and the cost the upstream reports itself (estimated_cost, or cost when that
     * is absent, as some upstreams name it; a number from 0 to 10^6, kept in millionths; anything else is null).
     * @return array{prompt: int, completion: int, total: int, cached: int, reasoning: int, cost: ?int}|null
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
        $cd = $u['completion_tokens_details'] ?? null;
        $r = (is_array($cd) ? ($cd['reasoning_tokens'] ?? null) : null) ?? ($u['reasoning_tokens'] ?? null);
        $cost = $u['estimated_cost'] ?? $u['cost'] ?? null;
        return ['prompt' => $p, 'completion' => $c, 'total' => $t, 'cached' => $cached,
            'reasoning' => is_int($r) && $r >= 0 && $r <= $c ? $r : 0,
            'cost' => (is_int($cost) || is_float($cost)) && is_finite((float) $cost) && $cost >= 0 && $cost <= 1e6 ? (int) round($cost * 1e6) : null];
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
     * @return array{0: int, 1: array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool, reasoning: int, cost: ?int}}
     */
    public static function charge(array $r, int $promptBound, string $alias, array $rates): array
    {
        $u = $r['usage'];
        [$reasoning, $cost] = [$u['reasoning'] ?? 0, $u['cost'] ?? null];
        if ($u !== null && !$r['delivered']) {
            // Nothing reached the client: no charge, but the upstream's own counts are recorded for cost tracking.
            [$prompt, $completion, $cached, $used, $estimated] = [$u['prompt'], $u['completion'], $u['cached'], 0, false];
        } elseif ($u !== null) {
            [$prompt, $completion, $cached] = [$u['prompt'], $u['completion'] + $r['unmetered'], $u['cached']];
            [$used, $estimated] = [self::usageCredits($u, $r['unmetered'], $rates), $r['unmetered'] > 0];
        } elseif ($r['delivered']) {
            [$prompt, $completion, $cached, $estimated] = [$promptBound, $r['completion_bound'], 0, true];
            $used = Credits::of($promptBound, 0, $r['completion_bound'], $rates);
        } else {
            [$prompt, $completion, $cached, $used, $estimated] = [0, 0, 0, 0, false];
        }
        return [$used, ['model' => $alias, 'prompt' => $prompt, 'completion' => $completion, 'cached' => $cached,
            'status' => $r['status'], 'aborted' => $r['aborted'], 'estimated' => $estimated, 'reasoning' => $reasoning, 'cost' => $cost]];
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
     * @param array{prompt: int, completion: int, total: int, cached: int, reasoning: int} $u
     * @param ?array{input: int, cached_input: int, output: int} $rates
     * @return array<string, mixed>
     */
    private static function usageView(array $u, ?array $rates, int $reserved): array
    {
        $view = ['prompt_tokens' => $u['prompt'], 'completion_tokens' => $u['completion'], 'total_tokens' => $u['total'],
            'prompt_tokens_details' => ['cached_tokens' => $u['cached']],
            'completion_tokens_details' => ['reasoning_tokens' => $u['reasoning']]];
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
                    // Upstreams name the reasoning text reasoning_content or reasoning; each is passed on under its own name.
                    $choice[$k] = self::typed($c[$k], ['role' => 'string', 'content' => '?string', 'reasoning_content' => '?string', 'reasoning' => '?string',
                        'refusal' => '?string']);
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
                throw new HttpError(400, 'tool_calls must be a non-empty list', code: 'invalid_messages', param: 'messages');
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
                throw new HttpError(400, 'each tool call needs string id, type, function name and arguments (a JSON string)', code: 'invalid_messages', param: 'messages');
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
     * non-empty text, reasoning (reasoning_content or reasoning) or refusal, or a tool call with id, type, function name and
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
            foreach (self::TEXT_FIELDS as $k) {
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
     * was relayed: neither discarded upstream fields nor relayed text outside a delta escape it. Without $calls the
     * tool calls are left out.
     * @param array<string, mixed> $view
     */
    private static function viewBytes(array $view, bool $calls = true): int
    {
        $n = 0;
        foreach ($view['choices'] ?? [] as $c) {
            $n += self::textBytes($c['message'] ?? null, $calls) + self::textBytes($c['delta'] ?? null, $calls);
        }
        return $n;
    }

    /** Bytes of text a message or delta carries: content, reasoning (either name), refusal and tool-call names and arguments. */
    private static function textBytes(mixed $part, bool $calls): int
    {
        if (!is_array($part)) {
            return 0;
        }
        $n = 0;
        foreach (self::TEXT_FIELDS as $k) {
            $n += is_string($part[$k] ?? null) ? strlen($part[$k]) : 0;
        }
        foreach ($calls && is_array($part['tool_calls'] ?? null) ? $part['tool_calls'] : [] as $t) {
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

    /**
     * Whether the request came from an internal caller, as the web server says in the VIRSILIPAS_SOURCE server variable
     * (a FastCGI parameter it sets from the client address). Only that variable counts: a client header of the same
     * name arrives as HTTP_VIRSILIPAS_SOURCE and is ignored, and a server that sets nothing makes every caller external.
     */
    private static function internalCaller(): bool
    {
        return ($_SERVER['VIRSILIPAS_SOURCE'] ?? null) === 'internal';
    }

    /**
     * The throttle's decision before a request takes a reservation: the key's weight and how long to wait. A key below the
     * busy weight while its source is at its busy line is refused with 503 server_busy and a Retry-After instead of
     * waiting. A throttle that cannot read its state lets the request through unslowed: it only ever slows requests down,
     * the hard limits stay in reserve().
     * @param array{id: int, ref: string, balance: int, active: bool, bonus_pct: int} $key
     * @return array{0: int, 1: int} weight and delay in milliseconds
     */
    private function admission(array $key, bool $internal): array
    {
        $t = $this->config->throttle;
        $weight = $t->weight($key['balance'], $key['bonus_pct'], $internal);
        $delay = 0;
        try {
            $line = $t->busyLine($internal);
            // While busy nothing waits in a worker: a low weight is shed, any other request is served at once.
            $busy = $line !== null && $this->ledger->openLeases($internal ? 1 : 0) >= $line;
            if ($busy && $weight < $t->busyMinWeight) {
                $retry = $t->shedRetryAfter($weight);
                Log::write('info', 'throttled', ['ref' => $key['ref'], 'action' => 'shed', 'weight' => $weight, 'retry_after' => $retry]);
                $shed = new HttpError(503, 'the server is busy; retry shortly', 'server_error', $retry, 'server_busy');
            } elseif (!$busy && $t->dailyBudget !== null) {
                $delay = $t->delayMs($t->pressure($this->ledger->chargedSince(time() - 86400)), $weight);
            }
        } catch (\Throwable $e) {
            Log::write('warn', 'throttle_error', ['ref' => $key['ref'], 'error' => $e::class]);
        }
        if (isset($shed)) {
            throw $shed;
        }
        if ($delay > 0) {
            Log::write('info', 'throttled', ['ref' => $key['ref'], 'action' => 'delay', 'weight' => $weight, 'delay_ms' => $delay]);
        }
        if (!headers_sent()) {
            header('X-Virsilipas-Weight: ' . $weight);
            header('X-Virsilipas-Delay-Ms: ' . $delay);
        }
        return [$weight, $delay];
    }

    /**
     * The caller's key: $key as the route reads it (Http::apiKey(), Http::googleKey()), else a bearer token. A disabled
     * or expired key is refused (ADR 0024; Ledger::reserve() checks the expiry again).
     * @return array{id: int, ref: string, balance: int, active: bool, bonus_pct: int, expires_at: ?int}
     */
    private function authenticate(?string $key = null): array
    {
        $key = $this->ledger->findKey($key ?? Http::bearer());
        if ($key === null) {
            throw new HttpError(401, 'invalid key', 'authentication_error', code: 'invalid_api_key');
        }
        if (!$key['active']) {
            throw new HttpError(403, 'key is disabled', 'permission_error', code: 'key_disabled');
        }
        if ($key['expires_at'] !== null && $key['expires_at'] <= time()) {
            throw new HttpError(403, 'key has expired', 'permission_error', code: 'key_expired');
        }
        return $key;
    }

    /**
     * One chat completions stream event: updates usage and the byte bounds in forward()'s state and returns the rebuilt
     * event to relay, true for the terminal [DONE], or null for nothing. With $holdCalls (the Gemini format, which sends
     * function calls whole at the end) the bytes of tool-call fragments are kept apart in call_bytes and call_unmetered
     * until Gemini::event() delivers them; everything else is counted when it is relayed.
     * @param array<string, mixed> $s
     * @param array{input: int, cached_input: int, output: int} $rates
     * @return array<string, mixed>|true|null
     */
    private static function chatEvent(array &$s, string $event, string $alias, array $rates, int $reserved, bool $holdCalls): array|true|null
    {
        $e = self::parseEvent($event);
        if ($e === null || $s['done'] || $s['failed']) {
            return null; // nothing after the terminal marker, or after an upstream error, is relayed or counted
        }
        if ($e['done']) {
            $s['done'] = true; // a [DONE] before any choice leaves the stream unstarted: a 502, no charge
            return true;
        }
        $j = $e['json'];
        $begun = $s['started'] || $s['start'];
        if (isset($j['error'])) {
            // Before the stream starts: a refusal (generic non-200). After: the stream ends here
            // with the generic in-band error and no [DONE]; output already relayed is charged.
            $s[$begun ? 'failed' : 'bad'] = true;
            return null;
        }
        if (!isset($j['choices']) && !isset($j['usage'])) {
            $s['bad'] = !$begun; // not a completion event; ignored once the stream has begun
            return null;
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
        $n = $relay ? self::viewBytes($view, !$holdCalls) : 0;
        $calls = $relay && $holdCalls ? self::viewBytes($view, true) - $n : 0;
        [$s['bytes'], $s['unmetered'], $s['call_bytes'], $s['call_unmetered']] = [$s['bytes'] + $n, $s['unmetered'] + $n, $s['call_bytes'] + $calls, $s['call_unmetered'] + $calls];
        $u = self::validUsage($j['usage'] ?? null);
        // Usage is cumulative. Only a report that advances covers the text delivered since the
        // last one; an equal or lower report changes nothing, so that text stays charged as bytes.
        if ($u !== null && ($s['usage'] === null || $u['total'] > $s['usage']['total'])) {
            $s['usage'] = $u;
            [$s['unmetered'], $s['call_unmetered']] = [0, 0];
            $s['over'] = self::usageCredits($u, 0, $rates) > $reserved;
        } elseif ($u !== null && [$u['prompt'], $u['completion'], $u['total']] === [$s['usage']['prompt'], $s['usage']['completion'], $s['usage']['total']]) {
            // The same counts again with details: upstreams that report running usage may send the cached
            // split (and other details) only with the final report. A detail the adopted report lacks is
            // added once and never changed or removed by a later repeat; it covers no text delivered since
            // the last advance.
            $s['usage']['cached'] = $s['usage']['cached'] === 0 ? $u['cached'] : $s['usage']['cached'];
            $s['usage']['reasoning'] = $s['usage']['reasoning'] === 0 ? $u['reasoning'] : $s['usage']['reasoning'];
            $s['usage']['cost'] ??= $u['cost'];
        }
        // A relayed usage object shows the report the request is charged by, not a repeat or a lower
        // report the proxy did not adopt, so the client sees the same credits as the ledger.
        if ($s['usage'] !== null && ($view['usage'] ?? null) !== null) {
            $view['usage'] = self::usageView($s['usage'], $rates, $reserved);
        }
        return $relay ? $view : null;
    }

    /**
     * One attempt at one upstream. The client gets a 200 only once the upstream has answered 2xx
     * with a completion that has choices (non-stream) or a first event with choices (stream).
     * Otherwise nothing is sent and the result says what the failure allows (see attemptResult):
     * the caller may try again, try the next upstream, or send a generic error of the right class.
     * Upstream bodies are never relayed; of an error body only a recognised machine code is kept.
     * A client that hangs up stops the upstream request, and so does a stream whose reported usage
     * passes the reservation; what was delivered is still charged. $format is the client's format: chat, messages (the
     * Anthropic Messages format: Messages' answer check, rebuilt views and stream events) or gemini (a chat upstream answer,
     * relayed in the Gemini shape by Gemini).
     * @param list<string> $headers
     * @param string $body the request as JSON (encode())
     * @param array{model: string, input: int, cached_input: int, output: int} $rates
     * @return array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_bound: int, unmetered: int, kind: string, retry_after: ?int, code: ?string, timed_out: bool, too_big: bool, first_byte_ns: ?int}
     */
    private function forward(string $url, array $headers, string $body, bool $stream, string $format, string $alias, array $rates, int $reserved, int $timeout): array
    {
        // Shared by reference with the curl callbacks below, which change it during the transfer.
        // held and raw are the Messages format's: the message_start held until the first content, and the usage counts so far.
        // calls, call_bytes, call_unmetered, finish and response_id are the Gemini format's: function calls put together from
        // their fragments and their bytes (all of them, and those since the last usage report), held until the last chunk.
        /** @var array{first_byte: ?int, status: int, usage: ?array, aborted: bool, started: bool, start: bool, done: bool, bad: bool, failed: bool, too_big: bool, over: bool, bytes: int, unmetered: int, buf: string, full: string, err: string, retry_after: ?int, held: string, raw: array<string, mixed>, calls: array<int, array<string, mixed>>, call_bytes: int, call_unmetered: int, finish: ?string, response_id: ?string} $s */
        $s = ['first_byte' => null, 'status' => 0, 'usage' => null, 'aborted' => false, 'started' => false, 'start' => false, 'done' => false, 'bad' => false, 'failed' => false,
            'too_big' => false, 'over' => false, 'bytes' => 0, 'unmetered' => 0, 'buf' => '', 'full' => '', 'err' => '', 'retry_after' => null, 'held' => '', 'raw' => [],
            'calls' => [], 'call_bytes' => 0, 'call_unmetered' => 0, 'finish' => null, 'response_id' => null];

        // One event: update usage and the byte bounds, return the text to relay ('' = drop).
        $handle = static function (string $event) use (&$s, $format, $alias, $rates, $reserved): string {
            if ($format === 'messages') {
                return Messages::event($s, $event, $alias, $rates, $reserved);
            }
            $r = self::chatEvent($s, $event, $alias, $rates, $reserved, $format === 'gemini');
            if ($format === 'gemini') {
                return Gemini::event($s, $r, $alias);
            }
            return $r === true ? "data: [DONE]\n\n" : ($r === null ? '' : 'data: ' . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$s): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $s['status'] = (int) $m[1]; // the last status line wins (e.g. after 100 Continue)
                    $s['retry_after'] = null;
                } elseif (preg_match('/^retry-after(-ms)?:\s*(\d{1,9})\s*$/i', $line, $m)) {
                    $s['retry_after'] = $m[1] === '' ? (int) $m[2] : (int) ceil((int) $m[2] / 1000); // seconds; an HTTP-date form is ignored
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$s, $stream, $handle): int {
                $len = strlen($chunk);
                if ($s['status'] < 200 || $s['status'] > 299) {
                    // Error body: kept (bounded) only to read a machine code from it, never logged or relayed.
                    $s['err'] .= substr($chunk, 0, max(0, self::MAX_ERROR_BYTES - strlen($s['err'])));
                    return $len;
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
                    $s['first_byte'] ??= hrtime(true); // performance metric: when the client got its first byte
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
        $sent = curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0; // false: the request never left (no connection, or TLS failed)

        $status = $s['status'];
        $result = ['status' => $status, 'aborted' => $s['aborted'], 'kind' => 'final', 'retry_after' => $s['retry_after'], 'code' => null,
            'timed_out' => false, 'too_big' => $s['too_big']] + self::attemptResult($status);
        $result['first_byte_ns'] = $s['first_byte'];

        if ($stream && $s['started']) {
            $tail = trim($s['buf']) === '' ? '' : $handle($s['buf']); // after a hang-up too: it may hold the final report
            if (!$s['aborted']) {
                if (!$s['done']) {
                    $tail .= match ($format) {
                        'messages' => Messages::earlyEnd(),
                        'gemini' => Gemini::earlyEnd(),
                        default => 'data: ' . json_encode(['error' => ['message' => 'upstream stream ended early', 'type' => 'api_error', 'param' => null, 'code' => 'upstream_error']]) . "\n\n",
                    };
                }
                echo $tail;
                flush();
            }
            return ['usage' => $s['usage'], 'delivered' => true, 'completion_bound' => $s['bytes'], 'unmetered' => $s['unmetered']] + $result;
        }

        $json = (!$stream && $curlErr === 0 && $status >= 200 && $status <= 299) ? json_decode($s['full'], true) : null;
        $messages = $format === 'messages';
        $usage = static fn (mixed $u): ?array => $messages ? Messages::usage($u) : self::validUsage($u);
        $view = $messages ? Messages::answer($json, $alias) : (self::hasChoices($json, true) ? self::publicView($json, $alias, $rates, $reserved) : null);
        if ($view !== null) {
            http_response_code(200);
            header('Content-Type: application/json');
            $result['first_byte_ns'] = hrtime(true);
            $u = $usage($json['usage'] ?? null);
            echo json_encode($format === 'gemini' ? Gemini::answer($view, $alias, $u) : $view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // What the client received, never discarded upstream fields (a Gemini answer carries the same text and calls).
            $bound = $messages ? Messages::bytes($view['content']) : self::viewBytes($view);
            return ['usage' => $u, 'delivered' => true, 'completion_bound' => $bound] + $result;
        }

        // A 2xx without a usable answer is free for the client, but its usage report (what the upstream billed) is kept for tracking.
        return ['kind' => self::outcome($status, $curlErr, $sent, $s['too_big']), 'timed_out' => $curlErr === CURLE_OPERATION_TIMEDOUT && $sent,
            'code' => self::errorCode($s['err']), 'usage' => $stream ? $s['usage'] : (is_array($json) ? $usage($json['usage'] ?? null) : null)] + $result;
    }
}
