<?php
/**
 * Draw / settlement / copy-trade worker.
 *
 *   php cron/worker.php --once            one pass, then exit (use with cron)
 *   php cron/worker.php --loop            long-running daemon (systemd)
 *   php cron/worker.php --once --game=WinGo_1M
 *
 * Each pass, for every enabled game:
 *   1. resolves results for finished rounds (provider -> override -> local)
 *   2. settles every pending bet of those rounds
 *   3. places copy-trade bets for the currently open round
 *
 * Every step is idempotent, so overlapping runs are harmless.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Support\Clock;
use Lottery\Support\Log;

$options = getopt('', ['once', 'loop', 'game::', 'interval::', 'quiet']);
$once     = isset($options['once']) || !isset($options['loop']);
$only     = isset($options['game']) ? (string) $options['game'] : '';
$sleep    = isset($options['interval']) ? max(1, (int) $options['interval']) : 2;
$quiet    = isset($options['quiet']);

$app = App::boot();
$app->bootstrapDatabase();

$registry   = $app->registry();
$settlement = $app->settlement();
$follow     = $app->follow();

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    }
};

$pass = static function () use ($registry, $settlement, $follow, $only, $say): void {
    foreach ($registry->all() as $game) {
        if ($only !== '' && strcasecmp($only, $game->code) !== 0) {
            continue;
        }

        try {
            $reports = $settlement->settleDue($game, 10, Clock::now());
            foreach ($reports as $report) {
                if ($report['settled'] && $report['bets'] > 0) {
                    $say(sprintf(
                        '%s %s settled: %d bets, %d won, payout %s',
                        $game->code,
                        $report['issueNumber'],
                        $report['bets'],
                        $report['won'],
                        $report['payout']
                    ));
                }
            }

            $copy = $follow->runForGame($game);
            if ($copy['placed'] > 0) {
                $say(sprintf('%s %s copy-trade: %d placed, %d failed',
                    $game->code, $copy['issueNumber'], $copy['placed'], $copy['failed']));
            }
        } catch (Throwable $e) {
            Log::exception($e, ['stage' => 'worker', 'game' => $game->code]);
            $say('ERROR ' . $game->code . ': ' . $e->getMessage());
        }
    }
};

if ($once) {
    $pass();
    $say('single pass complete');
    exit(0);
}

$say('worker started (interval ' . $sleep . 's) — Ctrl+C to stop');

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($say): void {
        $say('SIGTERM received, shutting down');
        exit(0);
    });
    pcntl_signal(SIGINT, static function () use ($say): void {
        $say('SIGINT received, shutting down');
        exit(0);
    });
}

while (true) {
    $pass();
    sleep($sleep);
}
