<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Proxy;

function testRewriteForwardsOnlyAllowlistedFields(): void
{
    $req = ['model' => 'alias', 'messages' => [['role' => 'user', 'content' => 'hi']], 'n' => 4, 'best_of' => 3,
        'service_tier' => 'priority', 'store' => true, 'modalities' => ['text', 'audio'], 'prediction' => ['content' => 'x'],
        'max_completion_tokens' => 9999, 'stream' => true, 'stream_options' => ['include_usage' => false], 'temperature' => 0.2];
    $out = Proxy::rewrite($req, 'upstream/model', 123, true, ['include_usage' => true]);
    same(['model', 'messages', 'temperature', 'max_tokens', 'stream', 'stream_options'], array_keys($out));
    same('upstream/model', $out['model']);
    same(123, $out['max_tokens']);
    same(['include_usage' => true], $out['stream_options']);
    $plain = Proxy::rewrite(['model' => 'a', 'messages' => []], 'm', 5, false, ['include_usage' => true]);
    check(!isset($plain['stream_options']), 'no stream_options on a non-streaming request');
}

function testOnlyTextContentIsAdmitted(): void
{
    $call = ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}']];
    $norm = Proxy::normalizeMessages([['role' => 'user', 'content' => 'hi', 'audio' => ['url' => 'x']],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call + ['audio' => ['url' => 'external']]]],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'ok'], ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello', 'cache_control' => ['x' => 1]]]]]);
    same([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'ok'], ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]], $norm, 'unknown message and part fields dropped');
    $bad = [
        null, [], ['x' => ['role' => 'user', 'content' => 'hi']], [['content' => 'no role']],
        [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/a.png']]]]],
        [['role' => 'user', 'content' => [['type' => 'input_audio', 'input_audio' => ['data' => 'AAAA']]]]],
        [['role' => 'user', 'content' => 42]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => ['audio' => ['url' => 'external']]]]]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => ['x'], 'type' => 'function']]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'function' => 'f']]]],
        [['role' => 'tool', 'tool_call_id' => ['x'], 'content' => 'ok']],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => null, 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}']]]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => null]]]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => null]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => []]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [[]]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => []]]]],
        [['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'f', 'arguments' => '{}']]]]],
    ];
    foreach ($bad as $messages) {
        same(400, throwsHttp(fn () => Proxy::normalizeMessages($messages))[0], json_encode($messages));
    }
}

function testPromptBoundCoversEveryForwardedField(): void
{
    $small = ['messages' => [['role' => 'user', 'content' => 'hi']]];
    $schema = $small + ['response_format' => ['type' => 'json_schema', 'json_schema' => ['schema' => ['description' => str_repeat('x', 100000)]]]];
    check(Proxy::promptBound($schema) > 100000, 'a large schema raises the bound');
    same(Proxy::promptBound($small), Proxy::promptBound($small + ['store' => true, 'modalities' => ['audio']]), 'dropped fields do not count');
    $text = str_repeat('é', 1000); // 2000 bytes
    check(Proxy::promptBound(['messages' => [['role' => 'user', 'content' => $text]]]) >= 2000, 'one token per byte at most');
}

function testRequestedMaxTokensValidation(): void
{
    same(null, Proxy::requestedMaxTokens([]));
    same(50, Proxy::requestedMaxTokens(['max_tokens' => 50]));
    same(70, Proxy::requestedMaxTokens(['max_tokens' => 50, 'max_completion_tokens' => 70]));
    same(400, throwsHttp(fn () => Proxy::requestedMaxTokens(['max_tokens' => 0]))[0]);
    same(400, throwsHttp(fn () => Proxy::requestedMaxTokens(['max_tokens' => '9']))[0]);
}

function testUsageValidation(): void
{
    same(['prompt' => 10, 'completion' => 5, 'total' => 15, 'cached' => 0, 'reasoning' => 0, 'cost' => null], Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]));
    same(['prompt' => 10, 'completion' => 5, 'total' => 17, 'cached' => 0, 'reasoning' => 0, 'cost' => null], Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 17]), 'a larger total is charged');
    foreach ([null, [], ['prompt_tokens' => 10], ['prompt_tokens' => 10, 'completion_tokens' => 5], ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 3],
        ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => -1], ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 'x'],
        ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], ['prompt_tokens' => -1, 'completion_tokens' => 5, 'total_tokens' => 4], ['prompt_tokens' => '10', 'completion_tokens' => 5, 'total_tokens' => 15]] as $u) {
        same(null, Proxy::validUsage($u), json_encode($u));
    }
}

