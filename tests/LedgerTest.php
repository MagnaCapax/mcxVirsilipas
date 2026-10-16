<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Ledger;

/** @return array{0: Ledger, 1: int} a ledger and the id of a key 'a' funded with $tokens */
function fundedLedger(int $tokens): array
{
    $l = freshLedger();
    $k = $l->createKey('a');
    if ($tokens !== 0) {
        $l->grant('a', 'g0', $tokens);
    }
    return [$l, $l->findKey($k)['id']];
}

function meta(): array
{
    return ['model' => 'm', 'prompt' => 1, 'completion' => 1, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false];
}

function testGrantIsAppliedOncePerGrantId(): void
{
    $l = freshLedger();
    $l->createKey('a');
    same(['applied' => true, 'balance' => 500], $l->grant('a', 'inv-1', 500));
    same(['applied' => false, 'balance' => 500], $l->grant('a', 'inv-1', 500), 'retry of the same grant');
    same(['applied' => true, 'balance' => 800], $l->grant('a', 'sub-2026-10', 300), 'subscription adds to the same balance');
    same(['applied' => true, 'balance' => 700], $l->grant('a', 'refund-1', -100), 'negative grant');
}

function testGrantIdReusedForSomethingElseIsAConflict(): void
{
    $l = freshLedger();
    $l->createKey('a');
    $l->createKey('b');
    $l->grant('a', 'inv-1', 500);
    same(409, throwsHttp(fn () => $l->grant('b', 'inv-1', 500))[0], 'other ref');
    same(409, throwsHttp(fn () => $l->grant('a', 'inv-1', 999))[0], 'other amount');
    same(0, $l->status('b', lim())['balance']);
}

function testGrantRejectsZeroOutOfRangeAndUnknownRef(): void
{
    [$l] = fundedLedger(0);
    same(400, throwsHttp(fn () => $l->grant('a', 'g', 0))[0]);
    same(400, throwsHttp(fn () => $l->grant('a', 'g', PHP_INT_MAX))[0]);
    same(404, throwsHttp(fn () => $l->grant('nobody', 'g', 5))[0]);
    for ($i = 0; $i < 1000; $i++) {
        try {
            $l->grant('a', "big-$i", Ledger::MAX_GRANT);
        } catch (\MagnaCapax\Virsilipas\HttpError $e) {
            same(400, $e->status, 'balance range');
            break;
        }
    }
    check($l->status('a', lim())['balance'] <= 1_000_000_000_000_000_000, 'balance stayed in range');
}

function testDuplicateRefIsRefused(): void
{
    [$l] = fundedLedger(0);
    same(409, throwsHttp(fn () => $l->createKey('a'))[0]);
}

function testKeyIsStoredHashedAndFound(): void
{
    $l = freshLedger();
    $key = $l->createKey('a');
    check(str_starts_with($key, 'vsk-') && strlen($key) === 52, 'key format');
    same('a', $l->findKey($key)['ref'] ?? null);
    same(null, $l->findKey($key . 'x'));
}

function testRotatedKeyReplacesTheOldOneAndKeepsTheBalance(): void
{
    [$l, $id] = fundedLedger(100);
    $old = $l->createKey('b');
    $new = $l->rotateKey('b');
    check($new !== $old && str_starts_with($new, 'vsk-') && strlen($new) === 52, 'a new key in the same format');
    same(null, $l->findKey($old), 'the old key stops working at once');
    same('b', $l->findKey($new)['ref'] ?? null);
    $a = $l->findKey($l->rotateKey('a'));
    same([$id, 100], [$a['id'] ?? null, $a['balance'] ?? null], 'the same key row: balance and history stay with the ref');
    same(404, throwsHttp(fn () => $l->rotateKey('nobody'))[0]);
}

