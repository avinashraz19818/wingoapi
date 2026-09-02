<?php
/**
 * Ops helper: inspect or top up a wallet.
 *
 *   php tools/wallet.php show 1001
 *   php tools/wallet.php credit 1001 5000 "manual topup"
 *   php tools/wallet.php debit 1001 250 "correction"
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Wallet\WalletService;

$app = App::boot();
$app->bootstrapDatabase();

$command = (string) ($argv[1] ?? 'show');
$userId  = (int) ($argv[2] ?? 0);

if ($userId <= 0) {
    fwrite(STDERR, "Usage: php tools/wallet.php <show|credit|debit> <userId> [amount] [remark]\n");
    exit(1);
}

$wallet = $app->wallet();

switch ($command) {
    case 'show':
        fwrite(STDOUT, json_encode($wallet->snapshot($userId), JSON_PRETTY_PRINT) . "\n");
        break;

    case 'credit':
    case 'debit':
        $amount = (float) ($argv[3] ?? 0);
        $remark = (string) ($argv[4] ?? 'cli adjustment');
        $key    = WalletService::entryKey('cli', $command, (string) $userId, (string) $amount, (string) microtime(true));

        $result = $command === 'credit'
            ? $wallet->credit($userId, $amount, $key, 'adjustment', null, $remark)
            : $wallet->debit($userId, $amount, $key, 'adjustment', null, $remark);

        fwrite(STDOUT, json_encode([
            'applied' => $result['applied'],
            'balance' => $result['balance'],
        ], JSON_PRETTY_PRINT) . "\n");
        break;

    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        exit(1);
}
