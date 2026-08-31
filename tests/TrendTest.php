<?php

declare(strict_types=1);

use Lottery\Support\Clock;

TestRunner::group('Trend statistics');

$app       = makeTestApp();
$registry  = $app->registry();
$scheduler = $app->scheduler();
$wingo     = $registry->get('WinGo_1M');

Clock::freeze(strtotime('2026-08-31 12:00:30'));

// Seed a controlled sequence of results (oldest first).
$sequence = [1, 1, 3, 5, 5, 5, 2, 7, 7, 4];
$base     = strtotime('2026-08-31 11:50:00');

foreach ($sequence as $index => $digit) {
    $issue = $scheduler->issueAt($wingo, $base + ($index * 60));
    $app->overrides()->set($wingo, $issue->issueNumber, (string) $digit, 'oneshot', 'test');
    $app->draws()->ensureResult($wingo, $issue, $base + ($index * 60) + 120);
}

$stats = $app->trends()->statistics($wingo, 100);
$byValue = [];
foreach ($stats['positions']['number'] as $row) {
    $byValue[$row['value']] = $row;
}

TestRunner::equals('rounds in window', 10, $stats['rounds']);
TestRunner::equals('digit 4 open count', 1, $byValue['4']['openCount']);
TestRunner::equals('digit 4 is the latest (missing 0)', 0, $byValue['4']['missing']);
TestRunner::equals('digit 5 open count', 3, $byValue['5']['openCount']);
TestRunner::equals('digit 5 max continuous', 3, $byValue['5']['maxContinuous']);
TestRunner::equals('digit 5 missing since', 4, $byValue['5']['missing']);
TestRunner::equals('digit 7 max continuous', 2, $byValue['7']['maxContinuous']);
TestRunner::equals('digit 7 missing since', 1, $byValue['7']['missing']);
TestRunner::equals('digit 1 max continuous', 2, $byValue['1']['maxContinuous']);
TestRunner::equals('unseen digit missing = window size', 10, $byValue['9']['missing']);
TestRunner::equals('unseen digit open count', 0, $byValue['9']['openCount']);

$sizes = [];
foreach ($stats['positions']['size'] as $row) {
    $sizes[$row['value']] = $row;
}
// sequence sizes: s s s b b b s b b s  -> small 5, big 5
TestRunner::equals('small open count', 5, $sizes['small']['openCount']);
TestRunner::equals('big open count', 5, $sizes['big']['openCount']);
TestRunner::equals('big max continuous', 3, $sizes['big']['maxContinuous']);
TestRunner::equals('small is the current streak', 1, $sizes['small']['currentStreak']);

$colors = [];
foreach ($stats['positions']['color'] as $row) {
    $colors[$row['value']] = $row;
}
// violet appears on every 5 -> 3 times
TestRunner::equals('violet counted from mixed colours', 3, $colors['violet']['openCount']);

$k3Stats = $app->trends()->statistics($registry->get('K3_1M'), 50);
TestRunner::ok('K3 exposes total/size/parity positions', isset($k3Stats['positions']['total'], $k3Stats['positions']['size']));

$d5Stats = $app->trends()->statistics($registry->get('D5_1M'), 50);
TestRunner::ok('D5 exposes a-e digit positions', isset($d5Stats['positions']['a'], $d5Stats['positions']['e'], $d5Stats['positions']['sum_size']));

Clock::unfreeze();
