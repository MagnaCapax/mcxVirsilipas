<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Ledger;

// Schema 9 (and 10: an index on grants(key_id) and a REPLACE-proof credit log): removing a key for good, grants that carry a stored (not enforced) expiry, and GET /v1/usage,
// where a key reads its own balance, consumption and grants and nothing about any other key.

function testRemoveKeyMakesItUnusableButKeepsBalanceAndHistory(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $plain = $l->createKey('a');
    $l->grant('a', 'g1', 500);
    $id = $l->findKey($plain)['id'];
    $lease = $l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60)['lease_id'];
    $l->settle($lease, $id, 5, ['model' => 'm', 'prompt' => 3, 'completion' => 2, 'cached' => 0, 'status' => 200, 'aborted' => false, 'estimated' => false]);
    $before = $l->history('a');

    $r = $l->removeKey('a');
    same([true, 495], [$r['applied'], $r['balance']]);
    same(['applied' => false, 'removed_at' => $r['removed_at'], 'balance' => 495], $l->removeKey('a'), 'removing again changes nothing');
    same(null, $l->findKey($plain), 'the key never authenticates again');
    $db = new \PDO("sqlite:$path");
    same(["removed:$id", 0], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $db->query('SELECT key_hash, active FROM keys')->fetch(\PDO::FETCH_NUM)),
        'the hash is a value no sha256 hex digest equals');
    same($before, $l->history('a'), 'the credit log stays');
    same(1, (int) $db->query('SELECT COUNT(*) FROM usage WHERE key_id = ' . $id)->fetchColumn(), 'the usage rows stay');
    same(495, $l->status('a', lim())['balance']);
    same([], $l->verify()['problems']);
    same(403, refusal(fn () => $l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60))[0], 'a request already authenticated cannot reserve either');

    foreach (['rotate' => fn () => $l->rotateKey('a'), 'enable' => fn () => $l->setActive('a', true), 'create' => fn () => $l->createKey('a'),
        'grant' => fn () => $l->grant('a', 'g2', 10), 'limits' => fn () => $l->setLimits('a', ['max_concurrent' => 2]),
        'bonus' => fn () => $l->setBonus('a', 10), 'group' => fn () => $l->setGroup('a', 'x')] as $what => $fn) {
        same(409, refusal($fn)[0], "$what on a removed key is refused");
    }
    same(['applied' => false, 'balance' => 495, 'expires_at' => null], $l->grant('a', 'g1', 500), 'a retried push of a grant applied before removal still answers');
    same(404, refusal(fn () => $l->removeKey('nobody'))[0]);
    same(true, $l->keys()[0]['removed_at'] === $r['removed_at'] && $l->keys()[0]['active'] === false, 'keys() shows the removal');
}

function testMigrateUpgradesASchemaEightLedgerKeepingItsGrants(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v8.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v8.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', '" . hash('sha256', 'vsk-old') . "', 70, 1, 0)");
    $db->exec("INSERT INTO grants (grant_id, key_id, credits, created_at) VALUES ('inv-1', 1, 70, 1000)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (1000, 1, 'grant', 70, 70, 'inv-1')");
    unset($db);
    $l = new Ledger("$dir/v8.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v8.sqlite");
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same(['inv-1', 70, 1000, null], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v,
        $db->query('SELECT grant_id, credits, created_at, expires_at FROM grants')->fetch(\PDO::FETCH_NUM)), 'an old grant keeps its values and never expires');
    same(null, $db->query('SELECT removed_at FROM keys')->fetchColumn(), 'an old key is not removed');
    same(1, $l->findKey('vsk-old')['id'], 'an old key still authenticates');
    same(['applied' => false, 'balance' => 70, 'expires_at' => null], $l->grant('old', 'inv-1', 70), 'a retried old grant is still a no-op');
    same(409, refusal(fn () => $l->grant('old', 'inv-1', 70, time() + 86400))[0], 'an old grant id with an expiry is a different grant');
    same(80, $l->grant('old', 'inv-2', 10, time() + 86400)['balance']);
    same([], $l->verify()['problems']);
}

