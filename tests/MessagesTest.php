<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Messages;
use MagnaCapax\Virsilipas\Proxy;

// POST /v1/messages and /v1/messages/count_tokens: the Anthropic Messages pass-through, metered like chat.

function anthBody(string $model, bool $stream, array $extra = []): array
{
    return $extra + ['model' => $model, 'max_tokens' => 1000, 'stream' => $stream, 'messages' => [['role' => 'user', 'content' => 'Say hello.']]];
}

/** The error object of an Anthropic-format error answer, after checking the shape. */
function anthError(array $r): array
{
    $j = json_decode($r['body'], true);
    check(is_array($j) && ($j['type'] ?? null) === 'error' && is_string($j['error']['type'] ?? null) && is_string($j['error']['message'] ?? null)
        && array_keys($j) === ['type', 'error'] && array_keys($j['error']) === ['type', 'message'], 'Anthropic error shape: ' . $r['body']);
    return $j['error'];
}

/** The events of an Anthropic stream as [name, data] pairs. */
function anthEvents(string $body): array
{
    $out = [];
    foreach (explode("\n\n", trim($body)) as $chunk) {
        if (preg_match('/^event: (\S+)\ndata: (.*)$/s', $chunk, $m)) {
            $out[] = [$m[1], json_decode($m[2], true)];
        }
    }
    return $out;
}

function lastUpstreamRequest(): array
{
    return json_decode((string) file_get_contents(Harness::get()['dir'] . '/last-request.json'), true);
}

