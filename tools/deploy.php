<?php

declare(strict_types=1);

// Deploys a release tag from a git mirror of this repository, as root on the proxy host:
//
//   php tools/deploy.php --url https://proxy.example.com v0.2.0
//
// 1. fetch the mirror and resolve the tag; unpack it into RELEASES/<tag> (git archive, no .git)
// 2. lint it, wait for in-flight requests to finish, stop php-fpm
// 3. back up the ledger (SQLite online backup + integrity check)
// 4. run `init` as the PHP user (schema migrations), point the CURRENT symlink at the release,
//    run `verify` (every balance equals its credit log), start php-fpm
// 5. check REVISION through the symlink and that GET /v1/models without a key answers 401
//
// If anything after php-fpm stopped fails, it puts back the previous release and the backup taken
// in step 3, starts php-fpm and exits 1. It restores the backup only if nothing but the migration
// wrote to the ledger since (the CLI works without php-fpm); otherwise it keeps the ledger as it is
// and, once the new schema is in place, keeps the new release live and says so.
// An older release is never run against a newer ledger: its `init` refuses the newer schema,
// which rolls the deploy back.
//
// One-time setup: see "Releasing and upgrading" in README.md.

const USAGE = <<<'TXT'
Usage: php tools/deploy.php --url <base url> [options] <tag>
  --url URL          public base URL, for the post-deploy check (required)
  --mirror DIR       bare git mirror                      [/opt/virsilipas.git]
  --releases DIR     where releases are unpacked          [/opt/virsilipas-releases]
  --current PATH     symlink the web server serves from   [/opt/virsilipas]
  --config FILE      deployment config                    [/etc/virsilipas/config.php]
  --snapshots DIR    where pre-deploy backups go (root)   [/var/lib/virsilipas-snapshots]
  --service NAME     php-fpm systemd unit                 [php8.4-fpm]
  --user NAME        the PHP user                         [www-data]
  --drain SECONDS    wait this long for in-flight requests [60]
  --log FILE         also append each step to FILE

TXT;

$opt = ['mirror' => '/opt/virsilipas.git', 'releases' => '/opt/virsilipas-releases', 'current' => '/opt/virsilipas',
    'config' => '/etc/virsilipas/config.php', 'snapshots' => '/var/lib/virsilipas-snapshots', 'service' => 'php8.4-fpm',
    'user' => 'www-data', 'drain' => '60', 'log' => '', 'url' => ''];
$tag = '';
for ($i = 1; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--') && array_key_exists(substr($argv[$i], 2), $opt) && isset($argv[$i + 1])) {
        $opt[substr($argv[$i], 2)] = $argv[++$i];
    } elseif ($tag === '' && !str_starts_with($argv[$i], '-')) {
        $tag = $argv[$i];
    } else {
        fail(USAGE);
    }
}
if (!preg_match('/^v\d+\.\d+\.\d+$/', $tag) || $opt['url'] === '' || !ctype_digit($opt['drain'])) {
    fail(USAGE);
}
if (posix_geteuid() !== 0) {
    fail('run as root');
}
umask(0022);
$lock = fopen('/run/virsilipas-deploy.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('another deploy is running');
}
// Unlock explicitly on exit: a process started from here (php-fpm) may inherit the descriptor and hold the lock.
register_shutdown_function(static fn () => flock($lock, LOCK_UN));

// 1. Fetch, resolve, unpack.
run(['git', '--git-dir=' . $opt['mirror'], 'fetch', '--prune', '--prune-tags', '--tags', 'origin']);
$commit = run(['git', '--git-dir=' . $opt['mirror'], 'rev-parse', '--verify', "refs/tags/$tag^{commit}"]);
$revision = "$tag " . substr($commit, 0, 12);
$release = $opt['releases'] . "/$tag";
if (is_dir($release)) {
    if (trim((string) @file_get_contents("$release/REVISION")) !== $revision) {
        fail("$release exists but is not $revision; move it away first");
    }
    say("$tag is already unpacked at $release");
} else {
    @mkdir($opt['releases'], 0755, true);
    $tmp = "$release.tmp";
    if (file_exists($tmp)) {
        run(['rm', '-rf', '--', $tmp]);
    }
    mkdir($tmp, 0755);
    $tar = "$tmp.tar";
    run(['git', '--git-dir=' . $opt['mirror'], 'archive', '--format=tar', '-o', $tar, $commit]);
    run(['tar', '-x', '--no-same-owner', '-f', $tar, '-C', $tmp]);
    unlink($tar);
    file_put_contents("$tmp/REVISION", "$revision\n");
    rename($tmp, $release);
    say("unpacked $revision into $release");
}