function testReserveClampsMaxTokensToBalance(): void
{
    [$l, $id] = fundedLedger(150);
    $r = $l->reserve($id, 50, 1000, 4000, unitRates(), lim(4), 60);
    same(100, $r['max_tokens'], 'clamped to balance minus prompt bound');
    same(150, $r['reserved']);
    same(['ref' => 'a', 'balance' => 0, 'active' => true, 'in_flight' => 1, 'held' => 0, 'reserved' => 150], array_slice($l->status('a', lim()), 0, 6));
    [$l2, $id2] = fundedLedger(100000);
    same(4000, $l2->reserve($id2, 50, null, 4000, unitRates(), lim(4), 60)['max_tokens'], 'no max_tokens requested: the cap applies');
}

function testReserveRefusesWhenTooLow(): void
{
    [$l, $id] = fundedLedger(40);
    same(402, throwsHttp(fn () => $l->reserve($id, 40, null, 1000, unitRates(), lim(4), 60))[0], 'prompt bound alone uses the balance');
}

function testSettleChargesReportedRefundsRestAndWritesOneUsageRow(): void
{
    [$l, $id] = fundedLedger(1000);
    $r = $l->reserve($id, 20, 200, 1000, unitRates(), lim(4), 60);
    same(780, $l->status('a', lim())['balance']);
    same(true, $l->settle($r['lease_id'], $id, 57, meta()));
    same(943, $l->status('a', lim())['balance'], '1000 - 57');
    same(false, $l->settle($r['lease_id'], $id, 57, meta()), 'second settle is a no-op');
    same(943, $l->status('a', lim())['balance']);
    same(0, $l->status('a', lim())['in_flight']);
}

function testChargeNeverExceedsTheReservation(): void
{
    [$l, $id] = fundedLedger(100);
    $r = $l->reserve($id, 10, 90, 1000, unitRates(), lim(4), 60);
    $l->settle($r['lease_id'], $id, 10000, meta());
    same(0, $l->status('a', lim())['balance'], 'over-reported usage is capped; the balance never goes negative');
}

function testConcurrencyLimitPerKey(): void
{
    [$l, $id] = fundedLedger(100000);
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(2), 60);
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(2), 60);
    same(429, throwsHttp(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(2), 60))[0]);
}

function testExpiredLeaseStaysChargedAndALateSettleCorrectsItOnce(): void
{
    [$l, $id] = fundedLedger(100);
    $r = $l->reserve($id, 10, 50, 1000, unitRates(), lim(1), 1); // reserves 60, lease lives 1 s, limit 1 in flight
    sleep(2);
    same(['ref' => 'a', 'balance' => 40, 'active' => true, 'in_flight' => 0, 'held' => 1, 'reserved' => 60], array_slice($l->status('a', lim()), 0, 6), 'expired: not in flight, still charged, counted as held');
    $r2 = $l->reserve($id, 10, 20, 1000, unitRates(), lim(1), 60); // the dead lease no longer blocks concurrency
    same(10, $l->status('a', lim())['balance']);
    same(true, $l->settle($r['lease_id'], $id, 15, meta()), 'late settle of the dead lease');
    same(55, $l->status('a', lim())['balance'], '60 reserved, 15 charged, 45 returned');
    same(0, $l->status('a', lim())['held'], 'no longer held once settled');
    same(false, $l->settle($r['lease_id'], $id, 15, meta()));
    same(55, $l->status('a', lim())['balance']);
    unset($r2);
}

function testDisabledKeyIsRefusedAndCanBeReenabled(): void
{
    [$l, $id] = fundedLedger(100);
    $l->setActive('a', false);
    same(403, throwsHttp(fn () => $l->reserve($id, 1, 1, 1000, unitRates(), lim(4), 60))[0]);
    $l->setActive('a', true);
    same(1, $l->reserve($id, 1, 1, 1000, unitRates(), lim(4), 60)['max_tokens']);
    same(404, throwsHttp(fn () => $l->setActive('nobody', true))[0]);
}

function testWorldReadableDatabaseIsRefused(): void
{
    $path = tempDir() . '/ledger.sqlite';
    (new Ledger($path))->migrate();
    chmod($path, 0644);
    try {
        new Ledger($path);
        throw new \RuntimeException('opened a world-readable database');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'other users'), 'refusal names the problem');
    }
}

