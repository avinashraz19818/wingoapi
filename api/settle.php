<?php
/**
 * API Endpoint: Settle Pending Bets
 * Call via cron or webhook
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/BetService.php';

try {
    $gameCode = $_GET['game'] ?? null;
    if ($gameCode === '') {
        $gameCode = null;
    }
    $pdo = DB::getConnection();
    $betService = new BetService($pdo);

    $settlement = $betService->settlePendingBets($gameCode);

    jsonSuccess($settlement, "Settlement finished. Settled {$settlement['settled_count']} bets.");

} catch (Throwable $e) {
    jsonError("Settlement error: " . $e->getMessage(), 500, 500);
}
