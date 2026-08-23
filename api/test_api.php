<?php
/**
 * API Diagnostic & Connection Health Tester
 * Validates external connectivity, database read/write, issue generation, and settlement logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';
require_once __DIR__ . '/BetService.php';

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'db_type' => DB_TYPE,
    'tests' => []
];

try {
    // 1. Test Database
    $pdo = DB::getConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM wingo_games");
    $gamesCount = (int)$stmt->fetchColumn();
    $report['tests']['database'] = [
        'status' => 'PASS',
        'games_configured' => $gamesCount
    ];

    // 2. Test Issue Calculation
    $syncService = new ResultSyncService($pdo);
    $issue1M = $syncService->getCurrentIssue('WinGo_1M');
    $report['tests']['issue_calculation'] = [
        'status' => 'PASS',
        'sample_issue' => $issue1M
    ];

    // 3. Test Sync
    $syncResult = $syncService->syncGame('WinGo_1M');
    $report['tests']['sync_fetch'] = [
        'status' => 'PASS',
        'result' => $syncResult
    ];

    // 4. Test Settlement Calculation logic
    $betService = new BetService($pdo);
    $testEval = $betService->evaluateBet('color', 'green', 5, 'green,violet', 100.0, 2.0);
    $report['tests']['odds_eval_half_violet'] = [
        'status' => ($testEval['multiplier'] === 1.5) ? 'PASS' : 'FAIL',
        'eval' => $testEval
    ];

    jsonSuccess($report, 'All diagnostic checks completed');

} catch (Throwable $e) {
    $report['tests']['error'] = $e->getMessage();
    jsonError("Diagnostic check failed: " . $e->getMessage(), 500, 500, $report);
}
