<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Ledger;

function testDailyTargetRetriesNeverReplenishInterveningUsage(): void
{
    [$l, $id] = fundedLedger(100);
    $first = $l->grantDailyTarget('a', 500);
    same([true, 500, 0, 500, 400], [$first['applied'], $first['balance'], $first['reserved'], $first['target'], $first['credits']]);
    same(gmdate('Y-m-d'), $first['day']);
    $lease = $l->reserve($id, 10, 50, 1000, unitRates(), lim(), 60);
    $l->settle($lease['lease_id'], $id, 20, meta());
    $retry = $l->grantDailyTarget('a', 500);
    same([false, 480, 400], [$retry['applied'], $retry['balance'], $retry['credits']], 'a retry reports the original grant, without adding it again');
    same(409, throwsHttp(fn () => $l->grantDailyTarget('a', 501))[0]);
    same(480, $l->status('a', lim())['balance']);
    same([], $l->verify()['problems']);
}

function testDailyTargetCountsExpiredHoldsAndLateSettlement(): void
{
    [$l, $id] = fundedLedger(1000);
    $lease = $l->reserve($id, 10, 390, 1000, unitRates(), lim(), 1);
    sleep(2); // exercise the real expired-lease path, not an artificial settlement record
    same(1, $l->status('a', lim())['held']);
    $reset = $l->grantDailyTarget('a', 100);
    same([-300, 400, -900], [$reset['balance'], $reset['reserved'], $reset['credits']], 'net entitlement is 100, including the expired reservation');
    same(402, throwsHttp(fn () => $l->reserve($id, 1, 1, 1000, unitRates(), lim(), 60))[0]);
    same(true, $l->settle($lease['lease_id'], $id, 80, meta()));
    same(20, $l->status('a', lim())['balance'], '100 target minus 80 charged, never 100 plus the refunded reservation');
    same(false, $l->settle($lease['lease_id'], $id, 80, meta()));
    same(false, $l->grantDailyTarget('a', 100)['applied']);
    same([], $l->verify()['problems']);
}

function testDailyTargetZeroDeltaStillConsumesThatDaysOperation(): void
{
    [$l, $id] = fundedLedger(100);
    $before = count($l->history('a'));
    same(0, $l->grantDailyTarget('a', 100)['credits']);
    same($before, count($l->history('a')), 'a zero change writes no credit-log row');
    same(2, count($l->grants($id, 10)), 'the daily identity is recorded even for a zero delta');
    $lease = $l->reserve($id, 1, 9, 1000, unitRates(), lim(), 60);
    $l->settle($lease['lease_id'], $id, 10, meta());
    same([false, 90], array_values(array_intersect_key($l->grantDailyTarget('a', 100), ['applied' => true, 'balance' => true])));
    same([], $l->verify()['problems']);
}

function testDailyTargetKeepsLegacyGrantsAndAccountBoundaries(): void
{
    [$l, $id] = fundedLedger(0);
    $l->createKey('b');
    $l->grantDailyTarget('a', 100);
    same(0, $l->status('b', lim())['balance']);
    same(40, $l->grantDailyTarget('b', 40)['balance'], 'each key has its own daily identity');
    $targetGrant = $l->grants($id, 10)[0];
    same(409, throwsHttp(fn () => $l->grant('a', $targetGrant['grant_id'], $targetGrant['credits']))[0], 'target identities cannot be replayed as additive grants');
    same(['applied' => true, 'balance' => 125, 'expires_at' => null], $l->grant('a', 'invoice', 25));
    same(['applied' => false, 'balance' => 125, 'expires_at' => null], $l->grant('a', 'invoice', 25));
    same(120, $l->grant('a', 'refund', -5)['balance']);
    same(409, throwsHttp(fn () => $l->grant('a', 'invoice', 26))[0]);
    same(120, $l->grantDailyTarget('a', 100)['balance'], 'a target retry does not undo a separate administrative grant');
    same([], $l->verify()['problems']);
}

function testDailyTargetRejectsInvalidOrRemovedRefsAndDoesNotEnableKeys(): void
{
    [$l] = fundedLedger(100);
    foreach ([-1, PHP_INT_MAX] as $bad) {
        same(400, throwsHttp(fn () => $l->grantDailyTarget('a', $bad))[0]);
    }
    same(404, throwsHttp(fn () => $l->grantDailyTarget('missing', 50))[0]);
    $l->setActive('a', false);
    same(0, $l->grantDailyTarget('a', 0)['balance'], 'zero is an explicit administrative target');
    same(false, $l->status('a', lim())['active'], 'funding never re-enables a key');
    $l->removeKey('a');
    same(409, throwsHttp(fn () => $l->grantDailyTarget('a', 0))[0]);
    same([], $l->verify()['problems']);
}

function testDailyTargetRetainsThePerGrantBound(): void
{
    [$l] = fundedLedger(0);
    $l->grant('a', 'large-1', Ledger::MAX_GRANT);
    $l->grant('a', 'large-2', Ledger::MAX_GRANT);
    $before = $l->status('a', lim())['balance'];
    same(400, throwsHttp(fn () => $l->grantDailyTarget('a', 0))[0]);
    same($before, $l->status('a', lim())['balance'], 'an excessive corrective debit is refused atomically');
    same(2, count($l->history('a')));
    same([], $l->verify()['problems']);
}

