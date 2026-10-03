<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Proxy;

function testRewriteForcesModelMaxTokensAndUsageReporting(): void
{
    $req = ['model' => 'alias', 'messages' => [['role' => 'user', 'content' => 'hi']], 'n' => 4, 'best_of' => 3,
        'service_tier' => 'priority', 'max_completion_tokens' => 9999, 'stream' => true,
        'stream_options' => ['include_usage' => false], 'temperature' => 0.2];
    $out = Proxy::rewrite($req, 'upstream/model', 123, true, ['include_usage' => true]);
    same('upstream/model', $out['model']);
    same(123, $out['max_tokens']);
    same(['include_usage' => true], $out['stream_options']);
    same(0.2, $out['temperature'], 'other parameters pass through');
    foreach (['n', 'best_of', 'service_tier', 'max_completion_tokens'] as $k) {
        check(!array_key_exists($k, $out), "$k must be stripped");
    }
    $plain = Proxy::rewrite(['model' => 'a', 'messages' => [], 'stream_options' => ['x' => 1]], 'm', 5, false, ['include_usage' => true]);
    check(!isset($plain['stream_options']), 'no stream_options on a non-streaming request');
}

function testRequestedMaxTokensValidation(): void
{
    same(null, Proxy::requestedMaxTokens([]));
    same(50, Proxy::requestedMaxTokens(['max_tokens' => 50]));
    same(70, Proxy::requestedMaxTokens(['max_tokens' => 50, 'max_completion_tokens' => 70]));
    same(400, throwsHttp(fn () => Proxy::requestedMaxTokens(['max_tokens' => 0]))[0]);
    same(400, throwsHttp(fn () => Proxy::requestedMaxTokens(['max_tokens' => '9']))[0]);
}

function testChargeUsesReportedUsageThenEstimate(): void
{
    $base = ['status' => 200, 'aborted' => false, 'delivered' => true, 'completion_estimate' => 9];
    [$used, $meta] = Proxy::charge(['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'prompt_tokens_details' => ['cached_tokens' => 4]]] + $base, 100, 'a');
    same(15, $used);
    same(4, $meta['cached']);
    same(false, $meta['estimated']);
    [$used, $meta] = Proxy::charge(['usage' => null] + $base, 100, 'a');
    same(109, $used, 'no usage reported: prompt estimate + streamed deltas');
    same(true, $meta['estimated']);
    [$used] = Proxy::charge(['usage' => null, 'delivered' => false] + $base, 100, 'a');
    same(0, $used, 'nothing delivered: nothing charged');
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
