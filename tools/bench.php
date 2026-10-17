<?php

declare(strict_types=1);

/*
 * Load test for a running proxy: N chat requests, C at a time per process, across P processes.
 *
 *   php tools/bench.php --url=http://127.0.0.1:8080 --key-file=/path/key --model=default \
 *       [--requests=200] [--concurrency=16] [--processes=1] [--stream] [--prompt-bytes=64] \
 *       [--max-tokens=32] [--timeout=120] [--no-ledger-check]
 *
 * The key is read from --key-file or VIRSILIPAS_BENCH_KEY, never from the command line.
 * Prints a human summary on stderr and one result line on stdout:
 *   [virsilipas-bench] {"schema":"virsilipas.bench.v2", "status":"ok"|"fail", "metric":"requests_per_second", ...}
 * Ledger check: the key's balance must fall by exactly the credits the proxy reported to the client (usage.credits).
 * It needs the key to have no other traffic during the run (--no-ledger-check skips it).
 * Exit code 0 only if every request succeeded and the ledger check passed (or was skipped).
 */

const DEFAULTS = ['url' => '', 'key-file' => '', 'model' => 'default', 'requests' => '200', 'concurrency' => '16',
    'processes' => '1', 'prompt-bytes' => '64', 'max-tokens' => '32', 'timeout' => '120'];

function options(array $argv): array
{
    $o = DEFAULTS + ['stream' => false, 'no-ledger-check' => false, 'worker' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) || !array_key_exists($m[1], $o)) {
            fwrite(STDERR, "unknown option: $arg\n");
            exit(2);
        }
        $o[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
    foreach (['requests', 'concurrency', 'processes', 'prompt-bytes', 'max-tokens', 'timeout'] as $k) {
        if (!ctype_digit((string) $o[$k]) || (int) $o[$k] < 1) {
            fwrite(STDERR, "--$k must be a positive integer\n");
            exit(2);
        }
        $o[$k] = (int) $o[$k];
    }
    if ($o['processes'] > $o['requests']) {
        fwrite(STDERR, "--processes must not exceed --requests\n");
        exit(2);
    }
    $o['key'] = $o['key-file'] !== '' ? trim((string) file_get_contents($o['key-file'])) : (string) getenv('VIRSILIPAS_BENCH_KEY');
    if ($o['url'] === '' || $o['key'] === '') {
        fwrite(STDERR, "--url and a key (--key-file or VIRSILIPAS_BENCH_KEY) are required\n");
        exit(2);
    }
    // The key travels with every request: https, or plain http only to this machine (as the example clients require).
    if (!preg_match('#^(https://|http://(127\.0\.0\.1|localhost)[:/])#', $o['url'] . '/')) {
        fwrite(STDERR, "--url must be https (plain http only to 127.0.0.1 or localhost)\n");
        exit(2);
    }
    $o['url'] = rtrim($o['url'], '/');
    return $o;
}

/** Runs $count requests with at most $o['concurrency'] in flight. @return list<array{status: int, ttfb: float, total: float, credits: ?int}> */
function runRequests(array $o, int $count): array
{
    $body = json_encode(['model' => $o['model'], 'stream' => (bool) $o['stream'], 'max_tokens' => $o['max-tokens'],
        'messages' => [['role' => 'user', 'content' => 'Reply with one word. ' . str_repeat('x', $o['prompt-bytes'])]]]);
    $mh = curl_multi_init();
    $bufs = [];
    $results = [];
    $started = 0;
    $add = static function () use ($mh, $o, $body, &$bufs, &$started): void {
        $ch = curl_init($o['url'] . '/v1/chat/completions');
        $id = spl_object_id($ch);
        $bufs[$id] = '';
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => $o['timeout'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $o['key']],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bufs, $id): int {
                $bufs[$id] .= $chunk;
                return strlen($chunk);
            }]);
        curl_multi_add_handle($mh, $ch);
        $started++;
    };
    while ($started < $count && $started < $o['concurrency']) {
        $add();
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.2);
        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $status = $info['result'] === CURLE_OK ? (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 0;
            $results[] = ['status' => $status, 'ttfb' => curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME),
                'total' => curl_getinfo($ch, CURLINFO_TOTAL_TIME), 'credits' => reportedCredits($bufs[$id], $status)];
            unset($bufs[$id]);
            curl_multi_remove_handle($mh, $ch);
            if ($started < $count) {
                $add();
            }
        }
    } while ($running > 0 || count($results) < $started);
    curl_multi_close($mh);
    return $results;
}

/** The usage.credits the proxy reported to the client: the response's, or the last one in a stream. */
function reportedCredits(string $body, int $status): ?int
{
    if ($status !== 200) {
        return null;
    }
    $docs = [json_decode($body, true)];
    foreach (explode("\n", $body) as $line) {
        if (str_starts_with($line, 'data: {')) {
            $docs[] = json_decode(substr($line, 6), true);
        }
    }
    $credits = null;
    foreach ($docs as $d) {
        if (is_array($d) && is_int($d['usage']['credits'] ?? null)) {
            $credits = $d['usage']['credits'];
        }
    }
    return $credits;
}

