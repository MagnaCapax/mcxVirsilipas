<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * Credits: the unit balances are kept in. Each model alias has operator-set rates, in credits per
 * million tokens, for fresh input, cached input and output; a request costs the sum, rounded up once.
 *
 * Token counts are clamped to MAX_COUNT and rates to MAX_RATE, so every product and sum here stays
 * far inside 64-bit integers.
 */
final class Credits
{
    public const MAX_COUNT = 1_000_000_000;
    public const MAX_RATE = 1_000_000_000;
    private const PER = 1_000_000;

    /**
     * Credits for one request's token counts.
     * @param array{input: int, cached_input: int, output: int} $rates
     */
    public static function of(int $fresh, int $cached, int $output, array $rates): int
    {
        $sum = self::count($fresh) * $rates['input'] + self::count($cached) * $rates['cached_input'] + self::count($output) * $rates['output'];
        return intdiv($sum + self::PER - 1, self::PER);
    }

    /** Credits for a token count at one rate, rounded up. */
    public static function at(int $tokens, int $rate): int
    {
        return intdiv(self::count($tokens) * $rate + self::PER - 1, self::PER);
    }

    /** The most tokens, at most $cap, whose credits at $rate fit in $available (0 if none do). */
    public static function affordable(int $available, int $cap, int $rate): int
    {
        if ($available < 1) {
            return 0;
        }
        if ($available >= self::at($cap, $rate)) {
            return $cap;
        }
        // Here $available < at($cap) <= MAX_COUNT * MAX_RATE / PER + 1, so the product stays in range.
        return intdiv($available * self::PER, $rate);
    }

    private static function count(int $n): int
    {
        return max(0, min($n, self::MAX_COUNT));
    }
}
