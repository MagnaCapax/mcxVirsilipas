<?php

declare(strict_types=1);

// Live smoke test, run on the proxy host as the PHP user after a deploy:
//
//   runuser -u www-data -- env VIRSILIPAS_CONFIG=/etc/virsilipas/config.php \
//       php tools/smoke.php --url https://proxy.example.com --model default
//
// Creates a key smoke-<UTC time>-<random>, grants it --credits, sends one small non-streaming request
// through the public endpoint, and checks the ledger: the credit log holds the grant, the
// reservation and its refund, each with the right balance after it, the response's credits equal
// what the balance lost, and verify() finds every balance consistent. The key is disabled at the
// end whatever happens and is never printed. The request costs real upstream tokens (a few dozen).
// Exits 0 when every check passes, 1 otherwise.

use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Ledger;

require dirname(__DIR__) . '/src/bootstrap.php';

$opt = ['url' => '', 'model' => '', 'credits' => '1000000'];
for ($i = 1; $i < $argc; $i++) {
    $name = substr($argv[$i], 2);
    if (!str_starts_with($argv[$i], '--') || !array_key_exists($name, $opt) || !isset($argv[$i + 1])) {
        $opt['url'] = '';
        break;
    }
    $opt[$name] = $argv[++$i];
}
if ($opt['url'] === '' || $opt['model'] === '' || !ctype_digit($opt['credits']) || (int) $opt['credits'] < 1) {
    fwrite(STDERR, "Usage: php tools/smoke.php --url <base url> --model <alias> [--credits N]\n");
    exit(2);
}
$credits = (int) $opt['credits'];
$config = Config::load(virsilipas_config_path());
$ledger = new Ledger($config->dbPath);
$ref = 'smoke-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(2));
$failed = false;
$check = static function (bool $ok, string $what) use (&$failed): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failed = $failed || !$ok;
};

$key = $ledger->createKey($ref);
try {
    $ledger->grant($ref, "$ref-grant", $credits);
    $c = curl_init(rtrim($opt['url'], '/') . '/v1/chat/completions');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $opt['model'], 'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ok']]])]);
    $body = curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $answer = is_string($body) ? json_decode($body, true) : null;
    $check($code === 200 && is_array($answer), "$ref: request answered $code");
    $charged = (int) ($answer['usage']['credits'] ?? -1);

    $rows = array_reverse($ledger->history($ref)); // oldest first
    $kinds = array_column($rows, 'kind');
    $check(in_array($kinds, [['grant', 'reserve', 'refund'], ['grant', 'reserve']], true), 'credit log: ' . implode(', ', $kinds));
    $running = 0;
    $chain = true;
    foreach ($rows as $r) {
        $running += $r['delta'];
        $chain = $chain && $r['balance_after'] === $running;
    }
    $check($chain && ($rows[0]['delta'] ?? 0) === $credits, 'each row\'s balance_after is the running sum, starting with the grant');
    $balance = $ledger->status($ref, $config->limitDefaults())['balance'];
    $check($running === $balance, "logged changes sum to the balance ($balance)");
    $check($charged >= 0 && $credits - $balance === $charged, "the response's credits ($charged) equal what the balance lost (" . ($credits - $balance) . ')');
    $v = $ledger->verify();
    $check($v['problems'] === [], "verify: {$v['keys']} keys, {$v['rows']} rows, " . count($v['problems']) . ' problems');
    foreach ($v['problems'] as $p) {
        echo "     $p\n";
    }
} catch (Throwable $e) {
    $check(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $ledger->setActive($ref, false);
    echo "key $ref disabled\n";
}
exit($failed ? 1 : 0);