function testDailyTargetMigrateElevenAndMissedDaysDoNotAccumulate(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $db = new PDO("sqlite:$path");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v11.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', 'digest', 100, 1, 0)");
    $db->exec("INSERT INTO grants (grant_id, key_id, credits, created_at) VALUES ('old-invoice', 1, 100, 1)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (1, 1, 'grant', 100, 100, 'old-invoice')");
    unset($db);
    $l = new Ledger($path);
    $l->migrate();
    $l->migrate();
    $db = new PDO("sqlite:$path");
    same(12, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same([null, null], $db->query('SELECT target_credits, target_day FROM grants')->fetch(PDO::FETCH_NUM));
    same(['applied' => false, 'balance' => 100, 'expires_at' => null], $l->grant('old', 'old-invoice', 100));
    // Historical fixture: one zero-delta target three days ago. There is no caller clock or date override.
    $db->prepare('INSERT INTO grants (grant_id, key_id, credits, created_at, target_credits, target_day) VALUES (?, 1, 0, ?, 100, ?)')
        ->execute(['historical-target', time() - 3 * 86400, intdiv(time(), 86400) - 3]);
    same(0, $l->grantDailyTarget('old', 100)['credits'], 'missed days add nothing');
    same(100, $l->status('old', lim())['balance']);
    same([], $l->verify()['problems']);
}

function testDailyTargetAuditFailureRollsBackBalanceAndDailyIdentity(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $key = $l->createKey('a');
    $id = $l->findKey($key)['id'];
    $l->grant('a', 'invoice', 100);
    $db = new PDO("sqlite:$path");
    $db->exec("CREATE TRIGGER reject_target_audit BEFORE INSERT ON credit_log WHEN NEW.ref LIKE 'daily-target:%' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    $failed = false;
    try {
        $l->grantDailyTarget('a', 200);
    } catch (PDOException $e) {
        $failed = str_contains($e->getMessage(), 'audit unavailable');
    }
    same(true, $failed, 'the audit failure reaches the caller');
    same(100, $l->status('a', lim())['balance']);
    same(1, count($l->grants($id, 10)), 'the operation marker was rolled back too');
    $db->exec('DROP TRIGGER reject_target_audit');
    same(true, $l->grantDailyTarget('a', 200)['applied'], 'retry after rollback can perform the operation');
    same([], $l->verify()['problems']);
}

/** The CLI is the actor's real surface, with an isolated fake config and no upstream calls. */
function dailyTargetProcess(string $dir, array $args): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
    check(is_resource($process), 'CLI process started');
    return [$process, $pipes];
}

function dailyTargetResult(array $started): array
{
    [$process, $pipes] = $started;
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), json_decode($out, true), $err];
}

function testDailyTargetConcurrentCliRetriesApplyExactlyOnce(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $l = new Ledger("$dir/ledger.sqlite");
    $l->migrate();
    $l->createKey('a');
    $children = [];
    for ($i = 0; $i < 6; $i++) {
        $children[] = dailyTargetProcess($dir, ['grant', 'a', '1000', '--daily-target']);
    }
    $applied = 0;
    foreach ($children as $child) {
        [$rc, $result, $err] = dailyTargetResult($child);
        same([0, ''], [$rc, $err]);
        $applied += (int) $result['applied'];
        same(1000, $result['balance']);
    }
    same(1, $applied);
    same(1, count($l->history('a')));
    same([], $l->verify()['problems']);
    [$rc, $result] = dailyTargetResult(dailyTargetProcess($dir, ['grant', 'a', '20', 'invoice']));
    same([0, 1020], [$rc, $result['balance']], 'legacy CLI grant remains additive');
    [$rc, $result] = dailyTargetResult(dailyTargetProcess($dir, ['grant', 'a', '1000', '--daily-target']));
    same([0, false, 1020], [$rc, $result['applied'], $result['balance']], 'retry does not reverse a subsequent additive grant');
    foreach ([['grant', 'a', '999', '--daily-target'], ['grant', 'a', '1000', '--daily-target', '2099-01-01'],
        ['grant', 'a', '1000', 'different-id', '--daily-target'], ['grant', 'a', '1000', '--daily-target', '0']] as $bad) {
        [$rc, $result, $err] = dailyTargetResult(dailyTargetProcess($dir, $bad));
        same(1, $rc);
        check($err !== '', 'the refusal is visible on stderr');
        same(1020, $l->status('a', lim())['balance']);
    }
}

function testDailyTargetConcurrentDifferentTargetsConflict(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $l = new Ledger("$dir/ledger.sqlite");
    $l->migrate();
    $l->createKey('a');
    $children = [dailyTargetProcess($dir, ['grant', 'a', '100', '--daily-target']),
        dailyTargetProcess($dir, ['grant', 'a', '200', '--daily-target'])];
    $codes = [];
    $winner = null;
    foreach ($children as $child) {
        [$rc, $row, $err] = dailyTargetResult($child);
        $codes[] = $rc;
        if ($rc === 0) {
            $winner = $row['target'];
        } else {
            check(str_contains($err, 'conflicts'), 'the losing target is explicitly refused');
        }
    }
    sort($codes);
    same([0, 1], $codes);
    same($winner, $l->status('a', lim())['balance']);
    same([], $l->verify()['problems']);
}