function testCachedInputIsReadFromEitherUsageShape(): void
{
    $base = ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050];
    $cached = static fn (array $extra): int => Proxy::validUsage($base + $extra)['cached'];
    same(800, $cached(['prompt_tokens_details' => ['cached_tokens' => 800]]), 'nested count');
    same(800, $cached(['prompt_tokens_details' => null, 'prompt_cache_hit_tokens' => 800]), 'top-level count with the detail null');
    same(800, $cached(['prompt_cache_hit_tokens' => 800]), 'top-level count without the detail');
    same(800, $cached(['prompt_tokens_details' => ['cached_tokens' => 800], 'prompt_cache_hit_tokens' => 100]), 'the nested count wins when both are present');
    same(100, $cached(['prompt_tokens_details' => ['cached_tokens' => null], 'prompt_cache_hit_tokens' => 100]), 'a null nested count falls through');
    foreach ([['prompt_cache_hit_tokens' => 1001], ['prompt_cache_hit_tokens' => -1], ['prompt_cache_hit_tokens' => '800'], ['prompt_tokens_details' => null], []] as $extra) {
        same(0, $cached($extra), 'out of range, wrong type or absent is 0: ' . json_encode($extra));
    }
}

function testReasoningAndUpstreamCostAreReadForTrackingOnly(): void
{
    $base = ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050];
    $u = static fn (array $extra): array => Proxy::validUsage($base + $extra);
    same(20, $u(['completion_tokens_details' => ['reasoning_tokens' => 20]])['reasoning'], 'nested reasoning count');
    same(20, $u(['reasoning_tokens' => 20])['reasoning'], 'top-level reasoning count');
    same(0, $u(['reasoning_tokens' => 51])['reasoning'], 'more reasoning than output is not a count');
    same(100, $u(['estimated_cost' => 0.0001])['cost'], 'cost in millionths');
    same(2, $u(['estimated_cost' => 0.0000015])['cost'], 'rounded to the nearest millionth');
    same(0, $u(['estimated_cost' => 0])['cost'], 'a reported zero is kept');
    foreach ([null, -0.1, '0.1', 1e7, INF] as $bad) {
        same(null, $u(['estimated_cost' => $bad])['cost'], 'not a usable cost: ' . var_export($bad, true));
    }
    $rates = ['input' => 1_000_000, 'cached_input' => 1_000_000, 'output' => 1_000_000];
    same(Proxy::usageCredits($u([]), 0, $rates), Proxy::usageCredits($u(['reasoning_tokens' => 20, 'estimated_cost' => 5.0]), 0, $rates), 'neither changes the charge');
}

function testChargeUsesReportedUsageThenAnUpperBound(): void
{
    $base = ['status' => 200, 'aborted' => false, 'delivered' => true, 'completion_bound' => 900, 'unmetered' => 0];
    [$used, $meta] = Proxy::charge(['usage' => ['prompt' => 10, 'completion' => 5, 'total' => 15, 'cached' => 0]] + $base, 100, 'a', unitRates());
    same(15, $used);
    same(false, $meta['estimated']);
    [$used, $meta] = Proxy::charge(['usage' => null] + $base, 100, 'a', unitRates());
    same(1000, $used, 'no usage reported: prompt bound + delivered bytes');
    same(true, $meta['estimated']);
    [$used] = Proxy::charge(['usage' => null, 'delivered' => false] + $base, 100, 'a', unitRates());
    same(0, $used, 'nothing delivered: nothing charged');
    [$used, $meta] = Proxy::charge(['usage' => ['prompt' => 10, 'completion' => 2, 'total' => 12, 'cached' => 0], 'unmetered' => 5000] + $base, 100, 'a', unitRates());
    same([5012, true], [$used, $meta['estimated']], 'output after the last usage report is added as an upper bound');
}

