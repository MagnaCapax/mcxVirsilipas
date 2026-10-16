<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Ledger;

// Server-wide caps: upstream spend per hour, day and month, and requests per minute across all keys.
// With unitRates() as the cost prices, one token costs one millionth of the upstream unit.

/** @param array<string, ?int> $spend */
function caps(array $spend = [], ?int $rpm = null): array
{
    return ['spend' => $spend + ['hour' => null, 'day' => null, 'month' => null], 'requests_per_minute' => $rpm];
}

/** @return array{0: Ledger, 1: int, 2: int} ledger and two funded key ids */
function twoKeys(): array
{
    $l = freshLedger();
    $a = $l->createKey('a');
    $b = $l->createKey('b');
    $l->grant('a', 'ga', 1_000_000);
    $l->grant('b', 'gb', 1_000_000);
    return [$l, $l->findKey($a)['id'], $l->findKey($b)['id']];
}

function testServerRequestsPerMinuteCountsEveryKey(): void
{
    [$l, $a, $b] = twoKeys();
    $l->reserve($a, 1, 1, 1000, unitRates(), lim(), 60, 0, caps(rpm: 2));
    $l->reserve($b, 1, 1, 1000, unitRates(), lim(), 60, 0, caps(rpm: 2));
    try {
        $l->reserve($a, 1, 1, 1000, unitRates(), lim(), 60, 0, caps(rpm: 2));
        throw new \RuntimeException('expected a refusal');
    } catch (\MagnaCapax\Virsilipas\HttpError $e) {
        same([429, 'server_rate_limit'], [$e->status, $e->errorCode]);
        check($e->retryAfter >= 1 && $e->retryAfter <= 60, 'Retry-After within the minute');
    }
    same(2, $l->capStatus(caps(rpm: 2))['requests_per_minute']['last_minute'], 'a refused request starts nothing');
}

function testSpendCapRefusesARequestThatDoesNotFitAndNeverShortensIt(): void
{
    [$l, $a] = twoKeys();
    // Bound = 10 prompt + 100 output tokens = 110 millionths; the day cap is 100.
    $e = throwsHttp(fn () => $l->reserve($a, 10, 100, 1000, unitRates(), lim(), 60, 0, caps(['day' => 100]), unitRates()));
    same(503, $e[0]);
    same(1_000_000, $l->status('a', lim())['balance'], 'nothing reserved');
    // A request that fits gets its full max_tokens.
    same(80, $l->reserve($a, 10, 80, 1000, unitRates(), lim(), 60, 0, caps(['day' => 100]), unitRates())['max_tokens']);
}

function testSpendCapCountsRequestsInFlightAndSettledCost(): void
{
    [$l, $a, $b] = twoKeys();
    $cap = caps(['hour' => 1000]);
    $r = $l->reserve($a, 100, 500, 1000, unitRates(), lim(), 60, 0, $cap, unitRates()); // bound 600 in flight
    same(503, throwsHttp(fn () => $l->reserve($b, 100, 400, 1000, unitRates(), lim(), 60, 0, $cap, unitRates()))[0], '600 + 500 > 1000');
    $l->settle($r['lease_id'], $a, 300, ['cost' => 300] + meta()); // the request cost 300: the bound is released
    same(['limit' => 1000, 'settled' => 300, 'in_flight' => 0, 'remaining' => 700], array_slice($l->capStatus($cap)['hour'], 0, 4));
    same(400, $l->reserve($b, 100, 400, 1000, unitRates(), lim(), 60, 0, $cap, unitRates())['max_tokens'], '300 + 500 <= 1000');
}

function testZeroCapStopsEverythingForEveryKey(): void
{
    [$l, $a, $b] = twoKeys();
    foreach ([$a, $b] as $id) {
        same(503, throwsHttp(fn () => $l->reserve($id, 1, 1, 1000, unitRates(), lim(), 60, 1, caps(['month' => 0]), ['input' => 0, 'cached_input' => 0, 'output' => 0]))[0],
            'stopped, internal callers and free upstreams included');
    }
}

function testCapWindowsAreUtcHourDayAndMonth(): void
{
    $t = gmmktime(13, 45, 10, 2, 28, 2027);
    same([gmmktime(13, 0, 0, 2, 28, 2027), gmmktime(14, 0, 0, 2, 28, 2027)], Ledger::capWindow('hour', $t));
    same([gmmktime(0, 0, 0, 2, 28, 2027), gmmktime(0, 0, 0, 3, 1, 2027)], Ledger::capWindow('day', $t));
    same([gmmktime(0, 0, 0, 2, 1, 2027), gmmktime(0, 0, 0, 3, 1, 2027)], Ledger::capWindow('month', $t));
    same([gmmktime(0, 0, 0, 12, 1, 2027), gmmktime(0, 0, 0, 1, 1, 2028)], Ledger::capWindow('month', gmmktime(5, 0, 0, 12, 31, 2027)));
}

