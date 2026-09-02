<?php
/**
 * EXAMPLE — "return money to main wallet".
 *
 * Call this when the user leaves the lottery, presses "withdraw from game", or
 * from a cron that sweeps idle game wallets back.
 */

declare(strict_types=1);

require __DIR__ . '/LotteryClient.php';

header('Content-Type: application/json');

$LOTTERY_URL = 'https://api.devlopedwithzayro.site';
$LOTTERY_KEY = 'PUT_YOUR_DOMAIN_API_KEY_HERE';

session_start();
$user = $_SESSION['user'] ?? null;
if ($user === null) {
    http_response_code(401);
    echo json_encode(['code' => 1002, 'msg' => 'Please log in first']);
    exit;
}

try {
    $lottery = new LotteryClient($LOTTERY_URL, $LOTTERY_KEY);

    $wallet = $lottery->balance($user['id']);
    $amount = (float) $wallet['balance'];

    if ($amount <= 0) {
        echo json_encode(['code' => 0, 'msg' => 'nothing to transfer', 'data' => ['amount' => '0.00']]);
        exit;
    }

    $orderId = 'OUT-' . $user['id'] . '-' . time();

    // 1. pull it out of the game wallet
    $lottery->transferOut($user['id'], $amount, $orderId);

    // 2. then credit your own wallet (your own DB code here)
    //    creditMainWallet($user['id'], $amount, $orderId);

    echo json_encode(['code' => 0, 'msg' => 'success', 'data' => ['amount' => $amount]]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['code' => 1500, 'msg' => $e->getMessage()]);
}
