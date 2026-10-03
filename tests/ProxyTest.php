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
    Proxy::checkMessages([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => null],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]]);
    $bad = [
        null, [], ['x' => ['role' => 'user', 'content' => 'hi']], [['content' => 'no role']],
        [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/a.png']]]]],
        [['role' => 'user', 'content' => [['type' => 'input_audio', 'input_audio' => ['data' => 'AAAA']]]]],
        [['role' => 'user', 'content' => 42]],
    ];
    foreach ($bad as $messages) {
        same(400, throwsHttp(fn () => Proxy::checkMessages($messages))[0], json_encode($messages));
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
    same(['prompt' => 10, 'completion' => 5, 'total' => 15], Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]));
    same(['prompt' => 10, 'completion' => 5, 'total' => 15], Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 3]), 'total below the parts');
    same(['prompt' => 10, 'completion' => 5, 'total' => 15], Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 'x']));
    foreach ([null, [], ['prompt_tokens' => 10], ['prompt_tokens' => -1, 'completion_tokens' => 5], ['prompt_tokens' => '10', 'completion_tokens' => 5]] as $u) {
        same(null, Proxy::validUsage($u), json_encode($u));
    }
}

function testChargeUsesReportedUsageThenAnUpperBound(): void
{
    $base = ['status' => 200, 'aborted' => false, 'delivered' => true, 'completion_bound' => 900];
    [$used, $meta] = Proxy::charge(['usage' => ['prompt' => 10, 'completion' => 5, 'total' => 15]] + $base, 100, 'a');
    same(15, $used);
    same(false, $meta['estimated']);
    [$used, $meta] = Proxy::charge(['usage' => null] + $base, 100, 'a');
    same(1000, $used, 'no usage reported: prompt bound + delivered bytes');
    same(true, $meta['estimated']);
    [$used] = Proxy::charge(['usage' => null, 'delivered' => false] + $base, 100, 'a');
    same(0, $used, 'nothing delivered: nothing charged');
}

function testPublicViewHidesUpstreamDetails(): void
{
    $j = ['model' => 'vendor/secret-model', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3,
        'estimated_cost' => 0.1, 'prompt_tokens_details' => ['cached_tokens' => 0, 'account' => 'x']]];
    same(['model' => 'alias', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3]], Proxy::publicView($j, 'alias'));
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
    $bad = [
        ['lease_seconds' => 30],                       // not longer than upstream_timeout
        ['models' => []],
        ['stream_options' => ['include_usage' => false]],
        ['admin_token_sha256' => 'short'],
        ['max_tokens_cap' => 0],
    ];
    foreach ($bad as $override) {
        try {
            new Config(configArrayFor($dir, $override));
            throw new \RuntimeException('accepted bad config: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
}
