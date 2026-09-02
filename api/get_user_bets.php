<?php
/**
 * API Endpoint: Get User Bets & Settlement Status
 * URL: /api/get_user_bets.php?user_id=1001&game=WinGo_1M&limit=20
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/BetService.php';

try {
    $userId = (int)($_GET['user_id'] ?? 1001);
    $gameCode = $_GET['game'] ?? null;
    $limit = (int)($_GET['limit'] ?? 50);

    $pdo = DB::getConnection();
    $betService = new BetService($pdo);

    $bets = $betService->getUserBets($userId, $gameCode, $limit);

    jsonSuccess([
        'user_id' => $userId,
        'count' => count($bets),
        'bets' => $bets
    ], 'User bets retrieved successfully');

} catch (Throwable $e) {
    jsonError("Failed to fetch user bets: " . $e->getMessage(), 500, 500);
}
