<?php

declare(strict_types=1);

// The ledger holds every customer's balance and usage: files this process creates are private to it.
umask(0077);

// Zero-dependency autoloader for the MagnaCapax\Virsilipas namespace (Composer works too).
spl_autoload_register(static function (string $class): void {
    $prefix = 'MagnaCapax\\Virsilipas\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** Path of the deployment config: VIRSILIPAS_CONFIG, else config.php in the project root. */
function virsilipas_config_path(): string
{
    $env = getenv('VIRSILIPAS_CONFIG');
    return is_string($env) && $env !== '' ? $env : dirname(__DIR__) . '/config.php';
}
