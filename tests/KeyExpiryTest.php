<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\KeyExpiry;
use MagnaCapax\Virsilipas\Ledger;

// Schema 11 (ADR 0024): a key can expire, checked at authentication and again at reserve, and a named key carries its
// ref in front of the same 48-hex random part. Rotation keeps the expiry and gives a plain key.

const PLAIN_KEY = '/^vsk-[0-9a-f]{48}$/D';

/** Sets a key's expires_at straight in a ledger file, as time passing would. */
function setKeyExpiry(string $path, string $ref, ?int $at): void
{
    $db = new \PDO("sqlite:$path", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $db->prepare('UPDATE keys SET expires_at = ? WHERE ref = ?')->execute([$at, $ref]);
}

function testNamedKeyCarriesItsRefAndAuthenticates(): void
{
    $l = freshLedger();
    $key = $l->createKey('Cust_42-a', null, true);
    same(1, preg_match('/^vsk-Cust_42-a-[0-9a-f]{48}$/D', $key), "named key shape: $key");
    $found = $l->findKey($key);
    same(['Cust_42-a', true, null], [$found['ref'], array_key_exists('expires_at', $found), $found['expires_at']]);
    $long = str_repeat('Z', 64);
    same(4 + 64 + 1 + 48, strlen($l->createKey($long, null, true)), 'a 64-character ref keeps the key at 113 characters after vsk-');
    same(1, preg_match(PLAIN_KEY, $l->createKey('a:b#Ä')), 'a plain key takes any ref, as before');

    $ref = 'named-' . bin2hex(random_bytes(3));
    $named = Harness::get()['ledger']->createKey($ref, null, true);
    Harness::get()['ledger']->grant($ref, "g-$ref", 10000);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $named)['status'], 'a named key authenticates over HTTP');
    settled($ref);
}

function testNamedKeyRefusesARefOutsideTheAsciiSet(): void
{
    $l = freshLedger();
    foreach (['a#b', 'a:b', 'Ä', str_repeat('a', 65), '', 'a b', "a\n", 'a.b', 'a/b'] as $bad) {
        same([400, 'named keys need a ref of 1-64 of A-Z a-z 0-9 _ -'], throwsHttp(fn () => $l->createKey($bad, null, true)), 'ref ' . json_encode($bad));
    }
    same([], $l->keys(), 'a refused named key creates nothing');
}

function testKeyExpiryMustBeInTheFutureAndIsCheckedAtTheBoundary(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    foreach ([time() - 1, time(), 0, -5] as $bad) {
        same(400, throwsHttp(fn () => $l->createKey('a', $bad))[0], "expires_at $bad refused");
    }
    same([], $l->keys(), 'nothing created');
    $exp = time() + 60;
    $key = $l->createKey('a', $exp);
    same(1, preg_match(PLAIN_KEY, $key), 'an expiring key is a plain key');
    $l->grant('a', 'g1', 1000);
    $id = $l->findKey($key)['id'];
    same([$exp, $exp, $exp], [$l->findKey($key)['expires_at'], $l->keys()[0]['expires_at'], $l->status('a', lim())['expires_at']]);
    check($l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60)['reserved'] > 0, 'expires_at = now + 60 is accepted');

    setKeyExpiry($path, 'a', time());
    same([403, 'key has expired'], array_slice(refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60)), 0, 2), 'expires_at == now is refused');
}

function testReserveRefusesAnExpiredKeyEvenWhenCalledDirectly(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $key = $l->createKey('a', time() + 3600);
    $l->grant('a', 'g1', 1000);
    $id = $l->findKey($key)['id'];
    // The race: authentication passed a moment before the expiry, reserve runs after it.
    setKeyExpiry($path, 'a', time() - 1);
    same([403, 'key has expired', null], refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60)));
    same([1000, 0, 0], [$l->status('a', lim())['balance'], $l->status('a', lim())['reserved'], $l->status('a', lim())['in_flight']], 'nothing reserved');
    same(1, count($l->history('a')), 'only the grant is in the credit log');
    same([], $l->verify()['problems']);
}

