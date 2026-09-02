<?php
/**
 * API Endpoint: Place User Bet
 * Method: POST (JSON or form-urlencoded)
 * Fields: user_id, game_code, bet_type, bet_value, amount
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/BetService.php';

try {
    $payload = getRequestPayload();

    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 1001; // Default demo user if not passed
    $gameCode = $payload['game_code'] ?? 'WinGo_1M';
    $betType = $payload['bet_type'] ?? '';
    $betValue = $payload['bet_value'] ?? '';
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;

    if (empty($betType) || $betValue === '' || $amount <= 0) {
        jsonError("Missing required parameters: game_code, bet_type, bet_value, amount (> 0)", 400, 400);
    }

    $pdo = DB::getConnection();
    $betService = new BetService($pdo);

    $betReceipt = $betService->placeBet($userId, $gameCode, $betType, (string)$betValue, $amount);

    jsonSuccess($betReceipt, 'Bet placed successfully');

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422, 422);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 400, 400);
} catch (Throwable $e) {
    jsonError("Bet placement failed: " . $e->getMessage(), 500, 500);
}
