<?php
/**
 * Result publication lag (ISSUE_OFFSET) — behavioural test.
 *
 * Runs entirely on SQLite with a frozen clock, so it needs no MySQL server and
 * no upstream draw provider:
 *
 *   php tests/result_lag.php
 *
 * It drives the real controllers (FeedController, LotteryController,
 * ArCompatController) through the real DrawService, so a regression in any of
 * the three read paths fails here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Lottery\Api\Compat\ArCompatController;
use Lottery\Api\FeedController;
use Lottery\Api\LotteryController;
use Lottery\App;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        echo "  [PASS] {$name}\n";
        $passed++;
        return;
    }
    echo "  [FAIL] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    $failed++;
}

/**
 * A fully-booted app on a throwaway SQLite file, with the upstream provider
 * switched off so every round is produced by the local HMAC generator.
 */
function makeApp(int $offset, string $dbFile): App
{
    @unlink($dbFile);

    // NB: config.php declares $issueOffset at its top level, and a require
    // inside a function creates those variables in *this* scope — hence the
    // distinct parameter name.
    $config = require dirname(__DIR__) . '/config.php';
    $config['database']['driver']      = 'sqlite';
    $config['database']['sqlite_file'] = $dbFile;
    $config['database']['allow_sqlite_fallback'] = false;
    $config['draw_enabled']            = false;
    $config['issue_timezone']          = '';
    $config['issue_offset']            = $offset;
    $config['publication_lag']         = -$offset;
    $config['log']['path']             = sys_get_temp_dir() . '/result-lag-test.log';

    $app = new App($config);
    $app->bootstrapDatabase();

    return $app;
}

/** Newest issue number a history endpoint is currently willing to publish. */
function newestPublished(App $app, \Lottery\Games\GameDefinition $game): ?string
{
    $rows = $app->draws()->history(
        $game,
        1,
        0,
        $app->draws()->resolveMaxIssue($game, '')
    );

    return $rows === [] ? null : (string) $rows[0]['issue_number'];
}

// 23:43:20 — round …1424 is open for betting (it closes at 23:44:00).
$T          = 1788200000;
$T_PLUS_1   = $T + 65;      // 23:44:05 — …1424 has just closed, …1425 is live
$T_PLUS_2   = $T + 125;     // 23:45:05 — …1425 has just closed, …1426 is live

echo "=================================================\n";
echo "Result publication lag (ISSUE_OFFSET) test suite\n";
echo "=================================================\n";

/* -------------------------------------------------------------------------
 | 1. Offset 0 must not change the published window
 * ---------------------------------------------------------------------- */
echo "\n--- 1. ISSUE_OFFSET=0 (standard) ---\n";

$app  = makeApp(0, '/tmp/lag-offset0.sqlite');
$game = $app->registry()->get('WinGo_1M');
Clock::freeze($T);
$app->draws()->catchUp($game, 6, $T);

$live     = $app->scheduler()->current($game, $T)->issueNumber;
$previous = $app->scheduler()->previous($game, $T)->issueNumber;

check('live round is …1424', $live === '20260831100011424', $live);
check('publicationLag() is 0', $app->draws()->publicationLag() === 0);
check(
    'newest published = the round that just closed',
    newestPublished($app, $game) === $previous,
    (string) newestPublished($app, $game)
);
check('visibleBefore() = the live issue', $app->draws()->visibleBefore($game, $T) === $live);

/* -------------------------------------------------------------------------
 | 2. Offset -1 holds the freshest result back by one period
 * ---------------------------------------------------------------------- */
echo "\n--- 2. ISSUE_OFFSET=-1 (1 period piche) ---\n";

$app  = makeApp(-1, '/tmp/lag-offset-1.sqlite');
$game = $app->registry()->get('WinGo_1M');
Clock::freeze($T);
$app->draws()->catchUp($game, 6, $T);

$live     = $app->scheduler()->current($game, $T)->issueNumber;
$previous = $app->scheduler()->previous($game, $T)->issueNumber;
$twoBack  = $app->scheduler()->shifted($game, 2, $T)->issueNumber;

