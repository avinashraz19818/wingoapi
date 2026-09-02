<?php
/**
 * Probe a draw provider before switching production over to it.
 *
 *   php tools/draw_probe.php WinGo_1M
 *   php tools/draw_probe.php K3_1M --base=https://draw.ar-lottery01.com
 *   php tools/draw_probe.php WinGo_1M --url=https://host/path.json
 *   php tools/draw_probe.php --all           probe every game (summary only)
 *
 * Every candidate URL shape of the active profile is tried. For each one the
 * script reports the HTTP result, how many rows could be indexed, a sample raw
 * row, and whether OUR issue number for the newest finished round is present —
 * which is exactly what the mirror needs to line up.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Games\GameDefinition;
use Lottery\Support\Http;

$args     = array_slice($argv, 1);
$gameCode = '';
$base     = '';
$url      = '';
$all      = false;

foreach ($args as $arg) {
    if ($arg === '--all') {
        $all = true;
    } elseif (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    } elseif (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    } elseif (str_starts_with($arg, 'http')) {
        $url = $arg;
    } elseif ($gameCode === '') {
        $gameCode = $arg;
    }
}

if ($gameCode === '' && !$all) {
    fwrite(STDERR, "Usage: php tools/draw_probe.php <gameCode> [--base=URL|--url=URL] | --all\n");
    exit(1);
}

$app  = App::boot();
$http = new Http(
    (int) $app->config('draw_timeout'),
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    (array) $app->config('draw_headers', []),
    (bool) $app->config('draw_verify_ssl', true)
);

$baseUrl   = $base !== '' ? $base : rtrim((string) $app->config('draw_base_url'), '/');
$templates = $url !== '' ? ['{url}'] : (array) $app->config('draw_url_templates', ['{base}/{game}/{interval}.json']);
$families  = (array) $app->config('draw_family_names', []);
$rules     = $app->rules();
$scheduler = $app->scheduler();

$line = static fn(string $k, string $v) => printf("  %-16s %s\n", $k . ':', $v);

/** @return array{ok:bool,rows:int,matched:bool} */
function probeGame(
    GameDefinition $game,
    array $templates,
    string $baseUrl,
    string $explicitUrl,
    array $families,
    Http $http,
    $rules,
    $scheduler,
    bool $verbose
): array {
    $family = $families[$game->family] ?? $game->family;
    $code   = $family === $game->family ? $game->code : $family . '_' . $game->intervalKey;
    $issue  = $scheduler->previous($game);

    if ($verbose) {
        printf("\n\033[1m%s\033[0m  (%s %s)\n", $game->code, $game->family, $game->intervalKey);
        printf("  %-16s %s\n", 'our issue:', $issue->issueNumber . '  (prefix ' . $game->familyCode . $game->intervalCode . ')');
    }

    $best = ['ok' => false, 'rows' => 0, 'matched' => false];

    foreach ($templates as $template) {
        $url = $explicitUrl !== '' ? $explicitUrl : strtr($template, [
            '{base}' => $baseUrl, '{game}' => $family, '{family}' => $family,
            '{interval}' => $code, '{code}' => $code, '{intervalKey}' => $game->intervalKey,
        ]);

        $res = $http->getJson($url);

        if ($verbose) {
            printf("\n  \033[36m%s\033[0m\n", $url);
            printf("  %-16s %s\n", 'http:', $res['status'] . ' in ' . $res['elapsed_ms'] . ' ms'
                . ($res['error'] !== null ? ' — ' . $res['error'] : ''));
        }
        if (!$res['ok']) {
            continue;
        }

        $payload = json_decode($res['body'], true);
        if (!is_array($payload)) {
            if ($verbose) {
                printf("  %-16s %s\n", 'body:', 'not JSON — ' . substr($res['body'], 0, 120));
            }
            continue;
        }

        // Reuse the production extractor/indexer.
        $fetcher = new \Lottery\Draw\DrawFetcher($http, $rules, $baseUrl, [$template], true, 0, $families);
        $rows    = $fetcher->fetchRows($game);
        $count   = $rows === null ? 0 : count(array_filter(array_keys($rows), static fn($k) => !str_contains((string) $k, '#')));

        if ($verbose) {
            printf("  %-16s %s\n", 'keys:', implode(', ', array_slice(array_keys($payload), 0, 8)));
            printf("  %-16s %d\n", 'rows indexed:', $count);
        }

        if ($rows === null || $rows === []) {
            if ($verbose) {
                echo "  sample payload:\n";
                echo '    ' . substr(json_encode($payload, JSON_UNESCAPED_SLASHES), 0, 400) . "\n";
            }
            continue;
        }

        $sampleIssue = null;
        foreach (array_keys($rows) as $key) {
            if (!str_contains((string) $key, '#')) {
                $sampleIssue = (string) $key;
                break;
            }
        }

        if ($verbose && $sampleIssue !== null) {
            printf("  %-16s %s\n", 'their issue:', $sampleIssue . '  (prefix ' . substr($sampleIssue, 8, 5) . ')');
            echo "  sample row:\n    " . json_encode($rows[$sampleIssue], JSON_UNESCAPED_SLASHES) . "\n";
        }

        $parsed  = $fetcher->fetchIssue($game, $issue->issueNumber);
        $matched = $parsed !== null;

        if ($verbose) {
            if ($matched) {
                echo "  \033[32mMATCH\033[0m parsed result: "
                    . json_encode(array_diff_key($parsed['result'], ['verify' => 1]), JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                echo "  \033[33mrows found but our issue number is not in them\033[0m\n";
                echo "    ours:  {$issue->issueNumber}\n";
                echo "    their: " . ($sampleIssue ?? 'n/a') . "\n";
            }
        }

        $best = ['ok' => true, 'rows' => $count, 'matched' => $matched];
        if ($matched) {
            return $best;
        }
    }

    return $best;
}

$games = $all ? $app->registry()->all() : [$app->registry()->get($gameCode)];

printf("profile: %s   base: %s   issue timezone: %s\n",
    (string) $app->config('draw_profile'),
    $baseUrl,
    (string) $app->config('issue_timezone') ?: (string) $app->config('app.timezone'));

$summary = [];
foreach ($games as $game) {
    $result = probeGame($game, $templates, $baseUrl, $url, $families, $http, $rules, $scheduler, !$all);
    $summary[$game->code] = $result;
}

if ($all) {
    echo "\n";
    printf("%-16s %-10s %-8s %s\n", 'GAME', 'REACHABLE', 'ROWS', 'ISSUE MATCH');
    foreach ($summary as $code => $r) {
        printf("%-16s %-10s %-8d %s\n", $code, $r['ok'] ? 'yes' : 'no', $r['rows'],
            $r['matched'] ? "\033[32myes\033[0m" : "\033[33mno\033[0m");
    }
}

$matched = count(array_filter($summary, static fn(array $r): bool => $r['matched']));
echo "\n" . ($matched > 0
    ? "RESULT: {$matched}/" . count($summary) . " game(s) mirror correctly.\n"
    : "RESULT: no game matched — send this output and the mapping will be adjusted.\n");

exit($matched > 0 ? 0 : 2);