function testGrantExpiryIsStoredPartOfIdempotencyAndNotEnforced(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $plain = $l->createKey('a');
    $exp = time() + 30 * 86400;
    same(['applied' => true, 'balance' => 100, 'expires_at' => $exp], $l->grant('a', 'g1', 100, $exp));
    same(['applied' => false, 'balance' => 100, 'expires_at' => $exp], $l->grant('a', 'g1', 100, $exp), 'a retry with the same expiry is a no-op');
    same(409, refusal(fn () => $l->grant('a', 'g1', 100, $exp + 1))[0], 'same grant_id, different expiry');
    same(409, refusal(fn () => $l->grant('a', 'g1', 100))[0], 'same grant_id, expiry dropped');
    foreach ([time(), time() - 1, 0, 253_402_300_800] as $bad) {
        same(400, refusal(fn () => $l->grant('a', "bad-$bad", 5, $bad))[0], "expires_at $bad refused");
    }
    same(100, $l->status('a', lim())['balance'], 'refused grants change nothing');
    // Not enforced: a grant whose expiry has passed leaves the balance and spending as they were.
    $db = new \PDO("sqlite:$path");
    $db->exec("UPDATE grants SET expires_at = 1 WHERE grant_id = 'g1'");
    $id = $l->findKey($plain)['id'];
    same(100, $l->status('a', lim())['balance']);
    check($l->reserve($id, 10, 10, 1000, unitRates(), lim(), 60)['reserved'] > 0, 'an expired grant still spends');
    same([['grant_id' => 'g1', 'credits' => 100, 'created_at' => (int) $db->query('SELECT created_at FROM grants')->fetchColumn(), 'expires_at' => 1]],
        $l->grants($id, 10));
}

function testIntegrationRemoveKeyEndpoint(): void
{
    [$ref, $key] = fundedKey(10000);
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $key)['status']);
    settled($ref);
    same(401, http('POST', '/admin/keys/remove', ['ref' => $ref])['status'], 'no token');
    same(401, http('POST', '/admin/keys/remove', ['ref' => $ref], $key)['status'], 'a client key is not the admin token');
    $r = json_decode(http('POST', '/admin/keys/remove', ['ref' => $ref], 'admin-secret')['body'], true);
    same(['ref', 'applied', 'removed_at', 'balance'], array_keys($r));
    same([$ref, true, 10000 - 18], [$r['ref'], $r['applied'], $r['balance']]);
    $again = json_decode(http('POST', '/admin/keys/remove', ['ref' => $ref], 'admin-secret')['body'], true);
    same([false, $r['removed_at']], [$again['applied'], $again['removed_at']], 'idempotent');
    foreach (['/v1/chat/completions' => msg('ok', false), '/v1/balance' => null, '/v1/usage' => null, '/v1/models' => null] as $path => $body) {
        same(401, http($body === null ? 'GET' : 'POST', $path, $body, $key)['status'], "$path refuses the removed key");
    }
    foreach (['/admin/keys' => [], '/admin/keys/rotate' => [], '/admin/keys/active' => ['active' => true],
        '/admin/keys/limits' => ['max_concurrent' => 2], '/admin/grants' => ['grant_id' => "new-$ref", 'credits' => 5]] as $path => $extra) {
        $res = http('POST', $path, ['ref' => $ref] + $extra, 'admin-secret');
        same([409, 'key_removed'], [$res['status'], json_decode($res['body'], true)['error']['code'] ?? null], "$path on a removed ref");
    }
    same(3, count(Harness::get()['ledger']->history($ref)), 'grant, reserve and refund stay in the credit log');
    same(18, lastUsage($ref)['charged'], 'the usage row stays');
    same(404, http('POST', '/admin/keys/remove', ['ref' => "nobody-$ref"], 'admin-secret')['status']);
}

