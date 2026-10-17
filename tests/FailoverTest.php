<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Proxy;

// Retries, failover between upstreams, server-set params and the error contract.

/** Runs one chat request with an empty upstream request log and returns [response, upstream requests in order]. */
function chatAndRequests(string $alias, bool $stream, string $key, array $extra = []): array
{
    $dir = Harness::get()['dir'];
    file_put_contents("$dir/requests.jsonl", '');
    @unlink("$dir/flaky-seen");
    $r = http('POST', '/v1/chat/completions', msg($alias, $stream, $extra), $key);
    $lines = array_filter(explode("\n", (string) file_get_contents("$dir/requests.jsonl")));
    return [$r, array_values(array_map(static fn (string $l): array => json_decode($l, true), $lines))];
}

/** [path, key] of each upstream request, so a test can see where each attempt went and with which key. */
function hops(array $requests): array
{
    return array_map(static fn (array $q): array => [str_contains($q['path'], '/second/') ? 'backup' : 'primary', substr($q['auth'], 7)], $requests);
}

function errorOf(array $r): array
{
    $e = json_decode($r['body'], true)['error'] ?? null;
    check(is_array($e) && array_key_exists('message', $e) && array_key_exists('type', $e) && array_key_exists('param', $e) && array_key_exists('code', $e),
        'error body has message, type, param and code: ' . $r['body']);
    return $e;
}

function testFailoverMovesToTheNextUpstreamWithItsOwnKey(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(10000);
        [$r, $req] = chatAndRequests('failover', $stream, $key);
        same(200, $r['status'], 'the second upstream answered');
        // One try plus one retry on the first upstream (503 costs nothing there), then the second upstream, each with its own key.
        same([['primary', 'fake-upstream-key'], ['primary', 'fake-upstream-key'], ['backup', 'second-upstream-key']], hops($req), 'attempts in order');
        checkNoUpstreamDetails($r['body']);
        check(settled($ref)['balance'] < 10000, 'the answer that was delivered is charged');
    }
}

function testRetryOnTheSameUpstreamThenSuccess(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('flaky', false, $key);
    same(200, $r['status']);
    same([['primary', 'fake-upstream-key'], ['primary', 'fake-upstream-key']], hops($req), 'one failure, one retry');
    settled($ref);
    $u = lastUsage($ref);
    same(2, $u['attempts'], 'both attempts are counted');
    check($u['first_byte_ms'] !== null && $u['first_byte_ms'] <= $u['duration_ms'], 'first byte measured within the request');
}

function testNoRetryOrFailoverOnceTheUpstreamAnswered2xx(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('after-2xx', false, $key);
    same(502, $r['status'], 'a 2xx without a usable answer may have been generated: the request ends');
    same(1, count($req), 'the second upstream is never asked');
    same('upstream_error', errorOf($r)['code']);
    same(10000, settled($ref)['balance'], 'nothing charged');
    same([null, 1], [lastUsage($ref)['first_byte_ms'], lastUsage($ref)['attempts']], 'no answer reached the client; one attempt');
}

function testAnUnreachableUpstreamIsRetriedThenSkipped(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('dead-first', true, $key);
    same(200, $r['status'], 'the request never left the first upstream, so the second one is asked');
    same([['backup', 'second-upstream-key']], hops($req), 'only the second upstream received a request');
    $log = (string) file_get_contents(Harness::get()['dir'] . '/logs/' . gmdate('Ymd') . '.jsonl');
    $attempts = array_filter(explode("\n", $log), static fn (string $l): bool => str_contains($l, '"upstream_attempt"') && str_contains($l, '"alias":"dead-first"'));
    check(count($attempts) >= 2, 'both tries on the unreachable upstream are logged');
    settled($ref);
}

function testTheUpstreamsRetryAfterIsHonoured(): void
{
    [$ref, $key] = fundedKey(10000);
    $t = microtime(true);
    [$r, $req] = chatAndRequests('flaky-hint', false, $key);
    same(200, $r['status']);
    same(2, count($req), 'a busy answer, then the retry');
    check(microtime(true) - $t >= 1.0, 'the retry waited the upstream\'s Retry-After of 1 s');
    settled($ref);
}

function testAnUpstreamWithoutItsKeyIsSkippedNeverServedWithAnotherKey(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('no-key-first', false, $key);
    same(200, $r['status']);
    same([['backup', 'second-upstream-key']], hops($req), 'the keyless upstream got no request at all');
    $log = (string) file_get_contents(Harness::get()['dir'] . '/logs/' . gmdate('Ymd') . '.jsonl');
    check(str_contains($log, '"upstream_skipped"'), 'the skip is logged');
    settled($ref);
}

