<?php
/**
 * API Endpoint: Get Real-time Issue & Countdown Info
 * URL: /api/get_issue.php?game=WinGo_1M
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';

try {
    $gameCode = $_GET['game'] ?? 'WinGo_1M';
    $validGames = ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M'];

    if (!in_array($gameCode, $validGames, true)) {
        jsonError("Invalid game code. Allowed: " . implode(', ', $validGames), 400, 400);
    }

    $pdo = DB::getConnection();
    $syncService = new ResultSyncService($pdo);
    $issueData = $syncService->getCurrentIssue($gameCode);

    jsonSuccess($issueData, 'Current issue retrieved successfully');

} catch (Throwable $e) {
    jsonError("Failed to retrieve issue: " . $e->getMessage(), 500, 500);
}
