<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The live view behind `bin/virsilipas top`: request rate, latency, time to first byte, tokens, credits, upstream cost,
 * errors, retries, the requests in flight and the caps, from the ledger's usage rows and leases. It reads metadata only;
 * the ledger holds no prompt or answer to show (ADR 0014, ADR 0018). The usage rows are streamed and folded into running
 * totals, so memory stays small however busy the hour was.
 */
final class Top
{
    /** Windows shown side by side, in seconds. */
    public const WINDOWS = ['1m' => 60, '5m' => 300, '1h' => 3600];
    /** Rows read for the longest window at most; a busier hour is marked as capped. */
    private const MAX_ROWS = 50000;
    private const RECENT = 15;
    private const IN_FLIGHT = 10;

    /** @param array{spend: array<string, ?int>, requests_per_minute: ?int} $caps */
    public static function render(Ledger $ledger, array $caps, int $now): string
    {
        $win = array_map(static fn (): array => self::fresh(), self::WINDOWS);
        $groups = [];
        $recent = [];
        $read = 0;
        foreach ($ledger->recentUsage($now - max(self::WINDOWS), self::MAX_ROWS) as $r) {
            $read++;
            foreach (self::WINDOWS as $name => $seconds) {
                if ($r['ts'] >= $now - $seconds) {
                    self::add($win[$name], $r);
                }
            }
            if ($r['ts'] >= $now - self::WINDOWS['5m']) {
                $k = $r['model'] . "\0" . $r['upstream'];
                $groups[$k] ??= self::fresh();
                self::add($groups[$k], $r);
            }
            if (count($recent) < self::RECENT) {
                $recent[] = $r;
            }
        }
        $capped = $read >= self::MAX_ROWS;

        $f = $ledger->inFlight($now);
        $out = [sprintf('Virsilipas  %s UTC   in flight %d (internal %d, external %d, oldest %ds)', gmdate('Y-m-d H:i:s', $now),
            $f['internal'] + $f['external'], $f['internal'], $f['external'], $f['oldest_s'])];
        $out[] = self::capsLine($ledger->capStatus($caps));
        $out[] = '';

        $sum = [];
        foreach (self::WINDOWS as $name => $seconds) {
            $sum[$name] = self::summary($win[$name], $seconds);
        }
        $labels = ['requests' => 'requests', 'rate' => 'requests/s', 'failed' => 'failed', 'p50' => 'duration p50 ms', 'p95' => 'duration p95 ms',
            'fb50' => 'first byte p50 ms', 'fb95' => 'first byte p95 ms', 'tps' => 'output tokens/s', 'tokens' => 'tokens in/out',
            'credits' => 'credits charged', 'cost' => 'upstream cost', 'retried' => 'retried/failover', 'aborted' => 'client hang-ups'];
        $out[] = sprintf('%-18s', '') . implode('', array_map(static fn (string $n): string => sprintf('%16s', 'last ' . $n), array_keys(self::WINDOWS)))
            . ($capped ? '   (1h capped at ' . self::MAX_ROWS . ' requests)' : '');
        foreach ($labels as $key => $label) {
            $out[] = sprintf('%-18s', $label) . implode('', array_map(static fn (array $w): string => sprintf('%16s', $w[$key]), $sum));
        }

        $out[] = '';
        $out[] = 'In flight, oldest first';
        $flying = $ledger->inFlightRequests($now, self::IN_FLIGHT);
        if ($flying === []) {
            $out[] = '  (none)';
        } else {
            $out[] = sprintf('  %-16s %-8s %6s %9s', 'ref', 'source', 'age s', 'reserved');
            foreach ($flying as $q) {
                $out[] = sprintf('  %-16s %-8s %6d %9d', self::cut($q['ref'], 16), $q['source'], $q['age_s'], $q['reserved']);
            }
            $more = $f['internal'] + $f['external'] - count($flying);
            if ($more > 0) {
                $out[] = "  (and $more more)";
            }
        }

        $out[] = '';
        $out[] = 'Last 5 minutes by model and upstream';
        $out[] = sprintf('  %-20s %-28s %6s %6s %8s %8s %8s %10s', 'model', 'upstream', 'reqs', 'failed', 'p50 ms', 'fb50 ms', 'tok/s', 'credits');
        ksort($groups);
        foreach ($groups as $k => $g) {
            [$model, $upstream] = explode("\0", $k);
            $s = self::summary($g, self::WINDOWS['5m']);
            $out[] = sprintf('  %-20s %-28s %6s %6s %8s %8s %8s %10s', self::cut($model, 20), self::cut($upstream === '' ? '-' : $upstream, 28),
                $s['requests'], $s['failed_n'], $s['p50'], $s['fb50'], $s['tps'], $s['credits']);
        }
        if ($groups === []) {
            $out[] = '  (no requests)';
        }

        $out[] = '';
        $out[] = 'Recent requests, newest first';
        $out[] = sprintf('  %-8s %-16s %-12s %-20s %4s %6s %6s %13s %8s %s', 'time', 'ref', 'model', 'upstream', 'http', 'ms', 'fb ms',
            'tokens in/out', 'credits', 'flags');
        foreach ($recent as $r) {
            $flags = array_keys(array_filter(['sync' => !$r['stream'], 'internal' => $r['source'] === 'internal', 'retry' => $r['attempts'] > 1,
                'hangup' => $r['aborted'], 'estimated' => $r['estimated'], 'delayed' => $r['delay_ms'] > 0]));
            $out[] = sprintf('  %-8s %-16s %-12s %-20s %4d %6d %6s %13s %8d %s', gmdate('H:i:s', $r['ts']), self::cut($r['ref'], 16),
                self::cut($r['model'], 12), self::cut($r['upstream'] === '' ? '-' : $r['upstream'], 20), $r['status'], $r['duration_ms'],
                $r['first_byte_ms'] ?? '-', $r['prompt_tokens'] . '/' . $r['completion_tokens'], $r['charged'], implode(',', $flags));
        }
        if ($recent === []) {
            $out[] = '  (no requests in the last hour)';
        }
        return implode("\n", $out) . "\n";
    }

