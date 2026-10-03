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
    return ['model' => 'm', 'prompt' => 1, 'completion' => 1, 'status' => 200, 'aborted' => false, 'estimated' => false];
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
    same(0, $l->status('b')['balance']);
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
    check($l->status('a')['balance'] <= 1_000_000_000_000_000_000, 'balance stayed in range');
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

function testReserveClampsMaxTokensToBalance(): void
{
    [$l, $id] = fundedLedger(150);
    $r = $l->reserve($id, 50, 1000, 4000, 4, 60);
    same(100, $r['max_tokens'], 'clamped to balance minus prompt bound');
    same(150, $r['reserved']);
    same(['ref' => 'a', 'balance' => 0, 'active' => true, 'in_flight' => 1, 'held' => 0], $l->status('a'));
    [$l2, $id2] = fundedLedger(100000);
    same(4000, $l2->reserve($id2, 50, null, 4000, 4, 60)['max_tokens'], 'no max_tokens requested: the cap applies');
}

function testReserveRefusesWhenTooLow(): void
{
    [$l, $id] = fundedLedger(40);
    same(402, throwsHttp(fn () => $l->reserve($id, 40, null, 1000, 4, 60))[0], 'prompt bound alone uses the balance');
}

function testSettleChargesReportedRefundsRestAndWritesOneUsageRow(): void
{
    [$l, $id] = fundedLedger(1000);
    $r = $l->reserve($id, 20, 200, 1000, 4, 60);
    same(780, $l->status('a')['balance']);
    same(true, $l->settle($r['lease_id'], $id, 57, meta()));
    same(943, $l->status('a')['balance'], '1000 - 57');
    same(false, $l->settle($r['lease_id'], $id, 57, meta()), 'second settle is a no-op');
    same(943, $l->status('a')['balance']);
    same(0, $l->status('a')['in_flight']);
}

function testChargeNeverExceedsTheReservation(): void
{
    [$l, $id] = fundedLedger(100);
    $r = $l->reserve($id, 10, 90, 1000, 4, 60);
    $l->settle($r['lease_id'], $id, 10000, meta());
    same(0, $l->status('a')['balance'], 'over-reported usage is capped; the balance never goes negative');
}

function testConcurrencyLimitPerKey(): void
{
    [$l, $id] = fundedLedger(100000);
    $l->reserve($id, 10, 10, 1000, 2, 60);
    $l->reserve($id, 10, 10, 1000, 2, 60);
    same(429, throwsHttp(fn () => $l->reserve($id, 10, 10, 1000, 2, 60))[0]);
}

function testExpiredLeaseStaysChargedAndALateSettleCorrectsItOnce(): void
{
    [$l, $id] = fundedLedger(100);
    $r = $l->reserve($id, 10, 50, 1000, 1, 1); // reserves 60, lease lives 1 s, limit 1 in flight
    sleep(2);
    same(['ref' => 'a', 'balance' => 40, 'active' => true, 'in_flight' => 0, 'held' => 1], $l->status('a'), 'expired: not in flight, still charged, counted as held');
    $r2 = $l->reserve($id, 10, 20, 1000, 1, 60); // the dead lease no longer blocks concurrency
    same(10, $l->status('a')['balance']);
    same(true, $l->settle($r['lease_id'], $id, 15, meta()), 'late settle of the dead lease');
    same(55, $l->status('a')['balance'], '60 reserved, 15 charged, 45 returned');
    same(0, $l->status('a')['held'], 'no longer held once settled');
    same(false, $l->settle($r['lease_id'], $id, 15, meta()));
    same(55, $l->status('a')['balance']);
    unset($r2);
}

function testDisabledKeyIsRefusedAndCanBeReenabled(): void
{
    [$l, $id] = fundedLedger(100);
    $l->setActive('a', false);
    same(403, throwsHttp(fn () => $l->reserve($id, 1, 1, 1000, 4, 60))[0]);
    $l->setActive('a', true);
    same(1, $l->reserve($id, 1, 1, 1000, 4, 60)['max_tokens']);
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
