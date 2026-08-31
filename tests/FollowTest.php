<?php

declare(strict_types=1);

use Lottery\Support\Clock;

TestRunner::group('Follow / copy trading');

$app    = makeTestApp();
$follow = $app->follow();
$userId = 5001;

$plans = $follow->plans();
TestRunner::ok('default plans seeded', count($plans) >= 5, 'count=' . count($plans));
TestRunner::ok('plans expose game + content', isset($plans[0]['gameCode'], $plans[0]['betType'], $plans[0]['betContent']));
TestRunner::equals('plans can be filtered by game', 'WinGo_1M', $follow->plans('WinGo_1M')[0]['gameCode']);

Clock::freeze(strtotime('2026-08-31 12:00:10'));
fundWallet($app, $userId, 1000.0);

$subscription = $follow->subscribe($userId, [
    'planCode'   => 'wingo1m-bigsmall-big',
    'amount'     => 10.0,
    'multiplier' => 2,
    'rounds'     => 2,
]);
TestRunner::equals('subscription active', 'active', $subscription['status']);
TestRunner::equals('subscription rounds', 2, $subscription['totalRounds']);

TestRunner::throws('cannot double-subscribe the same plan', static function () use ($follow, $userId) {
    $follow->subscribe($userId, ['planCode' => 'wingo1m-bigsmall-big', 'amount' => 10.0]);
}, 'already following');

TestRunner::throws('unknown plan rejected', static function () use ($follow, $userId) {
    $follow->subscribe($userId, ['planCode' => 'nope', 'amount' => 10.0]);
}, 'Unknown follow plan');

$wingo = $app->registry()->get('WinGo_1M');

$run1 = $follow->runForGame($wingo);
TestRunner::equals('one auto-bet placed', 1, $run1['placed']);
TestRunner::nearly('stake taken from the wallet', 980.0, $app->wallet()->balance($userId));

$rerun = $follow->runForGame($wingo);
TestRunner::equals('same issue is not bet twice', 0, $rerun['placed']);
TestRunner::nearly('balance unchanged on repeat run', 980.0, $app->wallet()->balance($userId));

Clock::freeze(strtotime('2026-08-31 12:01:10'));
$run2 = $follow->runForGame($wingo);
TestRunner::equals('next issue places the second bet', 1, $run2['placed']);

Clock::freeze(strtotime('2026-08-31 12:02:10'));
$run3 = $follow->runForGame($wingo);
TestRunner::equals('round budget exhausted', 0, $run3['placed']);
TestRunner::equals('subscription auto-completed', 'completed', $follow->userSubscriptions($userId)[0]['status']);

// Manual stop
$sub2 = $follow->subscribe($userId, ['planCode' => 'wingo1m-color-green', 'amount' => 5.0]);
$stopped = $follow->stop($userId, ['followId' => $sub2['followId']]);
TestRunner::equals('subscription stopped', 'stopped', $stopped['status']);

Clock::freeze(strtotime('2026-08-31 12:03:10'));
TestRunner::equals('stopped plan places no bets', 0, $follow->runForGame($wingo)['placed']);

TestRunner::throws('stopping an unknown record fails', static function () use ($follow, $userId) {
    $follow->stop($userId, ['followId' => 999999]);
}, 'not found');

// Insufficient funds stops the plan instead of looping forever
$app2  = makeTestApp();
$user2 = 5002;
fundWallet($app2, $user2, 5.0);
$app2->follow()->subscribe($user2, ['planCode' => 'wingo1m-bigsmall-big', 'amount' => 100.0]);
Clock::freeze(strtotime('2026-08-31 12:04:10'));
$failRun = $app2->follow()->runForGame($app2->registry()->get('WinGo_1M'));
TestRunner::equals('bet failed for lack of funds', 1, $failRun['failed']);
TestRunner::equals('subscription stopped after failure', 'stopped', $app2->follow()->userSubscriptions($user2)[0]['status']);

Clock::unfreeze();