function testIntegrationExpiredKeyIsRefusedOnEveryRoute(): void
{
    $h = Harness::get();
    $ref = 'exp-' . bin2hex(random_bytes(3));
    $key = $h['ledger']->createKey($ref, time() + 3600);
    $h['ledger']->grant($ref, "g-$ref", 10000);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status'], 'works before it expires');
    $balance = settled($ref)['balance'];
    setKeyExpiry($h['dir'] . '/ledger.sqlite', $ref, time() - 1);

    foreach (['POST /v1/chat/completions' => msg('ok', false), 'POST /v1/chat/completions ' => msg('ok', true), 'GET /v1/balance' => null,
        'GET /v1/models' => null, 'GET /v1/usage' => null] as $route => $body) {
        [$method, $path] = explode(' ', trim($route));
        $r = http($method, $path, $body, $key);
        $e = json_decode($r['body'], true)['error'] ?? [];
        same([403, 'key_expired', 'permission_error', 'key has expired'], [$r['status'], $e['code'] ?? null, $e['type'] ?? null, $e['message'] ?? null], $route);
    }
    foreach (['/v1/messages' => anthBody('anth', false), '/v1/messages/count_tokens' => anthBody('anth', false)] as $path => $body) {
        $r = http('POST', $path, $body, null, 0, ["x-api-key: $key"]);
        same([403, 'permission_error', 'key has expired'], [$r['status'], anthError($r)['type'], anthError($r)['message']], $path);
    }
    foreach ([['generateContent', ''], ['streamGenerateContent', '?alt=sse'], ['countTokens', '']] as [$method, $query]) {
        $r = gem('ok', $method, gemBody(), $key, [], $query);
        same([403, 'PERMISSION_DENIED', 'key has expired'], [$r['status'], gemError($r)['status'], gemError($r)['message']], $method);
    }
    same([$balance, 0], [settled($ref)['balance'], $h['ledger']->status($ref, lim())['reserved']], 'a refused request reserves nothing');
}

function testMigrateUpgradesASchemaTenLedgerWhoseKeysNeverExpire(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v10.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v10.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', '" . hash('sha256', 'vsk-old') . "', 70, 1, 0)");
    $db->exec("INSERT INTO grants (grant_id, key_id, credits, created_at, expires_at) VALUES ('inv-1', 1, 70, 1000, NULL)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (1000, 1, 'grant', 70, 70, 'inv-1')");
    unset($db);
    $l = new Ledger("$dir/v10.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v10.sqlite");
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same([null], $db->query('SELECT expires_at FROM keys')->fetchAll(\PDO::FETCH_COLUMN), 'an old key never expires');
    $k = $l->findKey('vsk-old');
    same([1, null, null, null], [$k['id'], $k['expires_at'], $l->keys()[0]['expires_at'], $l->status('old', lim())['expires_at']]);
    check($l->reserve(1, 10, 10, 1000, unitRates(), lim(), 60)['reserved'] > 0, 'an old key still spends');
    same(1, preg_match('/^vsk-new-[0-9a-f]{48}$/D', $l->createKey('new', time() + 60, true)), 'new keys can expire and be named');
    same([], $l->verify()['problems']);
}

function testRotationKeepsTheExpiryAndGivesAPlainKey(): void
{
    $l = freshLedger();
    $exp = time() + 86400;
    $named = $l->createKey('n', $exp, true);
    $new = $l->rotateKey('n');
    same(1, preg_match(PLAIN_KEY, $new), "a rotated key is plain: $new");
    same(null, $l->findKey($named), 'the named key stops working');
    same([$exp, $exp], [$l->findKey($new)['expires_at'], $l->status('n', lim())['expires_at']], 'the expiry stays with the ref');
}

