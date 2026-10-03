<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The customer-facing side: OpenAI-compatible chat completions with exact per-key metering.
 *
 * Flow per request: authenticate the key, reserve tokens (clamping max_tokens to the balance),
 * forward to the configured upstream, stream back, then settle the reservation against the
 * usage the upstream reported. Request and response bodies are never logged.
 */
final class Proxy
{
    /** Client parameters that are removed or replaced before forwarding (price-affecting or proxy-owned). */
    private const STRIP = ['model', 'n', 'best_of', 'service_tier', 'max_tokens', 'max_completion_tokens', 'stream_options'];
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;

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
        if (!isset($req['messages']) || !is_array($req['messages']) || $req['messages'] === []) {
            throw new HttpError(400, 'messages must be a non-empty array');
        }
        $alias = $req['model'] ?? null;
        if (!is_string($alias) || !isset($this->config->models[$alias])) {
            throw new HttpError(404, 'unknown model; see /v1/models', 'invalid_request_error');
        }
        $stream = ($req['stream'] ?? false) === true;
        $promptEstimate = self::estimatePromptTokens($req);

        $lease = $this->ledger->reserve(
            $key['id'],
            $promptEstimate,
            self::requestedMaxTokens($req),
            $this->config->maxTokensCap,
            $this->config->maxConcurrentPerKey,
            $this->config->leaseSeconds,
        );