function testReadableWalOrWorldWritableDirectoryIsRefused(): void
{
    $dir = tempDir();
    (new Ledger("$dir/ledger.sqlite"))->migrate();
    touch("$dir/ledger.sqlite-wal");
    chmod("$dir/ledger.sqlite-wal", 0644);
    same(true, str_contains(throwsRuntime(fn () => new Ledger("$dir/ledger.sqlite")), 'accessible'), 'readable WAL');
    chmod("$dir/ledger.sqlite-wal", 0600);
    chmod($dir, 0777);
    same(true, str_contains(throwsRuntime(fn () => new Ledger("$dir/ledger.sqlite")), 'writable'), 'world-writable directory');
    chmod($dir, 0770);
    same(true, str_contains(throwsRuntime(fn () => new Ledger("$dir/ledger.sqlite")), 'writable'), 'group-writable directory');
    chmod($dir, 0700);
}

function testNewDatabaseFilesArePrivateUnderAPermissiveUmask(): void
{
    $dir = tempDir();
    chmod($dir, 0755);
    $old = umask(0022);
    try {
        $l = new Ledger("$dir/ledger.sqlite");
        $l->migrate();
        $l->createKey('a'); // a write, so the WAL and shared-memory files exist
        foreach (['ledger.sqlite', 'ledger.sqlite-wal', 'ledger.sqlite-shm'] as $f) {
            same('600', decoct(fileperms("$dir/$f") & 0777), "$f is private");
        }
        same(0022, umask(), 'the caller umask is restored');
    } finally {
        umask($old);
    }
}

function throwsRuntime(callable $fn): string
{
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
    throw new \RuntimeException('expected a refusal');
}

/** @return array{0: Ledger, 1: int, 2: \PDO} a ledger, the id of key 'a' funded with $credits, and a raw handle to the same file */
function ledgerWithHandle(int $credits): array
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $k = $l->createKey('a');
    $l->grant('a', 'g0', $credits);
    return [$l, $l->findKey($k)['id'], new \PDO('sqlite:' . $path)];
}

/** @return array{0: int, 1: string, 2: ?int} [status, message, retryAfter] */
function refusal(callable $fn): array
{
    try {
        $fn();
    } catch (\MagnaCapax\Virsilipas\HttpError $e) {
        return [$e->status, $e->getMessage(), $e->retryAfter];
    }
    throw new \RuntimeException('expected an HttpError');
}

function testReservePricesThePromptAtTheInputRateAndOutputAtTheOutputRate(): void
{
    $w = ['input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
    [$l, $id] = fundedLedger(10000);
    $r = $l->reserve($id, 100, 1000, 4000, $w, lim(), 60);
    same([1000, 8200], [$r['max_tokens'], $r['reserved']], '100 prompt bytes x 2 + 1000 tokens x 8');
    [$l, $id] = fundedLedger(1000);
    $r = $l->reserve($id, 100, null, 4000, $w, lim(), 60);
    same([100, 1000], [$r['max_tokens'], $r['reserved']], '800 credits left after the prompt buy 100 output tokens');
    [$l, $id] = fundedLedger(207);
    same(402, refusal(fn () => $l->reserve($id, 100, null, 4000, $w, lim(), 60))[0], '7 credits left: not one output token');
    same('credit balance too low for this request', refusal(fn () => $l->reserve($id, 100, null, 4000, $w, lim(), 60))[1]);
    [$l, $id] = fundedLedger(1);
    $r = $l->reserve($id, 50, null, 1000, ['input' => 1, 'cached_input' => 1, 'output' => 1], lim(), 60);
    same([1000, 1], [$r['max_tokens'], $r['reserved']], 'one credit: the whole request costs one credit, rounded once');
}

function testRequestsPerMinuteLimit(): void
{
    [$l, $id, $db] = ledgerWithHandle(10000);
    foreach ([1, 2] as $n) {
        $r = $l->reserve($id, 10, 10, 1000, unitRates(), lim(10, 2), 60);
        $l->settle($r['lease_id'], $id, 5, meta());
    }
    [$status, $msg, $retry] = refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(10, 2), 60));
    same([429, 'requests per minute limit reached for this key'], [$status, $msg]);
    check($retry >= 1 && $retry <= 60, "Retry-After $retry within the minute");
    same(10000 - 10, $l->status('a', lim())['balance'], 'a refused request reserves nothing');
    $db->exec('UPDATE leases SET created_at = created_at - 61');
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(10, 2), 60);
    same(1, $l->status('a', lim(10, 2))['window']['requests'], 'requests older than a minute no longer count');
}

