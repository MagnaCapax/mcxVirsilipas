<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Log;

function testLogWritesOneJsonLinePerRecordIntoTheDailyFileAboveTheLevel(): void
{
    $dir = tempDir();
    Log::init($dir, 'info');
    try {
        Log::write('debug', 'hidden', ['n' => 1]);
        Log::write('info', 'shown', ['n' => 2, 'flag' => true]);
        Log::write('error', 'bad', []);
        $file = "$dir/" . gmdate('Ymd') . '.jsonl';
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        same(2, count($lines), 'debug is below the info level');
        $first = json_decode($lines[0], true);
        same(['level' => 'info', 'event' => 'shown', 'n' => 2, 'flag' => true], array_diff_key($first, ['ts' => 1]));
        check((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $first['ts']), 'UTC ISO-8601 ts');
        same('600', decoct(fileperms($file) & 0777), 'the log file is private');
    } finally {
        Log::init(null, 'error');
    }
}

function testLogNeverThrowsWhenTheDirectoryIsUnwritable(): void
{
    $dir = tempDir() . '/missing';
    Log::init($dir, 'debug');
    $old = ini_set('error_log', tempDir() . '/php-error.log');
    try {
        Log::write('warn', 'still-fine', ['n' => 1]); // falls back to error_log, no exception
        check(str_contains((string) file_get_contents(ini_get('error_log')), '"event":"still-fine"'), 'fallback to error_log');
    } finally {
        ini_set('error_log', (string) $old);
        Log::init(null, 'error');
    }
}
