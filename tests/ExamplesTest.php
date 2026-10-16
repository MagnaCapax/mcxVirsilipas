<?php

declare(strict_types=1);

// The example clients, run as a user would run them, against the test proxy.

/** Runs an example script with the key in the environment; returns [exit code, stdout, stderr]. */
function runExample(string $script, array $args, string $key, array $env = []): array
{
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__) . "/examples/$script"], $args);
    $env += ['VIRSILIPAS_URL' => Harness::get()['proxy'], 'VIRSILIPAS_KEY' => $key, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

function testExampleHelloWorldPrintsAnswerAndCredits(): void
{
    [$ref, $key] = fundedKey(1_000_000);
    [$rc, $out] = runExample('hello-world.php', ['hi'], $key, ['VIRSILIPAS_MODEL' => 'ok']);
    same(0, $rc, 'exit code');
    check((bool) preg_match('/\ncredits: \d+\n$/', $out), "answer then credits: $out");
    check(str_ends_with($out, 'credits: ' . (1_000_000 - settled($ref)['balance']) . "\n"), 'credits printed are the credits charged');
}

function testExampleHelloWorldReportsRefusals(): void
{
    [$rc, $out, $err] = runExample('hello-world.php', ['hi'], 'not-a-key', ['VIRSILIPAS_MODEL' => 'ok']);
    same(1, $rc, 'exit code');
    same('', $out, 'nothing on stdout');
    check(str_starts_with($err, 'error: '), "error on stderr: $err");
}

function testExampleClientModelsBalanceAndChat(): void
{
    [$ref, $key] = fundedKey(1_000_000);
    [$rc, $out] = runExample('client.php', ['models'], $key);
    same(0, $rc, 'models exit code');
    check((bool) preg_match('/^ok\s+.*input=1000000 cached=1000000 output=1000000$/m', $out), "models lists rates: $out");

    [$rc, $out] = runExample('client.php', ['balance'], $key);
    same(0, $rc, 'balance exit code');
    check(str_contains($out, 'balance=1000000 ') && str_contains($out, ' weight=100 bonus_pct=0 '), "balance shows weight: $out");

    foreach ([[], ['--no-stream']] as $extra) {
        [$rc, $out] = runExample('client.php', array_merge(['chat', 'hi', '--model', 'ok'], $extra), $key);
        same(0, $rc, 'chat exit code');
        check((bool) preg_match('/\nusage: prompt=\d+ completion=\d+ cached=\d+ reasoning=\d+ credits=\d+\n$/', $out), "chat ends with usage: $out");
    }
}

function testExampleClientKeepsTheKeyOffTheCommandLine(): void
{
    [$rc, , $err] = runExample('client.php', ['balance', '--key', 'secret'], '');
    same(2, $rc, 'a key option is not accepted');
    check(str_contains($err, 'unknown option: --key'), $err);
}
