<?php
/**
 * API Endpoint: Get Real-time Issue & Countdown Info
 * URL: /api/get_issue.php?game=WinGo_1M
 * Supports 100% backward compatibility for all clone scripts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';

try {
    $gameCode = $_GET['game'] ?? $_GET['game_code'] ?? 'WinGo_1M';
    
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/WinGo/([^/]+)/#i', $uri, $matches)) {
        $gameCode = $matches[1];
    }

    $validGames = ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M'];
    if (!in_array($gameCode, $validGames, true)) {
        $gameCode = 'WinGo_1M';
    }

    $pdo = DB::getConnection();
    $syncService = new ResultSyncService($pdo);
    // getCurrentIssue() also triggers the zero-delay pull of the period that just closed,
    // so the client's very first poll after 00 already sees the new result in /api/history.
    $issueData = $syncService->getCurrentIssue($gameCode);

    // Provide BOTH formats (camelCase and snake_case)
    $response = [
        'issueNumber'       => (string)$issueData['issue_number'],
        'issue_number'      => (string)$issueData['issue_number'],
        'lastIssueNumber'   => (string)($issueData['last_issue_number'] ?? ''),
        'last_issue_number' => (string)($issueData['last_issue_number'] ?? ''),
        'resultPending'     => (bool)($issueData['result_pending'] ?? false),
        'result_pending'    => (bool)($issueData['result_pending'] ?? false),
        'game_code'         => $issueData['game_code'],
        'game_name'         => $issueData['game_name'],
        'interval'          => $issueData['interval'],
        'lock_seconds'      => $issueData['lock_seconds'],
        'startTime'         => $issueData['start_time'],
        'start_time'        => $issueData['start_time'],
        'endTime'           => $issueData['end_time'],
        'end_time'          => $issueData['end_time'],
        'nextIssueNumber'   => (string)$issueData['next_issue_number'],
        'next_issue_number' => (string)$issueData['next_issue_number'],
        'nextStartTime'     => $issueData['next_start_time'],
        'next_start_time'   => $issueData['next_start_time'],
        'nextEndTime'       => $issueData['next_end_time'],
        'next_end_time'     => $issueData['next_end_time'],
        'seconds_left'      => $issueData['seconds_left'],
        'secondsLeft'       => $issueData['seconds_left'],
        'is_locked'         => $issueData['is_locked'],
        'isLocked'          => $issueData['is_locked'],
        'serverTime'        => $issueData['server_time'],
        'server_time'       => $issueData['server_time'],
        'serverTimestamp'   => $issueData['server_timestamp']
    ];

    echo json_encode([
        'code' => 0,
        'msg' => 'success',
        'data' => $response,
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

} catch (Throwable $e) {
    jsonError("Failed to retrieve issue: " . $e->getMessage(), 500, 500);
}
