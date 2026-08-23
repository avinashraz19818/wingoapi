<?php
/**
 * API Endpoint: User Wallet Balance & Demo Topup
 * Methods: GET (balance), POST (recharge/deposit)
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/BetService.php';

try {
    $pdo = DB::getConnection();
    $betService = new BetService($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = getRequestPayload();
        $userId = (int)($payload['user_id'] ?? 1001);
        $amount = (float)($payload['amount'] ?? 1000.0);

        $newBalance = $betService->deposit($userId, $amount);
        jsonSuccess([
            'user_id' => $userId,
            'balance' => $newBalance
        ], 'Balance updated successfully');
    } else {
        $userId = (int)($_GET['user_id'] ?? 1001);
        $wallet = $betService->getWallet($userId);
        jsonSuccess($wallet, 'Wallet fetched successfully');
    }

} catch (Throwable $e) {
    jsonError("Wallet operation failed: " . $e->getMessage(), 500, 500);
}
