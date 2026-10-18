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
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
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
