<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Messages;

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
    same(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 40]], $events[count($events) - 2][1]);
    same(100000 - 1020, settled($ref)['balance']);
    $u = lastUsage($ref);
    same([950, 800, 40, 1020, 0, 0, 1], [$u['prompt_tokens'], $u['cached_tokens'], $u['completion_tokens'], $u['charged'], $u['estimated'], $u['aborted'], $u['stream']]);
    check($u['first_byte_ms'] !== null, 'time to the first relayed byte recorded');
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
    same(['max_tokens', 'messages', 'metadata', 'model', 'stream', 'system', 'temperature', 'thinking', 'tool_choice', 'tools', 'top_k'], $keys, 'unknown fields dropped');
    same(['type', 'text', 'cache_control'], $up['block_keys'], 'unknown block fields dropped');
    same(['name', 'description', 'input_schema'], $up['tool_keys'], 'unknown tool fields dropped');
    same([1000, ['type' => 'enabled', 'budget_tokens' => 999]], [$up['max_tokens'], $up['thinking']], 'max_tokens clamped to the cap, the thinking budget below it');
    same('2023-06-01', $up['anthropic_version'], 'the default version when the client sends none');
    foreach ([
        ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'AAAA']]]]]],
        ['messages' => [['role' => 'user', 'content' => [['type' => 'document', 'source' => ['type' => 'url', 'url' => 'https://example.com/a.pdf']]]]]],
        ['messages' => [['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => [['type' => 'image', 'source' => []]]]]]]],
        ['tools' => [['type' => 'web_search_20250305', 'name' => 'web_search']]],
        ['messages' => []], ['messages' => [['content' => 'no role']]], ['messages' => [['role' => 'user', 'content' => 42]]],
        ['system' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'f', 'input' => []]]],
        ['max_tokens' => 0],
    ] as $bad) {
        $r = http('POST', '/v1/messages', $bad + anthBody('anth', false), $key);
        same([400, 'invalid_request_error'], [$r['status'], anthError($r)['type']], json_encode($bad));
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
    same(Messages::promptBound(Messages::fields($body)), $n, 'the prompt bound a request would reserve for');
    check($n > Messages::TOOLS_OVERHEAD, 'it covers the tool-use prompt the upstream adds');
    same(1000, settled($ref)['balance'], 'nothing reserved or charged');
    $db = new \PDO('sqlite:' . Harness::get()['dir'] . '/ledger.sqlite');
    $st = $db->prepare('SELECT COUNT(*) FROM usage u JOIN keys k ON k.id = u.key_id WHERE k.ref = ?');
    $st->execute([$ref]);
    same(0, (int) $st->fetchColumn(), 'no usage row');
}

function testMessagesUsageMapping(): void
{
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