function testMessagesNonStreamIsMeteredExactlyIncludingCachedInput(): void
{
    [$ref, $key] = fundedKey(100000);
    $r = http('POST', '/v1/messages', anthBody('anth', false), null, 0, ["x-api-key: $key", 'anthropic-version: 2023-06-01', 'anthropic-beta: example-beta-1']);
    same(200, $r['status'], $r['body']);
    checkNoUpstreamDetails($r['body']);
    $j = json_decode($r['body'], true);
    same(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'anth',
        'content' => [['type' => 'thinking', 'thinking' => 'pondering', 'signature' => 'sig'], ['type' => 'text', 'text' => 'hello world']],
        'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 800, 'output_tokens' => 40]], $j);
    // (100 fresh + 50 cache writes) x 2 + 800 cache reads x 0.5 + 40 output x 8 credits.
    same(100000 - 1020, settled($ref)['balance']);
    $u = lastUsage($ref);
    same([950, 800, 40, 0, 1020, 1020, 0, 0, '127.0.0.1', 0, 1], [$u['prompt_tokens'], $u['cached_tokens'], $u['completion_tokens'], $u['reasoning_tokens'],
        $u['reported'], $u['charged'], $u['estimated'], $u['aborted'], $u['upstream'], $u['stream'], $u['attempts']]);
    $up = lastUpstreamRequest();
    same(['scenario-anth-ok', '/second/v1/messages', 'second-upstream-key', 'Bearer second-upstream-key', '2023-06-01', 'example-beta-1'],
        [$up['model'], $up['path'], $up['x_api_key'], $up['auth'], $up['anthropic_version'], $up['anthropic_beta']], 'alias mapped, the upstream\'s own key, headers forwarded');
}

function testMessagesStreamIsRelayedAndMeteredExactly(): void
{
    [$ref, $key] = fundedKey(100000);
    $r = http('POST', '/v1/messages', anthBody('anth', true), $key);
    same(200, $r['status'], $r['body']);
    checkNoUpstreamDetails($r['body']);
    $events = anthEvents($r['body']);
    same(['message_start', 'content_block_start', 'content_block_delta'], array_column(array_slice($events, 0, 3), 0), 'starts at message_start; a ping before content is not relayed');
    same(['content_block_stop', 'message_delta', 'message_stop'], array_column(array_slice($events, -3), 0), 'ends at message_stop');
    $start = $events[0][1]['message'];
    same(['anth', [], ['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 800, 'output_tokens' => 1]],
        [$start['model'], $start['content'], $start['usage']], 'message_start rebuilt: the alias, the standard counts');
    $text = implode('', array_map(static fn (array $e): string => $e[1]['delta']['text'] ?? '', $events));
    same('t1 t2 t3 t4 t5 t6 t7 t8 t9 t10 ', $text, 'content deltas relayed unchanged');
    same(['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 't1 ']], array_diff_key($events[2][1], ['type' => 1]));
    same(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null],
        'usage' => ['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 800, 'output_tokens' => 40]], $events[count($events) - 2][1]);
    same(100000 - 1020, settled($ref)['balance']);
    $u = lastUsage($ref);
    same([950, 800, 40, 1020, 0, 0, 1], [$u['prompt_tokens'], $u['cached_tokens'], $u['completion_tokens'], $u['charged'], $u['estimated'], $u['aborted'], $u['stream']]);
    check($u['first_byte_ms'] !== null, 'time to the first relayed byte recorded');
}

function testMessagesStreamClientReceivesTheAdoptedInputAndCacheCounts(): void
{
    foreach (['zero', 'missing', 'partial', 'cache', 'preserve', 'invalid', 'decreasing', 'bad-cache', 'no-usage', 'broken'] as $case) {
        [$ref, $key] = fundedKey(100000);
        $r = http('POST', '/v1/messages', anthBody('anth-usage-' . $case, true), $key);
        same(200, $r['status'], $case . ': stream starts with usable content');
        checkNoUpstreamDetails($r['body']);
        $events = anthEvents($r['body']);
        $deltas = array_values(array_filter($events, static fn (array $e): bool => $e[0] === 'message_delta'));
        same(1, count($deltas), $case . ': one final usage delta reaches the client');
        $expected = ['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 800,
            'output_tokens' => in_array($case, ['invalid', 'bad-cache'], true) ? 1 : 40];
        if ($case === 'no-usage') {
            $expected = array_fill_keys(array_keys($expected), 0);
        }
        same($expected, $deltas[0][1]['usage'], $case . ': all four standard counts use the adopted report');
        $u = lastUsage($ref);
        $exact = !in_array($case, ['invalid', 'decreasing', 'bad-cache', 'no-usage'], true);
        if ($exact) {
            same([950, 800, 40, 1020, 0], [$u['prompt_tokens'], $u['cached_tokens'], $u['completion_tokens'], $u['charged'], $u['estimated']],
                $case . ': ledger and client see the same accepted report');
        } else {
            same(1, $u['estimated'], $case . ': invalid/missing/non-advancing usage keeps the output byte bound');
            check($u['charged'] > 0, $case . ': missing usage cannot become free output');
            if ($case !== 'no-usage') {
                same([950, 800], [$u['prompt_tokens'], $u['cached_tokens']], $case . ': invalid later reports preserve accepted input');
            }
        }
        same(100000 - $u['charged'], settled($ref)['balance'], $case . ': balance charged exactly once');
        same($case !== 'broken', in_array('message_stop', array_column($events, 0), true), $case . ': terminal marker reflects stream completion');
        same($case === 'broken', in_array('error', array_column($events, 0), true), $case . ': broken stream remains an in-band error');
        echo "ok   messages-stream-usage-case:$case\n";
    }
}

function testMessagesHangUpMidStreamIsChargedTheUpstreamCount(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = http('POST', '/v1/messages', anthBody('anth-slow', true), $key, 700);
    check(str_contains($r['body'], 'content_block_delta') && !str_contains($r['body'], 'message_stop'), 'the client hung up mid-stream');
    $s = settled($ref);
    $u = lastUsage($ref);
    // The upstream's final report (11 input + 10 output at unit rates), read after the hang-up, not the byte bounds.
    same([1, 0, 21, 11, 10], [$u['aborted'], $u['estimated'], $u['charged'], $u['prompt_tokens'], $u['completion_tokens']], 'aborted, exact, the final report');
    same(10000 - 21, $s['balance']);
}

function testMessagesAcceptsTheKeyAsXApiKeyOrBearer(): void
{
    [$ref, $key] = fundedKey(100000);
    same(200, http('POST', '/v1/messages', anthBody('anth', false), null, 0, ["x-api-key: $key"])['status'], 'x-api-key');
    same(200, http('POST', '/v1/messages', anthBody('anth', false), $key)['status'], 'Authorization: Bearer');
    same(100000 - 2 * 1020, settled($ref)['balance']);
    $missing = http('POST', '/v1/messages', anthBody('anth', false));
    same([401, 'authentication_error', 'missing key: send x-api-key or Authorization: Bearer'], [$missing['status'], ...array_values(anthError($missing))]);
    $wrong = http('POST', '/v1/messages', anthBody('anth', false), null, 0, ['x-api-key: vsk-wrong']);
    same([401, 'authentication_error'], [$wrong['status'], anthError($wrong)['type']]);
    same(401, http('POST', '/v1/messages/count_tokens', anthBody('anth', false))['status'], 'count_tokens needs a key too');
}

function testMessagesRefusesAModelWithoutAMessagesUrl(): void
{
    [$ref, $key] = fundedKey(10000);
    foreach (['ok', 'second-upstream', 'no-such-alias'] as $alias) {
        foreach (['/v1/messages', '/v1/messages/count_tokens'] as $path) {
            $r = http('POST', $path, anthBody($alias, false), $key);
            same([404, 'not_found_error'], [$r['status'], anthError($r)['type']], "$alias $path");
        }
    }
    same(10000, settled($ref)['balance'], 'nothing reserved or charged');
    // Only the route entries with a messages_url serve the format: anth-mixed's first entry has none.
    same(200, http('POST', '/v1/messages', anthBody('anth-mixed', false), $key)['status']);
    same('/second/v1/messages', lastUpstreamRequest()['path']);
    same(404, http('GET', '/v1/messages', null, $key)['status'], 'only POST');
    same('not_found_error', anthError(http('GET', '/v1/messages', null, $key))['type'], 'an unknown method on the route answers in its format');
}

function testMessagesForwardsOnlyAllowlistedFields(): void
{
    [, $key] = fundedKey(100000);
    $body = anthBody('anth', false, ['max_tokens' => 5000, 'container' => 'c1', 'mcp_servers' => [['url' => 'https://example.com']], 'service_tier' => 'auto', 'n' => 3,
        'system' => [['type' => 'text', 'text' => 'be brief', 'cache_control' => ['type' => 'ephemeral'], 'citations' => []]], 'temperature' => 0.5, 'top_k' => 5,
        'metadata' => ['user_id' => 'u1'], 'thinking' => ['type' => 'enabled', 'budget_tokens' => 4000],
        'tools' => [['name' => 'f', 'description' => 'd', 'input_schema' => ['type' => 'object'], 'extra_flag' => true]], 'tool_choice' => ['type' => 'auto'],
        'messages' => [['role' => 'user', 'id' => 'm1', 'content' => [['type' => 'text', 'text' => 'hi', 'cache_control' => ['type' => 'ephemeral'], 'citations' => []]]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'f', 'input' => new stdClass()]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => [['type' => 'text', 'text' => 'ok']]]]]]]);
    same(200, http('POST', '/v1/messages', $body, $key)['status']);
    $up = lastUpstreamRequest();
    $keys = $up['keys'];
    sort($keys);
    same(['max_tokens', 'messages', 'model', 'stream', 'system', 'temperature', 'thinking', 'tool_choice', 'tools', 'top_k'], $keys, 'unknown fields dropped, the client\'s metadata among them');
    same(['type', 'text', 'cache_control'], $up['block_keys'], 'unknown block fields dropped');
    same(['name', 'description', 'input_schema'], $up['tool_keys'], 'unknown tool fields dropped');
    same([1000, ['type' => 'enabled', 'budget_tokens' => 999]], [$up['max_tokens'], $up['thinking']], 'max_tokens clamped to the cap, the thinking budget below it');
    same('2023-06-01', $up['anthropic_version'], 'the default version when the client sends none');
    foreach ([
        ['messages' => [['role' => 'user', 'content' => [['type' => 'image']]]]],
        ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['inner_extra' => 1]]]]]],
        ['messages' => [['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => [['type' => 'image', 'source' => []]]]]]]],
        ['messages' => [['role' => 'user', 'content' => [['type' => 'audio', 'source' => ['type' => 'base64', 'data' => 'AAAA']]]]]],
        ['tools' => [['type' => 'web_search_20250305', 'name' => 'web_search']]],
        ['messages' => []], ['messages' => [['content' => 'no role']]], ['messages' => [['role' => 'user', 'content' => 42]]],
        ['system' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'f', 'input' => []]]],
        ['max_tokens' => 0],
    ] as $bad) {
        $r = http('POST', '/v1/messages', $bad + anthBody('anth', false), $key);
        same([400, 'invalid_request_error'], [$r['status'], anthError($r)['type']], json_encode($bad));
    }
}

