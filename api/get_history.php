<?php
/**
 * API Endpoint: Get Historical Draw Results
 * URL: /api/get_history.php?game=WinGo_1M&limit=50
 * Supports 100% backward compatibility with in999, 91club, and ar-lottery01 schemas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';

try {
    $gameCode = $_GET['game'] ?? $_GET['game_code'] ?? 'WinGo_1M';
    
    // Auto-detect gameCode from URI if accessed via /WinGo/{gameCode}/GetHistoryIssuePage.json
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/WinGo/([^/]+)/#i', $uri, $matches)) {
        $gameCode = $matches[1];
    }

    $limit = (int)($_GET['limit'] ?? $_GET['pageSize'] ?? 50);
    $pdo = DB::getConnection();
    $syncService = new ResultSyncService($pdo);

    // Zero-delay: make sure the period that just closed is in the DB before answering, and
    // hide only the period that is still open (never the newest closed draw).
    $issueData = $syncService->getCurrentIssue($gameCode);
    $rawHistory = $syncService->getHistory($gameCode, $limit, $issueData['issue_number'] ?? null);

    // Format fields in BOTH camelCase (issueNumber) and snake_case (issue_number)
    $formattedList = [];
    foreach ($rawHistory as $row) {
        $formattedList[] = [
            'issueNumber'  => (string)$row['issue_number'],
            'issue_number' => (string)$row['issue_number'],
            'number'       => (string)$row['number'],
            'drawNumber'   => (string)$row['number'],
            'color'        => (string)$row['color'],
            'premium'      => (string)($row['premium'] ?? $row['number']),
            'sum'          => (int)($row['sum'] ?? $row['number']),
            'drawTime'     => (string)$row['draw_time'],
            'draw_time'    => (string)$row['draw_time']
        ];
    }

    // Return standard ar-lottery / in999 wrapper
    echo json_encode([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'game_code' => $gameCode,
            'count' => count($formattedList),
            'list' => $formattedList
        ],
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

} catch (Throwable $e) {
    jsonError("Failed to fetch history: " . $e->getMessage(), 500, 500);
}