function testIntegrationGrantExpiresAt(): void
{
    $ref = 'exp-' . bin2hex(random_bytes(3));
    Harness::get()['ledger']->createKey($ref);
    $exp = time() + 86400;
    $g = ['ref' => $ref, 'grant_id' => "inv-$ref", 'credits' => 50, 'expires_at' => $exp];
    same(['applied' => true, 'balance' => 50, 'expires_at' => $exp], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true));
    same(['applied' => false, 'balance' => 50, 'expires_at' => $exp], json_decode(http('POST', '/admin/grants', $g, 'admin-secret')['body'], true));
    same(409, http('POST', '/admin/grants', ['expires_at' => $exp + 60] + $g, 'admin-secret')['status'], 'different expires_at');
    same(409, http('POST', '/admin/grants', ['expires_at' => null] + $g, 'admin-secret')['status'], 'expires_at dropped');
    foreach ([(string) $exp, 1.5, true, time() - 10] as $bad) {
        same(400, http('POST', '/admin/grants', ['grant_id' => 'other-' . bin2hex(random_bytes(3)), 'expires_at' => $bad] + $g, 'admin-secret')['status'],
            'expires_at ' . var_export($bad, true) . ' refused');
    }
    same(50, settled($ref)['balance']);
}

function testIntegrationUsageShowsOnlyTheCallersOwnData(): void
{
    [$refA, $keyA] = fundedKey(10000);
    [$refB, $keyB] = fundedKey(20000);
    Harness::get()['ledger']->grant($refA, "extra-$refA", 5, time() + 86400);
    for ($i = 0; $i < 2; $i++) {
        same(200, http('POST', '/v1/chat/completions', msg('ok', false), $keyA)['status']);
    }
    same(200, http('POST', '/v1/chat/completions', msg('ok', false), $keyB)['status']);
    settled($refA);
    settled($refB);

    $raw = http('GET', '/v1/usage', null, $keyA);
    same(200, $raw['status']);
    $u = json_decode($raw['body'], true);
    same(['balance', 'days', 'since', 'daily', 'models', 'grants'], array_keys($u));
    same([10005 - 36, 30], [$u['balance'], $u['days']]);
    same(intdiv(time(), 86400) * 86400 - 29 * 86400, $u['since']);
    same([['day' => gmdate('Y-m-d'), 'requests' => 2]], array_map(static fn ($d) => ['day' => $d['day'], 'requests' => $d['requests']], $u['daily']));
    same(['model', 'requests', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'reasoning_tokens', 'credits'], array_keys($u['models'][0]));
    same([['ok', 2, 36]], array_map(static fn ($m) => [$m['model'], $m['requests'], $m['credits']], $u['models']));
    same(["extra-$refA", "g-$refA"], array_column($u['grants'], 'grant_id'), 'own grants, newest first');
    same(['grant_id', 'credits', 'created_at', 'expires_at'], array_keys($u['grants'][0]));
    foreach ([$refB, '"ref"', 'upstream', 'scenario-'] as $needle) { // the own grant ids carry the own ref here (fundedKey)
        check(!str_contains($raw['body'], $needle), "the usage view does not contain $needle");
    }
    $b = json_decode(http('GET', '/v1/usage?days=1&ref=' . urlencode($refA), null, $keyB)['body'], true);
    same([20000 - 18, 1, 1, ["g-$refB"]], [$b['balance'], $b['days'], $b['models'][0]['requests'], array_column($b['grants'], 'grant_id')],
        'another key sees only its own rows, whatever it asks for');

    foreach (['0', '401', 'abc', '-1', '1.5', '', '1000'] as $bad) {
        same(400, http('GET', '/v1/usage?days=' . urlencode($bad), null, $keyA)['status'], "days=$bad refused");
    }
    same(400, http('GET', '/v1/usage?days[]=3', null, $keyA)['status'], 'days as an array refused');
    same([1, 400], [json_decode(http('GET', '/v1/usage?days=1', null, $keyA)['body'], true)['days'],
        json_decode(http('GET', '/v1/usage?days=400', null, $keyA)['body'], true)['days']]);
    same(401, http('GET', '/v1/usage')['status'], 'no key');
    same(401, http('GET', '/v1/usage', null, 'admin-secret')['status'], 'the admin token is not a key');
}