/** Claude Code sends metadata.user_id as a JSON string holding a per-install device id and the session id; none of it may reach the upstream. */
function testMessagesDropsTheClientsMetadata(): void
{
    [, $key] = fundedKey(100000);
    $userId = json_encode(['device_id' => str_repeat('ab', 32), 'account_uuid' => '', 'session_id' => '11111111-2222-3333-4444-555555555555']);
    foreach ([false, true] as $stream) {
        same(200, http('POST', '/v1/messages', anthBody('anth', $stream, ['metadata' => ['user_id' => $userId, 'extra' => 'x']]), $key)['status']);
        $keys = lastUpstreamRequest()['keys'];
        check(!in_array('metadata', $keys, true), 'metadata does not reach the upstream, stream=' . json_encode($stream));
        check(in_array('messages', $keys, true), 'the rest of the request does');
    }
    $fields = Messages::fields(anthBody('anth', false, ['metadata' => ['user_id' => $userId]]));
    check(!array_key_exists('metadata', $fields), 'fields() drops metadata');
}

function testMessagesBoundsImagesByTheirUpstreamTokenCount(): void
{
    [$ref, $key] = fundedKey(1000000);
    $image = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('A', 100000)], 'extra' => 1];
    $doc = ['type' => 'document', 'source' => ['type' => 'url', 'url' => 'https://example.com/a.pdf']];
    $body = anthBody('anth', false, ['messages' => [['role' => 'user', 'content' => [$image]],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => new stdClass()]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => [$image]]]]]]);
    $r = http('POST', '/v1/messages', $body, $key);
    same(200, $r['status'], $r['body']);
    same(['type', 'source'], lastUpstreamRequest()['block_keys'], 'an image keeps its type and source, unknown fields dropped');
    $fields = Messages::fields($body);
    $inner = $image;
    $inner['source'] += ['inner_extra' => ['x' => 1]];
    $cleaned = Messages::fields(anthBody('anth', false, ['messages' => [['role' => 'user', 'content' => [$inner]]]]));
    same($image['source'], $cleaned['messages'][0]['content'][0]['source'], 'an image source keeps only type, media_type, data and url');
    $bareSource = Messages::fields(anthBody('anth', false, ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['url' => 'https://example.com/a.png', 'inner_extra' => 1]]]]]]));
    same(['url' => 'https://example.com/a.png'], $bareSource['messages'][0]['content'][0]['source'], 'a url source is kept');
    $plain = $fields;
    $plain['messages'] = [['role' => 'user', 'content' => 'x']];
    $bound = Messages::promptBound($fields, 1000);
    check($bound >= 2 * 1000 && $bound < 2 * 1000 + 2000, 'each image, tool results included, counts as the upstream token count, not its base64 bytes');
    same(Messages::promptBound($plain, null), Messages::promptBound($plain, 1000), 'text alone: no image term');
    $count = static fn (string $alias): int => json_decode(http('POST', '/v1/messages/count_tokens', ['model' => $alias] + $body, $key)['body'], true)['input_tokens'] ?? 0;
    same($bound, $count('anth'), 'count_tokens answers the bound');
    same(Messages::promptBound($fields, 3000), $count('vision-two'), 'the route bound is the larger of its upstreams');
    foreach ([['anth', [$doc], 'documents are not enabled for this model'], ['anth-overloaded', [$image], 'images are not enabled for this model']] as [$alias, $content, $message]) {
        $r = http('POST', '/v1/messages', anthBody($alias, false, ['messages' => [['role' => 'user', 'content' => $content]]]), $key);
        same([400, $message], [$r['status'], anthError($r)['message']], $alias);
    }
}

