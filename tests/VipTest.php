<?php

declare(strict_types=1);

use Lottery\Database\Tables;
use Lottery\Support\Clock;

TestRunner::group('VIP experience & levels');

$app = makeTestApp();
$vip = $app->vip();

TestRunner::equals('L0 at 0 exp', 0, $vip->levelForExperience(0));
TestRunner::equals('L0 just below 3k', 0, $vip->levelForExperience(2999));
TestRunner::equals('L1 at 3k', 1, $vip->levelForExperience(3000));
TestRunner::equals('L2 at 30k', 2, $vip->levelForExperience(30000));
TestRunner::equals('L3 at 400k', 3, $vip->levelForExperience(400000));
TestRunner::equals('L4 at 4M', 4, $vip->levelForExperience(4000000));
TestRunner::equals('L5 at 20M', 5, $vip->levelForExperience(20000000));
TestRunner::equals('L5 stays at 100M', 5, $vip->levelForExperience(100000000));
TestRunner::equals('1 EXP per rupee staked', 250.0, $vip->experienceForStake(250.0));

$userId = 4001;
$award  = $vip->award($userId, 1000.0, 'bet', 'BET-A');
TestRunner::nearly('experience awarded', 1000.0, $award['experience']);
TestRunner::equals('still level 0', 0, $award['levelAfter']);

$replay = $vip->award($userId, 1000.0, 'bet', 'BET-A');
TestRunner::ok('duplicate award ignored', $replay['applied'] === false);
TestRunner::nearly('experience unchanged after replay', 1000.0, $replay['experience']);

$levelUp = $vip->award($userId, 2500.0, 'bet', 'BET-B');
TestRunner::nearly('experience accumulates', 3500.0, $levelUp['experience']);
TestRunner::equals('promoted to level 1', 1, $levelUp['levelAfter']);
TestRunner::equals('previous level reported', 0, $levelUp['levelBefore']);

$status = $vip->status($userId);
TestRunner::equals('status level', 1, $status['level']);
TestRunner::equals('status next level', 2, $status['nextLevel']);
TestRunner::equals('exp to next level', '26500.00', $status['expToNextLevel']);

TestRunner::group('VIP backfill of historical bets');

$app2   = makeTestApp();
$user2  = 4002;
$db     = $app2->db();

// Simulate legacy bets that were never counted for VIP.
foreach ([['L1', 5000], ['L2', 1000]] as $i => [$no, $stake]) {
    $db->execute(
        'INSERT INTO ' . Tables::BETS . '
            (bet_no, user_id, game_code, family, issue_number, bet_type, bet_content, unit_amount,
             multiplier, units, stake, odds, potential_payout, status, vip_experience, vip_counted, source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, 2, 0, ?, 0, 0, ?, ?)',
        [$no, $user2, 'WinGo_1M', 'WinGo', '20260830100010001', 'size', 'big',
         number_format($stake, 2, '.', ''), number_format($stake, 2, '.', ''), 'lost', 'legacy', Clock::dateTime()]
    );
}

$result = $app2->vip()->backfill($user2);
TestRunner::ok('backfill ran', $result['backfilled'] === true);
TestRunner::nearly('historic stake imported as experience', 6000.0, $result['experience']);
TestRunner::equals('level derived from imported exp', 1, $result['level']);

$second = $app2->vip()->backfill($user2);
TestRunner::ok('backfill only runs once', $second['backfilled'] === false);
TestRunner::nearly('experience unchanged on second backfill', 6000.0, $second['experience']);

$counted = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE user_id = ? AND vip_counted = 1',
    [$user2]
);
TestRunner::equals('historic bets marked as counted', 2, $counted);
