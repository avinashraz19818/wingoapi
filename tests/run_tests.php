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
//
//    Model under test (what production must do):
//      - the period number ALWAYS comes from the provider's own feed, never from our clock
//      - we run one period behind, so the result of the displayed period is already stored
//      - a draw becomes visible the instant the window it arrived in closes
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

// 5a. Issue number format must match the real provider feed.
//     Reference: production DB row 20260824100010884 has draw_time 2026-08-24 14:43:30,
//     i.e. the WinGo_1M period starting 14:43:00 IST -> index 884.
$refIssue = $zapi->calculateIssueNumberForTime('WinGo_1M', strtotime('2026-08-24 14:43:00'));
assertTest("Issue number uses provider format YYYYMMDD+10001+NNNN", strlen($refIssue) === 17, "got '{$refIssue}' (len " . strlen($refIssue) . ")");
assertTest("Issue matches real provider draw 20260824100010884", $refIssue === '20260824100010884', "got {$refIssue}");

$interval = 60;
$now = time();
$bucket = $now - ($now % $interval);      // start of the window we are in
$prevStart = $bucket - $interval;         // start of the window that just closed

// 5b. Writing provider batches: portable insert + newest row must end up with the highest id.
//     Providers send the list NEWEST first; we must store it so that ORDER BY id DESC = newest.
$batch = [
    ['issueNumber' => '20260824100010890', 'number' => 5, 'color' => 'green,violet'],
    ['issueNumber' => '20260824100010889', 'number' => 2, 'color' => 'red'],
];
$first = $zsync->persistResults('WinGo_1M', $batch);
assertTest("Provider batch saved on driver " . $tpdo->getAttribute(PDO::ATTR_DRIVER_NAME), $first['saved'] === 2, json_encode($first));
assertTest("Newest draw of a batch is the latest by id", $zsync->latestStoredIssue('WinGo_1M') === '20260824100010890', (string)$zsync->latestStoredIssue('WinGo_1M'));
$second = $zsync->persistResults('WinGo_1M', $batch);
assertTest("Duplicate draw ignored instead of erroring", $second['saved'] === 0 && $second['skipped_duplicates'] === 2, json_encode($second));
assertTest("Record without issue number is skipped", $zsync->persistResults('WinGo_1M', [['number' => 3]])['invalid'] === 1);

// 5c. Two draws with controlled arrival times:
//     P arrived during the PREVIOUS window (closed) -> must be visible now
//     C arrived during the CURRENT window (still betting) -> must stay hidden
$P = '20260824100010884';
$C = '20260824100010885';
$insDraw = $tpdo->prepare("INSERT INTO wingo_results (game_code, issue_number, number, color, premium, sum, draw_time, fetched_at) VALUES ('WinGo_1M', ?, ?, ?, ?, ?, ?, ?)");
$insDraw->execute([$P, 7, 'green', '7', 7, date('Y-m-d H:i:s', $prevStart), $zsync->dbTime($prevStart)]);
$insDraw->execute([$C, 3, 'green', '3', 3, date('Y-m-d H:i:s', $bucket), $zsync->dbTime($bucket)]);

$current = $zsync->getCurrentIssue('WinGo_1M', false);
assertTest("Open issue comes from the provider feed, not our clock", $current['issue_number'] === $C, $current['issue_number']);
assertTest("seconds_left inside the interval", $current['seconds_left'] >= 0 && $current['seconds_left'] <= $interval, (string)$current['seconds_left']);

$histNow = array_column($zsync->getHistory('WinGo_1M', 10, $current['visible_before']), 'issue_number');
assertTest("Previous window's draw is visible immediately", in_array($P, $histNow, true), implode(',', $histNow));
assertTest("Current window's draw is NOT leaked while betting is open", !in_array($C, $histNow, true), implode(',', $histNow));

// The moment the timer ends, the boundary moves one window forward -> C shows up at once.
$histAfter = array_column($zsync->getHistory('WinGo_1M', 10, $zsync->dbTime($bucket + $interval)), 'issue_number');
assertTest("Draw appears in history the instant the timer ends", $histAfter[0] === $C, implode(',', $histAfter));

// 5d. Settlement follows the same rule: closed window settles now, open window never early.
$zbets->deposit(9998, 1000.0);
$balanceBefore = $zbets->getWallet(9998)['balance'];
$insBet = $tpdo->prepare("INSERT INTO wingo_bets (user_id, game_code, issue_number, bet_type, bet_value, amount, odds, status, payout) VALUES (9998, 'WinGo_1M', ?, 'number', ?, 100, 9, 'pending', 0)");
$insBet->execute([$P, '7']);
$settleClosed = $zbets->ensureSettled('WinGo_1M');
assertTest("Closed-window bet settles in the same request", $settleClosed['settled_count'] === 1 && $settleClosed['won_count'] === 1, json_encode($settleClosed));
assertTest("Payout applies 9x odds minus 2% fee", abs($settleClosed['total_payout'] - 882.0) < 0.001, (string)$settleClosed['total_payout']);
assertTest(
    "Wallet credited in the same request",
    abs($zbets->getWallet(9998)['balance'] - ($balanceBefore + 882.0)) < 0.001,
    $zbets->getWallet(9998)['balance'] . " vs expected " . ($balanceBefore + 882.0)
);

$insBet->execute([$C, '3']);
$settleOpen = $zbets->ensureSettled('WinGo_1M');
assertTest("Open-window bet stays pending (no early settlement)", $settleOpen['settled_count'] === 0, json_encode($settleOpen));
assertTest("Settleable count ignores the open window", $zbets->countSettleableBets('WinGo_1M') === 0, (string)$zbets->countSettleableBets('WinGo_1M'));

// 5e. Live pull must be a no-op (no network) when the closed window already has a draw.
$pull = $zsync->ensureLiveResult('WinGo_1M');
assertTest("Live pull short-circuits when the closed window already has a draw", $pull['needed'] === false && $pull['fresh'] === true, json_encode($pull));

@unlink($tmpDb);

echo "\n=================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================\n";

if ($failed > 0) exit(1);