check('publicationLag() is 1', $app->draws()->publicationLag() === 1);
check('visibleBefore() = the previous issue', $app->draws()->visibleBefore($game, $T) === $previous);
check('newest published = two periods back', newestPublished($app, $game) === $twoBack, (string) newestPublished($app, $game));
check('the round that just closed is hidden', !$app->draws()->isVisible($game, $previous));
check('two periods back is visible', $app->draws()->isVisible($game, $twoBack));

// All three read paths must agree.
$feed    = (new FeedController($app, ['gameCode' => 'WinGo_1M', 'pageSize' => 5]))->history($game);
$lottery = (new LotteryController($app, ['gameCode' => 'WinGo_1M', 'pageSize' => 5]))->getHistoryIssuePage();
$compat  = (new ArCompatController($app, ['gameCode' => 'WinGo_1M', 'pageSize' => 5]))->history($game);

check('Feed history starts two periods back', $feed['list'][0]['issueNumber'] === $twoBack, (string) $feed['list'][0]['issueNumber']);
check('Lottery history starts two periods back', $lottery['list'][0]['issueNumber'] === $twoBack, (string) $lottery['list'][0]['issueNumber']);
check('Compat history starts two periods back', $compat['data']['list'][0]['issueNumber'] === $twoBack, (string) $compat['data']['list'][0]['issueNumber']);
$unlaggedCount = $app->draws()->countHistory($game, $app->scheduler()->current($game, $T)->issueNumber);
check('Feed totalCount drops by exactly one round', $feed['totalCount'] === $unlaggedCount - 1, $feed['totalCount'] . ' vs ' . $unlaggedCount);

// GetGameIssue keeps the live countdown but must not hand out the fresh result.
$issue = (new FeedController($app, ['gameCode' => 'WinGo_1M']))->issue($game);
check('GetGameIssue still reports the live round', $issue['issueNumber'] === $live, (string) $issue['issueNumber']);
check('GetGameIssue lastIssue is two periods back', $issue['lastIssue']['issueNumber'] === $twoBack, (string) ($issue['lastIssue']['issueNumber'] ?? 'null'));
check('GetGameIssue advertises the lag', $issue['publicationLag'] === 1);

/* -------------------------------------------------------------------------
 | 3. A caller cannot peek past the window
 * ---------------------------------------------------------------------- */
echo "\n--- 3. Window cannot be bypassed ---\n";

$peek = (new FeedController($app, ['gameCode' => 'WinGo_1M', 'pageSize' => 3, 'activeIssue' => $live]))->history($game);
check('client-supplied activeIssue is clamped', $peek['list'][0]['issueNumber'] === $twoBack, (string) $peek['list'][0]['issueNumber']);

$threw = false;
try {
    (new FeedController($app, ['gameCode' => 'WinGo_1M', 'issueNumber' => $previous]))->result($game);
} catch (ApiException $e) {
    $threw = true;
}
check('Feed GetResult refuses the held-back round', $threw);

$older = (new FeedController($app, ['gameCode' => 'WinGo_1M', 'issueNumber' => $twoBack]))->result($game);
check('Feed GetResult serves a round inside the window', $older['issueNumber'] === $twoBack, (string) $older['issueNumber']);

$compatHidden = (new ArCompatController($app, ['gameCode' => 'WinGo_1M', 'issueNumber' => $previous]))->result($game);
check('Compat GetResult hides the held-back round', (int) $compatHidden['code'] !== 0, json_encode($compatHidden['code']));

$compatShown = (new ArCompatController($app, ['gameCode' => 'WinGo_1M', 'issueNumber' => $twoBack]))->result($game);
check('Compat GetResult serves a round inside the window', (int) $compatShown['code'] === 0, json_encode($compatShown['code']));

/* -------------------------------------------------------------------------
 | 3b. Trend statistics must not leak the held-back round either
 * ---------------------------------------------------------------------- */
echo "\n--- 3b. Trend counters respect the window ---\n";

$trend = (new LotteryController($app, ['gameCode' => 'WinGo_1M', 'window' => 100]))->getTrendStatistics();
check('engine trend latestIssue is two periods back', $trend['latestIssue'] === $twoBack, (string) $trend['latestIssue']);
check('engine trend counts one round fewer', $trend['rounds'] === $unlaggedCount - 1, $trend['rounds'] . ' vs ' . ($unlaggedCount - 1));

