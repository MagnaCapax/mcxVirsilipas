<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * Soft throttling and per-key priority. Nothing here refuses what the hard limits would allow except when the
 * server is busy, and then only for low-weight keys, with a Retry-After the client's retry logic turns into a pause.
 *
 * Weight, on the scale of Linux cgroup cpu.weight / io.weight (1 to 10000, 100 is the reference):
 *   weight = 100 x sqrt(balance in units) x (1 + bonus_pct / 100) x (internal_factor for internal callers)
 * A key holding one unit of credit (credits_per_unit credits) with no bonus has weight 100; four units 200; a 300%
 * bonus multiplies by 4. More credit or a larger bonus means more priority, the same shape as a plan's disk/CPU weight.
 *
 * Spend pacing (when daily_budget is set): over the last 24 hours, once spend passes pace_start of the budget each
 * request waits base_delay_ms x pressure x 100 / weight, holding its lease, before it goes upstream, at most max_delay_ms, where pressure grows
 * from 0 at pace_start to 1 at the full budget and keeps growing past it. Low weights wait longer.
 *
 * Busy shedding (when busy lines are set): while a source's requests in flight are at its busy line, a key with a
 * weight below busy_min_weight gets 503 server_busy with Retry-After instead of a worker, and no pacing wait starts
 * while the source is busy, since a waiting request holds the worker another request needs.
 */
final class Throttle
{
    public const REFERENCE_WEIGHT = 100;
    public const MAX_WEIGHT = 10000;

    /** @param array{internal: ?int, external: ?int} $busy */
    public function __construct(
        public readonly ?int $dailyBudget,
        public readonly float $paceStart,
        public readonly int $baseDelayMs,
        public readonly int $maxDelayMs,
        public readonly array $busy,
        public readonly int $busyMinWeight,
        public readonly float $internalFactor,
        public readonly int $creditsPerUnit,
    ) {
    }

    /** The `throttle` config block, all of it optional; an absent block turns pacing and shedding off. */
    public static function fromConfig(mixed $t): self
    {
        if ($t === null) {
            $t = [];
        }
        if (!is_array($t)) {
            throw new \InvalidArgumentException('config: throttle must be a map');
        }
        $unknown = array_diff(array_keys($t), ['daily_budget', 'pace_start', 'base_delay_ms', 'max_delay_ms', 'busy', 'busy_min_weight', 'internal_factor', 'credits_per_unit']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('config: throttle.' . implode(', throttle.', $unknown) . ' is not a setting');
        }
        $int = static function (string $k, ?int $default, int $min, int $max) use ($t): ?int {
            $v = array_key_exists($k, $t) ? $t[$k] : $default;
            if ($v !== null && (!is_int($v) || $v < $min || $v > $max)) {
                throw new \InvalidArgumentException("config: throttle.$k must be an integer from $min to $max");
            }
            return $v;
        };
        $float = static function (string $k, float $default, float $min, float $max) use ($t): float {
            $v = $t[$k] ?? $default;
            if ((!is_int($v) && !is_float($v)) || is_nan((float) $v) || $v < $min || $v > $max) {
                throw new \InvalidArgumentException("config: throttle.$k must be a number from $min to $max");
            }
            return (float) $v;
        };
        $busy = $t['busy'] ?? [];
        if (!is_array($busy) || array_diff(array_keys($busy), ['internal', 'external']) !== []) {
            throw new \InvalidArgumentException('config: throttle.busy must be a map with internal and/or external');
        }
        foreach ($busy as $source => $line) {
            if (!is_int($line) || $line < 1 || $line > 100000) {
                throw new \InvalidArgumentException("config: throttle.busy.$source must be an integer from 1 to 100000 (requests in flight)");
            }
        }
        $max = (int) $int('max_delay_ms', 3000, 0, 60000);
        return new self(
            $int('daily_budget', null, 1, Ledger::MAX_GRANT),
            $float('pace_start', 0.8, 0.0, 0.99),
            (int) $int('base_delay_ms', 1000, 0, 60000),
            $max,
            ['internal' => $busy['internal'] ?? null, 'external' => $busy['external'] ?? null],
            (int) $int('busy_min_weight', self::REFERENCE_WEIGHT, 1, self::MAX_WEIGHT),
            $float('internal_factor', 2.0, 1.0, 100.0),
            (int) $int('credits_per_unit', 1_000_000, 1, Ledger::MAX_GRANT),
        );
    }

    /** A key's priority weight, 1 to MAX_WEIGHT. */
    public function weight(int $balance, int $bonusPct, bool $internal): int
    {
        $units = max(0, $balance) / $this->creditsPerUnit;
        $bonus = 1 + max(0, min(Ledger::MAX_BONUS_PCT, $bonusPct)) / 100;
        $w = self::REFERENCE_WEIGHT * sqrt($units) * $bonus * ($internal ? $this->internalFactor : 1.0);
        return (int) max(1, min(self::MAX_WEIGHT, round($w)));
    }

    /** How far 24-hour spend is past the pacing start, 0 when pacing is off or spend is below it. */
    public function pressure(int $spent24h): float
    {
        if ($this->dailyBudget === null) {
            return 0.0;
        }
        return max(0.0, ($spent24h / $this->dailyBudget - $this->paceStart) / (1 - $this->paceStart));
    }

    /** Milliseconds a request of this weight waits before it starts, at this pressure. */
    public function delayMs(float $pressure, int $weight): int
    {
        if ($pressure <= 0 || $this->baseDelayMs === 0) {
            return 0;
        }
        return (int) min($this->maxDelayMs, round($this->baseDelayMs * $pressure * self::REFERENCE_WEIGHT / max(1, $weight)));
    }

    /** The busy line for a source, or null when shedding is off for it. */
    public function busyLine(bool $internal): ?int
    {
        return $this->busy[$internal ? 'internal' : 'external'];
    }

    /** Seconds a shed request is asked to wait: longer for lower weights, 1 to 60. */
    public function shedRetryAfter(int $weight): int
    {
        return (int) max(1, min(60, ceil(5 * $this->busyMinWeight / max(1, $weight))));
    }
}
