<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Credits;
use MagnaCapax\Virsilipas\Proxy;

/** The weighted example rates from the test config, per million tokens. */
function weightedRates(): array
{
    return ['input' => 2_000_000, 'cached_input' => 500_000, 'output' => 8_000_000];
}

function testCreditsArithmetic(): void
{
    // 200 fresh x 2 + 800 cached x 0.5 + 50 output x 8 = 400 + 400 + 400.
    same(1200, Credits::of(200, 800, 50, weightedRates()));
    same(0, Credits::of(0, 0, 0, weightedRates()));
    same(1, Credits::of(1, 0, 0, ['input' => 1, 'cached_input' => 0, 'output' => 1]), 'a fraction of a credit rounds up');
    same(3, Credits::of(1_000_000, 0, 0, ['input' => 3, 'cached_input' => 0, 'output' => 1]));
    same(4, Credits::of(1_000_001, 0, 0, ['input' => 3, 'cached_input' => 0, 'output' => 1]), 'rounded once, up');
    same(2, Credits::of(1, 1, 0, ['input' => 600_000, 'cached_input' => 600_000, 'output' => 1]), '1.2 credits round up to 2, not 1 + 1 per part');
    same(0, Credits::of(-5, -5, -5, weightedRates()), 'negative counts count as zero');
    $max = ['input' => Credits::MAX_RATE, 'cached_input' => Credits::MAX_RATE, 'output' => Credits::MAX_RATE];
    same(3_000_000_000_000, Credits::of(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, $max), 'counts are clamped; nothing overflows');
}

function testAffordableTokens(): void
{
    $unit = ['input' => 1_000_000, 'cached_input' => 1_000_000, 'output' => 1_000_000];
    same(100, Credits::affordable(110, 10, 100, $unit), 'prompt 10 + cap 100 fit exactly');
    same(50, Credits::affordable(60, 10, 100, $unit), '60 credits: 10 for the prompt, 50 for output');
    same(100, Credits::affordable(1000, 100, 4000, weightedRates()), '100 x 2 + 100 x 8 = 1000');
    same(0, Credits::affordable(207, 100, 4000, weightedRates()), '7 credits left: not one output token at 8');
    same(0, Credits::affordable(0, 0, 100, $unit));
    same(0, Credits::affordable(-50, 10, 100, $unit));
    $tiny = ['input' => 1, 'cached_input' => 1, 'output' => 1];
    same(1000, Credits::affordable(1, 50, 1000, $tiny), 'one credit covers the whole request at a tiny rate: rounded once, not per part');
    $max = ['input' => Credits::MAX_RATE, 'cached_input' => Credits::MAX_RATE, 'output' => Credits::MAX_RATE];
    same(Credits::MAX_COUNT, Credits::affordable(1_000_000_000_000_000_000, Credits::MAX_COUNT, Credits::MAX_COUNT, $max), 'large balance: no overflow');
    same(999_999_999, Credits::affordable(1_999_999_999_999, Credits::MAX_COUNT, Credits::MAX_COUNT, $max), 'just below the cap at the largest values: no overflow');
}

function testCachedInputValidation(): void
{
    $u = static fn (mixed $cached, int $total = 1050): array => ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => $total,
        'prompt_tokens_details' => ['cached_tokens' => $cached]];
    same(800, Proxy::validUsage($u(800))['cached']);
    same(1000, Proxy::validUsage($u(1000))['cached'], 'the whole prompt may be cached');
    same(0, Proxy::validUsage($u(1001))['cached'], 'more cached than the prompt side: counted as fresh');
    same(0, Proxy::validUsage($u(1010, 1060))['cached'], 'cached is part of prompt_tokens, whatever the total');
    same(0, Proxy::validUsage(['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 100, 'prompt_tokens_details' => ['cached_tokens' => 100]])['cached'], 'more cached than prompt_tokens');
    same(0, Proxy::validUsage($u(-1))['cached']);
    same(0, Proxy::validUsage($u('800'))['cached']);
    same(0, Proxy::validUsage($u(null))['cached']);
    same(0, Proxy::validUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'prompt_tokens_details' => 'x'])['cached']);
}

