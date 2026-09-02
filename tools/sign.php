<?php
/**
 * Compute the MD5 request signature for a set of parameters.
 *
 *   php tools/sign.php action=WinGoBet gameCode=WinGo_1M amount=10 timestamp=1756600000
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;

$params = [];
foreach (array_slice($argv, 1) as $pair) {
    if (str_contains($pair, '=')) {
        [$key, $value] = explode('=', $pair, 2);
        $params[$key]  = $value;
    }
}

if ($params === []) {
    fwrite(STDERR, "Usage: php tools/sign.php key=value [key=value ...]\n");
    exit(1);
}

$params['timestamp'] = $params['timestamp'] ?? (string) time();

fwrite(STDOUT, json_encode([
    'params'    => $params,
    'signature' => App::boot()->signature()->calculate($params),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