function testPublicViewHidesUpstreamDetails(): void
{
    $j = ['model' => 'vendor/secret-model', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3,
        'estimated_cost' => 0.1, 'prompt_tokens_details' => ['cached_tokens' => 0, 'account' => 'x']]];
    same(['model' => 'alias', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3, 'prompt_tokens_details' => ['cached_tokens' => 0], 'completion_tokens_details' => ['reasoning_tokens' => 0]]], Proxy::publicView($j, 'alias'), 'only cached_tokens and the reasoning count survive from the details');
    $top = ['id' => 'x', 'provider' => 'p', 'choices' => [['index' => 0, 'message' => ['content' => 'hi', 'note' => 'n'], 'x' => 1]]];
    same(['id' => 'x', 'model' => 'alias', 'choices' => [['index' => 0, 'message' => ['content' => 'hi']]]], Proxy::publicView($top, 'alias'), 'unknown fields dropped at every level; alias added');
    $typed = ['id' => ['x'], 'created' => '1', 'choices' => [['index' => '0', 'message' => ['content' => ['private' => 'SECRET'], 'role' => 'assistant'], 'finish_reason' => 7], [], ['delta' => 'x']]];
    same(['model' => 'a', 'choices' => [['message' => ['role' => 'assistant']]]], Proxy::publicView($typed, 'a'), 'wrong-typed values dropped; a choice without a message or delta object dropped');
    $nulls = ['id' => null, 'object' => null, 'choices' => [['index' => 0, 'finish_reason' => null, 'message' => ['role' => null, 'content' => null, 'refusal' => null,
        'tool_calls' => [['index' => 0, 'id' => null, 'type' => null, 'function' => ['name' => null, 'arguments' => null]]]]]]];
    same(['model' => 'a', 'choices' => [['index' => 0, 'finish_reason' => null, 'message' => ['content' => null, 'refusal' => null, 'tool_calls' => [['index' => 0]]]]]],
        Proxy::publicView($nulls, 'a'), 'null kept only where the protocol allows it');
    same('{"model":"a","choices":[{"index":0,"finish_reason":"stop","delta":{}}]}', json_encode(Proxy::publicView(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]], 'a')),
        'an empty closing delta is relayed as {}');
    same([], Proxy::publicView(['choices' => [['message' => ['x']]]], 'a')['choices'], 'a list is not a message object');
    same('{"model":"a","choices":[{"message":{}}]}', json_encode(Proxy::publicView(['choices' => [['message' => ['tool_calls' => [[], 'x', ['id' => null]]]]]], 'a')),
        'calls without a usable field are dropped, and an empty tool_calls is omitted');
    $fragment = ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"a']]]]]]];
    same([['index' => 0, 'function' => ['arguments' => '{"a']]], Proxy::publicView($fragment, 'a')['choices'][0]['delta']['tool_calls'], 'a stream fragment of a call is kept');
    $tc = ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c', 'type' => 'function', 'secret' => 's', 'function' => ['name' => 'f', 'arguments' => '{}', 'secret' => 's']]]]]]];
    same([['index' => 0, 'id' => 'c', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}']]], Proxy::publicView($tc, 'a')['choices'][0]['delta']['tool_calls'], 'tool calls keep standard fields only');
}

function testSseEventParsing(): void
{
    same(['json' => ['a' => 1], 'done' => false], Proxy::parseEvent("data: {\"a\":1}"));
    same(['json' => ['a' => 1, 'b' => 2], 'done' => false], Proxy::parseEvent("data: {\"a\":1,\ndata: \"b\":2}"), 'multi-line data');
    same(['json' => null, 'done' => true], Proxy::parseEvent('data: [DONE]'));
    same(null, Proxy::parseEvent(': keep-alive'));
    same(null, Proxy::parseEvent('data: not json'));
}

function testConfigValidation(): void
{
    $dir = tempDir();
    new Config(configArrayFor($dir));
    // The example a self-hoster copies must load as it is: a setting it names that Config does not know would stop every first run.
    new Config(require dirname(__DIR__) . '/config.example.php');
    $bad = [
        ['lease_seconds' => 30],                       // not longer than upstream_timeout
        ['models' => []],
        ['stream_options' => ['include_usage' => false]],
        ['admin_token_sha256' => 'short'],
        ['max_tokens_cap' => 0],
        ['upstream_url' => 'http://llm.example.com/v1/chat/completions'],   // plain http off this machine
        ['upstream_url' => 'file:///etc/passwd'],
        ['upstream_url' => 'https:///v1/chat/completions'],                  // no host
    ];
    $model = ['model' => 'm', 'input' => 1, 'cached_input' => 1, 'output' => 1];
    $bad[] = ['models' => ['a' => $model + ['url' => 'https://other.example.com/v1/chat/completions']]];   // url without its own key_file
    $bad[] = ['models' => ['a' => $model + ['url' => 'http://other.example.com/v1/chat/completions', 'key_file' => '/k']]];
    $bad[] = ['models' => ['a' => $model + ['key_file' => '']]];
    $bad[] = ['models' => ['a' => $model + ['stream_options' => ['include_usage' => false]]]];
    // A misspelt setting is refused at every level, so a typo never silently drops a limit.
    $bad[] = ['cap' => ['day' => 1]];
    $bad[] = ['models' => ['a' => $model + ['max_tokens' => 10]]];
    $rates = ['input' => 1, 'cached_input' => 1, 'output' => 1];
    $u = ['url' => 'https://llm.example.com/v1/chat/completions', 'key_file' => '/k'];
    $routed = ['a' => ['route' => [['upstream' => 'u', 'model' => 'm']]] + $rates];
    new Config(configArrayFor($dir, ['upstreams' => ['u' => $u], 'models' => $routed]));   // the base of the next two is valid
    $bad[] = ['upstreams' => ['u' => $u + ['keyfile' => '/k']], 'models' => $routed];
    $bad[] = ['upstreams' => ['u' => $u], 'models' => ['a' => ['route' => [['upstream' => 'u', 'model' => 'm', 'costs' => []]]] + $rates]];
    try {
        new Config(configArrayFor($dir, ['cap' => ['day' => 1]]));
        throw new \RuntimeException('accepted a misspelt top-level key');
    } catch (\InvalidArgumentException $e) {
        same(true, str_contains($e->getMessage(), 'config: cap is not a setting here'), 'the refusal names the unknown key');
    }
    foreach ($bad as $override) {
        try {
            new Config(configArrayFor($dir, $override));
            throw new \RuntimeException('accepted bad config: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
}

function testConfigRoutesPerModel(): void
{
    $dir = tempDir();
    file_put_contents("$dir/upstream.key", "global-key\n");
    file_put_contents("$dir/second.key", " second-key \n");
    $c = new Config(configArrayFor($dir, ['upstream_url' => 'https://llm.example.com/v1/chat/completions']));
    same(['url' => 'https://llm.example.com/v1/chat/completions', 'messages_url' => null, 'key' => 'global-key',
        'stream_options' => ['include_usage' => true, 'continuous_usage_stats' => true], 'model' => 'scenario-ok', 'params' => []],
        $c->route('ok'), 'a plain model uses the global upstream');
    same(['url' => 'http://127.0.0.1:1/second/v1/chat/completions', 'messages_url' => null, 'key' => 'second-key', 'stream_options' => ['include_usage' => true],
        'model' => 'scenario-ok', 'params' => []], $c->route('second-upstream'), 'a model with its own url, key_file and stream_options uses them');
    unlink("$dir/second.key");
    try {
        $c->route('second-upstream');
        throw new \LogicException('a missing per-model key file must not fall back to the global key');
    } catch (\RuntimeException) {
    }
}

function testPromptBoundAndEncodeRefuseWhatJsonCannotCarry(): void
{
    $req = ['messages' => [['role' => 'user', 'content' => 'hi']]];
    foreach ([INF, -INF, NAN] as $bad) {
        $threw = false;
        try {
            Proxy::promptBound($req + ['temperature' => $bad]);
        } catch (\RuntimeException) {
            $threw = true;
        }
        check($threw, 'a bound is never computed from a body that cannot be encoded');
        $threw = false;
        try {
            Proxy::encode(['temperature' => $bad]);
        } catch (\RuntimeException) {
            $threw = true;
        }
        check($threw, 'nothing is forwarded empty');
    }
    same('{"a":"/é"}', Proxy::encode(['a' => '/é']));
}

function testEmptyObjectsSurviveTheRoundTrip(): void
{
    // A tool schema with no parameters is {} in JSON. Decoded as an associative array it became [] and was forwarded
    // as "properties":[]; an upstream that validates the schema rejects the whole request (lost turns on failover).
    $raw = '{"model":"alias","messages":[{"role":"user","content":"hi"}],"tools":[{"type":"function","function":{"name":"f",'
        . '"parameters":{"type":"object","properties":{},"additionalProperties":{},"items":{"enum":[]}}}}]}';
    $out = Proxy::encode(Proxy::rewrite(MagnaCapax\Virsilipas\Http::decode($raw), 'upstream/model', 10, false, []));
    check(str_contains($out, '"properties":{}'), 'empty properties stays an object: ' . $out);
    check(str_contains($out, '"additionalProperties":{}'), 'empty additionalProperties stays an object');
    check(str_contains($out, '"enum":[]'), 'an empty list stays a list');
    check(str_contains($out, '"model":"upstream/model"'), 'the rest of the request is rewritten as before');
}
