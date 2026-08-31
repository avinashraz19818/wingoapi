<?php

declare(strict_types=1);

use Lottery\Support\Clock;

TestRunner::group('Betting engine');

$app       = makeTestApp();
$registry  = $app->registry();
$scheduler = $app->scheduler();
$bets      = $app->bets();
$wallet    = $app->wallet();

$wingo  = $registry->get('WinGo_1M');
$userId = 2001;

Clock::freeze(strtotime('2026-08-31 12:00:10'));
fundWallet($app, $userId, 10000.0);

$placed = $bets->place($userId, [
    'gameCode'   => 'WinGo_1M',
    'betType'    => 'color',
    'betContent' => 'green',
    'amount'     => 100.0,
    'multiplier' => 2,
]);

TestRunner::ok('bet accepted', $placed['accepted'] === true);
TestRunner::equals('stake = amount x multiplier x units', '200.00', $placed['stake']);
TestRunner::equals('units counted', 1, $placed['units']);
TestRunner::equals('balance deducted', '9800.00', $placed['balance']);
TestRunner::equals('vip experience = stake', '200.00', $placed['vipExperienceAdded']);
TestRunner::equals('issue is the open round', '20260831100010721', $placed['issueNumber']);
TestRunner::equals('potential payout net of 2% tax', '392.00', $placed['potentialPayout']);

$multi = $bets->place($userId, [
    'gameCode'   => 'WinGo_1M',
    'betType'    => 'number',
    'betContent' => '1,2,3',
    'amount'     => 10.0,
    'multiplier' => 1,
]);
TestRunner::equals('3 selections = 3 units', 3, $multi['units']);
TestRunner::equals('multi-selection stake', '30.00', $multi['stake']);
TestRunner::equals('canonical content stored', '1,2,3', $multi['betContent']);

/* ---------------------------------------------------------- idempotency */
$first = $bets->place($userId, [
    'gameCode'        => 'WinGo_1M',
    'betType'         => 'size',
    'betContent'      => 'big',
    'amount'          => 50.0,
    'requestGroupKey' => 'grp-1',
    'requestKey'      => 'req-1',
]);
$repeat = $bets->place($userId, [
    'gameCode'        => 'WinGo_1M',
    'betType'         => 'size',
    'betContent'      => 'big',
    'amount'          => 50.0,
    'requestGroupKey' => 'grp-1',
    'requestKey'      => 'req-1',
]);
TestRunner::equals('idempotent replay returns same bet', $first['betId'], $repeat['betId']);
TestRunner::ok('replay flagged as duplicate', $repeat['duplicate'] === true);
TestRunner::nearly('replay does not double-charge', 9720.0, $wallet->balance($userId));

$different = $bets->place($userId, [
    'gameCode'        => 'WinGo_1M',
    'betType'         => 'size',
    'betContent'      => 'big',
    'amount'          => 50.0,
    'requestGroupKey' => 'grp-1',
    'requestKey'      => 'req-2',
]);
TestRunner::ok('different request key creates a new bet', $different['betId'] !== $first['betId']);

/* ------------------------------------------------------------- limits */
TestRunner::throws('stake below minimum rejected', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 0.5]);
}, 'Minimum total stake');

TestRunner::throws('stake above maximum rejected', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 2000000]);
}, 'Maximum total stake');

TestRunner::throws('insufficient balance rejected', static function () use ($bets) {
    fundWallet($GLOBALS['app'] ?? makeTestApp(), 9999, 1.0);
    $bets->place(9999, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 5000]);
}, 'Insufficient balance');

TestRunner::throws('invalid bet content rejected', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'color', 'betContent' => 'purple', 'amount' => 10]);
}, 'Invalid selection');

TestRunner::throws('unknown game rejected', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'Poker_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 10]);
}, 'Unknown gameCode');

TestRunner::throws('multiplier out of range rejected', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 10, 'multiplier' => 0]);
}, 'Multiplier');

/* ------------------------------------------------------- betting window */
Clock::freeze(strtotime('2026-08-31 12:00:57'));   // inside the 5s lock window
TestRunner::throws('bet rejected during lock window', static function () use ($bets, $userId) {
    $bets->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 10]);
}, 'closed');

Clock::freeze(strtotime('2026-08-31 12:01:10'));
TestRunner::throws('bet on a past issue rejected', static function () use ($bets, $userId) {
    $bets->place($userId, [
        'gameCode'    => 'WinGo_1M',
        'betType'     => 'size',
        'betContent'  => 'big',
        'amount'      => 10,
        'issueNumber' => '20260831100010721',
    ]);
}, 'not accepting bets');

/* ------------------------------------------------------- other families */
$k3Bet = $bets->place($userId, [
    'gameCode' => 'K3_1M', 'betType' => 'total', 'betContent' => '10', 'amount' => 20.0,
]);
TestRunner::equals('K3 bet accepted', '20.00', $k3Bet['stake']);
TestRunner::equals('K3 odds recorded', 7.68, $k3Bet['odds']);

$d5Bet = $bets->place($userId, [
    'gameCode' => 'D5_1M', 'betType' => 'number', 'betContent' => 'a:3,b:4', 'amount' => 5.0, 'multiplier' => 3,
]);
TestRunner::equals('D5 stake = 5 x 3 x 2', '30.00', $d5Bet['stake']);

$motoBet = $bets->place($userId, [
    'gameCode' => 'MotoRace_1M', 'betType' => 'champion', 'betContent' => '7', 'amount' => 10.0,
]);
TestRunner::equals('MotoRace odds', 9.4, $motoBet['odds']);

$history = $bets->history($userId, 'WinGo_1M', 1, 10);
TestRunner::ok('history returns the WinGo bets only', $history['total'] === 4, 'total=' . $history['total']);
TestRunner::equals('history rows are presented', 'WinGo_1M', $history['list'][0]['gameCode']);

Clock::unfreeze();