function testCapsConfigIsCheckedAndNeedsACostOnEveryRouteEntry(): void
{
    $dir = tempDir();
    $base = configArrayFor($dir);
    $msg = static function (array $cfg): string {
        try {
            new Config($cfg);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '';
    };
    check(str_contains($msg(['caps' => ['day' => 5]] + $base), 'needs a cost on every route entry'), 'a model without prices would escape the cap');
    $costedOnly = ['caps' => ['hour' => 0.5, 'day' => 5, 'month' => 0, 'requests_per_minute' => 120]] + $base;
    $costedOnly['models'] = array_intersect_key($base['models'], ['costed' => 1, 'costed-reported' => 1]);
    $c = new Config($costedOnly);
    same(['hour' => 500_000, 'day' => 5_000_000, 'month' => 0], $c->caps['spend'], 'currency units become millionths');
    same(120, $c->caps['requests_per_minute']);
    same(['input' => 300_000, 'cached_input' => 30_000, 'output' => 1_200_000], $c->costBounds['costed']);
    check(str_contains($msg(['caps' => ['week' => 1]] + $base), 'caps may set only'), 'unknown window refused');
    check(str_contains($msg(['caps' => ['day' => -1]] + $base), 'caps.day must be a number'), 'negative refused');
    same(['hour' => null, 'day' => null, 'month' => null], (new Config($base))->caps['spend'], 'no block, no cap');
}

function testMigrateUpgradesASchemaSixLedgerAndBackfillsHourlyCost(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v6.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v6.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', 'h', 7, 1, 0)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, 1, 'grant', 7, 7, 'g')");
    $db->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled) VALUES (1, 1, 5, 3600, 3600, 1), (2, 1, 5, 3700, 3700, 1)');
    $db->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, upstream_cost)
        VALUES (3600, 1, 1, 'm', 1, 1, 0, 3, 3, 200, 0, 0, 0, 9), (3700, 1, 2, 'm', 1, 1, 0, 2, 2, 200, 0, 0, 0, NULL)");
    $db->exec('INSERT INTO spend_hourly (hour, charged) VALUES (1, 5)');
    unset($db);
    $l = new Ledger("$dir/v6.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v6.sqlite");
    same(7, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same(['1', '5', '9'], array_map('strval', $db->query('SELECT hour, charged, cost FROM spend_hourly')->fetch(\PDO::FETCH_NUM)), 'cost backfilled from the usage rows');
    same(['0'], array_map('strval', $db->query('SELECT DISTINCT cost_reserved FROM leases')->fetchAll(\PDO::FETCH_COLUMN)), 'old leases hold no bound');
    same([], $l->verify()['problems']);
}

function testIntegrationSpendCapRefusesWith503AndRetryAfter(): void
{
    $p = configuredProxy(static function (array $cfg): array {
        $cfg['models'] = array_intersect_key($cfg['models'], ['costed' => 1]);
        return ['caps' => ['day' => 0.0005]] + $cfg; // 500 millionths: room for the first small request only
    });
    try {
        $l = $p['ledger'];
        $k = $l->createKey('capped');
        $l->grant('capped', 'g', 1_000_000);
        $body = ['model' => 'costed', 'max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'hi']]];
        $first = rawRequest($p['url'] . '/v1/chat/completions', 'POST', $body, $k);
        same(200, $first['status'], 'the first request fits');
        $body['max_tokens'] = 2000; // a bound far past what is left
        $refused = rawRequest($p['url'] . '/v1/chat/completions', 'POST', $body, $k);
        same(503, $refused['status']);
        same('spend_cap_reached', json_decode($refused['body'], true)['error']['code'] ?? null);
        check((int) ($refused['headers']['retry-after'] ?? 0) >= 1, 'Retry-After until the day ends');
    } finally {
        proc_terminate($p['proc']);
    }
}

function testCliCapsShowsEachCapAndItsUse(): void
{
    $dir = tempDir();
    $cfg = ['caps' => ['day' => 2, 'requests_per_minute' => 30]] + configArrayFor($dir);
    $cfg['models'] = array_intersect_key($cfg['models'], ['costed' => 1]);
    file_put_contents("$dir/config.php", '<?php return ' . var_export($cfg, true) . ';');
    $run = static function (string $cmd) use ($dir): string {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
        $out = stream_get_contents($pipes[1]);
        proc_close($p);
        return (string) $out;
    };
    $run('init');
    $c = json_decode($run('caps'), true);
    same(['limit' => 2_000_000, 'settled' => 0, 'in_flight' => 0, 'remaining' => 2_000_000], array_slice($c['day'], 0, 4));
    same(['limit' => 30, 'last_minute' => 0], $c['requests_per_minute']);
    same(['day', 'requests_per_minute'], array_keys($c), 'unset caps are not shown');
}
