#!/usr/bin/env php
<?php

declare(strict_types=1);

// Minimal example client for a Virsilipas-compatible proxy. Zero dependencies beyond ext-curl and
// ext-json. Key: VIRSILIPAS_KEY or --key-file <path> (never on the command line, so it never shows up in
// the process list). The key file holds either just the key, or a line key=<key> and optionally a line
// endpoint=<base URL>. Base URL: --url, else the key file's endpoint, else VIRSILIPAS_URL.
// Exit codes: 0 ok, 1 request failed, 2 usage error.
//   php client.php models | balance | chat "prompt" [--model alias] [--no-stream] [--show-thinking]

function usageError(string $msg): never
{
    fwrite(STDERR, "$msg\nUsage: client.php models|balance|chat \"prompt\" [--model alias] [--no-stream] "
        . "[--show-thinking] [--url url] [--key-file file]\n");
    exit(2);
}

/**
 * A key file: just the key, or lines key=<key> and endpoint=<base URL> (with or without a trailing /v1).
 * Without a key= line the whole file, trimmed, is the key.
 * @return array{key: string, endpoint: string}
 */
function readKeyFile(string $path): array
{
    $text = (string) @file_get_contents($path);
    if (preg_match('/^[ \t]*key[ \t]*=/m', $text) !== 1) {
        return ['key' => trim($text), 'endpoint' => ''];
    }
    $file = ['key' => '', 'endpoint' => ''];
    preg_match_all('/^[ \t]*(key|endpoint)[ \t]*=(.*)$/m', $text, $m, PREG_SET_ORDER);
    foreach ($m as [, $name, $value]) {
        $file[$name] = trim(trim($value), '"\'');
    }
    $file['endpoint'] = (string) preg_replace('#/v1/?$#', '', rtrim($file['endpoint'], '/'));
    return $file;
}

/** One SSE frame (up to the blank line) decoded to its 'data:' payload. Null = [DONE] or noise. */
function parseSseEvent(string $event): ?array
{
    $data = [];
    foreach (explode("\n", $event) as $line) {
        if (str_starts_with($line, 'data:')) {
            $data[] = ltrim(substr($line, 5), ' ');
        }
    }
    $payload = implode("\n", $data);
    if ($data === [] || trim($payload) === '[DONE]') {
        return null;
    }
    $j = json_decode($payload, true);
    return is_array($j) ? $j : null;
}

/** One HTTP attempt: GET/POST, optionally streaming SSE events through $onEvent as they arrive. */
function httpAttempt(string $method, string $url, ?array $body, string $key, bool $stream, ?callable $onEvent): array
{
    $s = ['status' => 0, 'retry_after' => null, 'body' => '', 'buf' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$s): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $s['status'] = (int) $m[1];
            } elseif (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                $s['retry_after'] = (int) $m[1];
            }
            return strlen($line);
        },
        // 2xx streaming: split into SSE frames and hand each one to $onEvent. Anything else
        // (an error body, or a plain non-stream JSON body) is just buffered whole.
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$s, $stream, $onEvent): int {
            if ($s['status'] < 200 || $s['status'] > 299 || !$stream || $onEvent === null) {
                $s['body'] .= $chunk;
                return strlen($chunk);
            }
            $s['buf'] .= $chunk;
            while (($p = strpos($s['buf'], "\n\n")) !== false) {
                $onEvent(parseSseEvent(substr($s['buf'], 0, $p)));
                $s['buf'] = substr($s['buf'], $p + 2);
            }
            return strlen($chunk);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    if (!curl_exec($ch)) {
        fwrite(STDERR, 'request failed: ' . curl_error($ch) . "\n");
        exit(1);
    }
    return $s;
}

/** Retries once on 429/503 when the proxy gave a Retry-After to honour. */
function request(string $method, string $url, ?array $body, string $key, bool $stream = false, ?callable $onEvent = null): array
{
    $r = httpAttempt($method, $url, $body, $key, $stream, $onEvent);
    if (in_array($r['status'], [429, 503], true) && $r['retry_after'] !== null) {
        sleep(min($r['retry_after'], 60));
        $r = httpAttempt($method, $url, $body, $key, $stream, $onEvent);
    }
    return $r;
}

/** Model text without terminal control codes (escape sequences, carriage returns); newlines and tabs stay. */
function plain(string $s): string
{
    return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
}

/** Prints the proxy's fixed error shape ({"error": {message, type, code, ...}}) and exits 1. */
function failApi(array $error): never
{
    $e = $error['error'] ?? [];
    fwrite(STDERR, sprintf("error: %s (type=%s code=%s)\n", $e['message'] ?? 'unknown error', $e['type'] ?? '?', $e['code'] ?? '?'));
    exit(1);
}