function testCreditsPerMinuteCountsChargedOrStillReserved(): void
{
    [$l, $id] = fundedLedger(10000);
    $a = $l->reserve($id, 10, 50, 1000, unitRates(), lim(10, null, 100), 60); // holds 60
    $b = $l->reserve($id, 10, 50, 1000, unitRates(), lim(10, null, 100), 60); // window 60 < 100: admitted, now 120
    [$status, $msg] = refusal(fn () => $l->reserve($id, 10, 50, 1000, unitRates(), lim(10, null, 100), 60));
    same([429, 'credits per minute limit reached for this key'], [$status, $msg]);
    same(['requests' => 2, 'credits' => 120], $l->status('a', lim())['window']);
    $l->settle($a['lease_id'], $id, 15, meta());
    $l->settle($b['lease_id'], $id, 15, meta());
    same(['requests' => 2, 'credits' => 30], $l->status('a', lim())['window'], 'settled requests count what they were charged');
    same(429, refusal(fn () => $l->reserve($id, 10, 50, 1000, unitRates(), lim(10, null, 30), 60))[0], 'a window at the limit is full');
    $l->reserve($id, 10, 50, 1000, unitRates(), lim(10, null, 31), 60);
}

function testPerKeyLimitsOverrideTheDefaultsBothWays(): void
{
    [$l, $id] = fundedLedger(10000);
    same(['max_concurrent' => 1, 'requests_per_minute' => null, 'credits_per_minute' => null], $l->setLimits('a', ['max_concurrent' => 1]));
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(4), 60);
    same(429, refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(4), 60))[0], 'lowered below the default');
    $l->setLimits('a', ['max_concurrent' => 3]);
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(1), 60);
    $l->reserve($id, 10, 10, 1000, unitRates(), lim(1), 60);
    same(429, refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(1), 60))[0], 'raised above the default, up to its own value');
    same(['max_concurrent' => null, 'requests_per_minute' => 7, 'credits_per_minute' => null], $l->setLimits('a', ['max_concurrent' => null, 'requests_per_minute' => 7]));
    same(['max_concurrent' => 4, 'requests_per_minute' => 7, 'credits_per_minute' => 9], $l->status('a', lim(4, 5, 9))['limits'], 'null follows the default; a set value wins');
    same(['max_concurrent' => null, 'requests_per_minute' => 7, 'credits_per_minute' => null], $l->setLimits('a', []), 'nothing given: nothing changed');
}

function testSetLimitsValidation(): void
{
    $l = freshLedger();
    $l->createKey('a');
    foreach ([['max_concurrent' => 0], ['requests_per_minute' => -1], ['credits_per_minute' => 1_000_000_000_000_001], ['max_concurrent' => '5'],
        ['max_concurrent' => true], ['max_concurrent' => 1.5], ['balance' => 5], ['max_concurrent = 1, balance' => 5], [0 => 5]] as $bad) {
        same(400, refusal(fn () => $l->setLimits('a', $bad))[0], json_encode($bad));
    }
    same(404, refusal(fn () => $l->setLimits('nobody', ['max_concurrent' => 1]))[0]);
    same(['max_concurrent' => null, 'requests_per_minute' => null, 'credits_per_minute' => null], $l->setLimits('a', []), 'nothing was changed by the refusals');
}

