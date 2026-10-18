<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\Top;

// Performance metrics (schema 8: time to first byte and upstream attempts) and the operator's views of them:
// stats, `keys` and the live `top`. Metadata only: there is no prompt or answer to show.

function testMigrateUpgradesASchemaSevenLedgerAddingPerformanceColumns(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v7.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v7.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', 'h', 7, 1, 0)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, 1, 'grant', 7, 7, 'g')");
    $db->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled) VALUES (1, 1, 5, 3600, 3600, 1)');
    $db->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, duration_ms)
        VALUES (3600, 1, 1, 'm', 1, 1, 0, 3, 3, 200, 0, 0, 0, 900)");
    unset($db);
    $l = new Ledger("$dir/v7.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v7.sqlite");
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same([null, 0, 900], array_map(static fn ($v) => $v === null ? null : (int) $v, $db->query('SELECT first_byte_ms, attempts, duration_ms FROM usage')->fetch(\PDO::FETCH_NUM)),
        'an old row has no first-byte time and 0 attempts (unknown), and keeps its values');
    same(1, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'usage_key'")->fetchColumn());
    same([], $l->verify()['problems']);
}

function testStatsCarryTheMeanFirstByteAndRetriedRequests(): void
{
    [$l, $id] = ledgerWithHandle(100000);
    foreach ([[100, 1], [300, 2], [null, 1]] as [$fb, $attempts]) {
        $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60)['lease_id'];
        $l->settle($lease, $id, 5, ['model' => 'm', 'prompt' => 3, 'completion' => 2, 'cached' => 0, 'status' => $fb === null ? 502 : 200,
            'aborted' => false, 'estimated' => false, 'duration_ms' => 400, 'first_byte_ms' => $fb, 'attempts' => $attempts]);
    }
    $s = $l->stats('all')[0];
    same(200, $s['mean_first_byte_ms'], 'the mean over the requests that delivered a byte');
    same(1, $s['retried']);
}

function testTopShowsRateLatencyCostAndRecentRequestsAsMetadataOnly(): void
{
    [$l, $id] = ledgerWithHandle(100000);
    $l->setGroup('a', 'plan-x');
    foreach ([[200, 120, 1, 2_500], [200, 80, 2, null], [503, null, 2, null]] as [$status, $fb, $attempts, $cost]) {
        $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60)['lease_id'];
        $l->settle($lease, $id, 5, ['model' => 'chat-small', 'prompt' => 30, 'completion' => 20, 'cached' => 0, 'status' => $status,
            'aborted' => false, 'estimated' => false, 'upstream' => 'llm.example.com', 'stream' => true, 'duration_ms' => 900,
            'first_byte_ms' => $fb, 'attempts' => $attempts, 'cost' => $cost]);
    }
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60); // one request still in flight
    $out = Top::render($l, ['spend' => [], 'requests_per_minute' => null], time());
    check(str_contains($out, 'in flight 1 (internal 0, external 1'), $out);
    check((bool) preg_match('/^In flight, oldest first\n\s+ref\s+source\s+age s\s+reserved\n\s+a\s+external\s+\d+\s+\d+$/m', $out),
        "the request in flight is listed by key, source, age and reservation:\n$out");
    check(str_contains($out, 'caps: none set'), 'no caps configured');
    check((bool) preg_match('/^requests\s+3\s+3\s+3$/m', $out), "three requests in every window:\n$out");
    check((bool) preg_match('/^failed\s+1 \(33%\)/m', $out), 'one failure of three');
    check((bool) preg_match('/^first byte p50 ms\s+80\s/m', $out), 'first byte over the delivered requests (80, 120)');
    check((bool) preg_match('/^upstream cost\s+0\.002500\s/m', $out), 'reported cost in units, to the millionth it is stored in');
    check((bool) preg_match('/^retried\/failover\s+2\s/m', $out), 'two requests needed more than one attempt');
    check(str_contains($out, 'chat-small') && str_contains($out, 'llm.example.com'), 'the per-model, per-upstream rows');
    check((bool) preg_match('/^\s+\d\d:\d\d:\d\d a\s+chat-small\s+llm\.example\.com\s+503\s+900\s+-\s+30\/20\s+5 retry$/m', $out),
        "a failed request shows no first byte and its flags:\n$out");
}

function testTopStreamsABusyHourInSmallMemory(): void
{
    [$l, $id, $db] = ledgerWithHandle(10);
    $now = time();
    $db->beginTransaction();
    $st = $db->prepare("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, upstream, stream, duration_ms, first_byte_ms, attempts, upstream_cost)
        VALUES (?, ?, ?, 'chat-small', 100, 50, 0, 5, 5, 200, 0, 0, 0, 'llm.example.com', 1, 900, 300, 1, 20)");
    for ($i = 0; $i < 30000; $i++) {
        $st->execute([$now - $i % 3600, $id, $i + 1]);
    }
    $db->commit();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $out = Top::render($l, ['spend' => [], 'requests_per_minute' => null], $now);
    $grew = memory_get_peak_usage() - $before;
    check((bool) preg_match('/^requests\s+\d+\s+\d+\s+30000$/m', $out), "every row of the hour is counted:\n$out");
    check($grew < 16 * 1024 * 1024, "30,000 rows rendered within 16 MB of extra memory (grew $grew bytes)");
}

function testTopWithNoTrafficSaysSo(): void
{
    [$l] = ledgerWithHandle(10);
    $out = Top::render($l, ['spend' => [], 'requests_per_minute' => null], time());
    check(str_contains($out, '(no requests in the last hour)') && str_contains($out, '(no requests)'), $out);
    check(str_contains($out, "In flight, oldest first\n  (none)"), 'nothing in flight');
    check((bool) preg_match('/^duration p50 ms\s+-\s+-\s+-$/m', $out), 'no latency without requests');
}

function testCliListsKeysAndPrintsOneTopView(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $run = static function (string ...$args) use ($dir): array {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    $run('init');
    $run('key-create', 'zeta');
    $run('key-create', 'alpha');
    $run('grant', 'alpha', '50', 'g-alpha');
    [$code, $out] = $run('keys');
    same(0, $code);
    $lines = array_map(static fn (string $l): array => json_decode($l, true), explode("\n", trim($out)));
    same(['alpha', 'zeta'], array_column($lines, 'ref'), 'ordered by ref');
    same([50, true, null], [$lines[0]['balance'], $lines[0]['active'], $lines[0]['last_used']]);
    [$code, $out] = $run('top', '--once');
    same(0, $code, $out);
    check(str_contains($out, 'Virsilipas') && str_contains($out, '(no requests in the last hour)'), $out);
    check(!str_contains($out, "\033["), '--once prints no terminal control codes');
    [$code, $out] = $run('top', '--interval', '0');
    same([1, true], [$code, str_contains($out, '--interval must be 1 to 60 seconds')]);
}
