<?php
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    api_headers();
    exit;
}

$path = trim((string) ($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");
$gameCode = (string) ($_GET['gameCode'] ?? '');

if ($path !== '') {
    $path = str_replace('\\', '/', urldecode($path));
    $path = preg_replace('#\.\./#', '', $path);
    if (preg_match('#^([^/]+)/([^/]+)/GetHistoryIssuePage\.json$#i', $path, $m)) {
        if (function_exists('lottery_upstream_draw')) {
            $upstream = lottery_upstream_draw($m[2], 'GetHistoryIssuePage', $_GET);
            if ($upstream !== null) {
                api_emit($upstream);
            }
        }
        $payload = api_draw_file_payload($m[1], $m[2]);
        api_emit($payload ?: api_lottery_history_payload($m[2], ['params' => ['lotteryCode' => $m[1], 'gameCode' => $m[2]]]));
    }
    if (preg_match('#^([^/]+)/([^/]+)\.json$#i', $path, $m)) {
        if (function_exists('lottery_upstream_draw')) {
            $upstream = lottery_upstream_draw($m[2], 'GetGameIssue');
            if ($upstream !== null) {
                api_emit($upstream);
            }
        }
        api_emit(api_lottery_issue_payload($m[2]));
    }
}

if ($gameCode === '') {
    $gameCode = 'WinGo_30S';
}

if (function_exists('lottery_upstream_draw')) {
    $upstream = lottery_upstream_draw($gameCode, 'GetGameIssue');
    if ($upstream !== null) {
        api_emit($upstream);
    }
}

api_emit(api_lottery_issue_payload($gameCode));
