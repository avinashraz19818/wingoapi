<?php
/**
 * EXAMPLE — put something like this on YOUR site, e.g. /api/Lottery/Open.php
 *
 * The browser calls it when the user taps "WinGo"; it answers with a token the
 * front-end stores and uses for every lottery call.
 */

declare(strict_types=1);

require __DIR__ . '/LotteryClient.php';

header('Content-Type: application/json');

// ---------------------------------------------------------------- settings
$LOTTERY_URL = 'https://api.devlopedwithzayro.site';
$LOTTERY_KEY = 'PUT_YOUR_DOMAIN_API_KEY_HERE';   // admin panel -> Domains
$AUTO_SWEEP  = true;                             // move the main balance in automatically

// -------------------------------------------------- your own session/user
// Replace these two lines with however your site identifies the logged-in user.
session_start();
$user = $_SESSION['user'] ?? null;               // e.g. ['id' => 1001, 'name' => 'Ravi', 'balance' => 500.00]

if ($user === null) {
    http_response_code(401);
    echo json_encode(['code' => 1002, 'msg' => 'Please log in first']);
    exit;
}

try {
    $lottery = new LotteryClient($LOTTERY_URL, $LOTTERY_KEY);

    // 1. get a game token for this user
    $session = $lottery->login($user['id'], $user['name'] ?? '');

    // 2. optional: push their main balance into the game wallet on entry
    if ($AUTO_SWEEP && $user['balance'] > 0) {
        $orderId = 'IN-' . $user['id'] . '-' . time();

        // a) take it off your own wallet FIRST (your own DB code here)
        //    debitMainWallet($user['id'], $user['balance'], $orderId);

        // b) then push it into the game
        $transfer = $lottery->transferIn($user['id'], (float) $user['balance'], $orderId);
        $session['balance'] = $transfer['balance'];
    }

    echo json_encode([
        'code' => 0,
        'msg'  => 'success',
        'data' => [
            'token'   => $session['token'],     // front-end: localStorage.setItem('ar_token', token)
            'balance' => $session['balance'],
            'apiBase' => $LOTTERY_URL . '/api/Lottery',
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['code' => 1500, 'msg' => $e->getMessage()]);
}