// 2. Lint, drain, stop.
foreach (array_merge(glob("$release/{src,public,tools}/*.php", GLOB_BRACE) ?: [], ["$release/bin/virsilipas"]) as $f) {
    run(['php', '-l', $f]);
}
$cfg = require $opt['config'];
$db = is_array($cfg) && is_string($cfg['db_path'] ?? null) ? $cfg['db_path'] : fail('config has no db_path');
$previous = is_link($opt['current']) ? (string) readlink($opt['current']) : (is_dir($opt['current']) ? $opt['current'] : '');
if ($previous === '' || realpath($previous) === realpath($release)) {
    fail($previous === '' ? "{$opt['current']} does not exist; install by hand once (README)" : "$tag is already live");
}
for ($t = 0, $flight = inFlight($db); $flight > 0 && $t < (int) $opt['drain']; $t += 2, $flight = inFlight($db)) {
    sleep(2);
}
say($flight > 0 ? "stopping with $flight request(s) still in flight; their reservations stay held until settled" : 'no requests in flight');
run(['systemctl', 'stop', $opt['service']]);
say("{$opt['service']} stopped; deploying $revision over " . revisionOf($previous));

// 3. Back up the ledger. From here on, any failure rolls back.
$backup = $opt['snapshots'] . "/pre-$tag-" . gmdate('Ymd\THis\Z') . '.sqlite';
$restoreNeeded = false;
try {
    @mkdir($opt['snapshots'], 0700, true);
    run(['sqlite3', '-readonly', '-nofollow', $db, ".backup '$backup'"]);
    chmod($backup, 0600);
    // One self-contained file (no -wal beside it), so a restore copies everything there is.
    run(['sqlite3', $backup, 'PRAGMA journal_mode = DELETE']);
    if (run(['sqlite3', '-readonly', $backup, 'PRAGMA integrity_check']) !== 'ok') {
        throw new RuntimeException("backup $backup fails its integrity check");
    }
    say("ledger backed up to $backup");
    $backupPrint = fingerprint($backup);

    // 4. Migrate, switch, verify, start.
    if (!is_link($opt['current'])) { // first deploy: a plain directory becomes the previous release
        $moved = $opt['releases'] . '/legacy-' . basename(str_replace(' ', '-', revisionOf($previous)));
        rename($opt['current'], $moved);
        $previous = $moved;
        say("moved the unversioned install to $moved");
    }
    $restoreNeeded = true;
    asUser($opt, $release, ['init']);
    $initDone = true;
    swap($opt['current'], $release);
    say("{$opt['current']} now points at $release");
    $verify = asUser($opt, $release, ['verify']);
    say("verify: $verify");
    if (trim((string) file_get_contents($opt['current'] . '/REVISION')) !== $revision) {
        throw new RuntimeException('REVISION through the symlink is not ' . $revision);
    }
    run(['systemctl', 'start', $opt['service']]);

    // 5. Live check.
    $code = httpStatus(rtrim($opt['url'], '/') . '/v1/models');
    if ($code !== 401) {
        throw new RuntimeException("GET /v1/models without a key answered $code, expected 401");
    }
    say("deployed $revision: verify clean, REVISION matches, /v1/models without a key answers 401");
} catch (Throwable $e) {
    say('FAILED: ' . $e->getMessage());
    run(['systemctl', 'stop', $opt['service']], false); // first, so nothing is served while the ledger is compared
    // Restoring is safe only if nothing but the migration wrote since the backup: a request served by
    // the new release, or a grant, key or limit change made with the CLI (which does not need php-fpm),
    // would be lost. Migrations only add tables or columns, so the rows of the other tables must match the backup.
    $changed = false;
    if ($restoreNeeded && isset($backupPrint)) {
        try {
            $changed = fingerprint($db) !== $backupPrint;
        } catch (Throwable $x) {
            $changed = true; // cannot compare: never restore blind
        }
    }
    if ($changed && isset($initDone)) {
        try {
            swap($opt['current'], $release);
        } finally {
            run(['systemctl', 'start', $opt['service']], false);
        }
        say("the ledger changed during the deploy (or could not be compared with the backup), so the backup is not restored and $revision stays live; investigate the failed check");
        exit(1);
    }
    say('rolling back');
    try {
        if (is_link($opt['current']) || !file_exists($opt['current'])) {
            swap($opt['current'], $previous);
        }
        if ($restoreNeeded && !$changed) {
            restore($backup, $db, $opt['user']);
            say("ledger restored from $backup");
        } elseif ($changed) {
            say("the ledger changed during the deploy, so $backup is not restored");
        }
        say('rolled back to ' . revisionOf($previous));
    } catch (Throwable $r) {
        say('ROLLBACK FAILED: ' . $r->getMessage());
    } finally {
        run(['systemctl', 'start', $opt['service']], false);
        say("{$opt['service']} started");
    }
    exit(1);
}

