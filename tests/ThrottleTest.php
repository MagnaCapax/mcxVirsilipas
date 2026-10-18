<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\Throttle;

function throttleWith(array $t): Throttle
{
    return Throttle::fromConfig($t);
}

function testWeightFollowsTheCgroupScale(): void
{
    $t = throttleWith([]);
    same(100, $t->weight(1_000_000, 0, false), 'one unit of credit, no bonus: the reference weight');
    same(200, $t->weight(4_000_000, 0, false), 'four units: twice the weight (square root)');
    same(400, $t->weight(1_000_000, 300, false), 'a 300% bonus multiplies by 4');
    same(200, $t->weight(1_000_000, 0, true), 'internal callers count double by default');
    same(1, $t->weight(0, 0, false), 'no credit: the floor, never 0');
    same(1, $t->weight(-50, 0, false), 'a negative balance is the floor');
    same(10000, $t->weight(1_000_000_000_000_000, 300, true), 'clamped at the cgroup maximum');
    same(400, $t->weight(1_000_000, 999, false), 'bonus beyond 300% counts as 300%');
    same(50, throttleWith(['credits_per_unit' => 4_000_000])->weight(1_000_000, 0, false), 'the unit is configurable');
    $prev = 0;
    foreach ([1, 1000, 100_000, 1_000_000, 50_000_000, 900_000_000] as $balance) {
        $w = $t->weight($balance, 0, false);
        check($w >= $prev, "weight never falls as the balance grows ($balance)");
        $prev = $w;
    }
}

function testPacingSlowsLowWeightsMoreAndStaysBounded(): void
{
    $t = throttleWith(['daily_budget' => 1000, 'pace_start' => 0.8, 'base_delay_ms' => 1000, 'max_delay_ms' => 3000]);
    same(0.0, $t->pressure(500), 'below the pacing start: no pressure');
    same(0.0, $t->pressure(800), 'at the pacing start: still none');
    check(abs($t->pressure(1000) - 1.0) < 1e-9, 'the full budget is pressure 1');
    check(abs($t->pressure(1200) - 2.0) < 1e-9, 'past the budget pressure keeps growing');
    same(1000, $t->delayMs(1.0, 100), 'reference weight at the full budget waits base_delay_ms');
    same(100, $t->delayMs(1.0, 1000), 'ten times the weight waits a tenth');
    same(3000, $t->delayMs(5.0, 1), 'capped at max_delay_ms');
    same(0, $t->delayMs(0.0, 1), 'no pressure, no wait');
    $prev = PHP_INT_MAX;
    foreach ([1, 10, 100, 1000, 10000] as $w) {
        $d = $t->delayMs(1.5, $w);
        check($d <= $prev, "a higher weight never waits longer ($w)");
        $prev = $d;
    }
    same(0.0, throttleWith([])->pressure(PHP_INT_MAX), 'no budget: pacing is off');
}

function testShedRetryAfterIsLongerForLowWeights(): void
{
    $t = throttleWith(['busy' => ['external' => 10], 'busy_min_weight' => 100]);
    same(5, $t->shedRetryAfter(100));
    same(50, $t->shedRetryAfter(10));
    same(60, $t->shedRetryAfter(1), 'at most a minute');
    same(1, $t->shedRetryAfter(10000), 'at least a second');
    same(10, $t->busyLine(false));
    same(null, $t->busyLine(true), 'no internal line: internal callers are never shed');
}

function testThrottleConfigIsValidated(): void
{
    $bad = [['daily_budget' => 0], ['daily_budget' => '5'], ['pace_start' => 1.0], ['max_delay_ms' => 60001], ['busy' => ['other' => 3]],
        ['busy' => ['external' => 0]], ['internal_factor' => 0.5], ['credits_per_unit' => 0], ['speed' => 1], 'yes', ['pace_start' => NAN], ['internal_factor' => NAN]];
    foreach ($bad as $t) {
        $ok = false;
        try {
            Throttle::fromConfig($t);
        } catch (\InvalidArgumentException) {
            $ok = true;
        }
        check($ok, 'refused: ' . var_export($t, true));
    }
    $d = Throttle::fromConfig(null);
    same([null, 0.8, 1000, 3000, ['internal' => null, 'external' => null], 100, 2.0, 1_000_000],
        [$d->dailyBudget, $d->paceStart, $d->baseDelayMs, $d->maxDelayMs, $d->busy, $d->busyMinWeight, $d->internalFactor, $d->creditsPerUnit], 'defaults: pacing and shedding off');
    $c = new Config(configArrayFor(tempDir(), ['throttle' => ['daily_budget' => 5000, 'busy' => ['external' => 3]]]));
    same([5000, 3], [$c->throttle->dailyBudget, $c->throttle->busyLine(false)], 'read from the config');
}