function balance(array $o): ?array
{
    $ch = curl_init($o['url'] . '/v1/balance');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $o['key']]]);
    $j = json_decode((string) curl_exec($ch), true);
    return is_array($j) && is_int($j['balance'] ?? null) ? $j : null;
}

function percentiles(array $values): array
{
    sort($values);
    $n = count($values);
    $at = static fn (float $p): float => $n === 0 ? 0.0 : round($values[min($n - 1, (int) ceil($p * $n) - 1)] * 1000, 1);
    return ['p50' => $at(0.50), 'p95' => $at(0.95), 'p99' => $at(0.99), 'max' => $at(1.0)];
}

$o = options($argv);
if ($o['worker']) {
    echo json_encode(runRequests($o, $o['requests'])), "\n"; // worker mode: raw results for the parent
    exit(0);
}

$before = $o['no-ledger-check'] ? null : balance($o);
$t0 = microtime(true);
if ($o['processes'] === 1) {
    $results = runRequests($o, $o['requests']);
} else {
    // Several processes, each with its own curl_multi loop: load from more than one CPU core.
    $procs = [];
    $outs = [];
    $base = intdiv($o['requests'], $o['processes']);
    for ($i = 0; $i < $o['processes']; $i++) {
        $n = $base + ($i < $o['requests'] % $o['processes'] ? 1 : 0);
        $args = [PHP_BINARY, __FILE__, '--worker', "--url={$o['url']}", "--model={$o['model']}", "--requests=$n",
            "--concurrency={$o['concurrency']}", "--prompt-bytes={$o['prompt-bytes']}", "--max-tokens={$o['max-tokens']}", "--timeout={$o['timeout']}"];
        if ($o['stream']) {
            $args[] = '--stream';
        }
        $env = ['VIRSILIPAS_BENCH_KEY' => $o['key'], 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $procs[] = proc_open($args, [1 => ['pipe', 'w']], $pipes, null, $env) ?: null;
        $outs[] = $pipes[1] ?? null;
    }
    $results = [];
    foreach ($outs as $i => $out) {
        $part = $out ? json_decode((string) stream_get_contents($out), true) : null;
        if ($procs[$i]) {
            proc_close($procs[$i]);
        }
        $results = array_merge($results, is_array($part) ? $part : []);
    }
}
$elapsed = microtime(true) - $t0;

$ok = array_values(array_filter($results, static fn ($r) => $r['status'] === 200));
// An object even when the only status is 0 (transport failure), which json_encode would make a list.
$statusCounts = (object) array_count_values(array_map(static fn ($r) => (string) $r['status'], $results));
$credits = array_sum(array_map(static fn ($r) => $r['credits'] ?? 0, $ok));
$ledgerExact = null;
if (!$o['no-ledger-check'] && $before === null) {
    fwrite(STDERR, "could not read the starting balance from /v1/balance: ledger check failed\n");
    $ledgerExact = false;
} elseif ($before !== null) {
    for ($i = 0, $after = balance($o); $after !== null && $after['in_flight'] > 0 && $i < 100; $i++, $after = balance($o)) {
        usleep(100000); // wait for the last settlements
    }
    $allReported = count(array_filter($ok, static fn ($r) => $r['credits'] !== null)) === count($ok);
    $ledgerExact = $after !== null && $allReported && ($before['balance'] - $after['balance']) === $credits;
}
$errors = count($results) - count($ok) + ($o['requests'] - count($results));
$result = [
    'schema' => 'virsilipas.bench.v2', 'benchmark' => 'chat-completions', 'status' => $errors === 0 && $ledgerExact !== false ? 'ok' : 'fail',
    'metric' => 'requests_per_second', 'unit' => 'req/s', 'score' => round(count($ok) / max($elapsed, 1e-6), 2),
    'requests' => $o['requests'], 'ok' => count($ok), 'errors' => $errors, 'status_counts' => $statusCounts,
    'concurrency' => $o['concurrency'], 'processes' => $o['processes'], 'stream' => (bool) $o['stream'],
    'latency_ms' => percentiles(array_column($ok, 'total')), 'ttfb_ms' => percentiles(array_column($ok, 'ttfb')),
    'credits' => $credits, 'ledger_exact' => $ledgerExact, 'duration_s' => round($elapsed, 3),
];
fwrite(STDERR, sprintf("%d/%d ok in %.2fs (%.1f req/s), p50 %.0f ms, p95 %.0f ms, p99 %.0f ms, ledger %s\n",
    count($ok), $o['requests'], $elapsed, $result['score'], $result['latency_ms']['p50'], $result['latency_ms']['p95'],
    $result['latency_ms']['p99'], $ledgerExact === null ? 'not checked' : ($ledgerExact ? 'exact' : 'MISMATCH')));
echo '[virsilipas-bench] ', json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
exit($result['status'] === 'ok' ? 0 : 1);
