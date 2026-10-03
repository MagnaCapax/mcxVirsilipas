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

function testLogNeverThrowsOnAnInvalidPathAndRestoresTheUmask(): void
{
    Log::init("bad\0dir", 'debug');
    $old = ini_set('error_log', tempDir() . '/php-error.log');
    $mask = umask(0022);
    try {
        Log::write('warn', 'nul-path', []); // file_put_contents throws ValueError on a NUL byte
        same(0022, umask(), 'the caller umask is restored');
        check(str_contains((string) file_get_contents(ini_get('error_log')), '"event":"nul-path"'), 'fallback to error_log');
    } finally {
        umask($mask);
        ini_set('error_log', (string) $old);
        Log::init(null, 'error');
    }
}

function testLogNeverAppendsToADailyFileOthersCanRead(): void
{
    $dir = tempDir();
    $file = "$dir/" . gmdate('Ymd') . '.jsonl';
    file_put_contents($file, '');
    chmod($file, 0644);
    Log::init($dir, 'info');
    $old = ini_set('error_log', tempDir() . '/php-error.log');
    try {
        Log::write('info', 'private', ['ref' => 'svc-x']);
        same('', file_get_contents($file), 'nothing appended to a world-readable file');
        check(str_contains((string) file_get_contents(ini_get('error_log')), '"event":"private"'), 'fallback to error_log');
    } finally {
        ini_set('error_log', (string) $old);
        Log::init(null, 'error');
    }
}