function testBonusIsSetValidatedAndShownInStatus(): void
{
    $l = freshLedger();
    $l->createKey('a');
    same(0, $l->status('a', lim())['bonus_pct'], 'new keys have no bonus');
    same(150, $l->setBonus('a', 150));
    same(150, $l->status('a', lim())['bonus_pct']);
    foreach ([-1, 301] as $bad) {
        same(400, throwsHttp(fn () => $l->setBonus('a', $bad))[0], "bonus $bad refused");
    }
    same(404, throwsHttp(fn () => $l->setBonus('nobody', 10))[0]);
    same(150, $l->status('a', lim())['bonus_pct'], 'a refused change leaves it as it was');
}

function testOpenLeasesAreCountedPerSourceAndChargesSummed(): void
{
    $l = freshLedger();
    $a = $l->findKey($l->createKey('a'))['id'] ?? 0;
    $l->grant('a', 'g', 100000);
    $b = $l->findKey($l->createKey('b'))['id'] ?? 0;
    $l->grant('b', 'g2', 100000);
    // Two external requests and one internal one in flight.
    $l->reserve($a, 10, 10, 1000, unitRates(), lim(4), 60, 0);
    $l->reserve($a, 10, 10, 1000, unitRates(), lim(4), 60, 0);
    $lease = $l->reserve($b, 10, 10, 1000, unitRates(), lim(4), 60, 1);
    same([2, 1], [$l->openLeases(0), $l->openLeases(1)], 'in flight per source');
    $l->settle($lease['lease_id'], $b, 7, ['model' => 'm', 'prompt' => 5, 'completion' => 2, 'cached' => 0, 'status' => 200,
        'aborted' => false, 'estimated' => false, 'delay_ms' => 250]);
    same([2, 0], [$l->openLeases(0), $l->openLeases(1)], 'a settled lease is no longer in flight');
    same(7, $l->chargedSince(time() - 60), 'charges in the window are summed');
    same(0, $l->chargedSince(time() + 3600), 'nothing in the next hour');
    $s = $l->stats('all');
    same([1, 250], [$s[0]['delayed'], $s[0]['mean_delay_ms']], 'the delay is recorded with the usage row');
}

function testMigrateUpgradesASchemaFourLedger(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $old = new \PDO('sqlite:' . $path);
    $old->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v4.sql'));
    $old->exec("INSERT INTO keys (id, ref, key_hash, balance, created_at) VALUES (1, 'a', 'h1', 90, 0)");
    $old->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, 1, 'grant', 100, 100, 'g'), (0, 1, 'reserve', -10, 90, 'lease:1')");
    $old->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled) VALUES (1, 1, 10, 0, 0, 1)');
    $old->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, upstream) VALUES (86400, 1, 1, 'm', 7, 3, 2, 10, 10, 200, 0, 0, 0, 'u')");
    $old = null;
    chmod($path, 0600);

    $l = new Ledger($path);
    $l->migrate();
    $db = new \PDO('sqlite:' . $path);
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same([0, 0, 0, 'u'], array_values($db->query('SELECT k.bonus_pct, l.source, u.delay_ms, u.upstream FROM keys k, leases l, usage u')->fetch(\PDO::FETCH_NUM)),
        'old rows get the defaults and keep their values');
    same([], $l->verify()['problems'], 'balances untouched');
    $l->migrate();
    same(1, (int) $db->query('SELECT COUNT(*) FROM usage')->fetchColumn(), 're-running init adds nothing');
    same(1, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'leases_source_open'")->fetchColumn(), 'the per-source index exists');
}

