<?php
/**
 * Issue a JWT for testing / integration.
 *
 *   php tools/token.php 1001 9876543210 [ttlSeconds]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;

$userId = (int) ($argv[1] ?? 0);
$mobile = (string) ($argv[2] ?? '');
$ttl    = isset($argv[3]) ? (int) $argv[3] : null;

if ($userId <= 0) {
    fwrite(STDERR, "Usage: php tools/token.php <userId> [mobile] [ttlSeconds]\n");
    exit(1);
}

fwrite(STDOUT, App::boot()->jwt()->issue($userId, $mobile !== '' ? $mobile : 'u' . $userId, $ttl) . "\n");