function testStatusShowsHoldsAndTheWindow(): void
{
    [$l, $id] = fundedLedger(1000);
    $l->reserve($id, 10, 40, 1000, unitRates(), lim(), 60);
    $s = $l->status('a', lim(4, 100, 5000));
    same([950, 50, 1, ['requests' => 1, 'credits' => 50]], [$s['balance'], $s['reserved'], $s['in_flight'], $s['window']]);
    same(['max_concurrent' => 4, 'requests_per_minute' => 100, 'credits_per_minute' => 5000], $s['limits']);
}

function testMigrateRefusesALedgerFromAnotherVersion(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $old = new \PDO('sqlite:' . $path);
    $old->exec('CREATE TABLE keys (id INTEGER PRIMARY KEY, ref TEXT, key_hash TEXT, balance INTEGER, active INTEGER, created_at INTEGER)');
    $old = null;
    chmod($path, 0600);
    check(str_contains(throwsRuntime(fn () => (new Ledger($path))->migrate()), 'another version'), 'old schema refused');
    $l = freshLedger();
    $l->migrate(); // a current ledger: re-running init is safe
    $l->createKey('b');
}

/** @return list<array{0: string, 1: int, 2: int, 3: string}> history rows as [kind, delta, balance_after, cause] */
function changes(Ledger $l, string $ref, int $limit = 100): array
{
    return array_map(fn (array $r) => [$r['kind'], $r['delta'], $r['balance_after'], $r['ref']], $l->history($ref, $limit));
}

function testCreditLogRecordsEveryChangeWithTheBalanceAfterIt(): void
{
    $l = freshLedger();
    $id = $l->findKey($l->createKey('a'))['id'];
    $l->grant('a', 'inv-1', 1000);                                    // +1000 -> 1000
    $r1 = $l->reserve($id, 20, 200, 1000, unitRates(), lim(4), 60);   // -(20 + 200) -> 780
    $l->settle($r1['lease_id'], $id, 57, meta());                     // +(220 - 57) -> 943
    $l->grant('a', 'inv-1', 1000);                                    // retry: no change, no row
    $l->grant('a', 'refund-1', -100);                                 // -100 -> 843
    $r2 = $l->reserve($id, 10, 90, 1000, unitRates(), lim(4), 60);    // -(10 + 90) -> 743
    $l->settle($r2['lease_id'], $id, 10000, meta());                  // all 100 used: nothing returned, no row
    same([
        ['reserve', -100, 743, 'lease:' . $r2['lease_id']],
        ['grant', -100, 843, 'refund-1'],
        ['refund', 163, 943, 'lease:' . $r1['lease_id']],
        ['reserve', -220, 780, 'lease:' . $r1['lease_id']],
        ['grant', 1000, 1000, 'inv-1'],
    ], changes($l, 'a'), 'newest first');
    same(743, $l->status('a', lim())['balance']);
    same(2, count($l->history('a', 2)), 'limit');
    same(['keys' => 1, 'rows' => 5, 'problems' => []], $l->verify());
    same(400, throwsHttp(fn () => $l->history('a', 0))[0]);
    same(404, throwsHttp(fn () => $l->history('nobody'))[0]);
}

function testVerifyCatchesABalanceChangedOutsideTheLedger(): void
{
    [$l, , $raw] = ledgerWithHandle(500);
    $raw->exec('UPDATE keys SET balance = 999');
    same(['key a: balance 999 but logged changes sum to 500'], $l->verify()['problems']);
}

function testVerifyCatchesABrokenRunningBalance(): void
{
    [$l, $id, $raw] = ledgerWithHandle(500);
    // A forged row whose delta makes the sum right (500 + 10 = 510) but whose balance_after is wrong.
    $raw->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, $id, 'grant', 10, 5, 'forged')");
    $raw->exec('UPDATE keys SET balance = 510');
    same(['key a: credit_log row 2 says balance_after 5 but the changes up to it sum to 510'], $l->verify()['problems']);
}