/** A separate proxy instance on the shared fake upstream, with its own ledger and config, for throttle behaviour. */
function throttledProxy(array $throttle, array $env = []): array
{
    return configuredProxy(static fn (array $cfg): array => ['throttle' => $throttle] + $cfg, $env);
}

/**
 * A proxy process of its own on the shared fake upstream, with the test config changed by $edit first.
 * @param callable(array<string, mixed>): array<string, mixed> $edit
 */
function configuredProxy(callable $edit, array $env = []): array
{
    $h = Harness::get();
    $dir = tempDir();
    file_put_contents("$dir/upstream.key", "fake-upstream-key\n");
    $shared = require $h['dir'] . '/config.php';
    $cfg = configArrayFor($dir, ['upstream_url' => $shared['upstream_url']]);
    $cfg['upstreams'] = $shared['upstreams'];
    foreach (array_keys($cfg['upstreams']) as $name) {
        $cfg['upstreams'][$name]['key_file'] = $shared['upstreams'][$name]['key_file'];
    }
    $cfg = $edit($cfg);
    file_put_contents("$dir/config.php", '<?php return ' . var_export($cfg, true) . ';');
    // The built-in server builds $_SERVER per request without the environment, so a router stands in for the web
    // server's FastCGI parameter: it copies VIRSILIPAS_SOURCE from the environment, as nginx sets it from the client address.
    file_put_contents("$dir/router.php", "<?php\nif (getenv('VIRSILIPAS_SOURCE') !== false) { \$_SERVER['VIRSILIPAS_SOURCE'] = getenv('VIRSILIPAS_SOURCE'); }\nrequire " . var_export(dirname(__DIR__) . '/public/index.php', true) . ";\n");
    [$p, $port] = startPhpServer(["$dir/router.php"], ['VIRSILIPAS_CONFIG' => "$dir/config.php", 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $env, "$dir/proxy.log");
    $ledger = new Ledger(Config::load("$dir/config.php")->dbPath);
    $ledger->migrate();
    return ['url' => "http://127.0.0.1:$port", 'ledger' => $ledger, 'proc' => $p, 'log' => "$dir/proxy.log"];
}

/** @return array{status: int, headers: array<string, string>, body: string, ms: int} */
function rawRequest(string $url, string $method, ?array $body, string $key, array $extraHeaders = []): array
{
    $h = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', "Authorization: Bearer $key"], $extraHeaders),
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$h): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        }]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $t = hrtime(true);
    $b = (string) curl_exec($ch);
    return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $h, 'body' => $b, 'ms' => intdiv(hrtime(true) - $t, 1_000_000)];
}

