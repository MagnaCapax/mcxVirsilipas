<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The live view behind `bin/virsilipas top`: request rate, latency, time to first byte, tokens, credits, upstream cost,
 * errors, retries, requests in flight and the caps, from the ledger's usage rows and leases. It reads metadata only;
 * the ledger holds no prompt or answer to show (ADR 0014, ADR 0018).
 */
final class Top
{
    /** Windows shown side by side, in seconds. */
    public const WINDOWS = ['1m' => 60, '5m' => 300, '1h' => 3600];
    /** Rows read for the longest window at most; a busier hour is marked as capped. */
    private const MAX_ROWS = 50000;
    private const RECENT = 15;

    /** @param array{spend: array<string, ?int>, requests_per_minute: ?int} $caps */
    public static function render(Ledger $ledger, array $caps, int $now): string
    {
        $rows = $ledger->recentUsage($now - max(self::WINDOWS), self::MAX_ROWS);
        $capped = count($rows) >= self::MAX_ROWS;
        $f = $ledger->inFlight($now);
        $out = [sprintf('Virsilipas  %s UTC   in flight %d (internal %d, external %d, oldest %ds)', gmdate('Y-m-d H:i:s', $now),
            $f['internal'] + $f['external'], $f['internal'], $f['external'], $f['oldest_s'])];
        $out[] = self::capsLine($ledger->capStatus($caps));
        $out[] = '';

        $win = [];
        foreach (self::WINDOWS as $name => $seconds) {
            $win[$name] = self::summary(array_values(array_filter($rows, static fn (array $r): bool => $r['ts'] >= $now - $seconds)), $seconds);
        }
        $labels = ['requests' => 'requests', 'rate' => 'requests/s', 'failed' => 'failed', 'p50' => 'duration p50 ms', 'p95' => 'duration p95 ms',
            'fb50' => 'first byte p50 ms', 'fb95' => 'first byte p95 ms', 'tps' => 'output tokens/s', 'tokens' => 'tokens in/out',
            'credits' => 'credits charged', 'cost' => 'upstream cost', 'retried' => 'retried/failover', 'aborted' => 'client hang-ups'];
        $out[] = sprintf('%-18s', '') . implode('', array_map(static fn (string $n): string => sprintf('%16s', 'last ' . $n), array_keys(self::WINDOWS)))
            . ($capped ? '   (1h capped at ' . self::MAX_ROWS . ' requests)' : '');
        foreach ($labels as $key => $label) {
            $out[] = sprintf('%-18s', $label) . implode('', array_map(static fn (array $w): string => sprintf('%16s', $w[$key]), $win));
        }

        $out[] = '';
        $out[] = 'Last 5 minutes by model and upstream';
        $out[] = sprintf('  %-20s %-28s %6s %6s %8s %8s %8s %10s', 'model', 'upstream', 'reqs', 'failed', 'p50 ms', 'fb50 ms', 'tok/s', 'credits');
        $groups = [];
        foreach ($rows as $r) {
            if ($r['ts'] >= $now - self::WINDOWS['5m']) {
                $groups[$r['model'] . "\0" . $r['upstream']][] = $r;
            }
        }
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
        foreach (array_slice($rows, 0, self::RECENT) as $r) {
            $flags = array_keys(array_filter(['sync' => !$r['stream'], 'internal' => $r['source'] === 'internal', 'retry' => $r['attempts'] > 1,
                'hangup' => $r['aborted'], 'estimated' => $r['estimated'], 'delayed' => $r['delay_ms'] > 0]));
            $out[] = sprintf('  %-8s %-16s %-12s %-20s %4d %6d %6s %13s %8d %s', gmdate('H:i:s', $r['ts']), self::cut($r['ref'], 16),
                self::cut($r['model'], 12), self::cut($r['upstream'] === '' ? '-' : $r['upstream'], 20), $r['status'], $r['duration_ms'],
                $r['first_byte_ms'] ?? '-', $r['prompt_tokens'] . '/' . $r['completion_tokens'], $r['charged'], implode(',', $flags));
        }
        if ($rows === []) {
            $out[] = '  (no requests in the last hour)';
        }
        return implode("\n", $out) . "\n";
    }

    /**
     * One window's figures, formatted for display.
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private static function summary(array $rows, int $seconds): array
    {
        $n = count($rows);
        $ok = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] >= 200 && $r['status'] <= 299));
        $failed = $n - count($ok);
        $durations = array_column($ok, 'duration_ms');
        $firstBytes = array_values(array_filter(array_column($ok, 'first_byte_ms'), static fn ($v): bool => $v !== null));
        $out = (int) array_sum(array_column($rows, 'completion_tokens'));
        $costs = array_values(array_filter(array_column($rows, 'upstream_cost'), static fn ($v): bool => $v !== null));
        return [
            'requests' => (string) $n,
            'rate' => number_format($n / $seconds, 2),
            'failed' => $n === 0 ? '0' : sprintf('%d (%d%%)', $failed, (int) round(100 * $failed / $n)),
            'failed_n' => (string) $failed,
            'p50' => self::percentile($durations, 50),
            'p95' => self::percentile($durations, 95),
            'fb50' => self::percentile($firstBytes, 50),
            'fb95' => self::percentile($firstBytes, 95),
            'tps' => number_format($out / $seconds, 1),
            'tokens' => array_sum(array_column($rows, 'prompt_tokens')) . '/' . $out,
            'credits' => (string) array_sum(array_column($rows, 'charged')),
            // millionths of the upstream's currency unit, shown in units; "-" when no request carried a cost
            'cost' => $costs === [] ? '-' : number_format(array_sum($costs) / 1_000_000, 4),
            'retried' => (string) count(array_filter($rows, static fn (array $r): bool => $r['attempts'] > 1)),
            'aborted' => (string) count(array_filter($rows, static fn (array $r): bool => $r['aborted'])),
        ];
    }

    /** @param list<int|float> $values */
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
