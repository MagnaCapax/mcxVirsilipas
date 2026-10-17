<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Ledger;

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(trim("$msg expected " . var_export($expected, true) . ', got ' . var_export($actual, true)));
    }
}

/** @return array{0: int, 1: string} [status, message] */
function throwsHttp(callable $fn): array
{
    try {
        $fn();
    } catch (\MagnaCapax\Virsilipas\HttpError $e) {
        return [$e->status, $e->getMessage()];
    }
    throw new \RuntimeException('expected an HttpError');
}

function tempDir(): string
{
    $d = sys_get_temp_dir() . '/virsilipas-test-' . bin2hex(random_bytes(4));
    mkdir($d, 0700);
    // Removed when the run ends (after Harness::stop), so repeated runs leave nothing behind; kept when a test failed, for its logs.
    register_shutdown_function(static function () use ($d): void {
        if (defined('VIRSILIPAS_TESTS_FAILED')) {
            fwrite(STDERR, "kept for inspection: $d\n");
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    });
    return $d;
}

const SCENARIOS = ['ok' => 'scenario-ok', 'final-only' => 'scenario-final-usage', 'busy' => 'scenario-429', 'slow' => 'scenario-slow',
            'empty-usage' => 'scenario-empty-usage', 'json-error' => 'scenario-json-error', 'truncated' => 'scenario-truncated',
            'redirect' => 'scenario-redirect', 'big-delta' => 'scenario-big-delta', 'no-done' => 'scenario-no-done',
            'empty-choices' => 'scenario-empty-choices', 'done-only' => 'scenario-done-only', 'stale-usage' => 'scenario-stale-usage',
            'runaway' => 'scenario-runaway', 'top-private' => 'scenario-top-private',
            'null-choice' => 'scenario-null-choice', 'usage-drop' => 'scenario-usage-drop', 'equal-usage' => 'scenario-equal-usage',
            'negative-total' => 'scenario-negative-total', 'empty-choice' => 'scenario-empty-choice', 'typed-private' => 'scenario-typed-private',
            'list-message' => 'scenario-list-message', 'empty-tool-call' => 'scenario-empty-tool-call',
            'incomplete-message' => 'scenario-incomplete-message', 'done-first' => 'scenario-done-first', 'after-done' => 'scenario-after-done',
            'blank-tool' => 'scenario-blank-tool', 'stream-message' => 'scenario-stream-message', 'invalid-prelude' => 'scenario-invalid-prelude',
            'done-hang' => 'scenario-done-hang', 'stream-message-bytes' => 'scenario-stream-message-bytes', 'private-bytes' => 'scenario-private-bytes',
            'over-batch' => 'scenario-over-batch', 'error-after-start' => 'scenario-error-after-start', 'error-batched' => 'scenario-error-batched',
            'named-error' => 'scenario-named-error', 'slow-final' => 'scenario-slow-final', 'usage-only' => 'scenario-usage-only',
            'reasoning-field' => 'scenario-reasoning-field'];

/** One credit per token of every kind: the credit counts equal the token counts. */
function unitRates(): array
{
    return ['input' => 1_000_000, 'cached_input' => 1_000_000, 'output' => 1_000_000];
}

/** Limit defaults as Config::limitDefaults() returns them. */
function lim(int $concurrent = 4, ?int $rpm = null, ?int $cpm = null): array
{
    return ['max_concurrent' => $concurrent, 'requests_per_minute' => $rpm, 'credits_per_minute' => $cpm];
}

/** @param array<string, mixed> $overrides */
function configArrayFor(string $dir, array $overrides = []): array
{
    $models = [];
    foreach (SCENARIOS as $alias => $scenario) {
        $models[$alias] = ['model' => $scenario] + unitRates();
    }
    // Round example weights for the credit tests: cached input a quarter of fresh, output four times fresh.
    $models['weighted'] = ['model' => 'scenario-cached', 'input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
    // The same counts in the other usage shape (top-level cache-hit count, usage only at the end of a stream).
    $models['weighted-hit'] = ['model' => 'scenario-cache-hit-field', 'input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
    // Near-free input, expensive output: the credit count and the token count tell different stories.
    $models['weighted-shift'] = ['model' => 'scenario-split-shift', 'input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
    $models['cheap-input'] = ['model' => 'scenario-ok', 'input' => 1, 'cached_input' => 1, 'output' => 8_000_000];
    // A model served by a second upstream with its own key and stream_options (the harness points the url at the fake upstream).
    $models['second-upstream'] = ['model' => 'scenario-ok'] + unitRates() + ['url' => 'http://127.0.0.1:1/second/v1/chat/completions',
        'key_file' => "$dir/second.key", 'stream_options' => ['include_usage' => true]];
    // Models with several upstreams, for the retry and failover tests (the harness points both urls at the fake upstream).
    $hop = static fn (string $upstream, string $model, array $params = []): array => ['upstream' => $upstream, 'model' => $model] + ($params === [] ? [] : ['params' => $params]);
    foreach (['failover' => ['scenario-503', 'scenario-ok'], 'after-2xx' => ['scenario-json-error', 'scenario-ok'],
        'context' => ['scenario-400-context', 'scenario-ok'], 'bad-key' => ['scenario-401', 'scenario-401'],
        'all-down' => ['scenario-503', 'scenario-503'], 'server-error' => ['scenario-500', 'scenario-ok']] as $alias => [$first, $second]) {
        $models[$alias] = ['route' => [$hop('primary', $first), $hop('backup', $second)]] + unitRates();
    }
    $models['flaky'] = ['model' => 'scenario-flaky'] + unitRates();
    // Upstream prices (millionths of its unit per million tokens), used only when the upstream reports no cost itself.
    $price = ['cost' => ['input' => 300_000, 'cached_input' => 30_000, 'output' => 1_200_000]];
    $models['costed'] = ['route' => [$hop('primary', 'scenario-no-cost') + $price]] + unitRates();
    $models['costed-reported'] = ['route' => [$hop('primary', 'scenario-ok') + $price]] + unitRates();
    $models['flaky-hint'] = ['model' => 'scenario-flaky-hint'] + unitRates();
    $models['no-key-first'] = ['route' => [$hop('nokey', 'scenario-ok'), $hop('backup', 'scenario-ok')]] + unitRates();
    // An upstream nothing listens on (the harness leaves its port-1 url as it is).
    $models['dead-first'] = ['route' => [$hop('dead', 'scenario-ok'), $hop('backup', 'scenario-ok')]] + unitRates();
    $models['pinned'] = ['route' => [$hop('primary', 'scenario-ok', ['chat_template_kwargs' => ['thinking' => false]])],
        'params' => ['reasoning_effort' => 'low'], 'description' => 'fast, little reasoning', 'context_length' => 128000] + unitRates();
    // Anthropic Messages aliases: the 'msgs' upstream has a messages_url (and its own key); 'primary' has none, so only
    // the second entry of anth-mixed serves /v1/messages. anth is priced like 'weighted', for the cached-input test.
    $models['anth'] = ['route' => [$hop('msgs', 'scenario-anth-ok')], 'input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
    $models['anth-slow'] = ['route' => [$hop('msgs', 'scenario-anth-slow')]] + unitRates();
    $models['anth-overloaded'] = ['route' => [$hop('msgs', 'scenario-anth-529')]] + unitRates();
    $models['anth-mixed'] = ['route' => [$hop('primary', 'scenario-ok'), $hop('msgs', 'scenario-anth-ok')]] + unitRates();
    return $overrides + [
        'upstreams' => [
            'primary' => ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'key_file' => "$dir/upstream.key"],
            'backup' => ['url' => 'http://127.0.0.1:1/second/v1/chat/completions', 'key_file' => "$dir/second.key"],
            'dead' => ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'key_file' => "$dir/upstream.key"],
            'nokey' => ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'key_file' => "$dir/missing.key"],
            'msgs' => ['url' => 'http://127.0.0.1:1/second/v1/chat/completions', 'messages_url' => 'http://127.0.0.1:1/second/v1/messages', 'key_file' => "$dir/second.key"],
        ],
        'db_path' => "$dir/ledger.sqlite",
        'upstream_url' => 'http://127.0.0.1:1/v1/chat/completions',
        'upstream_key_file' => "$dir/upstream.key",
        'upstream_timeout' => 30,
        'models' => $models,
        'max_tokens_cap' => 1000,
        'max_concurrent_per_key' => 5,
        'lease_seconds' => 60,
        'stream_options' => ['include_usage' => true, 'continuous_usage_stats' => true],
        'admin_token_sha256' => hash('sha256', 'admin-secret'),
    ];
}

function freshLedger(): Ledger
{
    $l = new Ledger(tempDir() . '/ledger.sqlite');
    $l->migrate();
    return $l;
}

/** Runs the fake upstream and the proxy under php -S with workers, shared by all integration tests. */
final class Harness
{
    private static ?array $state = null;
    /** @var list<resource> */
    private static array $procs = [];

    /** @return array{dir: string, proxy: string, proxies: list<string>, ledger: Ledger} */
    public static function get(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }
        $dir = tempDir();
        file_put_contents("$dir/upstream.key", "fake-upstream-key\n");
        file_put_contents("$dir/second.key", "second-upstream-key\n");
        mkdir("$dir/logs", 0700);
        $root = dirname(__DIR__);
        $env = ['PHP_CLI_SERVER_WORKERS' => '10', 'FAKE_UPSTREAM_DIR' => $dir, 'VIRSILIPAS_CONFIG' => "$dir/config.php", 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        [self::$procs[], $up] = startPhpServer(["$root/tests/fake-upstream.php"], $env, "$dir/upstream.log");
        $cfg = configArrayFor($dir, ['upstream_url' => "http://127.0.0.1:$up/v1/chat/completions", 'log_dir' => "$dir/logs", 'log_level' => 'debug']);
        $cfg['models']['second-upstream']['url'] = "http://127.0.0.1:$up/second/v1/chat/completions";
        $cfg['upstreams']['primary']['url'] = "http://127.0.0.1:$up/v1/chat/completions";
        $cfg['upstreams']['backup']['url'] = "http://127.0.0.1:$up/second/v1/chat/completions";
        $cfg['upstreams']['msgs']['url'] = "http://127.0.0.1:$up/second/v1/chat/completions";
        $cfg['upstreams']['msgs']['messages_url'] = "http://127.0.0.1:$up/second/v1/messages";
        file_put_contents("$dir/config.php", '<?php return ' . var_export($cfg, true) . ';');
        // php -S queues connections inside a worker, so genuine concurrency needs separate server processes.
        $ports = [];
        for ($i = 1; $i <= 7; $i++) {
            [self::$procs[], $ports[]] = startPhpServer(["$root/public/index.php"], $env, "$dir/proxy-$i.log");
        }
        $ledger = new Ledger(Config::load("$dir/config.php")->dbPath);
        $ledger->migrate();
        $urls = array_map(static fn (int $p): string => "http://127.0.0.1:$p", $ports);
        return self::$state = ['dir' => $dir, 'proxy' => $urls[0], 'proxies' => $urls, 'ledger' => $ledger];
    }

    public static function stop(): void
    {
        foreach (self::$procs as $p) {
            proc_terminate($p);
        }
        self::$procs = [];
    }
}

/**
 * Starts php -S on a free loopback port and waits until it answers. A port found free can be taken by another process
 * before the server binds it; the server then exits and the port answers for someone else. So a server that is not
 * still running a moment after its port answers is started again on a new port, up to three times.
 * Every server reports every PHP error, notice and deprecation to its log, and sends X-Powered-By unless the code removes
 * it; serverLogProblems() fails the run if a log has a Deprecated or Warning line.
 * @param list<string> $args what follows -S host:port (the router script)
 * @param array<string, string> $env
 * @return array{0: resource, 1: int} the server process and its port
 */
function startPhpServer(array $args, array $env, string $log): array
{
    $GLOBALS['virsilipasServerLogs'][$log] = true;
    $ini = ['-d', 'error_reporting=-1', '-d', 'log_errors=1', '-d', 'expose_php=1'];
    for ($attempt = 1; ; $attempt++) {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $p = proc_open([PHP_BINARY, ...$ini, '-S', "127.0.0.1:$port", ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
        check(is_resource($p), 'could not start php -S');
        for ($i = 0; $i < 100 && !($c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)); $i++) {
            usleep(50000);
        }
        if ($c) {
            fclose($c);
            usleep(200000); // time for a server that lost the port to exit
        }
        if ($c && proc_get_status($p)['running']) {
            return [$p, $port];
        }
        proc_terminate($p);
        check($attempt < 3, "php -S could not bind a port three times (see $log)");
    }
}

/**
 * The Deprecated and Warning lines in the logs of every php -S server the run started (at most 20).
 * @return list<string>
 */
function serverLogProblems(): array
{
    $found = [];
    foreach (array_keys($GLOBALS['virsilipasServerLogs'] ?? []) as $log) {
        foreach (is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/\b(Deprecated|Warning)\b/', $line) === 1 && count($found) < 20) {
                $found[] = basename($log) . ': ' . $line;
            }
        }
    }
    return $found;
}

/**
 * One HTTP request to the proxy. $stopAfterBytes > 0 makes the client hang up mid-response. $headers are sent as well.
 * @param array<string, mixed>|null $body
 * @param list<string> $headers
 * @return array{status: int, body: string}
 */
function http(string $method, string $path, ?array $body = null, ?string $bearer = null, int $stopAfterBytes = 0, array $headers = []): array
{
    $ch = curl_init(Harness::get()['proxy'] . $path);
    $out = '';
    $retryAfter = $requestId = null;
    $headers[] = 'Content-Type: application/json';
    if ($bearer !== null) {
        $headers[] = "Authorization: Bearer $bearer";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter, &$requestId): int {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m)) {
                $retryAfter = (int) $m[1];
            } elseif (preg_match('/^X-Request-Id:\s*(\d+)/i', $line, $m)) {
                $requestId = (int) $m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$out, $stopAfterBytes): int {
            $out .= $chunk;
            return ($stopAfterBytes > 0 && strlen($out) >= $stopAfterBytes) ? 0 : strlen($chunk);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ['status' => $status, 'body' => $out, 'retry_after' => $retryAfter, 'request_id' => $requestId];
}

/**
 * Runs several chat requests at once, one per proxy process.
 * @param list<array<string, mixed>> $bodies
 * @return list<int> HTTP statuses in request order
 */
function parallelChat(array $bodies, string $key): array
{
    $mh = curl_multi_init();
    $handles = [];
    $proxies = Harness::get()['proxies'];
    foreach ($bodies as $i => $b) {
        $ch = curl_init($proxies[$i % count($proxies)] . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($b),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $key"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $statuses = [];
    foreach ($handles as $ch) {
        $statuses[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $statuses;
}

/** Waits until a key has no request in flight (settle runs after the response ends). */
function settled(string $ref): array
{
    $ledger = Harness::get()['ledger'];
    for ($i = 0; $i < 100; $i++) {
        $s = $ledger->status($ref, lim(5));
        if ($s['in_flight'] === 0) {
            return $s;
        }
        usleep(50000);
    }
    throw new \RuntimeException("requests for $ref never settled");
}

/** Creates a funded key in the shared harness and returns [ref, key]. */
function fundedKey(int $tokens): array
{
    $ledger = Harness::get()['ledger'];
    $ref = 'svc-' . bin2hex(random_bytes(4));
    $key = $ledger->createKey($ref);
    $ledger->grant($ref, "g-$ref", $tokens);
    return [$ref, $key];
}

/** The newest usage row for a ref (token counts and flags only). */
function lastUsage(string $ref): array
{
    $db = new \PDO('sqlite:' . Harness::get()['dir'] . '/ledger.sqlite');
    $st = $db->prepare('SELECT u.* FROM usage u JOIN keys k ON k.id = u.key_id WHERE k.ref = ? ORDER BY u.id DESC LIMIT 1');
    $st->execute([$ref]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    check($row !== false, "no usage row for $ref");
    return array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $row);
}
