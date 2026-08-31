<?php

declare(strict_types=1);

use Lottery\Support\ApiException;
use Lottery\Wallet\WalletService;

TestRunner::group('Wallet & ledger');

$app    = makeTestApp();
$wallet = $app->wallet();
$userId = 1001;

TestRunner::equals('new wallet starts at zero', 0.0, $wallet->balance($userId));

$wallet->credit($userId, 1000.0, 'test:credit:1', 'adjustment', null, 'seed');
TestRunner::nearly('credit applied', 1000.0, $wallet->balance($userId));

$replay = $wallet->credit($userId, 1000.0, 'test:credit:1', 'adjustment', null, 'seed');
TestRunner::ok('duplicate entry_key is a no-op', $replay['applied'] === false);
TestRunner::nearly('balance unchanged after replay', 1000.0, $wallet->balance($userId));

$wallet->debit($userId, 250.5, 'test:debit:1', 'bet', 'BET1', 'stake');
TestRunner::nearly('debit applied', 749.5, $wallet->balance($userId));

TestRunner::throws('overdraft rejected', static function () use ($wallet, $userId) {
    $wallet->debit($userId, 10000.0, 'test:debit:overdraft', 'bet');
}, 'Insufficient balance');
TestRunner::nearly('balance intact after failed debit', 749.5, $wallet->balance($userId));

TestRunner::throws('zero amount rejected', static function () use ($wallet, $userId) {
    $wallet->credit($userId, 0.0, 'test:credit:zero', 'adjustment');
}, 'greater than zero');

$entries = $wallet->ledger($userId, 10);
TestRunner::equals('ledger rows recorded', 2, count($entries));
TestRunner::equals('newest entry is the debit', 'debit', $entries[0]['direction']);
TestRunner::nearly('balance_before tracked', 1000.0, (float) $entries[0]['balance_before']);
TestRunner::nearly('balance_after tracked', 749.5, (float) $entries[0]['balance_after']);

$snapshot = $wallet->snapshot($userId);
TestRunner::equals('snapshot balance is formatted', '749.50', $snapshot['balance']);
TestRunner::equals('total stake tracked', '250.50', $snapshot['totalStake']);
TestRunner::equals('total payout tracked', '1000.00', $snapshot['totalPayout']);

$k1 = WalletService::entryKey('bet', '1', 'g', 'r');
$k2 = WalletService::entryKey('bet', '1', 'g', 'r');
$k3 = WalletService::entryKey('bet', '1', 'g', 'r2');
TestRunner::equals('entry keys are deterministic', $k1, $k2);
TestRunner::ok('entry keys differ per request', $k1 !== $k3);

// Exact-balance debit is allowed (float rounding guard)
$app2 = makeTestApp();
$app2->wallet()->credit(7, 10.10, 'x:1', 'adjustment');
$app2->wallet()->debit(7, 10.10, 'x:2', 'bet');
TestRunner::nearly('exact balance debit allowed', 0.0, $app2->wallet()->balance(7));