function testUsageCreditsAndCharge(): void
{
    $u = ['prompt' => 1000, 'completion' => 50, 'total' => 1050, 'cached' => 800];
    same(1200, Proxy::usageCredits($u, 0, weightedRates()));
    same(1280, Proxy::usageCredits($u, 10, weightedRates()), '10 unmetered bytes at the output rate');
    $base = ['status' => 200, 'aborted' => false, 'delivered' => true, 'completion_bound' => 30, 'unmetered' => 0];
    [$used, $meta] = Proxy::charge(['usage' => $u] + $base, 1100, 'w', weightedRates());
    same([1200, 800, false], [$used, $meta['cached'], $meta['estimated']]);
    [$used, $meta] = Proxy::charge(['usage' => null] + $base, 100, 'w', weightedRates());
    same([440, 0, true], [$used, $meta['cached'], $meta['estimated']], 'no usage: prompt bound at the input rate, bytes at the output rate');
}

function testPublicViewReportsCreditsCappedAtTheReservation(): void
{
    $j = ['choices' => [], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050,
        'prompt_tokens_details' => ['cached_tokens' => 800, 'account' => 'PRIVATE-DETAIL']]];
    same(['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050, 'prompt_tokens_details' => ['cached_tokens' => 800], 'completion_tokens_details' => ['reasoning_tokens' => 0], 'credits' => 1200],
        Proxy::publicView($j, 'w', weightedRates(), 5000)['usage']);
    same(900, Proxy::publicView($j, 'w', weightedRates(), 900)['usage']['credits'], 'never more than the reservation');
    check(!array_key_exists('credits', Proxy::publicView($j, 'w')['usage']), 'no rates: no credits field');
}

function testConfigValidatesModelRatesAndLimits(): void
{
    $dir = tempDir();
    $c = new Config(configArrayFor($dir, ['requests_per_minute' => 60, 'credits_per_minute' => 50000]));
    same(['model' => 'scenario-cached'] + weightedRates(), $c->models['weighted']);
    same(['max_concurrent' => 5, 'requests_per_minute' => 60, 'credits_per_minute' => 50000], $c->limitDefaults());
    same(['max_concurrent' => 5, 'requests_per_minute' => null, 'credits_per_minute' => null], (new Config(configArrayFor($dir)))->limitDefaults(), 'unset: no limit');
    $m = static fn (array $entry): array => ['models' => ['a' => $entry]];
    $good = ['model' => 'x', 'input' => 2, 'cached_input' => 1, 'output' => 3];
    new Config(configArrayFor($dir, $m($good)));
    new Config(configArrayFor($dir, $m(['cached_input' => 0] + $good)));
    $bad = [
        ['models' => ['a' => 'upstream-model']],          // the old alias => model id form
        $m(['model' => ''] + $good),
        $m(['input' => 0] + $good),
        $m(['output' => 0] + $good),
        $m(['cached_input' => 3] + $good),                // cached costs more than fresh
        $m(['input' => Credits::MAX_RATE + 1, 'cached_input' => 1] + $good),
        $m(['input' => '2'] + $good),
        $m(array_diff_key($good, ['output' => 1])),
        ['max_tokens_cap' => Credits::MAX_COUNT + 1],
        ['requests_per_minute' => 0],
        ['credits_per_minute' => 'many'],
        ['credits_per_minute' => 1_000_000_000_000_001],
    ];
    foreach ($bad as $override) {
        try {
            new Config(configArrayFor($dir, $override));
            throw new \RuntimeException('accepted bad config: ' . json_encode($override));
        } catch (\InvalidArgumentException) {
        }
    }
}