function testServerErrorMovesOnWithoutRetryingTheSameUpstream(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('server-error', false, $key);
    same(200, $r['status']);
    same([['primary', 'fake-upstream-key'], ['backup', 'second-upstream-key']], hops($req), 'a 500 may have done work, so no second try there');
    settled($ref);
}

function testContextLengthErrorIsPassedOnAndNotFailedOver(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('context', false, $key);
    same(400, $r['status']);
    same(1, count($req), 'a request every upstream would reject is not sent to the next one');
    $e = errorOf($r);
    same(['invalid_request_error', 'messages', 'context_length_exceeded'], [$e['type'], $e['param'], $e['code']]);
    checkNoUpstreamDetails($r['body']);
    same(10000, settled($ref)['balance']);
}

function testAnUpstreamRejectingItsKeyIsNeverReportedAsTheClientsKey(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('bad-key', false, $key);
    same(502, $r['status'], 'not 401: the client key is fine');
    same([['primary', 'fake-upstream-key'], ['backup', 'second-upstream-key']], hops($req));
    same(['api_error', 'upstream_error'], [errorOf($r)['type'], errorOf($r)['code']]);
    same(10000, settled($ref)['balance']);
}

function testAllUpstreamsDownIsA503WithRetryAfter(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('all-down', true, $key);
    same(503, $r['status']);
    same(1, $r['retry_after']);
    same(4, count($req), 'one try and one retry on each upstream');
    same('upstream_unavailable', errorOf($r)['code']);
    checkNoUpstreamDetails($r['body']);
    same(10000, settled($ref)['balance']);
}

function testServerParamsWinOverTheClientAndModelsShowTheCatalog(): void
{
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = chatAndRequests('pinned', false, $key, ['reasoning_effort' => 'high']);
    same(200, $r['status']);
    same('low', $req[0]['reasoning_effort'], 'the model pins its reasoning level');
    same(['thinking' => false], $req[0]['chat_template_kwargs'], 'upstream params are sent too');
    settled($ref);

    $m = json_decode(http('GET', '/v1/models', null, $key)['body'], true);
    $pinned = array_values(array_filter($m['data'], static fn (array $d): bool => $d['id'] === 'pinned'))[0];
    same(['fast, little reasoning', 128000, 1000], [$pinned['description'], $pinned['context_length'], $pinned['max_completion_tokens']]);
    check(!str_contains(json_encode($m), 'scenario-') && !str_contains(json_encode($m), '127.0.0.1'), 'no upstream model ids or urls in /v1/models');
}

function testErrorsCarryACodeAndChatResponsesARequestId(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('no-such-model', false), $key);
    same(404, $r['status']);
    same(['model_not_found', 'model'], [errorOf($r)['code'], errorOf($r)['param']]);
    $r = http('POST', '/v1/chat/completions', msg('ok', false), 'wrong-key');
    same('invalid_api_key', errorOf($r)['code']);
    $r = http('POST', '/v1/chat/completions', msg('ok', false), $key);
    check(is_int($r['request_id']) && $r['request_id'] > 0, 'a chat response carries X-Request-Id');
    $lease = lastUsage($ref)['lease_id'];
    same($lease, $r['request_id'], 'the request id is the lease id in the usage row');
}

function testAttemptOutcomes(): void
{
    $cases = [
        [[0, CURLE_COULDNT_CONNECT, false, false], 'retry'],
        [[0, CURLE_OPERATION_TIMEDOUT, false, false], 'retry'],   // connect timeout: the request never left
        [[0, CURLE_OPERATION_TIMEDOUT, true, false], 'final'],    // sent, then no answer in time: output may exist
        [[0, CURLE_RECV_ERROR, true, false], 'final'],
        [[200, 0, true, false], 'final'],
        [[200, 0, true, true], 'final'],
        [[429, 0, true, false], 'retry'], [[503, 0, true, false], 'retry'], [[408, 0, true, false], 'retry'],
        [[500, 0, true, false], 'next'], [[401, 0, true, false], 'next'], [[404, 0, true, false], 'next'],
        [[400, 0, true, false], 'final'], [[422, 0, true, false], 'final'], [[302, 0, true, false], 'final'],
    ];
    foreach ($cases as [$args, $want]) {
        same($want, Proxy::outcome(...$args), json_encode($args));
    }
    same('context_length_exceeded', Proxy::errorCode('{"error":{"code":"context_length_exceeded"}}'));
    same('context_length_exceeded', Proxy::errorCode('{"error":{"code":400,"metadata":{"error_type":"context_length_exceeded"}}}'));
    same(null, Proxy::errorCode('{"error":{"code":"account_42_suspended"}}'), 'unknown codes stay with the upstream');
    same(null, Proxy::errorCode('not json'));
}