/** A GET expected to answer 200 with a JSON object; fails closed via failApi() otherwise. */
function getJson(string $base, string $path, string $key): array
{
    $r = request('GET', $base . $path, null, $key);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j)) {
        failApi(is_array($j) ? $j : []);
    }
    return $j;
}

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? '';
if (!in_array($cmd, ['models', 'balance', 'chat'], true)) {
    usageError('unknown or missing command');
}
$opt = ['model' => 'default', 'url' => '', 'key-file' => '', 'no-stream' => false, 'show-thinking' => false];
$prompt = null;
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($cmd === 'chat' && $prompt === null && !str_starts_with($a, '--')) {
        $prompt = $a;
        continue;
    }
    if (!preg_match('/^--([a-z-]+)$/', $a, $m) || !array_key_exists($m[1], $opt)) {
        usageError("unknown option: $a");
    }
    $opt[$m[1]] = is_bool($opt[$m[1]]) ? true : ($args[++$i] ?? usageError("--{$m[1]} needs a value"));
}
$file = $opt['key-file'] !== '' ? readKeyFile($opt['key-file']) : ['key' => (string) (getenv('VIRSILIPAS_KEY') ?: ''), 'endpoint' => ''];
// The key file's endpoint belongs with its key, so it comes before the environment's address.
$url = $opt['url'] !== '' ? $opt['url'] : ($file['endpoint'] !== '' ? $file['endpoint'] : (string) (getenv('VIRSILIPAS_URL') ?: ''));
if (($cmd === 'chat' && $prompt === null) || $url === '') {
    usageError($cmd === 'chat' && $prompt === null ? 'chat needs a prompt' : 'missing base URL: pass --url, set VIRSILIPAS_URL or put endpoint= in the key file');
}
$key = $file['key'];
if ($key === '') {
    usageError('missing API key: pass --key-file or set VIRSILIPAS_KEY');
}
$base = rtrim($url, '/');
if (!preg_match('#^(https://|http://(127\.0\.0\.1|localhost|\[::1\])(:\d+)?(/|$))#', $base . '/')) {
    usageError('the base URL must be https (plain http only to this machine): the key travels with every request');
}

if ($cmd === 'models') {
    foreach (getJson($base, '/v1/models', $key)['data'] ?? [] as $m) {
        $c = $m['credits_per_million_tokens'] ?? [];
        printf("%-20s %-30s input=%s cached=%s output=%s\n", $m['id'] ?? '?', $m['description'] ?? '',
            $c['input'] ?? '?', $c['cached_input'] ?? '?', $c['output'] ?? '?');
    }
    exit(0);
}

if ($cmd === 'balance') {
    $j = getJson($base, '/v1/balance', $key);
    printf("balance=%d reserved=%d in_flight=%d weight=%s bonus_pct=%s limits=%s window=%s\n", $j['balance'] ?? 0,
        $j['reserved'] ?? 0, $j['in_flight'] ?? 0, $j['weight'] ?? '?', $j['bonus_pct'] ?? '?',
        json_encode($j['limits'] ?? null), json_encode($j['window'] ?? null));
    exit(0);
}

// chat: streams token-by-token by default; --no-stream falls back to one plain JSON answer.
$stream = !$opt['no-stream'];
$body = ['model' => $opt['model'], 'stream' => $stream, 'messages' => [['role' => 'user', 'content' => $prompt]]];
$usage = $sawError = null;
$onEvent = function (?array $j) use (&$usage, &$sawError, $opt): void {
    if ($j === null) {
        return; // [DONE] or an unparsable line
    }
    if (isset($j['error'])) {
        $sawError = $j; // in-band stream error: the proxy's generic error event, no [DONE] after it
        return;
    }
    foreach ($j['choices'] ?? [] as $c) {
        $d = $c['delta'] ?? null;
        if (!is_array($d)) {
            continue;
        }
        echo is_string($d['content'] ?? null) ? plain($d['content']) : '';
        if ($opt['show-thinking'] && is_string($d['reasoning_content'] ?? null)) {
            echo "\033[2m" . plain($d['reasoning_content']) . "\033[0m";
        }
    }
    $usage = $j['usage'] ?? $usage; // the latest report is the one the request is charged by
};
$r = request('POST', "$base/v1/chat/completions", $body, $key, $stream, $stream ? $onEvent : null);
$j = json_decode($r['body'], true);
if ($r['status'] !== 200) {
    failApi(is_array($j) ? $j : []);
}
if ($sawError !== null) {
    failApi($sawError);
}
if (!$stream) {
    echo plain((string) ($j['choices'][0]['message']['content'] ?? ''));
    $usage = $j['usage'] ?? null;
}
echo "\n";
if ($usage !== null) {
    printf("usage: prompt=%d completion=%d cached=%d reasoning=%d credits=%s\n", $usage['prompt_tokens'] ?? 0,
        $usage['completion_tokens'] ?? 0, $usage['prompt_tokens_details']['cached_tokens'] ?? 0,
        $usage['completion_tokens_details']['reasoning_tokens'] ?? 0, $usage['credits'] ?? '?');
}
exit(0);
