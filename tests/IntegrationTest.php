<?php

declare(strict_types=1);

// End-to-end: real HTTP through php -S (with workers) to a fake upstream.

function msg(string $model, bool $stream, array $extra = []): array
{
    return $extra + ['model' => $model, 'stream' => $stream, 'messages' => [['role' => 'user', 'content' => 'Say hello.']]];
}

function testIntegrationNonStreamDebitsReportedUsage(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('ok', false), $key);
    same(200, $r['status']);
    same('hello world', json_decode($r['body'], true)['choices'][0]['message']['content'] ?? null);
    check(!str_contains($r['body'], 'estimated_cost'), 'upstream-private usage fields must not reach the client');
    same(10000 - 18, settled($ref)['balance']);
}

function testIntegrationStreamDebitsExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('ok', true, ['n' => 3, 'best_of' => 2]), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 'data: [DONE]'), 'stream completed');
    check(!str_contains($r['body'], 'estimated_cost'), 'upstream-private usage fields must not reach the client');
    check(str_contains($r['body'], '"total_tokens":21'), 'standard usage still reaches the client');
    same(10000 - 21, settled($ref)['balance']);
    $up = json_decode((string) file_get_contents(Harness::get()['dir'] . '/last-request.json'), true);
    same('scenario-ok', $up['model'], 'alias mapped to the upstream model');
    check(!isset($up['n']) && !isset($up['best_of']), 'price-affecting parameters stripped');
    same(['include_usage' => true, 'continuous_usage_stats' => true], $up['stream_options']);
}

function testIntegrationFinalOnlyUsageStreamDebitsExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    same(200, http('POST', '/v1/chat/completions', msg('final-only', true), $key)['status']);
    same(10000 - 21, settled($ref)['balance']);
}

function testIntegrationUpstream429IsGenericAndFree(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('busy', true), $key);
    same(429, $r['status']);
    check(!str_contains($r['body'], 'SecretVendor'), 'upstream error text must not reach the client');
    same(10000, settled($ref)['balance'], 'nothing charged');
}

function testIntegrationClientHangUpIsChargedForWhatItGot(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('slow', true), $key, 300);
    check($r['body'] !== '', 'client received some of the stream');
    $s = settled($ref);
    $charged = 10000 - $s['balance'];
    check($charged > 11 && $charged < 21, "charged $charged: more than the prompt, less than the full answer");
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
    $s = settled($ref);
    same(100000 - 5 * 21, $s['balance']);
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
    Harness::get()['ledger']->setActive($ref, false);
    same(403, http('GET', '/v1/models', null, $key)['status']);
}

function testIntegrationModelsAndBalance(): void
{
    [, $key] = fundedKey(777);
    $m = json_decode(http('GET', '/v1/models', null, $key)['body'], true);
    same(['ok', 'final-only', 'busy', 'slow'], array_column($m['data'], 'id'));
    same(['balance' => 777, 'in_flight' => 0], json_decode(http('GET', '/v1/balance', null, $key)['body'], true));
}

function testIntegrationAdminFlow(): void
{
    $ref = 'svc-admin-' . bin2hex(random_bytes(3));
    same(401, http('POST', '/admin/keys', ['ref' => $ref], 'wrong-token')['status']);
    $r = http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret');
    same(201, $r['status']);
    $key = json_decode($r['body'], true)['key'];
    same(409, http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret')['status']);

    $g = ['ref' => $ref, 'grant_id' => "inv-$ref", 'tokens' => 5000];
    same(['applied' => true, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true));
    same(['applied' => false, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true), 'retried push');

    same(200, http('POST', '/admin/keys/active', ['ref' => $ref, 'active' => false], 'admin-secret')['status']);
    same(403, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    same(200, http('POST', '/admin/keys/active', ['ref' => $ref, 'active' => true], 'admin-secret')['status']);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    same(5000 - 18, settled($ref)['balance']);
}
