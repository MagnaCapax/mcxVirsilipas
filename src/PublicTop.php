<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * PublicTop: the most active keys per window, published under an anonymous id (ADR 0026). Built from the same
 * `Ledger::stats('hour', ['key', 'model'])` rows as PublicUsage, over the keys whose ref starts with a deployment-chosen
 * prefix. Each key appears as `id` only: SHA-256 applied 1000 times, first to "<ref>:<sha256 of the key>", then each
 * time to the previous digest in lowercase hex. The holder of a key can compute their own id; without the key or its
 * digest nobody can tell whose id it is (the deployment, which holds both, can). The output carries no ref, no key
 * digest, no upstream, no cost and no grant.
 *
 * Windows end at `as_of`, the end of the last complete UTC hour, as in PublicUsage.
 */
final class PublicTop
{
    public const SCHEMA_VERSION = 1;
    public const ROUNDS = 1000;
    private const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];
    private const TOP = 10;

    /** The anonymous id of a key: SHA-256 ROUNDS times over "<ref>:<key digest>", hex-chained. */
    public static function id(string $ref, string $keyHash): string
    {
        $h = hash('sha256', $ref . ':' . $keyHash);
        for ($i = 1; $i < self::ROUNDS; $i++) {
            $h = hash('sha256', $h);
        }
        return $h;
    }

    /**
     * @param list<array<string, mixed>> $rows `stats('hour', ['key', 'model'], since, until)` rows
     * @param array<string, string> $keyHashes ref => current key digest (`Ledger::keyHashes`); a ref missing here is left out
     * @return array<string, mixed>
     */
    public static function build(array $rows, array $keyHashes, int $now, string $refPrefix): array
    {
        if ($refPrefix === '') {
            throw new \InvalidArgumentException('a ref prefix is required: it decides whose usage is published');
        }
        $asOf = intdiv($now, 3600) * 3600;
        $sum = array_fill_keys(array_keys(self::WINDOWS), []);
        $total = array_fill_keys(array_keys(self::WINDOWS), 0);
        foreach ($rows as $r) {
            $ref = (string) ($r['key'] ?? '');
            if (!str_starts_with($ref, $refPrefix)) {
                continue;
            }
            $start = self::hour((string) ($r['bucket'] ?? ''));
            if ($start >= $asOf) {
                continue;
            }
            foreach (['credits', 'requests'] as $f) {
                if (!is_int($r[$f] ?? null) || $r[$f] < 0) {
                    throw new \RuntimeException("unreadable $f in a stats row");
                }
            }
            foreach (self::WINDOWS as $name => $n) {
                if ($start < $asOf - $n * 3600) {
                    continue;
                }
                $total[$name] += $r['credits'];
                if (!isset($keyHashes[$ref])) {
                    continue;   // a removed key: counted in the total, never listed
                }
                $sum[$name][$ref]['credits'] = ($sum[$name][$ref]['credits'] ?? 0) + $r['credits'];
                $sum[$name][$ref]['requests'] = ($sum[$name][$ref]['requests'] ?? 0) + $r['requests'];
            }
        }
        $ids = [];
        $windows = [];
        foreach ($sum as $name => $keys) {
            $list = [];
            foreach ($keys as $ref => $v) {
                if ($v['credits'] <= 0) {
                    continue;
                }
                $ids[$ref] ??= self::id((string) $ref, $keyHashes[$ref]);
                $list[] = ['id' => $ids[$ref], 'credits' => $v['credits'], 'requests' => $v['requests'],
                    'credit_share' => $total[$name] > 0 ? round($v['credits'] / $total[$name], 4) : 0.0];
            }
            usort($list, static fn (array $a, array $b): int => [$b['credits'], $b['requests'], $a['id']] <=> [$a['credits'], $a['requests'], $b['id']]);
            $windows[$name] = [];
            foreach (array_slice($list, 0, self::TOP) as $i => $e) {
                $windows[$name][] = ['rank' => $i + 1] + $e;
            }
        }
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c', $now),
            'as_of' => gmdate('c', $asOf),
            'unit' => 'credits',
            'id' => 'sha256 applied ' . self::ROUNDS . ' times: first to "<key ref>:<sha256 hex of the key>", then to the previous lowercase hex digest',
            'windows' => $windows,
        ];
    }

    /** JSON for publishing, refused when it carries any ref or key digest (plain string checks, nothing to fail open). */
    public static function encode(array $feed, array $refs, array $keyHashes): string
    {
        $json = json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        // A ref is matched as a JSON string (a short ref is a substring of anything); a 64-hex digest anywhere.
        $secrets = array_values($keyHashes);
        foreach ($refs as $ref) {
            $secrets[] = '"' . $ref . '"';
        }
        foreach ($secrets as $secret) {
            if ($secret !== '""' && $secret !== '' && str_contains($json, (string) $secret)) {
                throw new \RuntimeException('the public top list would carry a key ref or digest; nothing written');
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
}