function testCreditLogRefusesUpdateAndDelete(): void
{
    [, , $raw] = ledgerWithHandle(500);
    foreach (['UPDATE credit_log SET delta = 1', 'DELETE FROM credit_log'] as $sql) {
        try {
            $raw->exec($sql);
            check(false, "$sql was allowed");
        } catch (\PDOException $e) {
            check(str_contains($e->getMessage(), 'append-only'), "$sql refused");
        }
    }
    same('1', (string) $raw->query('SELECT COUNT(*) FROM credit_log')->fetchColumn(), 'the row is still there');
}

function testMigrateUpgradesASchemaThreeLedgerKeepingItsUsageRows(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $old = new \PDO('sqlite:' . $path);
    $old->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v3.sql'));
    $old->exec("INSERT INTO keys (id, ref, key_hash, balance, created_at) VALUES (1, 'a', 'h1', 90, 0)");
    $old->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (0, 1, 'grant', 100, 100, 'g'), (0, 1, 'reserve', -10, 90, 'lease:1')");
    $old->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at, settled) VALUES (1, 1, 10, 0, 0, 1)');
    $old->exec("INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation) VALUES (86400, 1, 1, 'm', 7, 3, 2, 10, 10, 200, 0, 0, 0)");
    $old = null;
    chmod($path, 0600);

    $l = new Ledger($path);
    $l->migrate();
    $db = new \PDO('sqlite:' . $path);
    same(8, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same(['', 0, 0, 0, null], array_values($db->query('SELECT upstream, stream, duration_ms, reasoning_tokens, upstream_cost FROM usage')->fetch(\PDO::FETCH_NUM)), 'the old row gets the defaults');
    same([], $l->verify()['problems'], 'balances untouched');
    same(2, (int) $db->query('SELECT COUNT(*) FROM credit_log')->fetchColumn(), 'no opening rows from schema 3');
    $l->migrate(); // re-running init adds nothing
    same(1, (int) $db->query('SELECT COUNT(*) FROM usage')->fetchColumn());
    same([['bucket' => '1970-01-02', 'model' => 'm', 'requests' => 1, 'prompt_tokens' => 7, 'cached_tokens' => 2, 'completion_tokens' => 3, 'reasoning_tokens' => 0,
        'credits' => 10, 'upstream_cost' => null, 'costed_requests' => 0, 'computed_cost_requests' => 0, 'aborted' => 0, 'estimated' => 0, 'failed' => 0, 'mean_duration_ms' => 0, 'delayed' => 0, 'mean_delay_ms' => 0, 'mean_first_byte_ms' => null, 'retried' => 0]], $l->stats('day', ['model']));
}

