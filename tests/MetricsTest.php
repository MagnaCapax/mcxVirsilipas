<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Ledger;

// Usage tracking for cost reports: time buckets across days, the source and key-group dimensions, and the cost of
// requests whose upstream reports none, computed from the route entry's configured prices.

function testStatsBucketsByWeekHourOfDayAndWeekday(): void
{
    [$l, $id, $raw] = ledgerWithHandle(100000);
    // 1970-01-11 was a Sunday; weeks start on Monday (1970-01-05 opened week 01).
    foreach ([3600 * 24 * 10 + 60, 3600 * 24 * 10 + 7200, 3600 * 24 * 10 + 7300, 3600 * 24 * 11] as $ts) {
        $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60)['lease_id'];
        $l->settle($lease, $id, 5, ['model' => 'm', 'prompt' => 3, 'completion' => 2, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
        $raw->prepare('UPDATE usage SET ts = ? WHERE lease_id = ?')->execute([$ts, $lease]);
    }
    $pairs = static fn (string $bucket): array => array_map(static fn (array $r): array => [$r['bucket'], $r['requests']], $l->stats($bucket));
    same([['1970-W01', 3], ['1970-W02', 1]], $pairs('week'), 'per Monday-based week');
    same([['00', 2], ['02', 2]], $pairs('hour_of_day'), 'per hour of the day, all days folded');
    same([['0', 3], ['1', 1]], $pairs('weekday'), 'per weekday, 0 = Sunday');
}

function testStatsGroupBySourceAndKeyGroup(): void
{
    [$l, $a, ] = ledgerWithHandle(100000);
    $b = $l->findKey($l->createKey('b'))['id'];
    $l->grant('b', 'gb', 100000);
    same('resellers', $l->setGroup('a', 'resellers'));
    foreach ([[$a, 0], [$a, 1], [$b, 1]] as [$key, $source]) {
        $lease = $l->reserve($key, 10, 10, 1000, unitRates(), lim(10), 60, $source)['lease_id'];
        $l->settle($lease, $key, 5, ['model' => 'm', 'prompt' => 3, 'completion' => 2, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    }
    $rows = array_map(static fn (array $r): array => [$r['group'], $r['source'], $r['requests']], $l->stats('all', ['group', 'source']));
    same([['', 'internal', 1], ['resellers', 'external', 1], ['resellers', 'internal', 1]], $rows, 'the lease source is kept with the usage row');
    same(['resellers', ''], [$l->status('a', lim())['key_group'], $l->status('b', lim())['key_group']]);
    foreach (['Resellers', 'a b', str_repeat('x', 65), 'ä'] as $bad) {
        $refused = false;
        try {
            $l->setGroup('a', $bad);
        } catch (\Throwable) {
            $refused = true;
        }
        check($refused, "group refused: $bad");
    }
    same('', $l->setGroup('a', ''), 'an empty group clears it');
}

function testRouteCostIsValidated(): void
{
    $route = static fn (mixed $cost): array => ['upstreams' => ['p' => ['url' => 'https://up.example/v1/chat/completions', 'key_file' => '/nonexistent']],
        'models' => ['m' => ['route' => [['upstream' => 'p', 'model' => 'x', 'cost' => $cost]]] + unitRates()]];
    $ok = new Config(configArrayFor(tempDir(), $route(['input' => 1, 'cached_input' => 0, 'output' => 2])));
    same(['input' => 1, 'cached_input' => 0, 'output' => 2], $ok->routes['m'][0]['cost']);
    foreach ([['input' => 1, 'output' => 2], ['input' => 1, 'cached_input' => 0, 'output' => 2, 'extra' => 1], ['input' => -1, 'cached_input' => 0, 'output' => 2],
        ['input' => '1', 'cached_input' => 0, 'output' => 2], 'cheap'] as $bad) {
        $refused = false;
        try {
            new Config(configArrayFor(tempDir(), $route($bad)));
        } catch (\InvalidArgumentException) {
            $refused = true;
        }
        check($refused, 'cost refused: ' . var_export($bad, true));
    }
}

function testIntegrationCostIsComputedOnlyWhenTheUpstreamReportsNone(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(100000);
        same(200, http('POST', '/v1/chat/completions', msg('costed', $stream), $key)['status']);
        settled($ref);
        $u = lastUsage($ref);
        $expect = intdiv(($u['prompt_tokens'] - $u['cached_tokens']) * 300_000 + $u['cached_tokens'] * 30_000 + $u['completion_tokens'] * 1_200_000 + 999_999, 1_000_000);
        same([$expect, 1], [$u['upstream_cost'], $u['cost_computed']], 'priced from the route entry, flagged as computed');
        check($expect > 0, 'a real cost');

        [$ref2, $key2] = fundedKey(100000);
        same(200, http('POST', '/v1/chat/completions', msg('costed-reported', $stream), $key2)['status']);
        settled($ref2);
        $r = lastUsage($ref2);
        same([100, 0], [$r['upstream_cost'], $r['cost_computed']], "the upstream's own cost wins");
    }
    $row = array_values(array_filter(Harness::get()['ledger']->stats('all', ['model']), static fn (array $r): bool => $r['model'] === 'costed'))[0];
    same(2, $row['computed_cost_requests'], 'stats counts the computed costs');
}

function testMigrateUpgradesASchemaFiveLedger(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v5.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v5.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at, bonus_pct) VALUES (1, 'old', 'h', 7, 1, 0, 50)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, 1, 'grant', 7, 7, 'g')");
    $db->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled, source) VALUES (1, 1, 5, 0, 0, 1, 1)');
    $db->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, upstream_cost) VALUES (0, 1, 1, 'm', 1, 1, 0, 3, 3, 200, 0, 0, 0, 9)");
    unset($db);
    $l = new Ledger("$dir/v5.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v5.sqlite");
    same(12, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same(['0', '0', '9'], array_values(array_map('strval', $db->query('SELECT source, cost_computed, upstream_cost FROM usage')->fetch(\PDO::FETCH_ASSOC))), 'old rows: external, reported cost kept');
    $s = $l->status('old', lim());
    same([7, 50, ''], [$s['balance'], $s['bonus_pct'], $s['key_group']], 'balance and bonus kept, no group');
    same([], $l->verify()['problems'], 'credit log still sums');
}

