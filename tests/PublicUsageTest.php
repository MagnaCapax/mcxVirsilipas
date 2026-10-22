<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\PublicUsage;

/** One `stats('hour', ['key', 'model'])` row. */
function puRow(string $bucket, string $key, string $model, int $credits, int $requests, array $extra = []): array
{
    return $extra + ['bucket' => $bucket, 'key' => $key, 'model' => $model, 'credits' => $credits, 'requests' => $requests,
        'prompt_tokens' => 10 * $requests, 'cached_tokens' => 2 * $requests, 'completion_tokens' => 5 * $requests,
        'reasoning_tokens' => $requests, 'failed' => 0, 'retried' => 0, 'mean_first_byte_ms' => 400, 'upstream_cost' => 999,
        'gen_tokens' => 0, 'gen_ms' => 0];
}

function puNow(): int
{
    return gmmktime(10, 30, 0, 10, 18, 2026);   // as_of is 2026-10-18T10:00Z
}

/** @return list<array<string, mixed>> */
function puRows(): array
{
    return [
        puRow('2026-10-18T09', 'cust-101', 'default', 1000, 4, ['failed' => 1, 'retried' => 2, 'mean_first_byte_ms' => 200]),
        puRow('2026-10-18T10', 'cust-101', 'default', 5000, 1),             // the current hour: not complete, left out
        puRow('2026-10-17T11', 'cust-102', 'org/Model-1', 3000, 2),         // 23 h before as_of: in 24h
        puRow('2026-10-17T09', 'cust-103', 'alt', 7000, 3),                 // 25 h back: 7d only
        puRow('2026-10-12T00', 'cust-104', 'large', 100, 1),                // 6 days back
        puRow('2026-09-20T00', 'cust-105', 'large', 50, 1),                 // 28 days back: 30d only
        puRow('2026-09-10T00', 'cust-106', 'large', 9, 1),                  // older than 30 days: out
        puRow('2026-10-18T09', 'test-1', 'default', 80000, 40),             // not the prefix: out
    ];
}

function testPublicUsageWindowsCountCompleteHoursOfPrefixedKeysOnly(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same(2, $f['schema_version']);
    same('2026-10-18T10:00:00+00:00', $f['as_of'], 'as_of is the end of the last complete hour');
    same([4000, 6], [$f['windows']['24h']['credits'], $f['windows']['24h']['requests']], '24h: complete hours, prefixed keys only');
    same([11100, 11150], [$f['windows']['7d']['credits'], $f['windows']['30d']['credits']], '7d and 30d');
    same([60, 6], [$f['windows']['24h']['prompt_tokens'], $f['windows']['24h']['reasoning_tokens']], 'token sums');
    same([round(5 / 6, 4), round(2 / 6, 4)], [$f['windows']['24h']['http_2xx_share'], $f['windows']['24h']['retried_share']], 'shares');
    same(intdiv(200 * 4 + 400 * 2, 6), $f['windows']['24h']['mean_first_byte_ms'], 'first byte weighted by requests');
}

function testPublicUsageListsEveryModelWithTokensAndShares(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same(['alt', 'org/Model-1', 'default', 'large'], array_column($f['models_30d'], 'model'), 'sorted by credits, model names kept');
    $alt = $f['models_30d'][0];
    same([7000, 3, 30, 15, round(7000 / 11150, 4), round(3 / 11, 4), 400],
        [$alt['credits'], $alt['requests'], $alt['prompt_tokens'], $alt['completion_tokens'], $alt['credit_share'], $alt['request_share'], $alt['mean_first_byte_ms']]);
    $h = PublicUsage::build([puRow('2026-10-18T05', 'cust-9', '<b>x</b>', 5, 1)], puNow(), 'cust-');
    same('other', $h['models_30d'][0]['model'], 'a model string outside the name shape is reported as other');
}