function testMessagesInsufficientBalanceIsAnAnthropicError(): void
{
    [$ref, $key] = fundedKey(5);
    $r = http('POST', '/v1/messages', anthBody('anth', true), $key);
    same([402, 'billing_error', 'credit balance too low for this request'], [$r['status'], ...array_values(anthError($r))]);
    same(5, settled($ref)['balance']);
}

function testMessagesUpstreamFailureIsAnAnthropicErrorAndFree(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(10000);
        $r = http('POST', '/v1/messages', anthBody('anth-overloaded', $stream), $key);
        same([502, 'api_error'], [$r['status'], anthError($r)['type']], 'a 529 is the upstream\'s failure');
        checkNoUpstreamDetails($r['body']);
        same(10000, settled($ref)['balance'], 'nothing charged');
        check(is_int($r['request_id']), 'the request id is still sent');
    }
}

function testMessagesCountTokensIsAFreeConservativeEstimate(): void
{
    [$ref, $key] = fundedKey(1000);
    $body = anthBody('anth', false, ['tools' => [['name' => 'f', 'input_schema' => ['type' => 'object']]]]);
    $r = http('POST', '/v1/messages/count_tokens', $body, null, 0, ["x-api-key: $key"]);
    same(200, $r['status'], $r['body']);
    $n = json_decode($r['body'], true)['input_tokens'] ?? null;
    same(Messages::promptBound(Messages::fields($body), 1000), $n, 'the prompt bound a request would reserve for');
    check($n > Messages::TOOLS_OVERHEAD, 'it covers the tool-use prompt the upstream adds');
    same(1000, settled($ref)['balance'], 'nothing reserved or charged');
    $db = new \PDO('sqlite:' . Harness::get()['dir'] . '/ledger.sqlite');
    $st = $db->prepare('SELECT COUNT(*) FROM usage u JOIN keys k ON k.id = u.key_id WHERE k.ref = ?');
    $st->execute([$ref]);
    same(0, (int) $st->fetchColumn(), 'no usage row');
}