    /** @return array{n: int, failed: int, durations: list<int>, first_bytes: list<int>, prompt: int, completion: int, credits: int, cost: int, costed: int, retried: int, aborted: int} */
    private static function fresh(): array
    {
        return ['n' => 0, 'failed' => 0, 'durations' => [], 'first_bytes' => [], 'prompt' => 0, 'completion' => 0, 'credits' => 0,
            'cost' => 0, 'costed' => 0, 'retried' => 0, 'aborted' => 0];
    }

    /**
     * Folds one usage row into a window's running totals. Durations and first-byte times are kept (as plain integers) only
     * for successful requests, for the percentiles.
     * @param array{n: int, failed: int, durations: list<int>, first_bytes: list<int>, prompt: int, completion: int, credits: int, cost: int, costed: int, retried: int, aborted: int} $a
     * @param array<string, mixed> $r
     */
    private static function add(array &$a, array $r): void
    {
        $a['n']++;
        if ($r['status'] >= 200 && $r['status'] <= 299) {
            $a['durations'][] = $r['duration_ms'];
            if ($r['first_byte_ms'] !== null) {
                $a['first_bytes'][] = $r['first_byte_ms'];
            }
        } else {
            $a['failed']++;
        }
        $a['prompt'] += $r['prompt_tokens'];
        $a['completion'] += $r['completion_tokens'];
        $a['credits'] += $r['charged'];
        if ($r['upstream_cost'] !== null) {
            $a['cost'] += $r['upstream_cost'];
            $a['costed']++;
        }
        $a['retried'] += (int) ($r['attempts'] > 1);
        $a['aborted'] += (int) $r['aborted'];
    }

    /**
     * One window's figures, formatted for display.
     * @param array{n: int, failed: int, durations: list<int>, first_bytes: list<int>, prompt: int, completion: int, credits: int, cost: int, costed: int, retried: int, aborted: int} $a
     * @return array<string, string>
     */
    private static function summary(array $a, int $seconds): array
    {
        $n = $a['n'];
        return [
            'requests' => (string) $n,
            'rate' => number_format($n / $seconds, 2),
            'failed' => $n === 0 ? '0' : sprintf('%d (%d%%)', $a['failed'], (int) round(100 * $a['failed'] / $n)),
            'failed_n' => (string) $a['failed'],
            'p50' => self::percentile($a['durations'], 50),
            'p95' => self::percentile($a['durations'], 95),
            'fb50' => self::percentile($a['first_bytes'], 50),
            'fb95' => self::percentile($a['first_bytes'], 95),
            'tps' => number_format($a['completion'] / $seconds, 1),
            'tokens' => $a['prompt'] . '/' . $a['completion'],
            'credits' => (string) $a['credits'],
            // millionths of the upstream's currency unit, shown in units to the last stored digit; "-" when no request carried a cost
            'cost' => $a['costed'] === 0 ? '-' : number_format($a['cost'] / 1_000_000, 6),
            'retried' => (string) $a['retried'],
            'aborted' => (string) $a['aborted'],
        ];
    }

    /** @param list<int> $values */
    private static function percentile(array $values, int $p): string
    {
        if ($values === []) {
            return '-';
        }
        sort($values);
        return (string) $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
    }

    /** @param array<string, array<string, int>> $status */
    private static function capsLine(array $status): string
    {
        if ($status === []) {
            return 'caps: none set';
        }
        $parts = [];
        foreach ($status as $name => $c) {
            $parts[] = $name === 'requests_per_minute'
                ? sprintf('requests/min %d/%d', $c['last_minute'], $c['limit'])
                : sprintf('%s %s/%s', $name, number_format(($c['settled'] + $c['in_flight']) / 1_000_000, 2), number_format($c['limit'] / 1_000_000, 2));
        }
        return 'caps: ' . implode('   ', $parts);
    }

    private static function cut(string $s, int $width): string
    {
        return strlen($s) <= $width ? $s : substr($s, 0, $width - 1) . '~';
    }
}
