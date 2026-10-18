<?php

declare(strict_types=1);

// Nothing secret or deployment-specific may be committed: not config.php, keys, databases or logs,
// not a real admin token hash, not a proxy key. The checks are exact rules over the files git tracks.
// They fail closed: if git cannot list the files, the guard fails instead of passing on an empty list.
//
// Words a deployment must never publish (its upstream provider, hostnames) are not kept in the repo,
// not even hashed: a hash of a name from a short list is as good as the name. List them, one per line,
// in .forbidden-words (gitignored) or comma-separated in VIRSILIPAS_FORBIDDEN_WORDS (CI: a repository
// secret). They are matched however they are spaced, cased or punctuated.

/** The only 64-hex string allowed in the tree: the example config's placeholder admin token hash. */
const PLACEHOLDER_HEX64 = '0000000000000000000000000000000000000000000000000000000000000000';

/** @return list<string> forbidden words, normalized to lowercase letters and digits */
function forbiddenWords(): array
{
    $file = dirname(__DIR__) . '/.forbidden-words';
    $raw = (is_file($file) ? (string) file_get_contents($file) : '') . "\n" . str_replace(',', "\n", (string) getenv('VIRSILIPAS_FORBIDDEN_WORDS'));
    $words = [];
    foreach (explode("\n", $raw) as $w) {
        $w = (string) preg_replace('~[^a-z0-9]~', '', strtolower($w));
        if (strlen($w) >= 3) {
            $words[] = $w;
        }
    }
    return array_values(array_unique($words));
}

/** @return list<string> the tracked files, relative to the repository root */
function trackedFiles(): array
{
    $root = dirname(__DIR__);
    $out = [];
    $code = 1;
    exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $out, $code);
    $files = $code === 0 ? array_values(array_filter(explode("\0", implode("\n", $out)), fn ($f) => $f !== '')) : [];
    if ($code !== 0 || count($files) < 20 || !in_array('src/Ledger.php', $files, true)) {
        throw new \RuntimeException('secret guard: git ls-files failed or returned too few files; run the tests from a git checkout');
    }
    return $files;
}

function testNoSecretOrLocalFileIsTracked(): void
{
    $forbidden = '~(^|/)(config\.php|upstream\.key|credentials[^/]*|\.env[^/]*|id_(rsa|ed25519|ecdsa)[^/]*)$|\.(key|pem|p12|pfx|sqlite[^/]*|log)$~';
    $bad = array_values(array_filter(trackedFiles(), fn ($f) => preg_match($forbidden, $f) === 1));
    same([], $bad, 'local or credential files must stay untracked (.gitignore)');
}

function testNoSecretValueOrProviderNameIsCommitted(): void
{
    $root = dirname(__DIR__);
    $privateKey = '-----BEGIN ' . '([A-Z]+ )?PRIVATE KEY-----'; // split so this file does not match itself
    $problems = [];
    $words = forbiddenWords();
    if ($words === []) {
        // CI sets VIRSILIPAS_REQUIRE_FORBIDDEN_WORDS=1, so a lost secret fails the build instead of skipping the check.
        check(getenv('VIRSILIPAS_REQUIRE_FORBIDDEN_WORDS') !== '1', 'forbidden words are required here but none are configured');
        echo "     (no forbidden words configured: set .forbidden-words or VIRSILIPAS_FORBIDDEN_WORDS)\n";
    }
    foreach (trackedFiles() as $f) {
        $text = (string) file_get_contents("$root/$f");
        if (preg_match("~$privateKey~", $text)) {
            $problems[] = "$f: private key";
        }
        if (preg_match('~vsk-[A-Za-z0-9_-]{16,}~', $text)) { // a plain key or a named one (vsk-<ref>-<48 hex>)
            $problems[] = "$f: proxy key";
        }
        preg_match_all('~(?<![0-9a-fA-F])[0-9a-fA-F]{64}(?![0-9a-fA-F])~', $text, $m);
        foreach (array_unique($m[0]) as $hex) {
            if (strtolower($hex) !== PLACEHOLDER_HEX64) {
                $problems[] = "$f: 64-hex value (a real token hash?)";
            }
        }
        // The path counts too.
        $flat = (string) preg_replace('~[^a-z0-9]~', '', strtolower("$f\n$text"));
        foreach ($words as $w) {
            if (str_contains($flat, $w)) {
                $problems[] = "$f: a forbidden word (.forbidden-words / VIRSILIPAS_FORBIDDEN_WORDS)";
            }
        }
    }
    same([], $problems);
    same(PLACEHOLDER_HEX64, (string) (require "$root/config.example.php")['admin_token_sha256'], 'the example admin hash is the placeholder');
}
