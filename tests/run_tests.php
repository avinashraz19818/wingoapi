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
//    Hermetic: throwaway SQLite DB, so the live data file is never touched.
//
//    Model under test (exactly what production must do):
//      - the period number ALWAYS comes from the provider's own feed, never from our clock
//      - the provider's newest draw IS the period open for betting; history stops one short
//        of it, so the feed reads one period behind the provider
//      - the provider publishes ~2s before its minute ends, so our countdown is shifted by
//        the same 2s: it starts the instant a draw is published and ends the instant the next
//        one is - the reveal and the settlement at 00 are pure local reads, no network
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

// 5a. Issue format must match the real provider feed.
//     Production row 20260824100010884 has draw_time 2026-08-24 14:43:30 IST, i.e. the
//     WinGo_1M period starting 14:43:00 -> index 884.
$refIssue = $zapi->calculateIssueNumberForTime('WinGo_1M', strtotime('2026-08-24 14:43:00'));
assertTest("Issue number uses provider format YYYYMMDD+10001+NNNN", strlen($refIssue) === 17, "got '{$refIssue}' (len " . strlen($refIssue) . ")");
assertTest("Issue matches real provider draw 20260824100010884", $refIssue === '20260824100010884', "got {$refIssue}");

$interval = 60;
$now = time();

// 5b. Storing a provider batch: portable insert, and the newest draw must get the highest id.
//     Providers send the list NEWEST first.
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

// 5c. Countdown phase. The provider hands the next result over 2s before its minute ends, so
//     our periods must tick 2s early too - that is what removes the wait at the boundary.
$lead = $zsync->resultLeadSeconds($interval);
assertTest("Result lead is 2s (the provider publishes at :58)", $lead === 2, (string)$lead);
assertTest("Phase offset = interval - lead", $zsync->phaseOffsetSeconds($interval) === 58, (string)$zsync->phaseOffsetSeconds($interval));
assertTest("WinGo_30S gets its own 28s phase", $zsync->phaseOffsetSeconds(30) === 28, (string)$zsync->phaseOffsetSeconds(30));
$ws = $zsync->windowStart($interval, $now);
assertTest("Our window starts at :58, two seconds before the minute", (int)date('s', $ws) === 58, date('H:i:s', $ws));
assertTest("Window start is in the past and less than a period old", $ws <= $now && ($now - $ws) < $interval, date('H:i:s', $ws) . " vs now " . date('H:i:s', $now));

// 5d. The provider's feed with controlled arrival times:
//       A -> two windows ago
//       B -> last window (the period that just closed)
//       C -> published right at our window start (the provider's 2s-early draw) => ON SCREEN
$A = '20260824100010881';
$B = '20260824100010882';
$C = '20260824100010883';
$insDraw = $tpdo->prepare("INSERT INTO wingo_results (game_code, issue_number, number, color, premium, sum, draw_time, fetched_at) VALUES ('WinGo_1M', ?, ?, ?, ?, ?, ?, ?)");
$insDraw->execute([$A, 1, 'green',        '1', 1, date('Y-m-d H:i:s', $ws - 120), $zsync->dbTime($ws - 120)]);
$insDraw->execute([$B, 7, 'green',        '7', 7, date('Y-m-d H:i:s', $ws - 60),  $zsync->dbTime($ws - 60)]);
$insDraw->execute([$C, 3, 'green',        '3', 3, date('Y-m-d H:i:s', $ws),       $zsync->dbTime($ws)]);

$current = $zsync->getCurrentIssue('WinGo_1M', false);
assertTest("Betting period is the provider's newest draw", $current['issue_number'] === $C, $current['issue_number']);
assertTest("Arrival order beats issue-number order (890 stored earlier is not on screen)", $current['issue_number'] !== '20260824100010890', $current['issue_number']);
assertTest("Nothing pending while the provider keeps up", $current['result_pending'] === false, json_encode($current['result_pending']));
assertTest("seconds_left inside the interval", $current['seconds_left'] >= 0 && $current['seconds_left'] <= $interval, (string)$current['seconds_left']);
assertTest("Result of the open period is already stored", $zsync->getResult('WinGo_1M', $C) !== null);
assertTest("next_issue_number is the open period + 1", $current['next_issue_number'] === '20260824100010884', $current['next_issue_number']);