function testPublicUsageDailyIsTheLast30DaysWithTopFiveModels(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same(['2026-09-20', '2026-10-18', 29], [$f['daily'][0]['date'], end($f['daily'])['date'], count($f['daily'])], 'from the first day of use to today');
    same(['date' => '2026-09-21', 'credits' => 0, 'requests' => 0, 'prompt_tokens' => 0, 'cached_tokens' => 0,
        'completion_tokens' => 0, 'reasoning_tokens' => 0, 'top_models' => []], $f['daily'][1], 'a day without use is zeros');
    $six = [];
    foreach (['m1' => 60, 'm2' => 50, 'm3' => 40, 'm4' => 30, 'm5' => 20, 'm6' => 10] as $m => $c) {
        $six[] = puRow('2026-10-18T05', 'cust-200', $m, $c, 1);
    }
    $six[] = puRow('2026-09-18T05', 'cust-201', 'm1', 999, 1);   // the 31st day back
    $g = PublicUsage::build($six, puNow(), 'cust-');
    same(['m1', 'm2', 'm3', 'm4', 'm5'], array_column(end($g['daily'])['top_models'], 'model'), 'top 5 models per day');
    same(['2026-10-18'], array_column($g['daily'], 'date'), 'nothing older than the last 30 UTC days');
}

function testPublicUsageHourlyAndHeatmapAreDense(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same(168, count($f['hourly_7d']), 'every hour of the last 7 days');
    $last = end($f['hourly_7d']);
    same(['2026-10-18T09', 1000, 1000], [$last['hour'], $last['credits'], $last['models']['default']]);
    same(0, $last['models']['other']);
    same(168, count($f['heatmap_30d']), '7 weekdays x 24 hours');
    $sun = array_values(array_filter($f['heatmap_30d'], static fn (array $c): bool => $c['weekday'] === 7 && $c['hour'] === 9));
    same(1000, $sun[0]['credits'], '2026-10-18 is a Sunday (ISO 7), 09 UTC');
}

function testPublicUsageCarriesNoRefCostOrCountAndRefusesARefLeak(): void
{
    $json = PublicUsage::encode(PublicUsage::build(puRows(), puNow(), 'cust-'), ['cust-101', 'test-1']);
    foreach (['cust-', 'test-1', 'upstream', '999', '"key"'] as $absent) {
        check(!str_contains($json, $absent), "public usage carries $absent");
    }
    $refused = false;
    try {
        PublicUsage::encode(['model' => 'cust-101'], ['cust-101']);
    } catch (\RuntimeException) {
        $refused = true;
    }
    check($refused, 'an output carrying a key ref is refused');
    $empty = false;
    try {
        PublicUsage::build([], puNow(), '');
    } catch (\InvalidArgumentException) {
        $empty = true;
    }
    check($empty, 'an empty prefix (everyone) is refused');
    $none = PublicUsage::build([], puNow(), 'cust-');
    same([0, null, [], []], [$none['windows']['30d']['credits'], $none['windows']['30d']['http_2xx_share'], $none['daily'], $none['models_30d']]);
}