/** Runs a command (argument list, no shell) and returns its trimmed stdout; throws or exits on failure. */
function run(array $cmd, bool $strict = true): string
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('cannot start ' . $cmd[0]);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $code = proc_close($p);
    if ($code !== 0 && $strict) {
        throw new RuntimeException(implode(' ', $cmd) . " exited $code: " . trim($err));
    }
    return trim($out);
}

/** Runs bin/virsilipas of $release as the PHP user. */
function asUser(array $opt, string $release, array $args): string
{
    return run(['runuser', '-u', $opt['user'], '--', 'env', 'VIRSILIPAS_CONFIG=' . $opt['config'], 'php', "$release/bin/virsilipas", ...$args]);
}

/** Points $link at $target atomically: a new symlink renamed over the old one. */
function swap(string $link, string $target): void
{
    $new = "$link.new";
    if (is_link($new)) {
        unlink($new);
    }
    if (!symlink($target, $new) || !rename($new, $link)) {
        throw new RuntimeException("cannot point $link at $target");
    }
}

/**
 * Puts $backup in place as the ledger. Root writes into a directory the PHP user owns, so nothing
 * there is trusted: the copy is created exclusively (never through an existing name or symlink),
 * private by umask, handed to the user with lchown, then renamed over the database (rename
 * replaces a symlink, it does not follow it). Leftover -wal and -shm files are removed.
 */
function restore(string $backup, string $db, string $user): void
{
    $tmp = dirname($db) . '/.restore-' . bin2hex(random_bytes(6));
    $old = umask(0077);
    $out = fopen($tmp, 'x');
    umask($old);
    $in = fopen($backup, 'r');
    if ($out === false || $in === false || stream_copy_to_stream($in, $out) === false || !fflush($out)) {
        throw new RuntimeException("cannot restore $backup; it is intact, restore it by hand (README)");
    }
    fclose($in);
    fclose($out);
    $pw = posix_getpwnam($user);
    if ($pw === false || !lchown($tmp, $pw['uid']) || !lchgrp($tmp, $pw['gid']) || !rename($tmp, $db)) {
        throw new RuntimeException("cannot put $tmp in place; restore $backup by hand (README)");
    }
    foreach (["$db-wal", "$db-shm"] as $f) {
        if (file_exists($f) || is_link($f)) {
            unlink($f);
        }
    }
}

/** Requests holding an unexpired reservation right now (read-only). */
function inFlight(string $db): int
{
    return (int) run(['sqlite3', '-readonly', '-nofollow', $db, "SELECT COUNT(*) FROM leases WHERE settled = 0 AND expires_at > strftime('%s','now')"]);
}

/**
 * sha256 of the rows of every table except credit_log, read-only and streamed (the usage table can be large).
 * Only the schema-3 columns are read, never the schema text: a migration that adds columns (schema 4 added five
 * to usage) changes neither, so a deploy that failed after its migration still compares equal and is rolled back.
 */
function fingerprint(string $db): string
{
    $sql = 'SELECT id, ref, key_hash, balance, active, created_at, max_concurrent, requests_per_minute, credits_per_minute FROM keys ORDER BY id;'
        . ' SELECT grant_id, key_id, credits, created_at FROM grants ORDER BY grant_id;'
        . ' SELECT id, key_id, reserved, created_at, expires_at, settled FROM leases ORDER BY id;'
        . ' SELECT id, ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation FROM usage ORDER BY id;';
    $p = proc_open(['sqlite3', '-readonly', '-nofollow', '-batch', '-list', $db, $sql], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException("cannot read $db");
    }
    $h = hash_init('sha256');
    hash_update_stream($h, $pipes[1]);
    fclose($pipes[1]);
    if (proc_close($p) !== 0) {
        throw new RuntimeException("cannot read $db");
    }
    return hash_final($h);
}

function revisionOf(string $dir): string
{
    return trim((string) @file_get_contents("$dir/REVISION")) ?: basename($dir);
}

function httpStatus(string $url): int
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    for ($try = 0; $try < 5; $try++) { // php-fpm may take a moment to accept connections
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        if ($code !== 0 && $code !== 502) {
            return $code;
        }
        sleep(1);
    }
    return $code;
}

function say(string $msg): void
{
    global $opt;
    $line = gmdate('Y-m-d\TH:i:s\Z') . " virsilipas deploy: $msg\n";
    echo $line;
    if (($opt['log'] ?? '') !== '') {
        file_put_contents($opt['log'], $line, FILE_APPEND);
    }
}

function fail(string $msg): never
{
    fwrite(STDERR, rtrim($msg) . "\n");
    exit(2);
}
