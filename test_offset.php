<?php
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/api/ResultSyncService.php';

$pdo = DB::getConnection();
$service = new ResultSyncService($pdo);
$issue = $service->getCurrentIssue('WinGo_1M');
echo "Current Issue: " . $issue['issue_number'] . "\n";
echo "Next Issue: " . $issue['next_issue_number'] . "\n";
