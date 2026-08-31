<?php

declare(strict_types=1);

use Lottery\Games\IssueNumber;
use Lottery\Support\Clock;

TestRunner::group('Issue numbers & scheduling');

$app       = makeTestApp();
$registry  = $app->registry();
$scheduler = $app->scheduler();

$wingo1m = $registry->get('WinGo_1M');
$wingo30 = $registry->get('WinGo_30S');
$k3      = $registry->get('K3_5M');

// 2026-08-31 00:00:30 IST -> first 1M round of the day
Clock::freeze(strtotime('2026-08-31 00:00:30'));
$issue = $scheduler->current($wingo1m);

TestRunner::equals('17 digit issue number', 17, strlen($issue->issueNumber));
TestRunner::equals('example format YYYYMMDD+family+interval+seq', '20260831100010001', $issue->issueNumber);

$parts = IssueNumber::parse($issue->issueNumber);
TestRunner::equals('parsed date', '20260831', $parts['date']);
TestRunner::equals('parsed family code', '100', $parts['familyCode']);
TestRunner::equals('parsed interval code', '01', $parts['intervalCode']);
TestRunner::equals('parsed sequence', 1, $parts['sequence']);

Clock::freeze(strtotime('2026-08-31 12:00:00'));
TestRunner::equals('midday 1M sequence', '20260831100010721', $scheduler->current($wingo1m)->issueNumber);
TestRunner::equals('30S family/interval codes', '10000', substr($scheduler->current($wingo30)->issueNumber, 8, 5));
TestRunner::equals('K3 family code', '300', substr($scheduler->current($k3)->issueNumber, 8, 3));
TestRunner::equals('K3 5M interval code', '05', substr($scheduler->current($k3)->issueNumber, 11, 2));

// Round boundaries
Clock::freeze(strtotime('2026-08-31 12:00:00'));
$issue = $scheduler->current($wingo1m);
TestRunner::equals('round start', strtotime('2026-08-31 12:00:00'), $issue->startTs);
TestRunner::equals('round end', strtotime('2026-08-31 12:01:00'), $issue->endTs);
TestRunner::equals('lock 5s before end', strtotime('2026-08-31 12:00:55'), $issue->lockTs);
TestRunner::ok('betting open at start', $issue->isOpenAt(strtotime('2026-08-31 12:00:10')));
TestRunner::ok('betting closed in lock window', !$issue->isOpenAt(strtotime('2026-08-31 12:00:57')));
TestRunner::equals('remaining seconds', 45, $issue->remainingSeconds(strtotime('2026-08-31 12:00:15')));

TestRunner::equals('next issue', '20260831100010722', $scheduler->next($wingo1m)->issueNumber);
TestRunner::equals('previous issue', '20260831100010720', $scheduler->previous($wingo1m)->issueNumber);

$recent = $scheduler->recentClosed($wingo1m, 3);
TestRunner::equals('recentClosed newest first', '20260831100010720', $recent[0]->issueNumber);
TestRunner::equals('recentClosed third', '20260831100010718', $recent[2]->issueNumber);

$rebuilt = $scheduler->fromIssueNumber($wingo1m, '20260831100010721');
TestRunner::equals('rebuild issue from number', $issue->startTs, $rebuilt->startTs);

TestRunner::throws('reject foreign issue number', static function () use ($scheduler, $wingo1m) {
    $scheduler->fromIssueNumber($wingo1m, '20260831300010001');
}, 'does not belong');

TestRunner::throws('reject malformed issue number', static function () {
    IssueNumber::parse('2026083110001');
}, 'Invalid issue number');

TestRunner::equals('normalise 5D alias', 'D5_1M', $registry->normaliseCode('5D_1M'));
TestRunner::equals('normalise lowercase', 'WinGo_1M', $registry->normaliseCode('wingo_1m'));
TestRunner::equals('game count', 18, count($registry->all()));

Clock::unfreeze();
