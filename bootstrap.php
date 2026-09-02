<?php

declare(strict_types=1);

/**
 * PSR-4 style autoloader for the Lottery\ namespace plus shared bootstrap.
 * Included by every entrypoint (HTTP front controller, cron worker, CLI tools).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lottery\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/src/Support/Env.php';

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'data'        => null,
        'code'        => 1500,
        'msg'         => 'PHP 8.0 or newer is required',
        'msgCode'     => 'SERVER_ERROR',
        'serviceTime' => (int) round(microtime(true) * 1000),
    ]);
    exit;
}

mb_internal_encoding('UTF-8');