        $result = ['status' => 0, 'usage' => null, 'aborted' => false, 'delivered' => false, 'completion_estimate' => 0];
        try {
            $body = self::rewrite($req, $this->config->models[$alias], $lease['max_tokens'], $stream, $this->config->streamOptions);
            $result = $this->forward($body, $stream);
        } finally {
            $this->ledger->settle($lease['lease_id'], $key['id'], ...self::charge($result, $promptEstimate, $alias));
        }
    }

    /**
     * Builds the upstream request: operator-chosen model, n=1 (by omission), clamped max_tokens,
     * and server-side stream_options so usage is always reported.
     * @param array<string, mixed> $req
     * @param array<string, mixed> $streamOptions
     * @return array<string, mixed>
     */
    public static function rewrite(array $req, string $upstreamModel, int $maxTokens, bool $stream, array $streamOptions): array
    {
        foreach (self::STRIP as $k) {
            unset($req[$k]);
        }
        $req['model'] = $upstreamModel;
        $req['max_tokens'] = $maxTokens;
        $req['stream'] = $stream;
        if ($stream) {
            $req['stream_options'] = $streamOptions;
        }
        return $req;
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
     * Conservative input-token estimate (about 3 bytes per token) used only to size the reservation;
     * the settled charge uses the upstream's reported usage.
     * @param array<string, mixed> $req
     */
    public static function estimatePromptTokens(array $req): int
    {
        $text = json_encode([$req['messages'] ?? [], $req['tools'] ?? []], JSON_UNESCAPED_UNICODE) ?: '';
        return intdiv(strlen($text), 3) + 16;
    }

    /**
     * Turns a forward() result into the settle() arguments: (used tokens, usage-row metadata).
     * Reported usage wins; without it a delivered response is charged an estimate and flagged.
     * @param array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_estimate: int} $r
     * @return array{0: int, 1: array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool}}
     */
    public static function charge(array $r, int $promptEstimate, string $alias): array
    {
        $u = $r['usage'];
        if (is_array($u)) {
            $prompt = (int) ($u['prompt_tokens'] ?? 0);
            $completion = (int) ($u['completion_tokens'] ?? 0);
            $used = (int) ($u['total_tokens'] ?? $prompt + $completion);
            $cached = (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0);
            $estimated = false;
        } elseif ($r['delivered']) {
            $prompt = $promptEstimate;
            $completion = $r['completion_estimate'];
            $used = $prompt + $completion;
            $cached = 0;
            $estimated = true;
        } else {
            $prompt = $completion = $used = $cached = 0;
            $estimated = false;
        }
        return [max(0, $used), [
            'model' => $alias, 'prompt' => $prompt, 'completion' => $completion, 'cached' => $cached,
            'status' => $r['status'], 'aborted' => $r['aborted'], 'estimated' => $estimated,
        ]];
    }

    /**
     * The usage object the client sees: standard token counts only. Upstreams add their own fields
     * (costs, account details) that are the operator's business, not the customer's.
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    public static function publicUsage(array $usage): array
    {
        return array_intersect_key($usage, array_flip(['prompt_tokens', 'completion_tokens', 'total_tokens', 'prompt_tokens_details', 'completion_tokens_details']));
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
     * Forwards to the upstream. The client gets a 200 only once the upstream has answered 2xx;
     * an upstream error becomes a generic error of the same class (upstream bodies are not relayed).
     * A client that hangs up stops the upstream request; what it was sent is still charged.
     * @param array<string, mixed> $body
     * @return array{status: int, usage: ?array, aborted: bool, delivered: bool, completion_estimate: int}
     */
    private function forward(array $body, bool $stream): array
    {
        $status = 0;
        $usage = null;
        $aborted = false;
        $started = false;
        $deltas = 0;
        $buf = '';
        $full = '';

        $ch = curl_init($this->config->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->upstreamKey()],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->config->upstreamTimeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1]; // the last status line wins (e.g. after 100 Continue)
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$status, &$usage, &$aborted, &$started, &$deltas, &$buf, &$full, $stream): int {
                $len = strlen($chunk);
                if ($status < 200 || $status > 299) {
                    return $len; // error body: read and discard
                }
                if (!$stream) {
                    $full .= $chunk;
                    return $len;
                }
                if (!$started) {
                    $started = true;
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    http_response_code(200);
                    header('Content-Type: text/event-stream');
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');
                }
                // Pass complete lines on; a partial line waits for the rest of it in $buf.
                $buf .= $chunk;
                $out = '';
                while (($p = strpos($buf, "\n")) !== false) {
                    $line = substr($buf, 0, $p + 1);
                    $buf = substr($buf, $p + 1);
                    $j = str_starts_with($line, 'data:') ? json_decode(trim(substr($line, 5)), true) : null;
                    if (is_array($j)) {
                        if (isset($j['usage']) && is_array($j['usage'])) {
                            $usage = $j['usage']; // cumulative when the upstream reports continuously
                            $j['usage'] = self::publicUsage($j['usage']);
                            $line = 'data: ' . json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                        }
                        $d = $j['choices'][0]['delta'] ?? null;
                        if (is_array($d) && (($d['content'] ?? '') !== '' || ($d['reasoning_content'] ?? '') !== '' || !empty($d['tool_calls']))) {
                            $deltas++;
                        }
                    }
                    $out .= $line;
                }
                if ($out !== '') {
                    echo $out;
                    flush();
                }
                if (connection_aborted()) {
                    $aborted = true;
                    return 0; // a short write makes curl stop the upstream request
                }
                return $len;
            },
        ]);
        curl_exec($ch);
        $curlErr = curl_errno($ch);
        curl_close($ch);

        $ok = $status >= 200 && $status <= 299;
        $result = ['status' => $status, 'usage' => $usage, 'aborted' => $aborted, 'delivered' => $started, 'completion_estimate' => $deltas];

        if ($stream && $started) {
            echo $buf; // an unterminated last line, if any
            return $result;
        }
        if ($ok && !$stream && $full !== '') {
            $json = json_decode($full, true);
            http_response_code(200);
            header('Content-Type: application/json');
            if (is_array($json) && is_array($json['usage'] ?? null)) {
                $public = $json;
                $public['usage'] = self::publicUsage($json['usage']);
                echo json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                echo $full;
            }
            $content = is_array($json) ? (string) ($json['choices'][0]['message']['content'] ?? '') : '';
            return ['usage' => is_array($json['usage'] ?? null) ? $json['usage'] : null, 'delivered' => true, 'completion_estimate' => intdiv(strlen($content), 3)] + $result;
        }
        if ($status === 0 || $curlErr !== 0 || $ok) {
            (new HttpError(502, 'upstream unavailable', 'api_error'))->send();
        } elseif ($status === 429) {
            (new HttpError(429, 'upstream is busy, retry shortly', 'rate_limit_error'))->send();
        } elseif ($status >= 500) {
            (new HttpError(502, "upstream error (HTTP $status)", 'api_error'))->send();
        } else {
            (new HttpError($status, "upstream rejected the request (HTTP $status)"))->send();
        }
        return ['delivered' => false] + $result;
    }
}