function testPublicUsageBuildsFromRealLedgerStats(): void
{
    [$l, $id, ] = ledgerWithHandle(100000);   // key "a"
    $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10), 60)['lease_id'];
    $l->settle($lease, $id, 5, ['model' => 'default', 'prompt' => 3, 'completion' => 2, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    $rows = $l->stats('hour', ['key', 'model'], time() - 86400, null);
    same('a', $rows[0]['key'], 'the stats key group is the ref');
    // The usage row is in the current, incomplete hour; one hour later it counts.
    $later = PublicUsage::build($rows, time() + 3600, 'a');
    same([5, 1], [$later['windows']['24h']['credits'], $later['windows']['24h']['requests']]);
}

function testPublicUsageCliPrintsAndWritesAWorldReadableFile(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $run = static function (string ...$args) use ($dir): array {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    $run('init');
    $run('key-create', 'cust-1');
    [$code, $out] = $run('public-usage', 'cust-');
    same(0, $code, $out);
    same(2, json_decode($out, true)['schema_version'] ?? null, $out);
    check(!str_contains($out, 'cust-1'), 'no ref printed');
    [$code, $out] = $run('public-usage', 'cust-', "$dir/usage.json");
    same(0, $code, $out);
    same(0644, fileperms("$dir/usage.json") & 0777, 'readable by the web server despite the private umask');
    same(2, json_decode((string) file_get_contents("$dir/usage.json"), true)['schema_version'] ?? null);
    [$code, ] = $run('public-usage');
    same(1, $code, 'no prefix: refused');
}

function testPublicUsageGenerationSpeedIsTokenWeightedAndNeedsAMinuteOfMeasuredTime(): void
{
    $rows = [
        puRow('2026-10-17T05', 'cust-1', 'fast', 100, 10, ['gen_tokens' => 2000, 'gen_ms' => 20000]),
        puRow('2026-10-17T06', 'cust-2', 'fast', 100, 10, ['gen_tokens' => 4000, 'gen_ms' => 40000]),
        puRow('2026-10-17T07', 'cust-1', 'short', 50, 2, ['gen_tokens' => 900, 'gen_ms' => 59999]),
        puRow('2026-10-17T08', 'cust-1', 'none', 10, 1),
    ];
    $speed = array_column(PublicUsage::build($rows, puNow(), 'cust-')['models_30d'], 'generation_tokens_per_second', 'model');
    same(['fast' => 100.0, 'short' => null, 'none' => null], $speed, 'summed over hours: 6000 tokens in 60 s; under a minute of measured time: none');
    $fast = PublicUsage::build($rows, puNow(), 'cust-')['models_30d'][0];
    same([6000, 60000], [$fast['gen_tokens'], $fast['gen_ms']], 'the sums are published, so a reader can pool aliases');
}

function testPublicUsageRollingSumsAndHeatmapsFollowEachWindow(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same([168, 168, 30], [count($f['rolling_24h']), count($f['rolling_7d']), count($f['rolling_30d'])], 'hourly points over 7 days, daily over 30');
    foreach (['24h', '7d', '30d'] as $w) {
        $r = $f['rolling_' . $w];
        same('2026-10-18T09', end($r)['hour'], "$w: the last point names the last complete hour");
        same($f['windows'][$w]['credits'], end($r)['credits'], "$w: the last rolling point is the window's own total");
        same($f['windows'][$w]['credits'], array_sum(array_column($f['heatmap_' . $w], 'credits')), "$w: the heatmap adds up to the window");
        same(168, count($f['heatmap_' . $w]), "$w: a dense 7 x 24 heatmap");
    }
    same('2026-10-11T10', $f['rolling_7d'][0]['hour'], '7d points start 167 hours before the last');
    same(['2026-09-19T09', 9], [$f['rolling_30d'][0]['hour'], $f['rolling_30d'][0]['credits']],
        'the first 30-day point reaches back 30 more days: the row 38 days old counts there');
    same(['2026-10-18T08', 7000 + 3000], [$f['rolling_24h'][166]['hour'], $f['rolling_24h'][166]['credits']],
        'one hour earlier the 24 hours reach back to 2026-10-17T09 (alt) and stop before the 09:00 hour');
    same([], array_filter(PublicUsage::build([], puNow(), 'cust-')['rolling_7d'], static fn (array $p): bool => $p['credits'] !== 0), 'no use: all zeros');
}

function testPublicUsageListsModelsPerWindow(): void
{
    $f = PublicUsage::build(puRows(), puNow(), 'cust-');
    same(['org/Model-1', 'default'], array_column($f['models_24h'], 'model'), '24h: only the models used in the last 24 complete hours');
    same([3000, round(3000 / 4000, 4)], [$f['models_24h'][0]['credits'], $f['models_24h'][0]['credit_share']], 'shares of the same window');
    same(['alt', 'org/Model-1', 'default', 'large'], array_column($f['models_7d'], 'model'));
    same([100, 150], [$f['models_7d'][3]['credits'], $f['models_30d'][3]['credits']], 'large: 6 days back in 7d, plus 28 days back in 30d');
    same([], PublicUsage::build([], puNow(), 'cust-')['models_24h'], 'no use: an empty list');
}