$histNow = array_column($zsync->getHistory('WinGo_1M', 10, $current['history_before_id']), 'issue_number');
assertTest("History is one period behind the provider (top = B)", ($histNow[0] ?? null) === $B, implode(',', $histNow));
assertTest("The open period's own result is NOT in history yet", !in_array($C, $histNow, true), implode(',', $histNow));
assertTest("last_issue_number matches the top of history", $current['last_issue_number'] === $B, (string)$current['last_issue_number']);

// 5e. Settlement: a bet on a closed period settles in the same request; the open one waits.
$zbets->deposit(9998, 1000.0);
$balanceBefore = $zbets->getWallet(9998)['balance'];
$insBet = $tpdo->prepare("INSERT INTO wingo_bets (user_id, game_code, issue_number, bet_type, bet_value, amount, odds, status, payout) VALUES (9998, 'WinGo_1M', ?, 'number', ?, 100, 9, 'pending', 0)");
$insBet->execute([$B, '7']);   // B is 7 -> wins 9x
$settleClosed = $zbets->ensureSettled('WinGo_1M');
assertTest("Bet on a closed period settles in the same request", $settleClosed['settled_count'] === 1 && $settleClosed['won_count'] === 1, json_encode($settleClosed));
assertTest("Payout applies 9x odds minus 2% fee", abs($settleClosed['total_payout'] - 882.0) < 0.001, (string)$settleClosed['total_payout']);
assertTest(
    "Wallet credited in the same request",
    abs($zbets->getWallet(9998)['balance'] - ($balanceBefore + 882.0)) < 0.001,
    $zbets->getWallet(9998)['balance'] . " vs expected " . ($balanceBefore + 882.0)
);

$insBet->execute([$C, '1']);   // C is 3 -> would lose, but must stay pending until rollover
$settleOpen = $zbets->ensureSettled('WinGo_1M');
assertTest("Bet on the open period stays pending", $settleOpen['settled_count'] === 0, json_encode($settleOpen));
assertTest("Settleable count ignores the open period", $zbets->countSettleableBets('WinGo_1M') === 0, (string)$zbets->countSettleableBets('WinGo_1M'));

// 5f. Rollover. The provider publishes the next draw -> the period on screen advances, the
//     closed one tops history, and its bets settle. All local reads, no upstream call.
$D = '20260824100010884';
$insDraw->execute([$D, 9, 'green', '9', 9, date('Y-m-d H:i:s', $ws + 60), $zsync->dbTime($ws + 60)]);

$after = $zsync->getCurrentIssue('WinGo_1M', false);
assertTest("Next published draw moves straight into the betting period", $after['issue_number'] === $D, $after['issue_number']);
$histAfter = array_column($zsync->getHistory('WinGo_1M', 10, $after['history_before_id']), 'issue_number');
assertTest("Closed period tops history the instant the timer ends", ($histAfter[0] ?? null) === $C, implode(',', $histAfter));
assertTest("Still exactly one period behind after rollover", $after['last_issue_number'] === $C, (string)$after['last_issue_number']);

$settleRollover = $zbets->ensureSettled('WinGo_1M');
assertTest("Bet on the closed period settles at rollover", $settleRollover['settled_count'] === 1, json_encode($settleRollover));
assertTest("Settled as a loss (C is 3, the bet was on 1)", $settleRollover['won_count'] === 0, json_encode($settleRollover));

// 5g. Live pull is a no-op (no network) when the provider already published in this window.
$pull = $zsync->ensureLiveResult('WinGo_1M');
assertTest("Live pull short-circuits when this window already has a draw", $pull['needed'] === false && $pull['fresh'] === true, json_encode($pull));

@unlink($tmpDb);

echo "\n=================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=================================================\n";

if ($failed > 0) exit(1);