function testMessagesUsageMapping(): void
{
    foreach ([['cost' => 0.001, 'expected' => 1000], ['estimated_cost' => 0.002, 'expected' => 2000],
        ['cost' => -1, 'expected' => null], ['cost' => '0.1', 'expected' => null], ['cost' => INF, 'expected' => null]] as $case) {
        same($case['expected'], Messages::usage(['input_tokens' => 1, 'output_tokens' => 1] + $case)['cost']);
    }
    same(['prompt' => 950, 'completion' => 40, 'total' => 990, 'cached' => 800, 'reasoning' => 0, 'cost' => null, 'input' => 100, 'creation' => 50],
        Messages::usage(['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 800, 'output_tokens' => 40]));
    same(['prompt' => 11, 'completion' => 0, 'total' => 11, 'cached' => 0, 'reasoning' => 0, 'cost' => null, 'input' => 11, 'creation' => 0],
        Messages::usage(['input_tokens' => 11, 'output_tokens' => 0, 'cache_read_input_tokens' => null]), 'absent or null cache counts are 0');
    foreach ([null, [], ['input_tokens' => 10], ['output_tokens' => 5], ['input_tokens' => '10', 'output_tokens' => 5], ['input_tokens' => 0, 'output_tokens' => 5],
        ['input_tokens' => -1, 'output_tokens' => 5], ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 'x'],
        ['input_tokens' => 10, 'output_tokens' => 5, 'cache_creation_input_tokens' => -3]] as $u) {
        same(null, Messages::usage($u), json_encode($u));
    }
    same(Messages::usage(['input_tokens' => 1, 'output_tokens' => 0, 'cache_read_input_tokens' => 9])['prompt'], 10, 'a full cache hit still has a prompt');
}

function testMessagesStreamReportedCostSurvivesEventsChargeAndSettlement(): void
{
    foreach ([['cost', 'advance', 0.001, 1000], ['estimated_cost', 'repeat', 0.002, 2000], ['cost', 'repeat', 0, 0]] as [$field, $when, $cost, $expected]) {
        [$ledger, $id] = fundedLedger(1000);
        $lease = $ledger->reserve($id, 20, 100, 1000, unitRates(), lim(), 60);
        // The state and public calls are the production forward -> event -> charge -> settle path, without HTTP.
        $state = ['started' => false, 'start' => false, 'done' => false, 'failed' => false, 'bad' => false,
            'raw' => [], 'usage' => null, 'unmetered' => 0, 'over' => false, 'held' => '', 'aborted' => false, 'bytes' => 0];
        $client = '';
        $send = static function (array $event) use (&$state, &$client, $lease): void {
            $out = Messages::event($state, Messages::sse($event['type'], $event), 'alias', unitRates(), $lease['reserved']);
            if ($out !== '') $state['started'] = true; // forward() marks the stream when the held start is released
            $client .= $out;
        };
        $send(['type' => 'message_start', 'message' => ['id' => 'fixture-message', 'type' => 'message',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]]);
        $send(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => 'hello']]);
        $advance = ['output_tokens' => 4] + ($when === 'advance' ? [$field => $cost] : []);
        $send(['type' => 'message_delta', 'usage' => $advance]);
        same(0, $state['unmetered'], 'an advancing report still covers earlier delivered bytes');
        $send(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'tail']]);
        same(14, $state['unmetered'], 'the later delivered strings retain their byte bound');
        $send(['type' => 'message_delta', 'usage' => ['output_tokens' => 4, $field => $when === 'repeat' ? $cost : 99.0]]);
        same([$expected, 14], [$state['usage']['cost'], $state['unmetered']], 'repeat fills only missing cost and covers no new bytes');
        foreach ([['output_tokens' => 4, $field => 99.0], ['output_tokens' => 4, $field => null],
            ['output_tokens' => 4, $field => 'invalid'], ['output_tokens' => 3, $field => 99.0]] as $repeat) {
            $send(['type' => 'message_delta', 'usage' => $repeat]);
            same([$expected, 4, 14], [$state['usage']['cost'], $state['usage']['completion'], $state['unmetered']],
                'equal or lower reports cannot replace accepted cost or waive delivered bytes');
        }
        $send(['type' => 'message_stop']);
        [$used, $meta] = Proxy::charge(['status' => 200, 'usage' => $state['usage'], 'aborted' => false,
            'delivered' => true, 'completion_bound' => $state['bytes'], 'unmetered' => $state['unmetered']], 20, 'alias', unitRates());
        same([28, $expected, true], [$used, $meta['cost'], $meta['estimated']], 'cost metadata never changes the token and byte-bound charge');
        same(true, $ledger->settle($lease['lease_id'], $id, $used, ['stream' => true] + $meta));
        $stats = $ledger->stats('all')[0];
        same([972, 28, $expected, 0], [$ledger->status('a', lim())['balance'], $stats['credits'], $stats['upstream_cost'], $stats['computed_cost_requests']],
            'settlement persists reported cost independently of charged credits');
        same([], $ledger->verify()['problems']);
        check(!str_contains($client, 'estimated_cost') && !str_contains($client, '"cost"'), 'reported cost never reaches the client stream');
        check(str_contains($client, 'hello') && str_contains($client, 'tail'), 'the actual content was relayed');
    }
}

function testMessagesAdvancingReportDoesNotInheritAnEarlierCost(): void
{
    $state = ['started' => true, 'start' => true, 'done' => false, 'failed' => false, 'bad' => false,
        'raw' => ['input_tokens' => 10, 'output_tokens' => 1],
        'usage' => Messages::usage(['input_tokens' => 10, 'output_tokens' => 1, 'cost' => 0.1]),
        'unmetered' => 7, 'over' => false, 'held' => '', 'aborted' => false, 'bytes' => 7];
    Messages::event($state, Messages::sse('message_delta', ['type' => 'message_delta', 'usage' => ['output_tokens' => 2]]), 'alias', unitRates(), 100);
    same([12, null, 0], [$state['usage']['total'], $state['usage']['cost'], $state['unmetered']],
        'a new cumulative total needs its own cost report, matching chat adoption');
}

