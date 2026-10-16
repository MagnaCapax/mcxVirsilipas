<?php

declare(strict_types=1);

// docs/API.md must describe the API the code serves: every route, every error code, type and fixed
// message, and every response header found in the source has to appear in it. A failure here means the
// code changed without the docs (or the other way round).

/** Messages raised only from bin/virsilipas, never over HTTP. */
const CLI_ONLY_MESSAGES = ['limit must be from 1 to 100000']; // Ledger::history(), the `history` command

/** @return array{routes: list<string>, words: list<string>, messages: list<string>, headers: list<string>} */
function apiFacts(): array
{
    $root = dirname(__DIR__);
    preg_match_all("/'([A-Z]+ \\/[a-z0-9\\/]+)' =>/", (string) file_get_contents("$root/public/index.php"), $m);
    $routes = $m[1];
    $src = '';
    foreach (array_merge(glob("$root/src/*.php") ?: [], ["$root/public/index.php"]) as $f) {
        $src .= file_get_contents($f) . "\n";
    }
    $words = $messages = [];
    // Each HttpError construction with the line after it (one call wraps its code onto the next line).
    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        if (!str_contains($line, 'new HttpError(')) {
            continue;
        }
        $call = substr($line, (int) strpos($line, 'new HttpError(')) . ' ' . ($lines[$i + 1] ?? '');
        // Codes and types: the snake_case string arguments.
        preg_match_all("/(?<!\\[)'([a-z]+(?:_[a-z]+)+)'/", $call, $w); // not array keys such as $r['timed_out']
        $words = array_merge($words, $w[1]);
        // The message: the first string argument, up to its first interpolation or concatenation.
        if (preg_match('/new HttpError\(\d+, ([\'"])(.*?)\1/', $call, $mm)) {
            $fixed = rtrim(explode('$', $mm[2])[0]);
            if (strlen($fixed) >= 10 && !in_array($fixed, CLI_ONLY_MESSAGES, true)) {
                $messages[] = $fixed;
            }
        }
    }
    preg_match_all("/header\\('([A-Za-z-]+):/", $src, $h);
    return ['routes' => $routes, 'words' => array_values(array_unique($words)), 'messages' => array_values(array_unique($messages)),
        'headers' => array_values(array_unique($h[1]))];
}

function testApiDocsCoverEveryRouteErrorAndHeader(): void
{
    $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/API.md');
    $facts = apiFacts();
    // Floors, so an extractor that stops matching fails loudly instead of passing on nothing.
    check(count($facts['routes']) >= 8, 'routes found: ' . count($facts['routes']));
    check(count($facts['words']) >= 20, 'codes and types found: ' . count($facts['words']));
    check(count($facts['messages']) >= 30, 'messages found: ' . count($facts['messages']));
    check(count($facts['headers']) >= 7, 'headers found: ' . count($facts['headers']));
    $missing = [];
    foreach ($facts['routes'] as $route) {
        if (!str_contains($doc, "`$route`") && !str_contains($doc, "### $route")) {
            $missing[] = "route $route";
        }
    }
    foreach ($facts['words'] as $word) {
        if (!str_contains($doc, "`$word`")) {
            $missing[] = "code or type $word";
        }
    }
    foreach ($facts['messages'] as $message) {
        if (!str_contains($doc, $message)) {
            $missing[] = "message \"$message\"";
        }
    }
    foreach ($facts['headers'] as $header) {
        if (!str_contains($doc, "`$header`")) {
            $missing[] = "header $header";
        }
    }
    same([], $missing, 'docs/API.md is missing');
}
