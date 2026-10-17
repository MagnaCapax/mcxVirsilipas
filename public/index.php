<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Admin;
use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\Gemini;
use MagnaCapax\Virsilipas\HttpError;
use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\Log;
use MagnaCapax\Virsilipas\Proxy;

require dirname(__DIR__) . '/src/bootstrap.php';

// Keep running after a client hangs up so every request is settled.
ignore_user_abort(true);
// No PHP version in the response, whatever expose_php says.
header_remove('X-Powered-By');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// The Anthropic Messages routes, and the Gemini routes under /v1beta/, answer errors in their own format.
$format = match (true) {
    $path === '/v1/messages' || str_starts_with($path, '/v1/messages/') => 'messages',
    str_starts_with($path, '/v1beta/') => 'gemini',
    default => 'chat',
};
// POST /v1beta/models/{model}:generateContent, :streamGenerateContent and :countTokens (Gemini::METHODS).
$gemini = $method === 'POST' ? Gemini::target($path) : null;

try {
    $config = Config::load(virsilipas_config_path());
    Log::init($config->logDir, $config->logLevel);
    $ledger = new Ledger($config->dbPath);
    $proxy = new Proxy($config, $ledger);
    $admin = new Admin($config, $ledger);

    match ($gemini === null ? "$method $path" : 'gemini') {
        'gemini' => $proxy->gemini(...$gemini),
        'POST /v1/chat/completions' => $proxy->chat(),
        'POST /v1/messages' => $proxy->messages(),
        'POST /v1/messages/count_tokens' => $proxy->countTokens(),
        'GET /v1/models' => $proxy->models(),
        'GET /v1/balance' => $proxy->balance(),
        'GET /v1/usage' => $proxy->usage(),
        'POST /admin/keys' => $admin->createKey(),
        'POST /admin/keys/active' => $admin->setActive(),
        'POST /admin/keys/rotate' => $admin->rotateKey(),
        'POST /admin/keys/remove' => $admin->removeKey(),
        'POST /admin/keys/limits' => $admin->setLimits(),
        'POST /admin/grants' => $admin->grant(),
        default => throw new HttpError(404, 'not found'),
    };
} catch (HttpError $e) {
    // The reason is one of the proxy's fixed messages; the request path is client text and is not logged.
    Log::write('info', 'refused', ['status' => $e->status, 'reason' => $e->getMessage()]);
    if (!headers_sent()) {
        $e->sendAs($format);
    }
} catch (\Throwable $e) {
    // Fail closed. Log the class and the code location only: an exception message can carry
    // data. `bin/virsilipas` prints configuration errors in full.
    Log::write('error', 'exception', ['class' => get_class($e), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    if (!headers_sent()) {
        (new HttpError(500, 'internal error', 'api_error'))->sendAs($format);
    }
}