function testIntegrationSpendPacingDelaysByWeightAndRecordsIt(): void
{
    // A 10-credit daily budget, spent over by the first request: pressure far above 1.
    $cfg = ['daily_budget' => 10, 'base_delay_ms' => 100, 'max_delay_ms' => 600];
    $p = throttledProxy($cfg);
    try {
        $l = $p['ledger'];
        $small = $l->createKey('small');
        $l->grant('small', 'g1', 10_000); // weight 100 x sqrt(0.01) = 10
        $big = $l->createKey('big');
        $l->grant('big', 'g2', 400_000_000); // weight 100 x sqrt(400) = 2000
        $spender = $l->createKey('spender');
        $l->grant('spender', 'g3', 1_000_000);
        $first = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $spender);
        same(200, $first['status']);
        same('0', $first['headers']['x-virsilipas-delay-ms'] ?? '', 'nothing spent yet: no delay');
        check($l->chargedSince(time() - 86400) > 10, 'the budget is spent');
        $s = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $small);
        $t = Throttle::fromConfig($cfg);
        $expectBig = $t->delayMs($t->pressure($l->chargedSince(time() - 86400)), 2000);
        $b = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $big);
        same([200, 200], [$s['status'], $b['status']], 'pacing slows requests down, it never refuses them');
        same(['10', '600'], [$s['headers']['x-virsilipas-weight'] ?? '', $s['headers']['x-virsilipas-delay-ms'] ?? ''], 'low weight: the capped delay');
        same(['2000', (string) $expectBig], [$b['headers']['x-virsilipas-weight'] ?? '', $b['headers']['x-virsilipas-delay-ms'] ?? ''], 'high weight: the same pressure, a 200th of the low weight wait');
        check($expectBig > 0 && $expectBig < 600, 'high weight waits, but far less');
        // Wall time proves the wait happened; the headers above prove how the two weights compare (a timing difference would flake on a busy machine).
        check($s['ms'] >= 600, "the low-weight request really waited ({$s['ms']} ms)");
        same(600, $l->stats('all', ['key'])[array_search('small', array_column($l->stats('all', ['key']), 'key'), true)]['mean_delay_ms'], 'the wait is recorded with the usage row');
        $db = new \PDO('sqlite:' . dirname($p['log']) . '/ledger.sqlite');
        $duration = (int) $db->query("SELECT u.duration_ms FROM usage u JOIN keys k ON k.id = u.key_id WHERE k.ref = 'small' ORDER BY u.id DESC LIMIT 1")->fetchColumn();
        check($duration < 600, "the upstream duration starts after the wait ($duration ms)");
        // A waiting request already holds its lease: it counts as in flight for the busy line and the key's limits.
        $mh = curl_multi_init();
        $ch = curl_init($p['url'] . '/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $small"], CURLOPT_POSTFIELDS => json_encode(msg('ok', false))]);
        curl_multi_add_handle($mh, $ch);
        [$t0, $seen] = [microtime(true), 0];
        do {
            curl_multi_exec($mh, $running);
            $age = microtime(true) - $t0;
            if ($age > 0.15 && $age < 0.45) {
                $seen = max($seen, $l->openLeases(0));
            }
            usleep(10000);
        } while ($running > 0);
        curl_multi_close($mh);
        same(1, $seen, 'in flight during its wait');
    } finally {
        proc_terminate($p['proc']);
    }
}

function testIntegrationSourceComesOnlyFromTheServerNeverFromAHeader(): void
{
    $plain = throttledProxy([]);
    $internal = throttledProxy([], ['VIRSILIPAS_SOURCE' => 'internal']);
    try {
        foreach ([$plain, $internal] as $p) {
            $p['ledger']->createKey('k');
        }
        $key = $plain['ledger']->createKey('k2');
        $plain['ledger']->grant('k2', 'g', 1_000_000);
        $spoof = json_decode(rawRequest($plain['url'] . '/v1/balance', 'GET', null, $key, ['Virsilipas-Source: internal', 'X-Virsilipas-Source: internal'])['body'], true);
        same(100, $spoof['weight'] ?? null, 'a client header claiming to be internal changes nothing');
        $ikey = $internal['ledger']->createKey('k3');
        $internal['ledger']->grant('k3', 'g', 1_000_000);
        $real = json_decode(rawRequest($internal['url'] . '/v1/balance', 'GET', null, $ikey)['body'], true);
        same(200, $real['weight'] ?? null, 'the server variable set for internal callers doubles the weight');
    } finally {
        proc_terminate($plain['proc']);
        proc_terminate($internal['proc']);
    }
}