$compatTrend = (new ArCompatController($app, ['gameCode' => 'WinGo_1M']))->trend($game);
check('compat trend list starts two periods back', $compatTrend['data']['list'][0]['issueNumber'] === $twoBack, (string) $compatTrend['data']['list'][0]['issueNumber']);

/* -------------------------------------------------------------------------
 | 4. The lag is display-only: money still moves on the real clock
 * ---------------------------------------------------------------------- */
echo "\n--- 4. Settlement is unaffected by the lag ---\n";

$player = $app->players()->register('9000000001', 'Password#123', 'Lag Tester');
$userId = (int) $player['userId'];
$app->wallet()->credit($userId, 5000.0, 'test-topup-1', 'test', null, 'test funds');

$placement = $app->bets()->place($userId, [
    'gameCode'    => 'WinGo_1M',
    'betType'     => 'color',
    'betContent'  => 'green',
    'amount'      => 100.0,
    'multiplier'  => 1,
    'issueNumber' => $live,
    'source'      => 'manual',
]);
check('bet accepted on the live round', (string) $placement['issueNumber'] === $live, (string) $placement['issueNumber']);

// One period later …1424 has closed: it is drawn and paid, but still hidden.
Clock::freeze($T_PLUS_1);
$app->draws()->flushProviderCache();
$app->settlement()->settleDue($game, 5, $T_PLUS_1);

$bet = $app->db()->fetch(
    'SELECT status FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ?',
    ['WinGo_1M', $live]
);
check('held-back round was drawn on the real clock', $app->draws()->find($game, $live) !== null);
check('bet on the held-back round is settled', $bet !== null && $bet['status'] !== 'pending', (string) ($bet['status'] ?? 'missing'));
check(
    '…1424 is still not published while …1425 is live',
    newestPublished($app, $game) === $previous,
    (string) newestPublished($app, $game)
);

// One more period on and the same result becomes public.
Clock::freeze($T_PLUS_2);
$app->draws()->flushProviderCache();
$app->settlement()->settleDue($game, 5, $T_PLUS_2);
check(
    'the held-back result is published one period later',
    newestPublished($app, $game) === $live,
    (string) newestPublished($app, $game)
);
check('…1424 is visible once …1426 is live', $app->draws()->isVisible($game, $live, $T_PLUS_2));

/* -------------------------------------------------------------------------
 | 5. Scheduler maths, including the midnight rollover
 * ---------------------------------------------------------------------- */
echo "\n--- 5. IssueScheduler::shifted ---\n";

Clock::freeze($T);
check('shifted(0) is the current issue', $app->scheduler()->shifted($game, 0, $T)->issueNumber === $live);
check('shifted(1) is previous()', $app->scheduler()->shifted($game, 1, $T)->issueNumber === $previous);
check('shifted(-1) is next()', $app->scheduler()->shifted($game, -1, $T)->issueNumber === $app->scheduler()->next($game, $T)->issueNumber);
check('shifted(5) walks back five rounds', $app->scheduler()->shifted($game, 5, $T)->issueNumber === '20260831100011419');

// 23:59:30 IST on 31 Aug — three 1-minute rounds later the daily sequence
// restarts at 0001 on 1 Sep, so the date stamp has to roll too.
$beforeMidnight = (int) strtotime('2026-08-31 23:59:30');   // round …1440 is live
check('the last round of the day is …1440',
    $app->scheduler()->issueAt($game, $beforeMidnight)->issueNumber === '20260831100011440',
    $app->scheduler()->issueAt($game, $beforeMidnight)->issueNumber);
check(
    'shifted() forward across midnight restarts the daily sequence',
    $app->scheduler()->shifted($game, -3, $beforeMidnight)->issueNumber === '20260901100010003',
    $app->scheduler()->shifted($game, -3, $beforeMidnight)->issueNumber
);
check(
    'shifted() backward across midnight lands on the previous day',
    $app->scheduler()->shifted($game, 1, $beforeMidnight + 60)->issueNumber === '20260831100011440',
    $app->scheduler()->shifted($game, 1, $beforeMidnight + 60)->issueNumber
);

Clock::unfreeze();

echo "\n=================================================\n";
echo "Result lag tests: {$passed} passed, {$failed} failed\n";
echo "=================================================\n";

exit($failed === 0 ? 0 : 1);