function testIntegrationUsageGrantsParameter(): void
{
    [$ref, $key] = fundedKey(10000);
    $ledger = Harness::get()['ledger'];
    for ($i = 1; $i <= 4; $i++) {
        $ledger->grant($ref, "more-$i-$ref", 1);
    }
    $ids = static fn (string $q): array => array_column(json_decode(http('GET', "/v1/usage$q", null, $key)['body'], true)['grants'], 'grant_id');
    same(["more-4-$ref", "more-3-$ref"], $ids('?grants=2'), 'the newest M, newest first');
    same(5, count($ids('')), 'default 200 lists them all');
    same(5, count($ids('?days=7&grants=1000')));
    foreach (['0', '1001', 'abc', '-1', '1.5', '', '10000', ' 1'] as $bad) {
        $r = http('GET', '/v1/usage?grants=' . urlencode($bad), null, $key);
        same(400, $r['status'], "grants=$bad refused");
        same(['invalid_value', 'grants'], [json_decode($r['body'], true)['error']['code'], json_decode($r['body'], true)['error']['param']]);
    }
    same(400, http('GET', '/v1/usage?grants[]=3', null, $key)['status'], 'grants as an array refused');
}

function testMigrateUpgradesASchemaNineLedgerAddingTheGrantsIndexAndTheReplaceTrigger(): void
{
    $dir = tempDir();
    $db = new \PDO("sqlite:$dir/v9.sqlite");
    $db->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v9.sql'));
    $db->exec("INSERT INTO keys (id, ref, key_hash, balance, active, created_at) VALUES (1, 'old', '" . hash('sha256', 'vsk-old') . "', 70, 1, 0)");
    $db->exec("INSERT INTO grants (grant_id, key_id, credits, created_at, expires_at) VALUES ('inv-1', 1, 70, 1000, NULL)");
    $db->exec("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (1000, 1, 'grant', 70, 70, 'inv-1')");
    // Schema 9 had no guard against REPLACE: the hole schema 10 closes.
    $db->exec("INSERT OR REPLACE INTO credit_log (id, ts, key_id, kind, delta, balance_after, ref) VALUES (1, 1000, 1, 'grant', 70, 70, 'inv-1')");
    unset($db);
    $l = new Ledger("$dir/v9.sqlite");
    $l->migrate();
    $l->migrate();
    $db = new \PDO("sqlite:$dir/v9.sqlite");
    same(11, (int) $db->query('PRAGMA user_version')->fetchColumn());
    same(['credit_log_no_delete', 'credit_log_no_replace', 'credit_log_no_update'],
        $db->query("SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN));
    $plan = implode(' ', $db->query("EXPLAIN QUERY PLAN SELECT grant_id FROM grants WHERE key_id = 1 ORDER BY rowid DESC LIMIT 5")->fetchAll(\PDO::FETCH_COLUMN, 3));
    check(str_contains($plan, 'grants_key'), "grants() uses the key index: $plan");
    same([['grant_id' => 'inv-1', 'credits' => 70, 'created_at' => 1000, 'expires_at' => null]], $l->grants(1, 10), 'the old grant stays');
    same(1, $l->findKey('vsk-old')['id']);
    same(80, $l->grant('old', 'inv-2', 10)['balance']);
    same([], $l->verify()['problems']);
}

function testCreditLogRefusesAnInsertThatReplacesARow(): void
{
    $path = tempDir() . '/ledger.sqlite';
    $l = new Ledger($path);
    $l->migrate();
    $l->createKey('a');
    $l->grant('a', 'g1', 500);
    $raw = new \PDO("sqlite:$path", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    foreach (["INSERT OR REPLACE INTO credit_log (id, ts, key_id, kind, delta, balance_after, ref) VALUES (1, 1, 1, 'grant', 501, 501, 'forged')",
        "REPLACE INTO credit_log (id, ts, key_id, kind, delta, balance_after, ref) VALUES (1, 1, 1, 'grant', 501, 501, 'forged')",
        "INSERT INTO credit_log (id, ts, key_id, kind, delta, balance_after, ref) VALUES (1, 1, 1, 'grant', 501, 501, 'forged') ON CONFLICT(id) DO UPDATE SET delta = 501"] as $sql) {
        try {
            $raw->exec($sql);
            check(false, "$sql was allowed");
        } catch (\PDOException $e) {
            check(str_contains($e->getMessage(), 'append-only'), "$sql refused: " . $e->getMessage());
        }
    }
    same([['grant', 500, 500, 'g1']], array_map(static fn ($r) => [$r['kind'], $r['delta'], $r['balance_after'], $r['ref']], $l->history('a')), 'the row is unchanged');
    $l->grant('a', 'g2', 5);
    same(2, count($l->history('a')), 'ordinary appends still work');
    same([], $l->verify()['problems']);
}

function testCliRemovesAKeyGrantsWithAnExpiryAndPrintsHelpWithoutAConfig(): void
{
    $dir = tempDir();
    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $run = static function (array $env, string ...$args): array {
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out, $err];
    };
    $env = ['VIRSILIPAS_CONFIG' => "$dir/config.php"];
    $run($env, 'init');
    $run($env, 'key-create', 'a');
    $exp = time() + 86400;
    [$code, $out] = $run($env, 'grant', 'a', '50', 'g1', (string) $exp);
    same([0, ['applied' => true, 'balance' => 50, 'expires_at' => $exp]], [$code, json_decode($out, true)]);
    [$code, , $err] = $run($env, 'grant', 'a', '50', 'g2', '1');
    same([1, true], [$code, str_contains($err, 'expires_at must be null or a future unix time')]);
    [$code, $out] = $run($env, 'key-remove', 'a');
    $r = json_decode($out, true);
    same([0, 'a', true, 50], [$code, $r['ref'], $r['applied'], $r['balance']]);
    same(false, json_decode($run($env, 'key-remove', 'a')[1], true)['applied'], 'removing again changes nothing');
    [$code, , $err] = $run($env, 'key-enable', 'a');
    same([1, true], [$code, str_contains($err, 'removed')]);

    foreach (['--help', '-h', 'help'] as $h) {
        [$code, $out, $err] = $run(['VIRSILIPAS_CONFIG' => "$dir/missing.php"], $h);
        same([0, ''], [$code, $err], "$h needs no config");
        check(str_starts_with($out, 'Usage: virsilipas') && str_contains($out, 'key-remove <ref>'), $out);
    }
    [$code, , $err] = $run(['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
    same([2, true], [$code, str_starts_with($err, 'Usage: virsilipas')], 'no command: usage on stderr, exit 2');
}

function testKeyRefFindsTheRefOfACurrentKeyOnlyAndTheCliPrintsIt(): void
{
    $dir = tempDir();
    $l = new Ledger("$dir/ledger.sqlite");
    $l->migrate();
    $a = $l->createKey('a');
    $b = $l->createKey('b');
    same('a', $l->refForKeyHash(hash('sha256', $a)));
    $l->setActive('b', false);
    same('b', $l->refForKeyHash(hash('sha256', $b)), 'a disabled key still names its ref');
    $a2 = $l->rotateKey('a');
    same(null, $l->refForKeyHash(hash('sha256', $a)), 'a rotated key\'s old digest names no ref');
    same('a', $l->refForKeyHash(hash('sha256', $a2)));
    $l->removeKey('a');
    same(null, $l->refForKeyHash(hash('sha256', $a2)), 'a removed key names no ref');
    same(null, $l->refForKeyHash(str_repeat('0', 64)));
    foreach (['', 'removed:1', strtoupper(hash('sha256', $b)), hash('sha256', $b) . "\n"] as $bad) {
        $threw = false;
        try {
            $l->refForKeyHash($bad);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        same(true, $threw, 'not a digest: ' . json_encode($bad));
    }

    file_put_contents("$dir/config.php", '<?php return ' . var_export(configArrayFor($dir), true) . ';');
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/virsilipas', 'key-ref', hash('sha256', $b)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['VIRSILIPAS_CONFIG' => "$dir/config.php"]);
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    same([0, ['ref' => 'b']], [proc_close($p), json_decode($out, true)]);
}
