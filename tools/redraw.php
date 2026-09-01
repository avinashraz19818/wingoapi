<?php
/**
 * Re-draw rounds that were resolved locally while the upstream provider was
 * not yet configured (or had not published in time).
 *
 *   php tools/redraw.php --dry-run              show what would change
 *   php tools/redraw.php --hours=6              rewrite the last 6 hours
 *   php tools/redraw.php --game=WinGo_30S
 *
 * Rounds that already have bets are never touched: results players have been
 * paid against stay exactly as they were.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Database\Tables;
use Lottery\Support\Clock;

$options = getopt('', ['dry-run', 'hours::', 'game::', 'yes']);
$dry     = isset($options['dry-run']);
$hours   = isset($options['hours']) ? max(1, (int) $options['hours']) : 24;
$only    = isset($options['game']) ? (string) $options['game'] : '';

$app = App::boot();
$app->bootstrapDatabase();

$since   = date('Y-m-d H:i:s', Clock::now() - $hours * 3600);
$fetcher = $app->fetcher();

if (!$fetcher->enabled()) {
    fwrite(STDERR, "No upstream provider is configured (DRAW_PROFILE / DRAW_BASE_URL).\n");
    exit(1);
}

printf("Provider: %s   window: last %dh\n\n", (string) $app->config('draw_base_url'), $hours);
printf("%-14s %-20s %-10s %s\n", 'GAME', 'ISSUE', 'LOCAL', 'UPSTREAM');

$replaced = 0;
$kept     = 0;
$missing  = 0;

foreach ($app->registry()->all() as $game) {
    if ($only !== '' && strcasecmp($only, $game->code) !== 0) {
        continue;
    }
    if (!$fetcher->servesGame($game)) {
        continue;
    }

    $rows = $app->db()->fetchAll(
        'SELECT r.* FROM ' . Tables::RESULTS . ' r
          WHERE r.game_code = ? AND r.source = ? AND r.drawn_at >= ?
            AND NOT EXISTS (SELECT 1 FROM ' . Tables::BETS . ' b
                             WHERE b.game_code = r.game_code AND b.issue_number = r.issue_number)
          ORDER BY r.issue_number DESC',
        [$game->code, 'local', $since]
    );

    foreach ($rows as $row) {
        $remote = $fetcher->fetchIssue($game, (string) $row['issue_number']);
        if ($remote === null) {
            $missing++;
            continue;
        }

        $rules   = $app->rules()->forGame($game);
        $summary = $rules->summary($remote['result']);

        if ((string) $summary['primary_number'] === (string) $row['primary_number']
            && (string) $summary['color'] === (string) $row['color']) {
            $kept++;
            continue;
        }

        printf(
            "%-14s %-20s %-10s %s%s\n",
            $game->code,
            $row['issue_number'],
            $row['primary_number'] . ($row['color'] ? '/' . $row['color'] : ''),
            $summary['primary_number'] . ($summary['color'] ? '/' . $summary['color'] : ''),
            $dry ? '  (dry run)' : ''
        );

        if (!$dry) {
            $app->db()->execute(
                'UPDATE ' . Tables::RESULTS . '
                    SET result_json = ?, primary_number = ?, color = ?, sum_value = ?, source = ?, draw_hash = ?
                  WHERE id = ?',
                [
                    json_encode($remote['result'], JSON_UNESCAPED_SLASHES),
                    $summary['primary_number'],
                    $summary['color'],
                    $summary['sum_value'],
                    'remote',
                    $remote['hash'],
                    $row['id'],
                ]
            );
        }
        $replaced++;
    }

    $fetcher->flush();
}

printf(
    "\n%s %d round(s); %d already matched, %d not published upstream.\n",
    $dry ? 'Would replace' : 'Replaced',
    $replaced,
    $kept,
    $missing
);
