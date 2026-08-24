<?php
/**
 * WinGo CLI Sync & Settlement Worker
 * Can be executed via Linux Cron, Supervisor, Systemd, or cPanel Cron
 *
 * Usage:
 *   One-shot:   php cron/sync_worker.php
 *   Continuous: php cron/sync_worker.php --daemon --sleep=2
 *
 * All games are fetched in PARALLEL (curl_multi), so one cycle costs as much as the slowest
 * provider call instead of the sum of every call. That keeps results landing within ~1s of a
 * period closing even when one provider is slow.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../api/ResultSyncService.php';
require_once __DIR__ . '/../api/BetService.php';

$options = getopt('', ['daemon', 'sleep::']);
$isDaemon = isset($options['daemon']);
$sleepSeconds = isset($options['sleep']) ? max(1, (int)$options['sleep']) : 2;

echo "======================================================\n";
echo "WinGo Automated Sync & Settlement Engine Started\n";
echo "Mode: " . ($isDaemon ? "Continuous Loop (Every {$sleepSeconds}s)" : "One-shot Execution") . "\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "======================================================\n";

$pdo = DB::getConnection();
$syncService = new ResultSyncService($pdo);
$betService = new BetService($pdo);

// Fresh draws settle immediately, in the same cycle they arrive in.
$syncService->onNewResults(function (string $gameCode) use ($betService): void {
    $betService->settlePendingBets($gameCode);
});

do {
    $cycleStart = microtime(true);
    $nowStr = date('Y-m-d H:i:s');
    echo "[{$nowStr}] Running Sync Cycle...\n";

    try {
        $syncResults = $syncService->syncAll();
        foreach ($syncResults as $game => $res) {
            if (isset($res['error'])) {
                echo "  [-] {$game}: Error - {$res['error']}\n";
            } else {
                echo "  [+] {$game}: Fetched {$res['fetched']}, Saved {$res['saved']} new\n";
            }
        }

        $settlement = $betService->settlePendingBets();
        if ($settlement['settled_count'] > 0) {
            echo "  [*] Settled {$settlement['settled_count']} bets (Won: {$settlement['won_count']}, Payout: {$settlement['total_payout']})\n";
        } else {
            echo "  [*] No pending bets required settlement.\n";
        }
    } catch (Throwable $e) {
        echo "  [!] Exception: " . $e->getMessage() . "\n";
    }

    if ($isDaemon) {
        $elapsed = microtime(true) - $cycleStart;
        // Keep the cadence steady: subtract the time the cycle itself took.
        $remaining = $sleepSeconds - $elapsed;
        if ($remaining > 0) {
            usleep((int)round($remaining * 1000000));
        }
    }
} while ($isDaemon);

echo "[" . date('Y-m-d H:i:s') . "] Cycle Finished.\n";
