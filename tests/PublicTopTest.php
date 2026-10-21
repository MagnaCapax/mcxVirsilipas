<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\PublicTop;

/** One `stats('hour', ['key', 'model'])` row, credits and requests only matter here. */
function ptRow(string $bucket, string $key, int $credits, int $requests): array
{
    return ['bucket' => $bucket, 'key' => $key, 'model' => 'default', 'credits' => $credits, 'requests' => $requests,
        'upstream_cost' => 999];
}

function ptNow(): int
{
    return gmmktime(10, 30, 0, 10, 18, 2026);   // as_of is 2026-10-18T10:00Z
}

/** @return array<string, string> ref => digest of the current key */
function ptHashes(): array
{
    $h = [];
    foreach (['cust-1', 'cust-2', 'cust-3', 'cust-4', 'test-1'] as $ref) {
        $h[$ref] = hash('sha256', 'key-of-' . $ref);
    }
    return $h;
}

function testPublicTopIdIsTheDocumentedRecipe(): void
{
    // The recipe a key's owner runs (ADR 0026), with coreutils sha256sum instead of PHP: it must give the same id.
    $recipe = 'k=$(printf %s "$KEY" | sha256sum | cut -d" " -f1); h=$(printf %s "$REF:$k" | sha256sum | cut -d" " -f1); '
        . 'for i in $(seq 2 1000); do h=$(printf %s "$h" | sha256sum | cut -d" " -f1); done; printf %s "$h"';
    $p = proc_open(['bash', '-c', $recipe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['KEY' => 'vsk-example-key', 'REF' => 'cust-1', 'PATH' => getenv('PATH')]);
    $shell = (string) stream_get_contents($pipes[1]);
    same(0, proc_close($p), 'the shell recipe runs');
    same(64, strlen($shell));
    same($shell, PublicTop::id('cust-1', hash('sha256', 'vsk-example-key')), 'PHP and the published shell recipe agree');
    same(1000, PublicTop::ROUNDS);
    check(PublicTop::id('cust-1', 'x') !== PublicTop::id('cust-2', 'x'), 'the ref is part of the id');
}

function testPublicTopRanksPrefixedKeysPerWindowByCredits(): void
{
    $rows = [
        ptRow('2026-10-18T09', 'cust-1', 100, 1),
        ptRow('2026-10-18T10', 'cust-1', 50000, 9),    // the current hour: not complete, left out
        ptRow('2026-10-17T11', 'cust-2', 300, 2),      // 23 h before as_of: in 24h
        ptRow('2026-10-17T09', 'cust-3', 700, 3),      // 25 h back: 7d and 30d
        ptRow('2026-09-20T00', 'cust-4', 5000, 1),     // 28 days back: 30d only
        ptRow('2026-09-10T00', 'cust-1', 90000, 1),    // older than 30 days: out
        ptRow('2026-10-18T09', 'test-1', 80000, 40),   // not the prefix: out
    ];
    $h = ptHashes();
    $t = PublicTop::build($rows, $h, ptNow(), 'cust-');
    same(1, $t['schema_version']);
    same('2026-10-18T10:00:00+00:00', $t['as_of']);
    $id = static fn (string $ref): string => PublicTop::id($ref, $h[$ref]);
    same([$id('cust-2'), $id('cust-1')], array_column($t['windows']['24h'], 'id'), '24h by credits');
    same([1, 2], array_column($t['windows']['24h'], 'rank'));
    same([300, 2, round(300 / 400, 4)], [$t['windows']['24h'][0]['credits'], $t['windows']['24h'][0]['requests'], $t['windows']['24h'][0]['credit_share']]);
    same([$id('cust-3'), $id('cust-2'), $id('cust-1')], array_column($t['windows']['7d'], 'id'));
    same([$id('cust-4'), $id('cust-3'), $id('cust-2'), $id('cust-1')], array_column($t['windows']['30d'], 'id'));
    same(['rank', 'id', 'credits', 'requests', 'credit_share'], array_keys($t['windows']['30d'][0]), 'nothing else per entry');
}

function testPublicTopListsTenAndLeavesOutRemovedKeysButCountsThemInTheShare(): void
{
    $rows = [];
    $h = [];
    for ($i = 1; $i <= 12; $i++) {
        $rows[] = ptRow('2026-10-18T08', "cust-$i", $i * 10, 1);
        $h["cust-$i"] = hash('sha256', "k$i");
    }
    unset($h['cust-12']);                               // removed: no digest left
    $t = PublicTop::build($rows, $h, ptNow(), 'cust-');
    same(10, count($t['windows']['24h']), 'ten at most');
    same(PublicTop::id('cust-11', $h['cust-11']), $t['windows']['24h'][0]['id'], 'the removed key is not listed');
    same(round(110 / 780, 4), $t['windows']['24h'][0]['credit_share'], 'its credits stay in the window total');
    $tie = PublicTop::build([ptRow('2026-10-18T08', 'cust-1', 5, 1), ptRow('2026-10-18T08', 'cust-2', 5, 1)], $h, ptNow(), 'cust-');
    same(2, count($tie['windows']['24h']), 'ties are both listed');
    same([], PublicTop::build([], $h, ptNow(), 'cust-')['windows']['30d'], 'no use: an empty list');
}

function testPublicTopCarriesNoRefOrDigestAndRefusesALeak(): void
{
    $h = ptHashes();
    $t = PublicTop::build([ptRow('2026-10-18T09', 'cust-1', 100, 1)], $h, ptNow(), 'cust-');
    $json = PublicTop::encode($t, array_keys($h), $h);
    foreach (['cust-', 'test-1', '999', 'upstream', $h['cust-1']] as $absent) {
        check(!str_contains($json, $absent), "public top carries $absent");
    }
    foreach ([['x' => 'cust-1'], ['x' => 'a' . $h['cust-2']]] as $leak) {
        $refused = false;
        try {
            PublicTop::encode($leak, array_keys($h), $h);
        } catch (\RuntimeException) {
            $refused = true;
        }
        check($refused, 'an output carrying a ref or a key digest is refused');
    }
    $empty = false;
    try {
        PublicTop::build([], $h, ptNow(), '');
    } catch (\InvalidArgumentException) {
        $empty = true;
    }
    check($empty, 'an empty prefix (everyone) is refused');
}

function testPublicTopFromARealLedgerAndTheCli(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $run = static function (string ...$args) use ($dir): array {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    };
    $run('init');
    [, $key] = $run('key-create', 'cust-1');
    $run('key-create', 'cust-2');
    $run('key-remove', 'cust-2');
    $l = new Ledger(configArrayFor($dir)['db_path'] ?? "$dir/ledger.sqlite");
    same(['cust-1' => hash('sha256', trim($key))], $l->keyHashes('cust-'), 'current digests of the prefixed keys; a removed key has none');
    [$code, $out] = $run('public-top', 'cust-', "$dir/top.json");
    same(0, $code, $out);
    same(0644, fileperms("$dir/top.json") & 0777, 'readable by the web server');
    $t = json_decode((string) file_get_contents("$dir/top.json"), true);
    same([1, []], [$t['schema_version'] ?? null, $t['windows']['24h'] ?? null], 'no use yet: empty lists');
    [$code, ] = $run('public-top');
    same(1, $code, 'no prefix: refused');
}