function testMessagesConfigValidatesMessagesUrl(): void
{
    $dir = tempDir();
    file_put_contents("$dir/a.key", "key-a\n");
    file_put_contents("$dir/m.key", "key-m\n");
    file_put_contents("$dir/upstream.key", "key-g\n");
    $model = ['input' => 1, 'cached_input' => 1, 'output' => 1];
    $base = configArrayFor($dir);
    $up = ['a' => ['url' => 'https://a.example.com/v1/chat/completions', 'messages_url' => 'https://a.example.com/v1/messages', 'key_file' => "$dir/a.key"]];
    $c = new Config(['upstreams' => $up, 'models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'x']]],
        'own' => $model + ['model' => 'y', 'url' => 'http://127.0.0.1:9/v1/chat/completions', 'messages_url' => 'http://localhost:9/v1/messages', 'key_file' => "$dir/m.key"],
        'global' => $model + ['model' => 'z', 'messages_url' => 'https://g.example.com/v1/messages', 'key_file' => "$dir/m.key"]]] + $base);
    same(['https://a.example.com/v1/messages', 'key-a'], [$c->route('m')['messages_url'], $c->route('m')['key']], 'an upstream\'s messages_url goes with its own key');
    same(['http://localhost:9/v1/messages', 'key-m'], [$c->route('own')['messages_url'], $c->route('own')['key']], 'a model\'s own messages_url, plain http to this machine');
    same(['https://g.example.com/v1/messages', 'key-m'], [$c->route('global')['messages_url'], $c->route('global')['key']], 'with the global url: its own key file');
    same(null, (new Config($base))->route('ok')['messages_url'], 'none unless configured');
    // Each bad case replaces the upstreams or the models of a good config, so it fails for its own reason only.
    $good = ['upstreams' => $up, 'models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'x']]]]];
    new Config($good + $base);
    foreach ([
        ['upstreams' => ['a' => ['messages_url' => 'http://a.example.com/v1/messages'] + $up['a']]],      // plain http off this machine
        ['upstreams' => ['a' => ['messages_url' => ''] + $up['a']]],
        ['upstreams' => ['a' => ['messages_uri' => 'https://a.example.com/v1/messages'] + $up['a']]],     // a typo is not dropped silently
        ['models' => ['m' => $model + ['model' => 'z', 'messages_url' => 'https://g.example.com/v1/messages']]], // the global key never goes elsewhere
        ['models' => ['m' => $model + ['messages_url' => 'https://a.example.com/v1/messages', 'route' => [['upstream' => 'a', 'model' => 'x']]]]],
        ['models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'x', 'messages_url' => 'https://a.example.com/v1/messages']]]]],
    ] as $override) {
        try {
            new Config($override + $good + $base);
            throw new \RuntimeException('accepted bad config: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
}

function testMessagesEmptyObjectsSurviveTheRoundTrip(): void
{
    // A tool with no parameters and a tool call with no arguments carry {}; forwarded as [] an upstream rejects them.
    $raw = '{"model":"a","max_tokens":10,"tools":[{"name":"f","input_schema":{"type":"object","properties":{}}}],"messages":['
        . '{"role":"user","content":"hi"},{"role":"assistant","content":[{"type":"tool_use","id":"t1","name":"f","input":{}}]},'
        . '{"role":"user","content":[{"type":"tool_result","tool_use_id":"t1","content":"ok"}]}]}';
    $out = MagnaCapax\Virsilipas\Proxy::encode(Messages::rewrite(Messages::fields(MagnaCapax\Virsilipas\Http::decode($raw)), 'up', 10, false));
    check(str_contains($out, '"input_schema":{"type":"object","properties":{}}'), 'empty tool schema properties kept as {}: ' . $out);
    check(str_contains($out, '"input":{}'), 'empty tool call input kept as {}');
}

/** One Messages request with an empty upstream request log: [response, upstream requests in order]. */
function messagesAndRequests(string $alias, bool $stream, string $key, array $extra = []): array
{
    $dir = Harness::get()['dir'];
    file_put_contents("$dir/requests.jsonl", '');
    $r = http('POST', '/v1/messages', anthBody($alias, $stream, $extra), $key);
    $lines = array_filter(explode("\n", (string) file_get_contents("$dir/requests.jsonl")));
    return [$r, array_values(array_map(static fn (string $l): array => json_decode($l, true), $lines))];
}

/** The pin the failover aliases of tests/lib.php carry as messages_params. */
const MESSAGES_PIN = ['provider' => ['only' => ['pin-a'], 'allow_fallbacks' => false], 'temperature' => 0.25];

function testMessagesFailsOverToTheSecondUpstreamWithItsOwnKeyAndParams(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(100000);
        [$r, $req] = messagesAndRequests('anth-failover', $stream, $key, ['temperature' => 0.9, 'metadata' => ['user_id' => 'u']]);
        same(200, $r['status'], $r['body']);
        checkNoUpstreamDetails($r['body']);
        // One try plus one retry on the first upstream (503 costs nothing there), then the second one, each with its own key.
        same([['primary', 'fake-upstream-key'], ['primary', 'fake-upstream-key'], ['backup', 'second-upstream-key']], hops($req), 'attempts in order');
        same([null, null, MESSAGES_PIN['provider']], array_column($req, 'provider'), 'the pin goes only to the entry that has it');
        same([0.9, 0.9, 0.25], array_column($req, 'temperature'), 'messages_params win over the client\'s field, on their entry only');
        foreach ($req as $q) {
            check(!in_array('metadata', $q['keys'], true), 'the client\'s metadata reaches no upstream');
        }
        same(100000 - 990, settled($ref)['balance'], 'one answer, one charge, at the alias rate');
        $u = lastUsage($ref);
        same([200, 3, $stream ? 1 : 0], [$u['http_status'], $u['attempts'], $u['stream']], 'the row records the three attempts');
    }
}

function testMessagesAnAliasWithOnlyASecondProviderStyleEntryServesTheFormat(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(100000);
        [$r, $req] = messagesAndRequests('anth-only-second', $stream, $key);
        same(200, $r['status'], $r['body']);
        same([['backup', 'second-upstream-key']], hops($req));
        same([MESSAGES_PIN['provider'], 0.25, 'scenario-anth-ok'], [$req[0]['provider'], $req[0]['temperature'], $req[0]['model']]);
        same(100000 - 990, settled($ref)['balance']);
    }
}

function testMessagesFailoverWorksWithAnImageInAToolResult(): void
{
    [$ref, $key] = fundedKey(100000);
    $image = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('A', 50000)]];
    $messages = [['role' => 'user', 'content' => 'look'],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => new stdClass()]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => [$image]]]]];
    [$r, $req] = messagesAndRequests('anth-failover-image', true, $key, ['messages' => $messages]);
    same(200, $r['status'], $r['body']);
    same(3, count($req), 'the image is bounded by the entry\'s token count, not its bytes, and the failover answers');
    settled($ref);
}