function testIntegrationBusyShedsLowWeightsAndKeepsHighWeightsServing(): void
{
    // Busy line 1: one request in flight makes the external source busy. A 1-credit budget puts spend pacing on
    // as soon as anything is charged, to show that nothing waits in a worker while busy.
    $p = throttledProxy(['busy' => ['external' => 1], 'busy_min_weight' => 100, 'daily_budget' => 1, 'max_delay_ms' => 200]);
    try {
        $l = $p['ledger'];
        $holder = $l->createKey('holder');
        $l->grant('holder', 'g0', 100_000_000);
        $low = $l->createKey('low');
        $l->grant('low', 'g1', 10_000); // weight 10
        $high = $l->createKey('high');
        $l->grant('high', 'g2', 4_000_000); // weight 200
        same(200, rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $holder)['status'], 'spend past the budget');
        // A slow streaming request keeps one lease open while the others arrive.
        $mh = curl_multi_init();
        $slow = curl_init($p['url'] . '/v1/chat/completions');
        curl_setopt_array($slow, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $holder"], CURLOPT_POSTFIELDS => json_encode(msg('slow', true))]);
        curl_multi_add_handle($mh, $slow);
        for ($i = 0; $i < 100 && $l->openLeases(0) < 1; $i++) {
            curl_multi_exec($mh, $running);
            usleep(20000);
        }
        same(1, $l->openLeases(0), 'the slow request holds the only slot of the busy line');
        $shed = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $low);
        same(503, $shed['status'], 'below the busy weight while busy: told to come back');
        same(['server_busy', '50'], [json_decode($shed['body'], true)['error']['code'] ?? '', $shed['headers']['retry-after'] ?? ''], 'with a weight-scaled Retry-After');
        $served = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $high);
        same(200, $served['status'], 'a high weight is still served while busy');
        same('0', $served['headers']['x-virsilipas-delay-ms'] ?? '', 'and without a pacing wait, though spend is past the budget');
        do {
            curl_multi_exec($mh, $running);
            usleep(20000);
        } while ($running > 0);
        curl_multi_close($mh);
        $after = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $low);
        same(200, $after['status'], 'once not busy, the low weight is served again');
        same('200', $after['headers']['x-virsilipas-delay-ms'] ?? '', 'after a pacing wait, now that the server is not busy');
    } finally {
        proc_terminate($p['proc']);
    }
}

function testSpendIsKeptPerHourAndBackfilledOnUpgrade(): void
{
    $l = freshLedger();
    $l->createKey('a');
    $l->grant('a', 'g', 1_000_000);
    $key = $l->findKey($l->createKey('b'));
    $l->grant('b', 'g2', 1_000_000);
    $lease = $l->reserve($key['id'], 10, 10, 100, unitRates(), lim(), 60);
    $l->settle($lease['lease_id'], $key['id'], 15, ['model' => 'm', 'prompt' => 10, 'completion' => 5, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    same(15, $l->chargedSince(time() - 86400), 'a settle adds to the current hour');
    same(15, $l->chargedSince(time()), 'the current hour counts whole');
    same(0, $l->chargedSince(time() + 3600), 'nothing in a later hour');
    // Upgrading a schema-4 ledger with usage rows fills the hours from them.
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v4.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v4.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', 'h', 0, 1, 0)");
    $db->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled) VALUES (1, 1, 50, 0, 0, 1), (2, 1, 50, 0, 0, 1)');
    $now = time();
    $db->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation) VALUES ($now, 1, 1, 'm', 1, 1, 0, 40, 40, 200, 0, 0, 0), (" . ($now - 7200) . ", 1, 2, 'm', 1, 1, 0, 2, 2, 200, 0, 0, 0)");
    unset($db);
    $up = new Ledger("$dir/v4.sqlite");
    $up->migrate();
    same([42, 40], [$up->chargedSince($now - 86400), $up->chargedSince($now)], 'old usage rows counted by hour after the upgrade');
    $up->migrate();
    same(42, $up->chargedSince($now - 86400), 'a second init does not count them twice');
}

function testIntegrationAThrottleFailureNeverFailsTheRequest(): void
{
    $p = throttledProxy(['daily_budget' => 10, 'base_delay_ms' => 100, 'max_delay_ms' => 600]);
    try {
        $l = $p['ledger'];
        $key = $l->createKey('k');
        $l->grant('k', 'g', 1_000_000);
        // Two earlier hours whose sum overflows 64 bits: reading the day's spend fails, settling the current hour does not.
        $db = new \PDO('sqlite:' . dirname($p['log']) . '/ledger.sqlite');
        $h = intdiv(time(), 3600);
        $db->exec('INSERT INTO spend_hourly (hour, charged) VALUES (' . ($h - 1) . ', ' . PHP_INT_MAX . '), (' . ($h - 2) . ', ' . PHP_INT_MAX . ')');
        $r = rawRequest($p['url'] . '/v1/chat/completions', 'POST', msg('ok', false), $key);
        same([200, '0'], [$r['status'], $r['headers']['x-virsilipas-delay-ms'] ?? ''], 'served at once, without a wait');
        check(str_contains((string) file_get_contents($p['log']), 'throttle_error'), 'the failure is logged');
    } finally {
        proc_terminate($p['proc']);
    }
}
