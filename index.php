<?php
/**
 * WinGo Universal API Gateway & Router
 * Host: api.devlopedwithzayro.site
 */

declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/api/common.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// 1. Standard 91Club / in999 / Daman / BDG WebAPI endpoints: /api/webapi/*
if (str_starts_with($uri, '/api/webapi')) {
    require __DIR__ . '/api/webapi_controller.php';
    exit;
}

// 2. Direct drop-in support for: /WinGo/{GameCode}/GetHistoryIssuePage.json
if (preg_match('#/WinGo/([^/]+)/GetHistoryIssuePage\.json#i', $uri, $m)) {
    $_GET['game'] = $m[1];
    require __DIR__ . '/api/get_history.php';
    exit;
}

// 3. Direct drop-in support for: /WinGo/{GameCode}/GetNoaverageEmerdList.json
if (preg_match('#/WinGo/([^/]+)/GetNoaverageEmerdList\.json#i', $uri, $m)) {
    $_GET['game'] = $m[1];
    require __DIR__ . '/api/get_issue.php';
    exit;
}

// 4. Interactive Visual Frontend Game & Dashboard
if ($uri === '/app' || $uri === '/game' || $uri === '/play' || $uri === '/index.html' || $uri === '/demo') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// 5. Root / Health Check & API Docs
if ($uri === '' || $uri === '/' || $uri === '/health' || $uri === '/api/health') {
    jsonSuccess([
        'system' => 'WinGo Automated Lottery & Betting API Engine',
        'domain' => getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site',
        'status' => 'ONLINE',
        'version' => '2.6.0',
        'frontend_app' => 'https://' . (getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site') . '/index.html',
        'server_time' => date('Y-m-d H:i:s'),
        'webapi_endpoints' => [
            'POST/GET https://api.devlopedwithzayro.site/api/webapi/GetNoaverageEmerdList' => 'History list (91club / in999 standard)',
            'POST/GET https://api.devlopedwithzayro.site/api/webapi/GetGameIssue' => 'Active period & countdown',
            'POST     https://api.devlopedwithzayro.site/api/webapi/WinGoBet' => 'Place user bet',
            'POST/GET https://api.devlopedwithzayro.site/api/webapi/GetMyEmerdList' => 'User bet history'
        ],
        'rest_endpoints' => [
            'GET  /api/issue?game=WinGo_1M' => 'Current issue & timer',
            'GET  /api/history?game=WinGo_1M&limit=50' => 'Draw results history',
            'POST /api/bet' => 'Place bet'
        ]
    ], 'WinGo API is operational');
}

// 6. Clean Route Mapping
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
    '/api/test_api' => __DIR__ . '/api/test_api.php'
];

if (isset($routes[$uri]) && file_exists($routes[$uri])) {
    require $routes[$uri];
    exit;
}

// Direct file check
$directFile = __DIR__ . $uri;
if (file_exists($directFile) && is_file($directFile)) {
    require $directFile;
    exit;
}

// 404 Not Found
jsonError("Endpoint not found: {$uri}. Check / for full documentation.", 404, 404);