function testMessagesParamsCountInThePromptBound(): void
{
    $fields = Messages::fields(anthBody('anth', false));
    $pad = ['padding' => str_repeat('x', 3000)];
    check(Messages::promptBound($fields, null, $pad) - Messages::promptBound($fields, null) >= 3000, 'every forwarded byte counts, the operator\'s too');
    same(Messages::promptBound($fields, null), Messages::promptBound($fields, null, []), 'no params, no change');
    $up = Messages::rewrite($fields, 'm', 100, false, ['temperature' => 0.1, 'padding' => 'p']);
    same([0.1, 'p', 'm', 100, false], [$up['temperature'], $up['padding'], $up['model'], $up['max_tokens'], $up['stream']]);
    same(['model' => 'm'] + $fields + ['max_tokens' => 100, 'stream' => false], Messages::rewrite($fields, 'm', 100, false), 'without params the request is what v0.21.2 sent');
    // Over HTTP: a key that covers the plain request cannot cover the same request with 3000 bytes of messages_params.
    [$ref, $key] = fundedKey(1000);
    same(200, http('POST', '/v1/messages', anthBody('anth-only-second', false, ['max_tokens' => 5]), $key)['status'], 'the small pin fits');
    settled($ref);
    $r = http('POST', '/v1/messages', anthBody('anth-big-params', false, ['max_tokens' => 5]), $key);
    same([402, 'billing_error'], [$r['status'], anthError($r)['type']], 'the bound includes messages_params, so the reservation is refused');
    settled($ref);
    // count_tokens answers at least what those bytes add, for the route that carries them.
    $count = static fn (string $alias): int => json_decode(http('POST', '/v1/messages/count_tokens', anthBody($alias, false), $key)['body'], true)['input_tokens'] ?? 0;
    check($count('anth-only-second') - $count('anth') >= strlen(json_encode(MESSAGES_PIN)) - 40, 'count_tokens counts messages_params');
    check($count('anth-big-params') - $count('anth') >= 3000, 'count_tokens counts a large messages_params in full');
}

function testMessagesFailoverGuardAnswersTheEarlierRetryableFailure(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(10000);
        [$r, $req] = messagesAndRequests('anth-guard', $stream, $key);
        same(429, $r['status'], 'a 429 first, then the second upstream\'s 400: the client is told about the 429 it can retry');
        same(2, $r['retry_after'], 'its Retry-After is kept');
        same(['rate_limit_error'], [anthError($r)['type']]);
        checkNoUpstreamDetails($r['body']);
        same([['primary', 'fake-upstream-key'], ['primary', 'fake-upstream-key'], ['backup', 'second-upstream-key']], hops($req), 'both upstreams were asked');
        same(10000, settled($ref)['balance'], 'nothing delivered, nothing charged');
        same([400, 3], [lastUsage($ref)['http_status'], lastUsage($ref)['attempts']], 'the usage row still records the last attempt');
    }
    // A request the first upstream rejected itself keeps its 400, and the second is never asked.
    [$ref, $key] = fundedKey(10000);
    [$r, $req] = messagesAndRequests('anth-first-400', false, $key);
    same([400, 'invalid_request_error', 1], [$r['status'], anthError($r)['type'], count($req)], 'a 400 from the first upstream alone stays a 400');
    same('upstream rejected the request (HTTP 400)', anthError($r)['message']);
    // An over-long request is passed on as such, whatever failed before it.
    [$ref, $key] = fundedKey(10000);
    [$r] = messagesAndRequests('anth-guard-context', false, $key);
    same([400, 'the request is longer than the model accepts'], [$r['status'], anthError($r)['message']], 'context_length_exceeded is not masked');
    same(10000, settled($ref)['balance']);
}

