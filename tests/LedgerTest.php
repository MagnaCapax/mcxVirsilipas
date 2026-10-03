<?php

declare(strict_types=1);

function testGrantIsAppliedOncePerGrantId(): void
{
    $l = freshLedger();
    $l->createKey('a');
    same(['applied' => true, 'balance' => 500], $l->grant('a', 'inv-1', 500));
    same(['applied' => false, 'balance' => 500], $l->grant('a', 'inv-1', 500), 'retry of the same grant');
    same(['applied' => true, 'balance' => 800], $l->grant('a', 'sub-2026-10', 300), 'subscription adds to the same balance');
    same(['applied' => true, 'balance' => 700], $l->grant('a', 'refund-1', -100), 'negative grant');
}

function testGrantRejectsZeroAndUnknownRef(): void
{
    $l = freshLedger();
    $l->createKey('a');
    same(400, throwsHttp(fn () => $l->grant('a', 'g', 0))[0]);
    same(404, throwsHttp(fn () => $l->grant('nobody', 'g', 5))[0]);
}

function testDuplicateRefIsRefused(): void
{
    $l = freshLedger();
    $l->createKey('a');
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
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 150);
    $keyId = $l->findKey($k)['id'];
    $r = $l->reserve($keyId, 50, 1000, 4000, 4, 60);
    same(100, $r['max_tokens'], 'clamped to balance minus prompt estimate');
    same(150, $r['reserved']);
    same(0, $l->status('a')['balance']);
    same(1, $l->status('a')['in_flight']);
    $r2 = (function () use ($l) { $k = $l->createKey('b'); $l->grant('b', 'gb', 100000); return $l->reserve($l->findKey($k)['id'], 50, null, 4000, 4, 60); })();
    same(4000, $r2['max_tokens'], 'no max_tokens requested: the cap applies');
}

function testReserveRefusesWhenExhausted(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 40);
    $keyId = $l->findKey($k)['id'];
    same(402, throwsHttp(fn () => $l->reserve($keyId, 40, null, 1000, 4, 60))[0], 'prompt alone uses the balance');
    $l->grant('a', 'g2', -100);
    same(402, throwsHttp(fn () => $l->reserve($keyId, 1, null, 1000, 4, 60))[0], 'negative balance');
}

function testSettleRefundsUnusedReservationAndLogsUsage(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 1000);
    $keyId = $l->findKey($k)['id'];
    $r = $l->reserve($keyId, 20, 200, 1000, 4, 60);
    same(780, $l->status('a')['balance']);
    $l->settle($r['lease_id'], $keyId, 57, ['model' => 'm', 'prompt' => 30, 'completion' => 27, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    same(943, $l->status('a')['balance'], '1000 - 57');
    same(0, $l->status('a')['in_flight']);
}

function testConcurrencyLimitPerKey(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 100000);
    $keyId = $l->findKey($k)['id'];
    $l->reserve($keyId, 10, 10, 1000, 2, 60);
    $l->reserve($keyId, 10, 10, 1000, 2, 60);
    same(429, throwsHttp(fn () => $l->reserve($keyId, 10, 10, 1000, 2, 60))[0]);
}

function testExpiredLeaseIsRefundedAndLateSettleChargesUsageOnly(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 100);
    $keyId = $l->findKey($k)['id'];
    $r = $l->reserve($keyId, 10, 90, 1000, 1, 1); // reserves the whole balance, lease lives 1 s
    same(0, $l->status('a')['balance']);
    sleep(2);
    // The dead lease must not block the key: it is reaped (refunded) even though this reserve is refused for concurrency later.
    $r2 = $l->reserve($keyId, 10, 20, 1000, 1, 60);
    same(70, $l->status('a')['balance'], 'old reservation refunded, new one taken');
    // The first request settles late: only its usage is debited.
    $l->settle($r['lease_id'], $keyId, 15, ['model' => 'm', 'prompt' => 10, 'completion' => 5, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    same(55, $l->status('a')['balance']);
    unset($r2);
}

function testReapSurvivesARefusal(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 100);
    $keyId = $l->findKey($k)['id'];
    $l->reserve($keyId, 10, 90, 1000, 4, 1);
    sleep(2);
    // This request is refused (prompt larger than the balance), but the dead lease's refund must stick.
    same(402, throwsHttp(fn () => $l->reserve($keyId, 500, null, 1000, 4, 60))[0]);
    same(100, $l->status('a')['balance']);
    same(0, $l->status('a')['in_flight']);
}

function testDisabledKeyIsRefusedAndCanBeReenabled(): void
{
    $l = freshLedger();
    $k = $l->createKey('a');
    $l->grant('a', 'g', 100);
    $keyId = $l->findKey($k)['id'];
    $l->setActive('a', false);
    same(403, throwsHttp(fn () => $l->reserve($keyId, 1, 1, 1000, 4, 60))[0]);
    $l->setActive('a', true);
    same(1, $l->reserve($keyId, 1, 1, 1000, 4, 60)['max_tokens']);
    same(404, throwsHttp(fn () => $l->setActive('nobody', true))[0]);
}
