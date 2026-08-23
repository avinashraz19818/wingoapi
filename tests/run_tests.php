<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../api/ResultSyncService.php';
require_once __DIR__ . '/../api/BetService.php';

echo "=================================================\n";
echo "Starting WinGo API & Settlement Validation Suite\n";
echo "=================================================\n";

$pdo = DB::getConnection();
$syncService = new ResultSyncService($pdo);
$betService = new BetService($pdo);

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $msg = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}: {$msg}\n";
        $failed++;
    }
}

// 1. Test Odds Multiplier Engine
echo "\n--- 1. Testing WinGo Odds Logic ---\n";
// Pure green on 3
$r1 = $betService->evaluateBet('color', 'green', 3, 'green', 100, 2.0);
assertTest("Green on 3 (Pure Green win)", $r1['is_won'] === true && $r1['multiplier'] === 2.0);

// Green on 5 (Half win violet)
$r2 = $betService->evaluateBet('color', 'green', 5, 'green,violet', 100, 2.0);
assertTest("Green on 5 (Half-win violet: 1.5x)", $r2['is_won'] === true && $r2['multiplier'] === 1.5);

// Red on 0 (Half win violet)
$r3 = $betService->evaluateBet('color', 'red', 0, 'red,violet', 100, 2.0);
assertTest("Red on 0 (Half-win violet: 1.5x)", $r3['is_won'] === true && $r3['multiplier'] === 1.5);

// Violet on 5
$r4 = $betService->evaluateBet('color', 'violet', 5, 'green,violet', 100, 4.5);
assertTest("Violet on 5 (4.5x)", $r4['is_won'] === true && $r4['multiplier'] === 4.5);

// Number 7 on 7
$r5 = $betService->evaluateBet('number', '7', 7, 'green', 100, 9.0);
assertTest("Number 7 on 7 (9.0x)", $r5['is_won'] === true && $r5['multiplier'] === 9.0);

// Big on 8
$r6 = $betService->evaluateBet('big_small', 'big', 8, 'red', 100, 2.0);
assertTest("Big on 8 (2.0x)", $r6['is_won'] === true && $r6['multiplier'] === 2.0);

// Small on 2
$r7 = $betService->evaluateBet('big_small', 'small', 2, 'red', 100, 2.0);
assertTest("Small on 2 (2.0x)", $r7['is_won'] === true && $r7['multiplier'] === 2.0);

// Odd on 9
$r8 = $betService->evaluateBet('odd_even', 'odd', 9, 'green', 100, 2.0);
assertTest("Odd on 9 (2.0x)", $r8['is_won'] === true && $r8['multiplier'] === 2.0);

// Even on 4
$r9 = $betService->evaluateBet('odd_even', 'even', 4, 'red', 100, 2.0);
assertTest("Even on 4 (2.0x)", $r9['is_won'] === true && $r9['multiplier'] === 2.0);

// Losing bet: Red on 7
$r10 = $betService->evaluateBet('color', 'red', 7, 'green', 100, 2.0);
assertTest("Red on 7 (Loss)", $r10['is_won'] === false && $r10['payout'] === 0.0);

// 2. Test Issue Calculation Engine
echo "\n--- 2. Testing Issue Period Calculation ---\n";
$issue = $syncService->getCurrentIssue('WinGo_1M');
assertTest("Issue calculation returns valid issue number", !empty($issue['issue_number']));
assertTest("Issue calculation returns remaining seconds", isset($issue['seconds_left']) && $issue['seconds_left'] >= 0);
assertTest("Issue calculation returns lock state", isset($issue['is_locked']));

// 3. Test Wallet & Bet Placement
echo "\n--- 3. Testing Wallet & Bet Placement ---\n";
$testUser = 9999;
$betService->deposit($testUser, 5000.0);
$w1 = $betService->getWallet($testUser);
assertTest("Wallet initial balance >= 5000", $w1['balance'] >= 5000.0);

try {
    $receipt = $betService->placeBet($testUser, 'WinGo_1M', 'color', 'green', 500.0);
    assertTest("Bet placement succeeded", isset($receipt['bet_id']) && $receipt['bet_id'] > 0);
    $w2 = $betService->getWallet($testUser);
    assertTest("Wallet debited correctly", $w2['balance'] === ($w1['balance'] - 500.0));
} catch (Exception $e) {
    if (str_contains($e->getMessage(), 'locked')) {
        echo "  [INFO] Period is currently in lock window (skipped live bet lock test)\n";
    } else {
        assertTest("Bet placement exception", false, $e->getMessage());
    }
}

// 4. Test Historical Sync & Settlement
echo "\n--- 4. Testing Historical Sync & Settle ---\n";
$syncRes = $syncService->syncGame('WinGo_1M');
assertTest("Sync returns valid records", isset($syncRes['saved']));

$history = $syncService->getHistory('WinGo_1M', 10);
assertTest("Get history returns items", count($history) > 0);

echo "\n=================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================\n";

if ($failed > 0) exit(1);
