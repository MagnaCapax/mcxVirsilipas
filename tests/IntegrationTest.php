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
    same(['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18, 'prompt_tokens_details' => ['cached_tokens' => 0], 'credits' => 18], $j['usage']);
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
        ['empty-choices', false, 502], ['done-only', true, 502], ['null-choice', false, 502], ['empty-choice', false, 502], ['list-message', false, 502], ['empty-tool-call', false, 502], ['empty-tool-call', true, 502],
        ['incomplete-message', false, 502], ['done-first', true, 502], ['blank-tool', false, 502], ['stream-message', true, 502]] as [$model, $stream, $status]) {
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
    $seen = $m[1] === [] ? 0 : (int) end($m[1]);
    $generated = (int) file_get_contents(Harness::get()['dir'] . '/generated-total.txt');
    check($seen > 11 && $charged >= $seen && $charged <= $generated,
        "charged $charged: at least the last usage the client saw ($seen), at most what the upstream generated ($generated)");
}

function testIntegrationAnEqualUsageReportDoesNotEraseLaterOutput(): void
{
    [$ref, $key] = fundedKey(100000);
    same(200, http('POST', '/v1/chat/completions', msg('equal-usage', true), $key)['status']);
    settled($ref);
    $u = lastUsage($ref);
    same([1, 1], [$u['estimated'], $u['over_reservation']], 'the 5 kB after the last advancing report is an upper bound, capped');
    check($u['reported'] > 5000 && $u['charged'] > 1000, "5 kB delivered after the report must count, not the stale 61: got {$u['charged']}");
}

function testIntegrationMalformedUsageIsChargedAnUpperBound(): void
{
    [$ref, $key] = fundedKey(100000);
    same(200, http('POST', '/v1/chat/completions', msg('negative-total', false), $key)['status']);
    settled($ref);
    $u = lastUsage($ref);
    same([1, 1], [$u['estimated'], $u['over_reservation']], 'a negative total is not an exact report');
    check($u['reported'] > 5000 && $u['charged'] > 1000, "the 5 kB answer is charged as bytes up to the reservation, not 1 token: got {$u['charged']}");
}

function testIntegrationWrongTypedResponseValuesNeverReachTheClient(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('typed-private', false), $key);
    same(200, $r['status']);
    checkNoUpstreamDetails($r['body']);
    $msg = json_decode($r['body'], true)['choices'][0]['message'];
    same(['index' => 0, 'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'f']], $msg['tool_calls'][0], 'object arguments dropped');
    check(!isset($msg['content']), 'object content dropped');
    settled($ref);
}

function testIntegrationNothingAfterTheTerminalMarkerIsRelayedOrCharged(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('after-done', true), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 'first') && !str_contains($r['body'], 'AFTER-DONE'), 'the event after [DONE] is not relayed');
    settled($ref);
    same(12, lastUsage($ref)['charged'], 'charged the usage seen before [DONE] (11 + 1), not the late report');
}

function testIntegrationDroppedEventsAddNothingToTheCharge(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('invalid-prelude', true), $key);
    same(200, $r['status']);
    check(!str_contains($r['body'], 'XXXX'), 'the malformed event is not relayed');
    settled($ref);
    $u = lastUsage($ref);
    same(1, $u['estimated'], 'no usage: an upper bound');
    check($u['charged'] < 1000, "the 1,000 dropped bytes are not charged: got {$u['charged']}");
}

function testIntegrationTheEstimateCoversExactlyWhatTheClientReceived(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('stream-message-bytes', true), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 'MMMM'), 'the message text was relayed');
    settled($ref);
    check(lastUsage($ref)['charged'] > 1000, 'so its 4 kB count toward the estimate (capped at the reservation)');

    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('private-bytes', false), $key);
    same(200, $r['status']);
    check(!str_contains($r['body'], 'PPPP'), 'the private field was dropped');
    settled($ref);
    $u = lastUsage($ref);
    same(1, $u['estimated']);
    check($u['charged'] < 200, "so its 4 kB do not count: charged {$u['charged']}");
}

function testIntegrationNothingIsRelayedAfterTheReservationIsUsedUp(): void
{
    [$ref, $key] = fundedKey(300);
    $r = http('POST', '/v1/chat/completions', msg('over-batch', true), $key);
    same(200, $r['status']);
    check(!str_contains($r['body'], 'WWWW'), 'the event queued after the over-limit report is not relayed');
    same(0, settled($ref)['balance'], 'charged the reservation, never more');
}

