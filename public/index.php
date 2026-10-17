<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Admin;
use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\HttpError;
use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\Log;
use MagnaCapax\Virsilipas\Proxy;

require dirname(__DIR__) . '/src/bootstrap.php';

// Keep running after a client hangs up so every request is settled.
ignore_user_abort(true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    $config = Config::load(virsilipas_config_path());
    Log::init($config->logDir, $config->logLevel);
    $ledger = new Ledger($config->dbPath);
    $proxy = new Proxy($config, $ledger);
    $admin = new Admin($config, $ledger);

    match ("$method $path") {
        'POST /v1/chat/completions' => $proxy->chat(),
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
        $e->send();
    }
} catch (\Throwable $e) {
    // Fail closed. Log the class and the code location only: an exception message can carry
    // data. `bin/virsilipas` prints configuration errors in full.
    Log::write('error', 'exception', ['class' => get_class($e), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    if (!headers_sent()) {
        (new HttpError(500, 'internal error', 'api_error'))->send();
    }
}
