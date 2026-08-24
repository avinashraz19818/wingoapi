<?php
/**
 * WinGo Result Sync & Settlement Webhook Endpoint
 * Call this endpoint via cron-job.org or local crontab every 30s
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';
require_once __DIR__ . '/BetService.php';

try {
    $pdo = DB::getConnection();
    $syncService = new ResultSyncService($pdo);
    $betService = new BetService($pdo);

    // Settle as soon as fresh draws land, not on a later pass.
    $syncService->onNewResults(function (string $gameCode) use ($betService): void {
        $betService->settlePendingBets($gameCode);
    });

    $gameCode = $_GET['game'] ?? null;
    $allowFallback = isset($_GET['fallback']) && (string)$_GET['fallback'] === '1';
    $syncResults = [];

    if (!empty($gameCode)) {
        $syncResults[$gameCode] = $syncService->syncGame($gameCode, $allowFallback);
    } else {
        $syncResults = $syncService->syncAll($allowFallback);
    }

    // Auto-settle pending bets right after syncing fresh results
    $settlement = $betService->settlePendingBets($gameCode !== null && $gameCode !== '' ? $gameCode : null);

    jsonSuccess([
        'sync' => $syncResults,
        'settlement' => $settlement
    ], 'Sync & Settlement executed successfully');

} catch (Throwable $e) {
    jsonError("Sync error: " . $e->getMessage(), 500, 500);
}