function testMessagesAStreamThatBrokeAfterItsFirstByteIsNotFailedOver(): void
{
    [$ref, $key] = fundedKey(100000);
    [$r, $req] = messagesAndRequests('anth-midstream', true, $key);
    same(200, $r['status']);
    same(1, count($req), 'the second upstream is never asked once output was relayed (ADR 0011)');
    check(str_contains($r['body'], 'event: error') && !str_contains($r['body'], 'message_stop'), 'the client gets the in-band error');
    check(settled($ref)['balance'] < 100000, 'what was delivered is charged');
}

function testMessagesParamsConfig(): void
{
    $dir = tempDir();
    foreach (['a', 'b'] as $n) {
        file_put_contents("$dir/$n.key", "key-$n\n");
    }
    $model = ['input' => 1, 'cached_input' => 1, 'output' => 1];
    $base = configArrayFor($dir);
    $up = ['a' => ['url' => 'https://a.example.com/v1/chat/completions', 'messages_url' => 'https://a.example.com/v1/messages', 'key_file' => "$dir/a.key"],
        'b' => ['url' => 'https://b.example.com/v1/chat/completions', 'key_file' => "$dir/b.key"]];
    $c = new Config(['upstreams' => $up, 'models' => [
        'm' => $model + ['messages_params' => ['x' => 1, 'y' => 1], 'route' => [['upstream' => 'a', 'model' => 'p', 'messages_params' => ['y' => 2]],
            ['upstream' => 'b', 'model' => 'q'], ['upstream' => 'a', 'model' => 'r']]],
        'own' => $model + ['model' => 'z', 'url' => 'https://o.example.com/v1/chat/completions', 'messages_url' => 'https://o.example.com/v1/messages',
            'key_file' => "$dir/a.key", 'messages_params' => ['x' => 3]],
        'plain' => $model + ['route' => [['upstream' => 'a', 'model' => 'p']]]]] + $base);
    same(['y' => 2, 'x' => 1], $c->route('m', 0)['messages_params'], 'the entry\'s over the model\'s');
    same([], $c->route('m', 1)['messages_params'], 'an entry whose upstream serves no Messages format has none');
    same(['x' => 1, 'y' => 1], $c->route('m', 2)['messages_params'], 'the model\'s default');
    same(['x' => 3], $c->route('own')['messages_params'], 'a model in the older form');
    same([], $c->route('plain')['messages_params'], 'none unless configured');
    same([], (new Config($base))->routes['ok'][0]['messages_params'], 'a config from before this setting loads with none');
    $good = ['upstreams' => $up, 'models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'p']]]]];
    new Config($good + $base);
    foreach ([
        ['messages_params' => ['model' => 'z']], ['messages_params' => ['messages' => []]], ['messages_params' => ['max_tokens' => 9]], ['messages_params' => ['stream' => true]],
        ['messages_params' => ['a', 'b']], ['messages_params' => 'x'], ['messages_params' => ['' => 1]], ['messages_params' => ['x' => NAN]],
    ] as $bad) {
        foreach ([['models' => ['m' => $model + $bad + ['route' => [['upstream' => 'a', 'model' => 'p']]]]],
            ['models' => ['m' => $model + ['route' => [['upstream' => 'a', 'model' => 'p'] + $bad]]]]] as $override) {
            try {
                new Config($override + $good + $base);
                throw new \RuntimeException('accepted bad config: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
            }
        }
    }
    foreach ([
        ['models' => ['m' => $model + ['route' => [['upstream' => 'b', 'model' => 'p', 'messages_params' => ['x' => 1]]]]]],   // an entry with no messages_url
        ['models' => ['m' => $model + ['messages_params' => ['x' => 1], 'route' => [['upstream' => 'b', 'model' => 'p']]]]],    // a default that no entry can use
        ['models' => ['m' => $model + ['model' => 'z', 'messages_params' => ['x' => 1]]]],                                       // a model with no messages_url at all
    ] as $override) {
        try {
            new Config($override + $good + $base);
            throw new \RuntimeException('accepted a messages_params with no effect: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
}

/** The old-config upgrade case: a config from v0.21.2 (no messages_params anywhere) sends the Messages request it always sent. */
function testMessagesWithoutMessagesParamsIsUnchanged(): void
{
    [$ref, $key] = fundedKey(100000);
    [$r, $req] = messagesAndRequests('anth', false, $key);
    same(200, $r['status']);
    $keys = $req[0]['keys'];
    sort($keys);
    same(['max_tokens', 'messages', 'model', 'stream'], $keys, 'exactly the forwarded fields and the proxy\'s own: nothing is added');
    same([null, null], [$req[0]['provider'], $req[0]['temperature']]);
    settled($ref);
}
