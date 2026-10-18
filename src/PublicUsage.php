<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * PublicUsage: the aggregate usage a deployment may publish (ADR 0023). Built from `Ledger::stats('hour',
 * ['key', 'model'])` rows of the keys whose ref starts with a deployment-chosen prefix; the output is rebuilt from
 * fixed fields and carries no ref, no key or customer count, no upstream, no cost and no grant.
 *
 * Windows end at `as_of`, the end of the last complete UTC hour, so a run inside the hour gives the same numbers.
 */
final class PublicUsage
{
    public const SCHEMA_VERSION = 2;
    private const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];
    private const DAYS = 30;
    private const TOP = 5;
    private const TOKENS = ['prompt_tokens', 'cached_tokens', 'completion_tokens', 'reasoning_tokens'];
    private const COUNTS = ['credits', 'requests', 'failed', 'retried', ...self::TOKENS];

    /**
     * @param list<array<string, mixed>> $rows `stats('hour', ['key', 'model'], since, until)` rows
     * @return array<string, mixed>
     */
    public static function build(array $rows, int $now, string $refPrefix): array
    {
        if ($refPrefix === '') {
            throw new \InvalidArgumentException('a ref prefix is required: it decides whose usage is published');
        }
        $asOf = intdiv($now, 3600) * 3600;
        $firstDay = intdiv($asOf - 1, 86400) * 86400 - (self::DAYS - 1) * 86400;
        $win = array_fill_keys(array_keys(self::WINDOWS), ['fb_weighted' => 0, 'fb_requests' => 0] + array_fill_keys(self::COUNTS, 0));
        $models = [];
        $days = [];
        $hours = [];
        $heat = [];
        foreach ($rows as $r) {
            if (!str_starts_with((string) ($r['key'] ?? ''), $refPrefix)) {
                continue;
            }
            $start = self::hour((string) ($r['bucket'] ?? ''));
            if ($start >= $asOf) {
                continue;
            }
            foreach (self::COUNTS as $f) {
                if (!is_int($r[$f] ?? null) || $r[$f] < 0) {
                    throw new \RuntimeException("unreadable $f in a stats row");
                }
            }
            $fb = is_int($r['mean_first_byte_ms'] ?? null) ? $r['mean_first_byte_ms'] : null;
            $model = self::model((string) ($r['model'] ?? ''));
            foreach (self::WINDOWS as $name => $n) {
                if ($start >= $asOf - $n * 3600) {
                    self::add($win[$name], $r, self::COUNTS, $fb);
                }
            }
            if ($start >= $asOf - self::WINDOWS['30d'] * 3600) {
                self::add($models[$model], $r, self::COUNTS, $fb);
                $cell = gmdate('N', $start) . '-' . gmdate('G', $start);   // ISO weekday 1-7, hour 0-23
                self::add($heat[$cell], $r, ['credits', 'requests']);
            }
            if ($start >= $asOf - self::WINDOWS['7d'] * 3600) {
                self::add($hours[$start], $r, ['credits', 'requests']);
                $hours[$start]['models'][$model] = ($hours[$start]['models'][$model] ?? 0) + $r['credits'];
            }
            if ($start >= $firstDay) {
                $d = gmdate('Y-m-d', $start);
                self::add($days[$d], $r, ['credits', 'requests', ...self::TOKENS]);
                self::add($days[$d]['models'][$model], $r, ['credits', 'requests']);
            }
        }
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c', $now),
            'as_of' => gmdate('c', $asOf),
            'unit' => 'credits',
            'windows' => array_map(static fn (array $w): array => self::summary($w), $win),
            'models_30d' => self::modelList($models, $win['30d']),
            'daily' => self::daily($days, $asOf),
            'hourly_7d' => self::hourly($hours, $models, $asOf),
            'heatmap_30d' => self::heatmap($heat),
        ];
    }

    /** JSON for publishing, refused when it carries any of the given refs (plain string checks, nothing to fail open). */
    public static function encode(array $feed, array $refs): string
    {
        $json = json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        foreach ($refs as $ref) {
            if ($ref !== '' && str_contains($json, '"' . $ref . '"')) {
                throw new \RuntimeException('the public usage would carry a key ref; nothing written');
            }
        }
        return $json;
    }

    private static function hour(string $bucket): int
    {
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d\TH', $bucket, new \DateTimeZone('UTC'));
        if ($t === false) {
            throw new \RuntimeException('unreadable bucket in a stats row');
        }
        return $t->getTimestamp();
    }

    /** The model name as requested: a configured alias or model name; anything else is "other". */
    private static function model(string $m): string
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,79}$#', $m) === 1 ? $m : 'other';
    }

    /** @param list<string> $fields */
    private static function add(?array &$into, array $r, array $fields, ?int $fb = null): void
    {
        $into ??= [];
        foreach ($fields as $f) {
            $into[$f] = ($into[$f] ?? 0) + $r[$f];
        }
        if ($fb !== null) {
            $into['fb_weighted'] = ($into['fb_weighted'] ?? 0) + $fb * $r['requests'];
            $into['fb_requests'] = ($into['fb_requests'] ?? 0) + $r['requests'];
        }
    }

    private static function share(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }

    private static function summary(array $w): array
    {
        return ['credits' => $w['credits'], 'requests' => $w['requests']]
            + array_intersect_key($w, array_flip(self::TOKENS))
            + ['http_2xx_share' => self::share($w['requests'] - $w['failed'], $w['requests']),
               'retried_share' => self::share($w['retried'], $w['requests']),
               'mean_first_byte_ms' => ($w['fb_requests'] ?? 0) > 0 ? intdiv($w['fb_weighted'], $w['fb_requests']) : null];
    }

    private static function byCredits(array $x, array $y): int
    {
        return [$y['credits'], $x['model']] <=> [$x['credits'], $y['model']];
    }

    private static function modelList(array $models, array $total): array
    {
        $list = [];
        foreach ($models as $m => $v) {
            $s = self::summary($v + array_fill_keys(self::COUNTS, 0));
            $list[] = ['model' => (string) $m, 'credits' => $v['credits'], 'requests' => $v['requests']]
                + array_intersect_key($v, array_flip(self::TOKENS))
                + ['credit_share' => self::share($v['credits'], $total['credits']) ?? 0.0,
                   'request_share' => self::share($v['requests'], $total['requests']) ?? 0.0,
                   'mean_first_byte_ms' => $s['mean_first_byte_ms']];
        }
        usort($list, [self::class, 'byCredits']);
        return $list;
    }

    /** One row per UTC day from the first day with use, at most 30 days, each with its top 5 models by credits. */
    private static function daily(array $days, int $asOf): array
    {
        if ($days === []) {
            return [];
        }
        $out = [];
        for ($t = strtotime(min(array_keys($days)) . ' UTC'); $t < $asOf; $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            $v = $days[$d] ?? [];
            $top = [];
            foreach ($v['models'] ?? [] as $m => $mv) {
                $top[] = ['model' => (string) $m, 'credits' => $mv['credits'], 'requests' => $mv['requests']];
            }
            usort($top, [self::class, 'byCredits']);
            $row = ['date' => $d, 'credits' => $v['credits'] ?? 0, 'requests' => $v['requests'] ?? 0];
            foreach (self::TOKENS as $f) {
                $row[$f] = $v[$f] ?? 0;
            }
            $out[] = $row + ['top_models' => array_slice($top, 0, self::TOP)];
        }
        return $out;
    }

    /** Hourly credits for the last 7 days (every hour, zeros included), split into the 5 largest models plus other. */
    private static function hourly(array $hours, array $models, int $asOf): array
    {
        $rank = [];
        foreach ($models as $m => $v) {
            $rank[(string) $m] = $v['credits'];
        }
        arsort($rank);
        $keep = array_slice(array_keys($rank), 0, self::TOP);
        $out = [];
        for ($t = $asOf - self::WINDOWS['7d'] * 3600; $t < $asOf; $t += 3600) {
            $v = $hours[$t] ?? [];
            $split = array_fill_keys($keep, 0) + ['other' => 0];
            foreach ($v['models'] ?? [] as $m => $c) {
                $split[in_array((string) $m, $keep, true) ? (string) $m : 'other'] += $c;
            }
            $out[] = ['hour' => gmdate('Y-m-d\TH', $t), 'credits' => $v['credits'] ?? 0, 'requests' => $v['requests'] ?? 0, 'models' => $split];
        }
        return $out;
    }

    /** Credits and requests per ISO weekday (1 = Monday) and UTC hour over the last 30 days. */
    private static function heatmap(array $heat): array
    {
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $v = $heat["$d-$h"] ?? [];
                $out[] = ['weekday' => $d, 'hour' => $h, 'credits' => $v['credits'] ?? 0, 'requests' => $v['requests'] ?? 0];
            }
        }
        return $out;
    }
}
