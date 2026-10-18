<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The `--expires=<when>` value of `bin/virsilipas key-create` (ADR 0024): +N followed by m, h or d (N from 1 to 999999)
 * counted from now, or an absolute UTC time YYYY-MM-DDTHH:MM:SSZ. Anything else is refused. Whether the time is in the
 * future is Ledger::createKey()'s check, not this one's.
 */
final class KeyExpiry
{
    private const UNITS = ['m' => 60, 'h' => 3600, 'd' => 86400];
    public const FORMS = '+N followed by m, h or d (N from 1 to 999999), or a UTC time YYYY-MM-DDTHH:MM:SSZ';

    /** The unix time $when names, read at $now. */
    public static function parse(string $when, int $now): int
    {
        if (preg_match('/^\+([0-9]{1,6})([mhd])$/D', $when, $m) === 1 && (int) $m[1] >= 1) {
            return $now + (int) $m[1] * self::UNITS[$m[2]];
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $when) === 1) {
            $t = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $when, new \DateTimeZone('UTC'));
            // The round trip refuses what createFromFormat would roll over, such as February 30 or hour 24.
            if ($t !== false && $t->format('Y-m-d\TH:i:s\Z') === $when) {
                return $t->getTimestamp();
            }
        }
        throw new \InvalidArgumentException('--expires must be ' . self::FORMS);
    }
}
