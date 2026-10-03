<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * Leveled operational log: one JSON object per line, one file per UTC day
 * (<log_dir>/YYYYMMDD.jsonl), appended under an exclusive lock so parallel workers
 * never interleave. Without a log_dir, records go to PHP's error_log as single JSON lines.
 *
 * Records hold event names, counts, statuses and billing references only. They never hold
 * request or response bodies, prompts, keys, the admin token, upstream error text or client
 * addresses. Logging is best effort: a failed write never fails or delays a request.
 */
final class Log
{
    public const LEVELS = ['error' => 0, 'warn' => 1, 'info' => 2, 'debug' => 3];

    private static ?string $dir = null;
    private static int $threshold = 0; // before init(): errors only, to error_log

    public static function init(?string $dir, string $level): void
    {
        self::$dir = $dir;
        self::$threshold = self::LEVELS[$level] ?? self::LEVELS['warn'];
    }

    public static function enabled(string $level): bool
    {
        return (self::LEVELS[$level] ?? PHP_INT_MAX) <= self::$threshold;
    }

    /** @param array<string, scalar|null> $fields */
    public static function write(string $level, string $event, array $fields = []): void
    {
        if (!self::enabled($level)) {
            return;
        }
        $line = json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event] + $fields,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        if (self::$dir !== null) {
            $umask = umask(0077);
            $ok = @file_put_contents(self::$dir . '/' . gmdate('Ymd') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
            umask($umask);
            if ($ok !== false) {
                return;
            }
        }
        error_log('virsilipas ' . $line);
    }
}