function testStatsGroupsUsageByTimeAndDimension(): void
{
    [$l, $id, $raw] = ledgerWithHandle(100000);
    $rows = [ // ts, model, upstream, status, prompt, cached, completion, reasoning, cost, duration, aborted, estimated
        [3600 * 24 * 10 + 60, 'a', 'up1', 200, 100, 40, 10, 4, 500, 1000, false, false],
        [3600 * 24 * 10 + 7200, 'a', 'up2', 200, 200, 0, 20, 0, null, 3000, true, false],
        [3600 * 24 * 10 + 7300, 'b', 'up1', 502, 0, 0, 0, 0, null, 50, false, false],
        [3600 * 24 * 11, 'a', 'up1', 200, 10, 0, 5, 1, 70, 200, false, true],
    ];
    foreach ($rows as $i => [$ts, $model, $up, $status, $p, $c, $o, $r, $cost, $ms, $ab, $est]) {
        $lease = $l->reserve($id, 300, 1000, 1000, unitRates(), lim(10), 60)['lease_id']; // 1300 held, more than any row uses
        $l->settle($lease, $id, $p + $o, ['model' => $model, 'prompt' => $p, 'completion' => $o, 'cached' => $c, 'status' => $status, 'aborted' => $ab, 'estimated' => $est,
            'upstream' => $up, 'stream' => true, 'duration_ms' => $ms, 'reasoning' => $r, 'cost' => $cost]);
        $raw->prepare('UPDATE usage SET ts = ? WHERE lease_id = ?')->execute([$ts, $lease]);
    }
    $all = $l->stats('all');
    same(1, count($all));
    // Hand-computed: 4 requests; prompt 310, cached 40, output 35, reasoning 5; credits = prompt + output = 345;
    // cost 500 + 70 = 570 over 2 costed requests; 1 hang-up, 1 estimate, 1 failure (502); mean duration (1000+3000+50+200)/4 = 1062.
    same([4, 310, 40, 35, 5, 345, 570, 2, 1, 1, 1, 1062], [$all[0]['requests'], $all[0]['prompt_tokens'], $all[0]['cached_tokens'], $all[0]['completion_tokens'],
        $all[0]['reasoning_tokens'], $all[0]['credits'], $all[0]['upstream_cost'], $all[0]['costed_requests'], $all[0]['aborted'], $all[0]['estimated'], $all[0]['failed'], $all[0]['mean_duration_ms']]);
    $byDayUp = array_map(static fn (array $r): array => [$r['bucket'], $r['upstream'], $r['requests'], $r['credits']], $l->stats('day', ['upstream']));
    same([['1970-01-11', 'up1', 2, 110], ['1970-01-11', 'up2', 1, 220], ['1970-01-12', 'up1', 1, 15]], $byDayUp, 'per day and upstream, oldest first');
    $byHour = array_map(static fn (array $r): array => [$r['bucket'], $r['model'], $r['status'], $r['requests']], $l->stats('hour', ['model', 'status'], 3600 * 24 * 10, 3600 * 24 * 11));
    same([['1970-01-11T00', 'a', 200, 1], ['1970-01-11T02', 'a', 200, 1], ['1970-01-11T02', 'b', 502, 1]], $byHour, 'since inclusive, until exclusive');
    same(['a'], array_column($l->stats('month', ['key']), 'key'), 'grouped by the key ref');
    check(refusesArgument(fn () => $l->stats('fortnight')), 'unknown bucket refused');
    check(refusesArgument(fn () => $l->stats('day', ['ts; DROP TABLE usage'])), 'only the fixed groupings reach SQL');
}

function refusesArgument(callable $fn): bool
{
    try {
        $fn();
    } catch (\InvalidArgumentException) {
        return true;
    }
    return false;
}

function testMigrateUpgradesASchemaTwoLedgerWithOpeningBalances(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $old = new \PDO('sqlite:' . $path);
    $old->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v2.sql'));
    // a: 700 left with 100 of it held by an open lease; b: empty; c: overdrawn by a chargeback.
    $old->exec("INSERT INTO keys (id, ref, key_hash, balance, created_at) VALUES (1, 'a', 'h1', 700, 0), (2, 'b', 'h2', 0, 0), (3, 'c', 'h3', -50, 0)");
    $old->exec('INSERT INTO leases (id, key_id, reserved, created_at, expires_at) VALUES (1, 1, 100, 0, 0)');
    $old = null;
    chmod($path, 0600);

    $l = new Ledger($path);
    $l->migrate();
    same([['opening', 700, 700, 'schema-3']], changes($l, 'a'));
    same([], changes($l, 'b'), 'a zero balance gets no opening row');
    same([['opening', -50, -50, 'schema-3']], changes($l, 'c'));
    same(['keys' => 3, 'rows' => 2, 'problems' => []], $l->verify());

    same(true, $l->settle(1, 1, 40, meta()), 'a lease opened before the upgrade settles after it');
    same(['refund', 60, 760, 'lease:1'], changes($l, 'a')[0], '100 held, 40 used');
    same([], $l->verify()['problems']);
    $l->migrate(); // re-running init on the upgraded ledger adds nothing
    same(3, $l->verify()['rows']);
}