function testConfigRoutesAndParams(): void
{
    $dir = tempDir();
    $model = ['input' => 1, 'cached_input' => 1, 'output' => 1];
    $up = ['a' => ['url' => 'https://a.example.com/v1/chat/completions', 'key_file' => "$dir/a.key"],
        'b' => ['url' => 'https://b.example.com/v1/chat/completions', 'key_file' => "$dir/b.key"]];
    file_put_contents("$dir/a.key", "key-a\n");
    file_put_contents("$dir/b.key", "key-b\n");
    // No global upstream needed when every model names its upstreams.
    $base = configArrayFor($dir);
    unset($base['upstream_url'], $base['upstream_key_file']);
    $c = new Config(['upstreams' => $up, 'models' => ['m' => $model + ['params' => ['reasoning_effort' => 'low'],
        'route' => [['upstream' => 'a', 'model' => 'x'], ['upstream' => 'b', 'model' => 'y', 'params' => ['reasoning_effort' => 'high', 'top_k' => 5]]]]]] + $base);
    same(['url' => $up['a']['url'], 'messages_url' => null, 'key' => 'key-a', 'stream_options' => ['include_usage' => true, 'continuous_usage_stats' => true],
        'model' => 'x', 'params' => ['reasoning_effort' => 'low']], $c->route('m', 0));
    same(['key-b', 'y', ['reasoning_effort' => 'high', 'top_k' => 5]], [$c->route('m', 1)['key'], $c->route('m', 1)['model'], $c->route('m', 1)['params']],
        'an upstream entry has its own key, model id and params over the model params');
    same('x', $c->models['m']['model'], 'the first upstream model id stays the model field');

    $bad = [
        ['models' => ['m' => $model + ['route' => [['upstream' => 'nope', 'model' => 'x']]]]],
        ['models' => ['m' => $model + ['route' => []]]],
        ['models' => ['m' => $model + ['model' => 'x', 'route' => [['upstream' => 'a', 'model' => 'x']]]]],
        ['models' => ['m' => $model + ['route' => [['upstream' => 'a']]]]],
        ['models' => ['m' => $model + ['model' => 'x', 'params' => ['max_tokens' => 9]]]],
        ['models' => ['m' => $model + ['model' => 'x', 'params' => ['stream' => true]]]],
        ['models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'x', 'params' => ['model' => 'z']]]]]],
        ['models' => ['m' => $model + ['model' => 'x', 'params' => ['a', 'b']]]],
        ['models' => ['m' => $model + ['model' => 'x', 'context_length' => 0]]],
        ['models' => ['m' => $model + ['model' => 'x', 'description' => 5]]],
        ['models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'x', 'url' => 'https://c.example.com/v1']]]]],
        ['models' => ['m' => $model + ['stream_options' => ['include_usage' => true], 'route' => [['upstream' => 'a', 'model' => 'x']]]]],
        ['retries' => 3],
        ['retries' => -1],
        ['upstreams' => ['a' => ['url' => 'http://a.example.com/v1', 'key_file' => '/k']]],   // plain http off this machine
        ['upstreams' => ['a' => ['url' => 'https://a.example.com/v1']]],                       // no key file
    ];
    foreach ($bad as $override) {
        try {
            new Config($override + ['upstreams' => $up] + $base);
            throw new \RuntimeException('accepted bad config: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
    try {
        new Config(['models' => ['m' => $model + ['model' => 'x']]] + $base);
        throw new \RuntimeException('a model without any upstream was accepted');
    } catch (\InvalidArgumentException) {
    }
}

function testFailureMapping(): void
{
    $r = static fn (int $status, string $kind = 'final', array $extra = []): array => $extra + ['status' => $status, 'kind' => $kind,
        'retry_after' => null, 'code' => null, 'timed_out' => false, 'too_big' => false];
    $cases = [
        [$r(429, 'retry', ['retry_after' => 7]), 429, 'upstream_busy', 7],
        [$r(429, 'retry', ['retry_after' => 900]), 429, 'upstream_busy', 60],
        [$r(503, 'retry'), 503, 'upstream_unavailable', 1],
        [$r(0, 'retry'), 503, 'upstream_unavailable', 1],
        [$r(0, 'next'), 503, 'upstream_unavailable', 1],
        [$r(500, 'next'), 502, 'upstream_error', null],
        [$r(401, 'next'), 502, 'upstream_error', null],
        [$r(400), 400, 'upstream_rejected', null],
        [$r(400, 'final', ['code' => 'context_length_exceeded']), 400, 'context_length_exceeded', null],
        [$r(0, 'final', ['timed_out' => true]), 504, 'upstream_timeout', null],
        [$r(200, 'final', ['too_big' => true]), 502, 'upstream_error', null],
        [$r(200), 502, 'upstream_error', null],
    ];
    foreach ($cases as [$in, $status, $code, $retry]) {
        $e = Proxy::failure($in);
        same([$status, $code, $retry], [$e->status, $e->errorCode, $e->retryAfter], json_encode($in));
    }
}
