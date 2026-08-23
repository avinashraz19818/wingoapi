<?php
/**
 * WinGo Universal API Gateway & Drop-in Router
 * Compatible with in999, 91club, Daman, BDG, and ar-lottery01 clone scripts.
 * Host: api.devlopedwithzayro.site
 */

declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/api/common.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// 1. Direct drop-in support for: /WinGo/{GameCode}/GetHistoryIssuePage.json
if (preg_match('#/WinGo/([^/]+)/GetHistoryIssuePage\.json#i', $uri, $m)) {
    $_GET['game'] = $m[1];
    require __DIR__ . '/api/get_history.php';
    exit;
}

// 2. Direct drop-in support for: /WinGo/{GameCode}/GetNoaverageEmerdList.json
if (preg_match('#/WinGo/([^/]+)/GetNoaverageEmerdList\.json#i', $uri, $m)) {
    $_GET['game'] = $m[1];
    require __DIR__ . '/api/get_issue.php';
    exit;
}

// 3. Interactive Visual Frontend Game & Dashboard
if ($uri === '/app' || $uri === '/game' || $uri === '/play' || $uri === '/index.html' || $uri === '/demo') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// 4. Root / Health Check & API Docs
if ($uri === '' || $uri === '/' || $uri === '/health' || $uri === '/api/health') {
    jsonSuccess([
        'system' => 'WinGo Automated Lottery & Betting API Engine',
        'domain' => getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site',
        'status' => 'ONLINE',
        'version' => '2.5.0',
        'frontend_app' => 'https://' . (getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site') . '/index.html',
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'dropin_endpoints' => [
            'https://api.devlopedwithzayro.site/WinGo/WinGo_1M/GetHistoryIssuePage.json' => 'Exact drop-in replacement for draw.ar-lottery01.com',
            'https://api.devlopedwithzayro.site/WinGo/WinGo_30S/GetHistoryIssuePage.json' => 'WinGo 30S drop-in replacement'
        ],
        'standard_endpoints' => [
            'GET  /api/issue?game=WinGo_1M' => 'Current issue, countdown seconds, and lock state',
            'GET  /api/history?game=WinGo_1M&limit=50' => 'Historical draw results list',
            'POST /api/bet' => 'Place user bet atomically with balance check',
            'GET  /api/user-bets?user_id=1001' => 'User bet history with win/loss/payout',
            'GET  /api/sync' => 'Sync draws from external provider (ar-lottery01) & auto-settle',
            'GET  /api/settle' => 'Trigger manual bet settlement for completed draws',
            'GET  /api/wallet?user_id=1001' => 'User balance check',
            'POST /api/wallet' => 'Deposit/recharge balance'
        ],
        'supported_games' => ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M']
    ], 'WinGo API is operational');
}

// 5. Clean Route Mapping
$routes = [
    '/api/issue' => __DIR__ . '/api/get_issue.php',
    '/api/get_issue' => __DIR__ . '/api/get_issue.php',
    '/api/history' => __DIR__ . '/api/get_history.php',
    '/api/get_history' => __DIR__ . '/api/get_history.php',
    '/api/bet' => __DIR__ . '/api/place_bet.php',
    '/api/place_bet' => __DIR__ . '/api/place_bet.php',
    '/api/user-bets' => __DIR__ . '/api/get_user_bets.php',
    '/api/get_user_bets' => __DIR__ . '/api/get_user_bets.php',
    '/api/sync' => __DIR__ . '/api/sync.php',
    '/api/settle' => __DIR__ . '/api/settle.php',
    '/api/wallet' => __DIR__ . '/api/wallet.php',
    '/api/test_api' => __DIR__ . '/api/test_api.php',
    '/api/webapi/GetHistoryIssuePage' => __DIR__ . '/api/get_history.php',
    '/api/webapi/GetNoaverageEmerdList' => __DIR__ . '/api/get_issue.php'
];

if (isset($routes[$uri]) && file_exists($routes[$uri])) {
    require $routes[$uri];
    exit;
}

// Direct php file execution
$directFile = __DIR__ . $uri;
if (file_exists($directFile) && is_file($directFile)) {
    require $directFile;
    exit;
}

// 404 Not Found
jsonError("Endpoint not found: {$uri}. Check / for full documentation.", 404, 404);
