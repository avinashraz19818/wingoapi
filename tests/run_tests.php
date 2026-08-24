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

// =========================================================================
// 5. ZERO-DELAY REGRESSION SUITE
//    Hermetic: runs against a throwaway SQLite DB so the live data file is untouched.
// =========================================================================
echo "\n--- 5. Zero-Delay Issue / History / Settlement ---\n";

$tmpDb = sys_get_temp_dir() . '/wingo_zerodelay_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$tpdo = new PDO('sqlite:' . $tmpDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
DB::initSQLiteSchema($tpdo);
$zsync = new ResultSyncService($tpdo);
$zbets = new BetService($tpdo);
$zapi = $zsync->getApi();

// 5a. Issue number format must match what the draw provider actually publishes.
//     Reference draws taken from stored provider data:
//       WinGo_1M  period 12:11:00-12:12:00 IST -> 2026082300732
//       WinGo_30S period 12:11:30-12:12:00 IST -> 2026082301464
$refIssue = $zapi->calculateIssueNumberForTime('WinGo_1M', strtotime('2026-08-23 12:11:00'));
assertTest("Issue number uses provider format YYYYMMDD+NNNNN", strlen($refIssue) === 13, "got '{$refIssue}' (len " . strlen($refIssue) . ")");
assertTest("WinGo_1M issue matches real provider draw", $refIssue === '2026082300732', "got {$refIssue}");
$refIssue30 = $zapi->calculateIssueNumberForTime('WinGo_30S', strtotime('2026-08-23 12:11:30'));
assertTest("WinGo_30S issue matches real provider draw", $refIssue30 === '2026082301464', "got {$refIssue30}");

// 5b. The open period comes from the clock, so the countdown never waits for a sync cycle.
$now = time();
$bucket = $now - ($now % 60);
$current = $zsync->getCurrentIssue('WinGo_1M', false);
assertTest("Open issue is the period containing now", $current['issue_number'] === $zapi->calculateIssueNumberForTime('WinGo_1M', $bucket, 60), $current['issue_number']);
assertTest("seconds_left inside the interval", $current['seconds_left'] >= 0 && $current['seconds_left'] <= 60, (string)$current['seconds_left']);
assertTest("next issue is the following period", $current['next_issue_number'] === $zapi->calculateIssueNumberForTime('WinGo_1M', $bucket + 60, 60), $current['next_issue_number']);
assertTest("last issue is the period that just closed", $current['last_issue_number'] === $zapi->calculateIssueNumberForTime('WinGo_1M', $bucket - 60, 60), $current['last_issue_number']);
assertTest("Issue rolls over to a new day at midnight", $zsync->issueForTime('WinGo_1M', strtotime('2026-08-23 23:59:00') + 60, 60, 0) === '2026082400001', $zsync->issueForTime('WinGo_1M', strtotime('2026-08-23 23:59:00') + 60, 60, 0));
assertTest(
    "ISSUE_OFFSET=-1 lags exactly one period",
    $zsync->deriveNextIssueNumber($zsync->issueForTime('WinGo_1M', $bucket, 60, -1)) === $current['issue_number'],
    $zsync->issueForTime('WinGo_1M', $bucket, 60, -1) . ' -> ' . $current['issue_number']
);

// 5c. Writing results must work on this driver (INSERT IGNORE is MySQL-only).
$closedIssue = $current['last_issue_number'];
$openIssue = $current['issue_number'];
$draw = [['issueNumber' => $closedIssue, 'number' => 7, 'color' => 'green', 'drawTime' => date('Y-m-d H:i:s', $bucket)]];
$first = $zsync->persistResults('WinGo_1M', $draw);
assertTest("Result insert works on driver " . $tpdo->getAttribute(PDO::ATTR_DRIVER_NAME), $first['saved'] === 1, json_encode($first));
$second = $zsync->persistResults('WinGo_1M', $draw);
assertTest("Duplicate result ignored instead of erroring", $second['saved'] === 0 && $second['skipped_duplicates'] === 1, json_encode($second));
assertTest("Invalid record without issue number skipped", $zsync->persistResults('WinGo_1M', [['number' => 3]])['invalid'] === 1);

// 5d. The period that just closed must be visible in history IMMEDIATELY (this is the 5s bug),
//     while the still-open period must never leak.
$histNow = array_column($zsync->getHistory('WinGo_1M', 10, $openIssue), 'issue_number');
assertTest("Just-closed period appears in history at once", in_array($closedIssue, $histNow, true), implode(',', $histNow));
$zsync->persistResults('WinGo_1M', [['issueNumber' => $openIssue, 'number' => 3, 'color' => 'green', 'drawTime' => date('Y-m-d H:i:s', $bucket + 60)]]);
$histLeak = array_column($zsync->getHistory('WinGo_1M', 10, $openIssue), 'issue_number');
assertTest("Open period result is NOT leaked before countdown ends", !in_array($openIssue, $histLeak, true), implode(',', $histLeak));

// 5e. Settlement: closed period settles at once, open period never settles early.
$zbets->deposit(9998, 1000.0);
$balanceBefore = $zbets->getWallet(9998)['balance'];
$insBet = $tpdo->prepare("INSERT INTO wingo_bets (user_id, game_code, issue_number, bet_type, bet_value, amount, odds, status, payout) VALUES (9998, 'WinGo_1M', ?, 'number', ?, 100, 9, 'pending', 0)");
$insBet->execute([$closedIssue, '7']);
$settleClosed = $zbets->ensureSettled('WinGo_1M');
assertTest("Closed-period bet settles in the same request", $settleClosed['settled_count'] === 1 && $settleClosed['won_count'] === 1, json_encode($settleClosed));
assertTest("Payout applies 9x odds minus 2% fee", abs($settleClosed['total_payout'] - 882.0) < 0.001, (string)$settleClosed['total_payout']);
assertTest(
    "Wallet credited by the payout in the same request",
    abs($zbets->getWallet(9998)['balance'] - ($balanceBefore + 882.0)) < 0.001,
    $zbets->getWallet(9998)['balance'] . " vs expected " . ($balanceBefore + 882.0)
);

$insBet->execute([$openIssue, '3']);
$settleOpen = $zbets->ensureSettled('WinGo_1M');
assertTest("Open-period bet stays pending (no early settlement)", $settleOpen['settled_count'] === 0, json_encode($settleOpen));
assertTest("Settleable count ignores the open period", $zbets->countSettleableBets('WinGo_1M') === 0, (string)$zbets->countSettleableBets('WinGo_1M'));

// 5f. Live pull must be a no-op (no network) once the closed result is present.
$pull = $zsync->ensureLiveResult('WinGo_1M');
assertTest("Live pull short-circuits when result already stored", $pull['needed'] === false && $pull['row'] !== null, json_encode(['needed' => $pull['needed'], 'row' => $pull['row']]));

@unlink($tmpDb);

echo "\n=================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================\n";

if ($failed > 0) exit(1);
