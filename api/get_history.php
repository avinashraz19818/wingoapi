<?php
/**
 * API Endpoint: Get Historical Draw Results
 * URL: /api/get_history.php?game=WinGo_1M&limit=50
 * Compatible with ar-lottery01, in999, 91club, and Daman schemas.
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
    
    $rawHistory = $syncService->getHistory($gameCode, $limit);

    $formattedList = [];
    foreach ($rawHistory as $row) {
        $num = (int)$row['number'];
        $formattedList[] = [
            'issueNumber'  => (string)$row['issue_number'],
            'issue_number' => (string)$row['issue_number'],
            'number'       => (string)$num,
            'drawNumber'   => (string)$num,
            'color'        => (string)$row['color'],
            'colour'       => (string)$row['color'],
            'premium'      => (string)($row['premium'] ?? $num),
            'sum'          => (int)($row['sum'] ?? $num),
            'drawTime'     => (string)$row['draw_time'],
            'draw_time'    => (string)$row['draw_time'],
            'openTime'     => (string)$row['draw_time']
        ];
    }

    // Return exact data structure as ar-lottery01 / in999
    echo json_encode([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $formattedList
        ],
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    jsonError("Failed to fetch history: " . $e->getMessage(), 500, 500);
}
