<?php
/**
 * Test a draw provider URL before switching production over to it.
 *
 *   php tools/draw_probe.php WinGo_1M
 *   php tools/draw_probe.php WinGo_1M https://draw.example.com/WinGo/WinGo_1M.json
 *   php tools/draw_probe.php WinGo_1M --base=https://draw.example.com
 *
 * Shows the resolved endpoint, the HTTP result, how many rows we could index by
 * issue number, and whether the newest finished round parses into a result.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Support\Http;

$args     = array_slice($argv, 1);
$gameCode = '';
$url      = '';
$base     = '';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, 7);
    } elseif (str_starts_with($arg, 'http')) {
        $url = $arg;
    } elseif ($gameCode === '') {
        $gameCode = $arg;
    }
}

if ($gameCode === '') {
    fwrite(STDERR, "Usage: php tools/draw_probe.php <gameCode> [url|--base=https://host]\n");
    exit(1);
}

$app  = App::boot();
$game = $app->registry()->get($gameCode);

if ($url === '') {
    $template = (string) $app->config('draw_url_template');
    $baseUrl  = rtrim($base !== '' ? $base : (string) $app->config('draw_base_url'), '/');
    $url      = strtr($template, [
        '{base}' => $baseUrl, '{game}' => $game->family,
        '{interval}' => $game->code, '{family}' => $game->family, '{code}' => $game->code,
    ]);
}

$line = static fn(string $k, string $v) => printf("%-18s %s\n", $k . ':', $v);

$line('game', $game->code . ' (' . $game->family . ', ' . $game->intervalKey . ')');
$line('endpoint', $url);

$http = new Http((int) $app->config('draw_timeout'));
$res  = $http->getJson($url);

$line('http status', (string) $res['status']);
$line('elapsed', $res['elapsed_ms'] . ' ms');
if ($res['error'] !== null) {
    $line('transport error', $res['error']);
}

if (!$res['ok']) {
    echo "\nRESULT: provider unreachable — the local HMAC generator would be used.\n";
    exit(2);
}

$payload = json_decode($res['body'], true);
if (!is_array($payload)) {
    $line('body preview', substr($res['body'], 0, 200));
    echo "\nRESULT: response is not JSON.\n";
    exit(3);
}
$line('top-level keys', implode(', ', array_slice(array_keys($payload), 0, 8)));

$fetcher = $app->fetcher();
$rows    = $fetcher->fetchRows($game);

if ($rows === null || $rows === []) {
    echo "\nRESULT: JSON parsed but no rows could be indexed by issue number.\n";
    echo "Expected an array of objects each holding issueNumber (or issue/period) plus\n";
    echo "the drawn value (number / openCode / result / dice / digits / ranking).\n";
    echo "Sample of what we received:\n";
    echo substr(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0, 600) . "\n";
    exit(4);
}

$line('rows indexed', (string) count($rows));
$line('sample issues', implode(', ', array_slice(array_keys($rows), 0, 3)));

$issue  = $app->scheduler()->previous($game);
$parsed = $fetcher->fetchIssue($game, $issue->issueNumber);

$line('our last issue', $issue->issueNumber);

if ($parsed === null) {
    echo "\nRESULT: rows found, but our issue number is not among them.\n";
    echo "Provider issue format: " . (array_key_first($rows) ?: 'n/a') . "\n";
    echo "Ours (17 digits):      " . $issue->issueNumber . "\n";
    echo "If the formats differ, tell me the provider's format and I will map it.\n";
    exit(5);
}

echo "\nRESULT: OK — provider is usable.\n";
echo json_encode($parsed['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
