<?php

declare(strict_types=1);

// End-to-end: real HTTP through separate php -S proxy processes to a fake upstream.

function msg(string $model, bool $stream, array $extra = []): array
{
    return $extra + ['model' => $model, 'stream' => $stream, 'messages' => [['role' => 'user', 'content' => 'Say hello.']]];
}

/** Response text must not carry anything the upstream added for its own purposes. */
function checkNoUpstreamDetails(string $body): void
{
    foreach (['estimated_cost', 'PRIVATE-DETAIL', 'private upstream detail', 'scenario-'] as $needle) {
        check(!str_contains($body, $needle), "client response contains upstream detail '$needle'");
    }
}

function testIntegrationNonStreamDebitsReportedUsage(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('ok', false), $key);
    same(200, $r['status']);
    $j = json_decode($r['body'], true);
    same('hello world', $j['choices'][0]['message']['content'] ?? null);
    same('ok', $j['model'], 'the alias, not the upstream model id');
    same(['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18], $j['usage']);
    checkNoUpstreamDetails($r['body']);
    same(10000 - 18, settled($ref)['balance']);
    $u = lastUsage($ref);
    same([18, 18, 0, 0, 0], [$u['reported'], $u['charged'], $u['estimated'], $u['aborted'], $u['over_reservation']]);
}

function testIntegrationStreamDebitsExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('ok', true, ['n' => 3, 'best_of' => 2, 'store' => true, 'modalities' => ['audio']]), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 'data: [DONE]'), 'stream completed');
    check(str_contains($r['body'], '"model":"ok"'), 'alias in the stream');
    check(str_contains($r['body'], '"total_tokens":21'), 'standard usage still reaches the client');
    checkNoUpstreamDetails($r['body']);
    same(10000 - 21, settled($ref)['balance']);
    $up = json_decode((string) file_get_contents(Harness::get()['dir'] . '/last-request.json'), true);
    same('scenario-ok', $up['model'], 'alias mapped to the upstream model');
    foreach (['n', 'best_of', 'store', 'modalities'] as $k) {
        check(!isset($up[$k]), "$k must not be forwarded");
    }
    same(['include_usage' => true, 'continuous_usage_stats' => true], $up['stream_options']);
    same(21, lastUsage($ref)['charged']);
}

function testIntegrationFinalOnlyUsageStreamDebitsExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    same(200, http('POST', '/v1/chat/completions', msg('final-only', true), $key)['status']);
    same(10000 - 21, settled($ref)['balance']);
}

function testIntegrationUpstreamErrorsAreGenericAndFree(): void
{
    foreach ([['busy', true, 429], ['redirect', false, 502], ['json-error', false, 502], ['json-error', true, 502], ['truncated', false, 502],
        ['empty-choices', false, 502], ['done-only', true, 502], ['null-choice', false, 502]] as [$model, $stream, $status]) {
        [$ref, $key] = fundedKey(10000);
        $r = http('POST', '/v1/chat/completions', msg($model, $stream), $key);
        same($status, $r['status'], "$model stream=" . var_export($stream, true));
        checkNoUpstreamDetails($r['body']);
        same(10000, settled($ref)['balance'], "$model: nothing charged");
    }
}

function testIntegrationMissingUsageIsChargedAnUpperBound(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(10000);
        same(200, http('POST', '/v1/chat/completions', msg('empty-usage', $stream), $key)['status']);
        $u = lastUsage($ref);
        same(1, $u['estimated'], 'flagged as estimated');
        check($u['charged'] > 11, 'never free when usage is missing');
        same(10000 - $u['charged'], settled($ref)['balance']);
    }
}

function testIntegrationStreamEndingEarlyIsReportedInBand(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('no-done', true), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 'upstream stream ended early'), 'client is told the stream is incomplete');
    check(!str_contains($r['body'], '[DONE]'), 'no false completion marker');
    $u = lastUsage($ref);
    same([12, 0], [$u['charged'], $u['estimated']], 'charged the last cumulative usage it saw (11 + 1)');
}

function testIntegrationClientHangUpIsChargedForWhatWasGenerated(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('slow', true), $key, 300);
    check($r['body'] !== '', 'client received some of the stream');
    $s = settled($ref);
    $charged = 10000 - $s['balance'];
    check($charged > 11 && $charged < 21, "charged $charged: more than the prompt, less than the full answer");
    $u = lastUsage($ref);
    same([1, 0, $charged], [$u['aborted'], $u['estimated'], $u['charged']], 'aborted, exact (continuous usage)');
    preg_match_all('/"total_tokens":(\d+)/', $r['body'], $m);
    check($m[1] !== [] && $charged >= (int) end($m[1]), 'charged at least the last usage the client saw');
}

function testIntegrationHangUpWithoutUsageIsChargedAtMostTheReservation(): void
{
    [$ref, $key] = fundedKey(100000);
    http('POST', '/v1/chat/completions', msg('big-delta', true), $key, 5000);
    settled($ref);
    $u = lastUsage($ref);
    same([1, 1, 1], [$u['aborted'], $u['estimated'], $u['over_reservation']], 'aborted, estimated, capped');
    check($u['charged'] > 1000 && $u['charged'] < $u['reported'], 'the 10 kB delta is charged up to the reservation, not as one token');
}

function testIntegrationParallelStreamsDebitExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    $statuses = parallelChat(array_fill(0, 5, msg('ok', true)), $key);
    same([200, 200, 200, 200, 200], $statuses);
    same(10000 - 5 * 21, settled($ref)['balance']);
}

function testIntegrationPerKeyConcurrencyLimit(): void
{
    [$ref, $key] = fundedKey(100000);
    $statuses = parallelChat(array_fill(0, 7, msg('slow', true)), $key);
    sort($statuses);
    same([200, 200, 200, 200, 200, 429, 429], $statuses, 'limit is 5 per key');
    same(100000 - 5 * 21, settled($ref)['balance']);
}

function testIntegrationRefusals(): void
{
    [$ref, $key] = fundedKey(5);
    same(402, http('POST', '/v1/chat/completions', msg('ok', true), $key)['status'], 'balance too small for the prompt');
    same(401, http('POST', '/v1/chat/completions', msg('ok', true), 'vsk-wrong')['status']);
    same(401, http('POST', '/v1/chat/completions', msg('ok', true))['status']);
    [, $key2] = fundedKey(1000);
    same(404, http('POST', '/v1/chat/completions', msg('not-a-model', true), $key2)['status']);
    same(404, http('GET', '/v1/nothing', null, $key2)['status']);
    $image = ['model' => 'ok', 'messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/a.png']]]]]];
    same(400, http('POST', '/v1/chat/completions', $image, $key2)['status'], 'images refused');
    Harness::get()['ledger']->setActive($ref, false);
    same(403, http('GET', '/v1/models', null, $key)['status']);
}

function testIntegrationModelsAndBalance(): void
{
    [, $key] = fundedKey(777);
    $m = json_decode(http('GET', '/v1/models', null, $key)['body'], true);
    same(array_keys(configArrayFor('x')['models']), array_column($m['data'], 'id'));
    same(['balance' => 777, 'in_flight' => 0], json_decode(http('GET', '/v1/balance', null, $key)['body'], true));
}

function testIntegrationAdminFlow(): void
{
    $ref = 'user-' . bin2hex(random_bytes(3));
    same(401, http('POST', '/admin/keys', ['ref' => $ref], 'wrong-token')['status']);
    $r = http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret');
    same(201, $r['status']);
    $key = json_decode($r['body'], true)['key'];
    same(409, http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret')['status']);

    $g = ['ref' => $ref, 'grant_id' => "inv-$ref", 'tokens' => 5000];
    same(['applied' => true, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true));
    same(['applied' => false, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true), 'retried push');
    same(409, http('POST', '/admin/grants', ['tokens' => 6000] + $g, 'admin-secret')['status'], 'same grant_id, different amount');

    same(200, http('POST', '/admin/keys/active', ['ref' => $ref, 'active' => false], 'admin-secret')['status']);
    same(403, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    same(200, http('POST', '/admin/keys/active', ['ref' => $ref, 'active' => true], 'admin-secret')['status']);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    same(5000 - 18, settled($ref)['balance']);
}

function testIntegrationUnknownResponseFieldsNeverReachTheClient(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('top-private', false), $key);
    same(200, $r['status']);
    checkNoUpstreamDetails($r['body']);
    same('top-private', json_decode($r['body'], true)['model'], 'alias added even when the upstream sent no model');
    settled($ref);
}

function testIntegrationOutputAfterTheLastUsageReportIsCharged(): void
{
    [$ref, $key] = fundedKey(100000);
    same(200, http('POST', '/v1/chat/completions', msg('stale-usage', true), $key)['status']);
    settled($ref);
    $u = lastUsage($ref);
    same(1, $u['estimated'], 'flagged');
    check($u['reported'] > 5000, "5 kB delivered after the last usage report must count, got {$u['reported']}");
    same(1, $u['over_reservation'], 'and is charged up to the reservation');
    check($u['charged'] > 1000, 'not the stale 12');
}

function testIntegrationRunawayStreamIsStoppedAtTheReservation(): void
{
    [$ref, $key] = fundedKey(300);
    $r = http('POST', '/v1/chat/completions', msg('runaway', true), $key);
    same(200, $r['status']);
    check(!str_contains($r['body'], 't10 '), 'upstream stopped before the end');
    check(str_contains($r['body'], 'upstream stream ended early'), 'client told');
    same(0, settled($ref)['balance'], 'charged the whole reservation, never more');
    same(1, lastUsage($ref)['over_reservation']);
}

function testIntegrationALowerUsageReportDoesNotEraseAHigherOne(): void
{
    [$ref, $key] = fundedKey(100000);
    same(200, http('POST', '/v1/chat/completions', msg('usage-drop', true), $key)['status']);
    settled($ref);
    same(61, lastUsage($ref)['charged'], 'the 11 + 50 report stands; the later 11 + 3 is ignored');
}
