<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Admin;
use MagnaCapax\Virsilipas\Config;
use MagnaCapax\Virsilipas\HttpError;
use MagnaCapax\Virsilipas\Ledger;
use MagnaCapax\Virsilipas\Proxy;

require dirname(__DIR__) . '/src/bootstrap.php';

// Keep running after a client hangs up so every request is settled.
ignore_user_abort(true);

try {
    $config = Config::load(virsilipas_config_path());
    $ledger = new Ledger($config->dbPath);
    $proxy = new Proxy($config, $ledger);
    $admin = new Admin($config, $ledger);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    match ("$method $path") {
        'POST /v1/chat/completions' => $proxy->chat(),
        'GET /v1/models' => $proxy->models(),
        'GET /v1/balance' => $proxy->balance(),
        'POST /admin/keys' => $admin->createKey(),
        'POST /admin/keys/active' => $admin->setActive(),
        'POST /admin/grants' => $admin->grant(),
        default => throw new HttpError(404, 'not found'),
    };
} catch (HttpError $e) {
    if (!headers_sent()) {
        $e->send();
    }
} catch (\Throwable $e) {
    // Fail closed. Log the class and message only: never request or response bodies.
    error_log('virsilipas: ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        (new HttpError(500, 'internal error', 'api_error'))->send();
    }
}