function testKeyExpiryParsesRelativeAndUtcFormsOnly(): void
{
    $now = 1_800_000_000;
    same([$now + 60, $now + 7200, $now + 999999 * 86400, $now + 86400], array_map(static fn ($w) => KeyExpiry::parse($w, $now), ['+1m', '+2h', '+999999d', '+01d']));
    same(gmmktime(3, 4, 5, 1, 2, 2030), KeyExpiry::parse('2030-01-02T03:04:05Z', $now));
    same(gmmktime(0, 0, 0, 2, 29, 2028), KeyExpiry::parse('2028-02-29T00:00:00Z', $now), 'a leap day');
    foreach (['', '+0m', '+000000h', '+1000000d', '+1s', '1m', '+1M', '+ 1m', "+1m\n", '-1d', '+1.5h', '1792224000', '2030-02-30T00:00:00Z',
        '2030-01-02T24:00:00Z', '2030-13-01T00:00:00Z', '2030-01-02 03:04:05Z', '2030-01-02T03:04:05', '2030-01-02T03:04:05+00:00',
        '2030-01-02t03:04:05z', '2030-1-2T03:04:05Z', "2030-01-02T03:04:05Z\n"] as $bad) {
        $threw = false;
        try {
            KeyExpiry::parse($bad, $now);
        } catch (\InvalidArgumentException $e) {
            $threw = str_contains($e->getMessage(), '--expires must be');
        }
        same(true, $threw, 'refused: ' . json_encode($bad));
    }
}

function testCliKeyCreateTakesExpiresNamedAndJson(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $run = static function (string ...$args) use ($dir): array {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($p), trim($out), $err];
    };
    $run('init');
    [$code, $out] = $run('key-create', 'a');
    same([0, 1], [$code, preg_match(PLAIN_KEY, $out)], 'no flags: the bare key, as before');
    $t = time();
    [$code, $out] = $run('key-create', 'b', '--expires=+1h', '--json');
    $j = json_decode($out, true);
    same([0, ['ref', 'key', 'expires_at', 'named'], 'b', false, 1], [$code, array_keys($j), $j['ref'], $j['named'], preg_match(PLAIN_KEY, $j['key'])]);
    check($j['expires_at'] >= $t + 3600 && $j['expires_at'] <= time() + 3600, 'expires an hour from now');
    $bExpires = $j['expires_at'];
    [$code, $out] = $run('key-create', '--json', 'C_1', '--named', '--expires=2099-01-01T00:00:00Z');
    $j = json_decode($out, true);
    same([0, 'C_1', gmmktime(0, 0, 0, 1, 1, 2099), true, 1], [$code, $j['ref'], $j['expires_at'], $j['named'], preg_match('/^vsk-C_1-[0-9a-f]{48}$/D', $j['key'])]);
    $listed = static fn (string $field): array => array_column(array_map(static fn ($l) => json_decode($l, true), explode("\n", $run('keys')[1])), $field, 'ref');
    same(['C_1' => $j['expires_at'], 'a' => null, 'b' => $bExpires], $listed('expires_at'), 'keys lists expires_at');

    foreach ([['--expires=tomorrow', '--expires must be'], ['--expires=+0d', '--expires must be'], ['--expires=2000-01-01T00:00:00Z', 'future'],
        ['--expires', 'unknown or repeated option'], ['--bogus', 'unknown or repeated option'], ['--json', '--json', 'unknown or repeated option'],
        ['--expires=+1h', '--expires=+2h', 'unknown or repeated option'], ['extra', 'takes one ref']] as $case) {
        $want = array_pop($case);
        [$code, $out, $err] = $run('key-create', 'd', ...$case);
        same([1, '', true], [$code, $out, str_contains($err, $want)], implode(' ', $case) . ": $err");
    }
    [$code, , $err] = $run('key-create', 'a#b', '--named');
    same([1, true], [$code, str_contains($err, 'named keys need a ref of 1-64')]);
    [$code, , $err] = $run('key-create', '--named');
    same([1, true], [$code, str_contains($err, 'takes one ref')]);
    same(['C_1', 'a', 'b'], array_keys($listed('ref')), 'refused calls created nothing');
}
