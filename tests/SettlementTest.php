<?php

declare(strict_types=1);

use Lottery\Support\Clock;

TestRunner::group('Settlement');

$app       = makeTestApp();
$registry  = $app->registry();
$scheduler = $app->scheduler();
$bets      = $app->bets();
$wallet    = $app->wallet();
$userId    = 3001;

$wingo = $registry->get('WinGo_1M');

Clock::freeze(strtotime('2026-08-31 12:00:10'));
fundWallet($app, $userId, 10000.0);
$issue = $scheduler->current($wingo);

// Force the result so the outcome is deterministic: digit 3 (green, small, odd)
$app->overrides()->set($wingo, $issue->issueNumber, '3', 'oneshot', 'test');

$winner = $bets->place($userId, [
    'gameCode' => 'WinGo_1M', 'betType' => 'number', 'betContent' => '3', 'amount' => 100.0,
]);
$loser = $bets->place($userId, [
    'gameCode' => 'WinGo_1M', 'betType' => 'number', 'betContent' => '4', 'amount' => 100.0,
]);
$colour = $bets->place($userId, [
    'gameCode' => 'WinGo_1M', 'betType' => 'color', 'betContent' => 'green', 'amount' => 100.0,
]);
$spread = $bets->place($userId, [
    'gameCode' => 'WinGo_1M', 'betType' => 'number', 'betContent' => '2,3,4', 'amount' => 100.0,
]);

TestRunner::nearly('stakes deducted up front', 9400.0, $wallet->balance($userId));

Clock::freeze(strtotime('2026-08-31 12:01:05'));
$report = $app->settlement()->settleIssue($wingo, $issue);

TestRunner::ok('issue settled', $report['settled'] === true);
TestRunner::equals('all four bets processed', 4, $report['bets']);
TestRunner::equals('three winners', 3, $report['won']);

$rows = [];
foreach ($bets->history($userId, 'WinGo_1M', 1, 20)['list'] as $row) {
    $rows[$row['betId']] = $row;
}

// number 3 on 100 stake: 100 x 9 = 900 gross, 2% tax = 18, net 882
TestRunner::equals('winning number status', 'won', $rows[$winner['betId']]['status']);
TestRunner::equals('winning number gross', '900.00', $rows[$winner['betId']]['payoutGross']);
TestRunner::equals('2% payout tax deducted', '18.00', $rows[$winner['betId']]['payoutTax']);
TestRunner::equals('net payout credited', '882.00', $rows[$winner['betId']]['payout']);

TestRunner::equals('losing bet status', 'lost', $rows[$loser['betId']]['status']);
TestRunner::equals('losing bet pays nothing', '0.00', $rows[$loser['betId']]['payout']);

// green on 3 = pure green 2x -> 200 gross, 196 net
TestRunner::equals('pure green payout', '196.00', $rows[$colour['betId']]['payout']);

// 2,3,4 -> 3 units of 100, only "3" wins: 100 x 9 = 900 gross, 882 net
TestRunner::equals('multi-selection stake charged', '300.00', $rows[$spread['betId']]['stake']);
TestRunner::equals('only the winning unit pays', '882.00', $rows[$spread['betId']]['payout']);

// 9400 + 882 + 196 + 882
TestRunner::nearly('wallet credited with net winnings', 11360.0, $wallet->balance($userId));

$again = $app->settlement()->settleIssue($wingo, $issue);
TestRunner::equals('re-settling finds nothing pending', 0, $again['bets']);
TestRunner::nearly('no double payout on re-settlement', 11360.0, $wallet->balance($userId));

$winLoss = $app->settlement()->winLossForUser($userId, $wingo, $issue->issueNumber);
TestRunner::equals('win/loss status', 'won', $winLoss['status']);
TestRunner::equals('win/loss stake total', '600.00', $winLoss['stake']);
TestRunner::equals('win/loss payout total', '1960.00', $winLoss['payout']);
TestRunner::equals('win/loss profit', '1360.00', $winLoss['profit']);
TestRunner::equals('win/loss exposes the result', 3, $winLoss['result']['number']);

TestRunner::group('Settlement — other families');

$app2  = makeTestApp();
$user2 = 3002;
Clock::freeze(strtotime('2026-08-31 12:00:10'));
fundWallet($app2, $user2, 5000.0);

$k3     = $app2->registry()->get('K3_1M');
$issue2 = $app2->scheduler()->current($k3);
$app2->overrides()->set($k3, $issue2->issueNumber, '3,3,5', 'oneshot', 'test');   // sum 11, big, odd

$k3Win  = $app2->bets()->place($user2, ['gameCode' => 'K3_1M', 'betType' => 'total', 'betContent' => '11', 'amount' => 10.0]);
$k3Size = $app2->bets()->place($user2, ['gameCode' => 'K3_1M', 'betType' => 'size', 'betContent' => 'small', 'amount' => 10.0]);
$k3Pair = $app2->bets()->place($user2, ['gameCode' => 'K3_1M', 'betType' => 'pair', 'betContent' => '3', 'amount' => 10.0]);

Clock::freeze(strtotime('2026-08-31 12:01:05'));
$app2->settlement()->settleIssue($k3, $issue2);

$k3Rows = [];
foreach ($app2->bets()->history($user2, 'K3_1M', 1, 20)['list'] as $row) {
    $k3Rows[$row['betId']] = $row;
}
TestRunner::equals('K3 total 11 payout (10 x 7.68 - 2%)', '75.26', $k3Rows[$k3Win['betId']]['payout']);
TestRunner::equals('K3 wrong size loses', 'lost', $k3Rows[$k3Size['betId']]['status']);
TestRunner::equals('K3 pair payout (10 x 13.83 - 2%)', '135.53', $k3Rows[$k3Pair['betId']]['payout']);

TestRunner::group('Settlement — settleDue sweep');

$app3  = makeTestApp();
$user3 = 3003;
Clock::freeze(strtotime('2026-08-31 12:00:10'));
fundWallet($app3, $user3, 1000.0);
$g3 = $app3->registry()->get('WinGo_1M');
$app3->bets()->place($user3, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 10.0]);

Clock::freeze(strtotime('2026-08-31 12:03:05'));
$reports = $app3->settlement()->settleDue($g3, 5);
TestRunner::ok('sweep settled the pending round', count($reports) > 0);

$pending = $app3->bets()->pendingForIssue('WinGo_1M', '20260831100010721');
TestRunner::equals('no pending bets left', 0, count($pending));

Clock::unfreeze();