function testIntegrationAnUnusableAnswerIsFreeButItsUpstreamUsageIsRecorded(): void
{
    // A 2xx with no usable answer: the client gets a 502 and pays nothing, but the upstream billed it, so the usage
    // row keeps the upstream's own counts and cost for cost tracking.
    foreach ([['empty-choices', 0, false], ['empty-choice', 1, false], ['usage-only', 4, true]] as [$model, $completion, $stream]) {
        [$ref, $key] = fundedKey(10000);
        same(502, http('POST', '/v1/chat/completions', msg($model, $stream), $key)['status']);
        same(10000, settled($ref)['balance'], "$model: nothing charged");
        $u = lastUsage($ref);
        same([11, $completion, 100, 0, 200], [$u['prompt_tokens'], $u['completion_tokens'], $u['upstream_cost'], $u['charged'], $u['http_status']], "$model: the upstream's usage is kept");
    }
}

function testHealthBucketsPercentilesAndCounts(): void
{
    [$l, $id, $raw] = ledgerWithHandle(1000000);
    $base = ['model' => 'm', 'cached' => 0, 'aborted' => false, 'estimated' => false, 'attempts' => 1, 'status' => 200, 'stream' => true];
    $rows = [
        // prompt, completion, first byte ms, duration ms, overrides
        [500, 200, 1000, 3000, []],                                                     // lt1k stream, 100 tok/s
        [500, 100, 3000, 5000, []],                                                     // lt1k stream, 50 tok/s
        [500, 0, 2000, 2500, ['estimated' => true]],                                    // lt1k stream, answered, no speed
        [5000, 10, 2000, 2000, ['stream' => false]],                                    // 1k-8k plain
        [50000, 0, null, 600000, ['status' => 504]],                                    // stall: deadline, no first byte, failed
        [200000, 5, 500, 900, ['aborted' => true, 'attempts' => 2]],                    // ge128k hung up, retried
    ];
    foreach ($rows as $i => [$prompt, $completion, $fb, $duration, $over]) {
        $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60)['lease_id'];
        $used = $i === 0 ? 5000 : 5;                                                    // the first one is charged over its reservation
        $l->settle($lease, $id, $used, $over + ['prompt' => $prompt, 'completion' => $completion, 'first_byte_ms' => $fb, 'duration_ms' => $duration] + $base);
        $raw->prepare('UPDATE usage SET ts = ? WHERE lease_id = ?')->execute([86400 + $i, $lease]);
    }
    $h = $l->health(86400, 2 * 86400, 600);
    same(['requests' => 6, 'failed' => 1, 'aborted' => 1, 'estimated' => 1, 'retried' => 1, 'over_reservation' => 1, 'deadline_hits' => 1, 'no_first_byte' => 1],
        $h['counts'], 'counts over the window');
    $by = [];
    foreach ($h['buckets'] as $b) {
        $by[$b['bucket'] . ($b['stream'] ? ':stream' : ':plain')] = $b;
    }
    same(['lt1k:stream', '1k-8k:plain', '32k-128k:stream', 'ge128k:stream'], array_keys($by), 'only buckets with requests, in bucket order');
    same([3, 3, 2000, 3000, 50], [$by['lt1k:stream']['requests'], $by['lt1k:stream']['answered'], $by['lt1k:stream']['first_byte_p50_ms'],
        $by['lt1k:stream']['first_byte_p95_ms'], $by['lt1k:stream']['tokens_per_second_p50']], 'nearest-rank p50/p95 and the median speed');
    same([1, 0, null], [$by['32k-128k:stream']['requests'], $by['32k-128k:stream']['answered'], $by['32k-128k:stream']['first_byte_p95_ms']], 'a stall is counted, never timed');
    same([1, null], [$by['1k-8k:plain']['answered'], $by['1k-8k:plain']['tokens_per_second_p50']], 'plain answers carry no generation speed');
    same(0, $by['ge128k:stream']['answered'], 'a hung-up stream is not an answer');
    same(0, $l->health(0, 86400, 600)['counts']['requests'], 'the window bounds the rows');
    $json = json_encode($h);
    same(false, str_contains($json, '"m"') || str_contains($json, 'ref'), 'no model, ref or key in the output');
}