function testIntegrationAnUpstreamErrorAfterTheStreamStartedEndsItWithoutDone(): void
{
    // model => [text relayed before the error, minimum charge proving that text was counted]
    foreach (['error-after-start' => ['partial', 12], 'named-error' => ['partial', 12], 'error-batched' => ['VVVV', 1000]] as $model => [$text, $min]) {
        [$ref, $key] = fundedKey(10000);
        $r = http('POST', '/v1/chat/completions', msg($model, true), $key);
        same(200, $r['status'], "$model: the 200 was already sent");
        check(str_contains($r['body'], $text), "$model: output relayed before the error is kept");
        check(str_contains($r['body'], 'upstream stream ended early') && !str_contains($r['body'], '[DONE]'), "$model: generic in-band error, no [DONE]");
        checkNoUpstreamDetails($r['body']);
        $charged = 10000 - settled($ref)['balance'];
        check($charged >= $min, "$model: the relayed output is charged (at least $min), got $charged");
    }
}

function testIntegrationTheUpstreamIsLeftAtTheTerminalMarker(): void
{
    [$ref, $key] = fundedKey(10000);
    $t = microtime(true);
    $r = http('POST', '/v1/chat/completions', msg('done-hang', true), $key);
    $elapsed = microtime(true) - $t;
    same(200, $r['status']);
    check($elapsed < 2.0, sprintf('the request ends at [DONE], not when the upstream closes 3 s later: took %.1f s', $elapsed));
    settled($ref);
    same(12, lastUsage($ref)['charged']);
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
    $w = array_values(array_filter($m['data'], static fn ($d) => $d['id'] === 'weighted'))[0];
    same(['input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000], $w['credits_per_million_tokens'], 'rates are listed per alias');
    check(!str_contains(json_encode($m), 'scenario-'), 'upstream model ids stay hidden');
    same(['balance' => 777, 'reserved' => 0, 'in_flight' => 0, 'limits' => ['max_concurrent' => 5, 'requests_per_minute' => null, 'credits_per_minute' => null],
        'window' => ['requests' => 0, 'credits' => 0]], json_decode(http('GET', '/v1/balance', null, $key)['body'], true));
}

function testIntegrationAdminFlow(): void
{
    $ref = 'user-' . bin2hex(random_bytes(3));
    same(401, http('POST', '/admin/keys', ['ref' => $ref], 'wrong-token')['status']);
    $r = http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret');
    same(201, $r['status']);
    $key = json_decode($r['body'], true)['key'];
    same(409, http('POST', '/admin/keys', ['ref' => $ref], 'admin-secret')['status']);

    $g = ['ref' => $ref, 'grant_id' => "inv-$ref", 'credits' => 5000];
    same(['applied' => true, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true));
    same(['applied' => false, 'balance' => 5000], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true), 'retried push');
    same(409, http('POST', '/admin/grants', ['credits' => 6000] + $g, 'admin-secret')['status'], 'same grant_id, different amount');
    same(400, http('POST', '/admin/grants', ['ref' => $ref, 'grant_id' => 'old-field', 'tokens' => 10], 'admin-secret')['status'], 'the field is credits');

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

function testIntegrationLogsRecordEventsButNeverBodiesOrKeys(): void
{
    [$ref, $key] = fundedKey(10000);
    $secret = 'SECRET-PROMPT-' . bin2hex(random_bytes(4));
    $body = ['model' => 'ok', 'stream' => true, 'messages' => [['role' => 'user', 'content' => $secret]]];
    same(200, http('POST', '/v1/chat/completions', $body, $key)['status']);
    same(401, http('POST', '/admin/grants', ['ref' => $ref, 'grant_id' => 'g', 'tokens' => 1], 'wrong-admin-token')['status']);
    same(404, http('GET', "/v1/$key/$secret", null, $key)['status']); // client text in the path is not logged
    settled($ref);
    for ($i = 0; $i < 50; $i++) { // the record is written just after the settlement settled() waits for
        $log = implode('', array_map('file_get_contents', glob(Harness::get()['dir'] . '/logs/*.jsonl')));
        $records = array_map(static fn (string $l) => json_decode($l, true), array_filter(explode("\n", $log)));
        $mine = array_values(array_filter($records, static fn ($r) => ($r['event'] ?? '') === 'request' && ($r['ref'] ?? '') === $ref));
        if ($mine !== []) {
            break;
        }
        usleep(20000);
    }
    same(1, count($mine), 'one request record for the request');
    same(['info', 'ok', true, true, false], [$mine[0]['level'], $mine[0]['alias'], $mine[0]['stream'], $mine[0]['delivered'], $mine[0]['estimated']]);
    check(in_array('admin_auth_failed', array_column($records, 'event'), true), 'a failed admin authentication is logged');
    check(in_array(404, array_column(array_filter($records, static fn ($r) => ($r['event'] ?? '') === 'refused'), 'status'), true), 'the 404 is logged');
    check(!str_contains($log, $secret), 'the prompt never reaches the log');
    check(!str_contains($log, $key) && !str_contains($log, 'wrong-admin-token'), 'no key or token reaches the log');
    check(!str_contains($log, 'hello world') && !str_contains($log, 'PRIVATE-DETAIL'), 'no response text reaches the log');
}

/** Load smoke: parallel streams across processes; no errors, and the ledger debits exactly what was reported. */
function testIntegrationLoadSmokeUnderParallelStreams(): void
{
    [$ref, $key] = fundedKey(1000000);
    $keyFile = tempDir() . '/key';
    file_put_contents($keyFile, $key);
    $cmd = [PHP_BINARY, dirname(__DIR__) . '/tools/bench.php', '--url=' . Harness::get()['proxy'], "--key-file=$keyFile", '--model=ok',
        '--requests=60', '--concurrency=2', '--processes=2', '--stream']; // 4 in flight: under the per-key cap of 5
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    check(preg_match('/^\[virsilipas-bench\] (\{.*\})$/m', $out, $m) === 1, "one result line expected, got: $out $err");
    $r = json_decode($m[1], true);
    same(['ok', 60, 0, true], [$r['status'], $r['ok'], $r['errors'], $r['ledger_exact']], trim($err) . ' ' . json_encode($r['status_counts']));
    same(0, $code);
    same(60 * 21, $r['credits'], 'every stream reported its credits');
    same('virsilipas.bench.v2', $r['schema']);

    // Over the per-key cap (12 in flight against 5): the excess is refused with 429, uncharged, and the ledger stays exact.
    $cmd[6] = '--concurrency=6';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    preg_match('/^\[virsilipas-bench\] (\{.*\})$/m', (string) stream_get_contents($pipes[1]), $m);
    proc_close($p);
    $r = json_decode($m[1] ?? 'null', true);
    check(($r['status_counts']['429'] ?? 0) > 0 && $r['ledger_exact'] === true, 'cap refusals are 429 and the ledger stays exact: ' . json_encode($r));
}

/** A run where every request fails to connect: a tagged fail line, status_counts keyed "0", exit 1. */
function testIntegrationBenchReportsTransportFailuresAsAStatusMap(): void
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s); // nothing listens on this port now
    $env = ['VIRSILIPAS_BENCH_KEY' => 'unused', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/bench.php', "--url=http://127.0.0.1:$port", '--requests=2', '--no-ledger-check', '--timeout=5'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $code = proc_close($p);
    check(preg_match('/^\[virsilipas-bench\] (\{.*\})$/m', $out, $m) === 1, "one result line expected, got: $out");
    check(str_contains($m[1], '"status_counts":{"0":2}'), "status_counts stays an object: {$m[1]}");
    same(['fail', 2], [json_decode($m[1], true)['status'], json_decode($m[1], true)['errors']]);
    same(1, $code);
}

function testIntegrationBenchRefusesMoreProcessesThanRequests(): void
{
    $env = ['VIRSILIPAS_BENCH_KEY' => 'unused', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/bench.php', '--url=http://127.0.0.1:1', '--requests=1', '--processes=2'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    same(2, proc_close($p), 'an option error');
    same('', $out, 'no result line');
}

/** The ledger check was requested but /v1/balance fails: the run must fail, not skip the check. */
function testIntegrationBenchFailsWhenTheStartingBalanceIsUnreadable(): void
{
    $dir = tempDir();
    file_put_contents("$dir/router.php", '<?php header("Content-Type: application/json");'
        . 'if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/v1/balance") { http_response_code(503); echo "{}"; return; }'
        . 'echo json_encode(["choices" => [["index" => 0, "message" => ["role" => "assistant", "content" => "ok"], "finish_reason" => "stop"]],'
        . '"usage" => ["prompt_tokens" => 1, "completion_tokens" => 1, "total_tokens" => 2]]);');
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    try {
        for ($i = 0; $i < 100 && !($c = @fsockopen('127.0.0.1', $port)); $i++) {
            usleep(50000);
        }
        $env = ['VIRSILIPAS_BENCH_KEY' => 'unused', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/bench.php', "--url=http://127.0.0.1:$port", '--requests=3', '--concurrency=1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $bp, null, $env);
        $out = stream_get_contents($bp[1]);
        stream_get_contents($bp[2]);
        $code = proc_close($p);
        check(preg_match('/^\[virsilipas-bench\] (\{.*\})$/m', $out, $m) === 1, "one result line expected, got: $out");
        $r = json_decode($m[1], true);
        same(['fail', 3, 0, false], [$r['status'], $r['ok'], $r['errors'], $r['ledger_exact']]);
        same(1, $code);
    } finally {
        proc_terminate($srv);
    }
}
/** A prompt long enough that its bound covers the 1000 prompt tokens the cached scenario reports. */
function cachedMsg(bool $stream): array
{
    return ['model' => 'weighted', 'stream' => $stream, 'max_tokens' => 100, 'messages' => [['role' => 'user', 'content' => str_repeat('a', 1100)]]];
}

function testIntegrationCachedInputIsChargedAtItsOwnRate(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(100000);
        $r = http('POST', '/v1/chat/completions', cachedMsg($stream), $key);
        same(200, $r['status']);
        checkNoUpstreamDetails($r['body']);
        // 200 fresh x 2 + 800 cached x 0.5 + 50 output x 8 credits.
        check(str_contains($r['body'], '"prompt_tokens_details":{"cached_tokens":800},"credits":1200'), 'usage shows cached tokens and credits: ' . substr($r['body'], -300));
        same(100000 - 1200, settled($ref)['balance'], $stream ? 'stream' : 'non-stream');
        if ($stream) {
            $last = null;
            foreach (explode("\n", $r['body']) as $line) {
                $e = str_starts_with($line, 'data: {') ? json_decode(substr($line, 6), true) : null;
                $last = isset($e['usage']) ? $e['usage'] : $last;
            }
            same([800, 1200], [$last['prompt_tokens_details']['cached_tokens'] ?? null, $last['credits'] ?? null], 'the last usage the client sees is what it was charged, after repeats without or with other details');
        }
        $u = lastUsage($ref);
        same([1000, 50, 800, 1200, 1200, 0], [$u['prompt_tokens'], $u['completion_tokens'], $u['cached_tokens'], $u['reported'], $u['charged'], $u['estimated']]);
    }
}

function testIntegrationOperatorSetsLimitsAndClientsSeeThem(): void
{
    [$ref, $key] = fundedKey(10000);
    $set = ['ref' => $ref, 'requests_per_minute' => 1];
    same(401, http('POST', '/admin/keys/limits', $set)['status'], 'no token');
    same(401, http('POST', '/admin/keys/limits', $set, $key)['status'], 'a client key is not the admin token');
    $r = json_decode(http('POST', '/admin/keys/limits', $set, 'admin-secret')['body'], true);
    same(['ref' => $ref, 'overrides' => ['max_concurrent' => null, 'requests_per_minute' => 1, 'credits_per_minute' => null],
        'limits' => ['max_concurrent' => 5, 'requests_per_minute' => 1, 'credits_per_minute' => null]], $r);
    same(400, http('POST', '/admin/keys/limits', ['ref' => $ref, 'burst' => 3], 'admin-secret')['status'], 'unknown limit');
    same(404, http('POST', '/admin/keys/limits', ['ref' => 'nobody-' . $ref, 'max_concurrent' => 2], 'admin-secret')['status']);

    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    settled($ref);
    $refused = http('POST', '/v1/chat/completions', msg('ok', false), $key);
    same(429, $refused['status']);
    check(str_contains($refused['body'], 'requests per minute limit reached'), 'the reason is named');
    check($refused['retry_after'] !== null && $refused['retry_after'] >= 1 && $refused['retry_after'] <= 60, 'Retry-After within the minute');
    $b = json_decode(http('GET', '/v1/balance', null, $key)['body'], true);
    same([1, ['requests' => 1, 'credits' => 18]], [$b['limits']['requests_per_minute'], $b['window']], 'the client sees its limit and its last minute');
    [, $other] = fundedKey(10000);
    $o = json_decode(http('GET', '/v1/balance?ref=' . urlencode($ref), null, $other)['body'], true);
    same([10000, null, ['requests' => 0, 'credits' => 0]], [$o['balance'], $o['limits']['requests_per_minute'], $o['window']], 'another key sees only its own balance, limits and window');

    same(200, http('POST', '/admin/keys/limits', ['ref' => $ref, 'requests_per_minute' => null], 'admin-secret')['status']);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status'], 'back to the default: no limit');
}
function testIntegrationStreamStopsWhenTheCreditsPassTheReservation(): void
{
    // Reservation: 1 credit for the prompt + 2 tokens x 8 = 17 credits. Event i reports 11 + i tokens,
    // which cost 8i + 1 credits: past 17 at the third event, while the token count passes 17 only at the seventh.
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/chat/completions', msg('cheap-input', true, ['max_tokens' => 2]), $key);
    same(200, $r['status']);
    check(str_contains($r['body'], 't3 ') && !str_contains($r['body'], 't4 '), 'stopped right after the event that passed the reservation');
    same(10000 - 17, settled($ref)['balance'], 'charged the reservation, never more');
    same(1, lastUsage($ref)['over_reservation']);
}
function testIntegrationARepeatedTotalAddsDetailButNeverASplit(): void
{
    [$ref, $key] = fundedKey(10000);
    same(200, http('POST', '/v1/chat/completions', msg('weighted-shift', true), $key)['status']);
    // The first report stands: 10 prompt x 2 + 20 output x 8. The re-split repeat (29 x 2 + 1 x 8 = 66) is ignored.
    same(10000 - 180, settled($ref)['balance']);
}