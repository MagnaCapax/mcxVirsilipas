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
 * addresses. Logging is best effort: a failed write never fails or delays a request. The log
 * directory must be writable by its owner only, and a daily file that group or others can read,
 * or a symlink in its place, is never written; such records go to error_log.
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
        $now = time(); // one clock read: the record's ts and its day file always agree
        $line = json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z', $now), 'level' => $level, 'event' => $event] + $fields,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        if (self::$dir !== null && self::append(self::$dir, gmdate('Ymd', $now) . '.jsonl', $line . "\n")) {
            return;
        }
        error_log('virsilipas ' . $line);
    }

    /**
     * Appends to a new (created 0600) or private daily file in a directory only its owner can
     * write; false if it cannot, never throws. A symlink is never followed: in a directory others
     * could write, it could point the write at any file this process can open.
     */
    private static function append(string $dir, string $name, string $data): bool
    {
        $file = "$dir/$name";
        $umask = umask(0077);
        try {
            clearstatcache();
            $dirPerms = @fileperms($dir);
            if ($dirPerms === false || ($dirPerms & 0022) !== 0 || is_link($file) || (file_exists($file) && !is_file($file))) {
                return false; // missing or shared directory, a planted link, or not a regular file (a FIFO would block)
            }
            $perms = @fileperms($file);
            if ($perms !== false && ($perms & 0077) !== 0) {
                return false; // readable by others: never append to it
            }
            return @file_put_contents($file, $data, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        } finally {
            umask($umask);
        }
    }
}
