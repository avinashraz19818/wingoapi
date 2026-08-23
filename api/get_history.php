<?php
/**
 * API Endpoint: Get Historical Draw Results
 * URL: /api/get_history.php?game=WinGo_1M&limit=50
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';

try {
    $gameCode = $_GET['game'] ?? 'WinGo_1M';
    $limit = (int)($_GET['limit'] ?? 50);

    $pdo = DB::getConnection();
    $syncService = new ResultSyncService($pdo);
    
    $history = $syncService->getHistory($gameCode, $limit);

    // If no history exists yet, trigger an initial sync
    if (empty($history)) {
        $syncService->syncGame($gameCode);
        $history = $syncService->getHistory($gameCode, $limit);
    }

    jsonSuccess([
        'game_code' => $gameCode,
        'count' => count($history),
        'list' => $history
    ], 'Draw history retrieved successfully');

} catch (Throwable $e) {
    jsonError("Failed to fetch history: " . $e->getMessage(), 500, 500);
}
