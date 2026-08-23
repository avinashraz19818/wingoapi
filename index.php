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

// 1. Universal In999 / 91Club / Daman WebAPI routes
// Matches /api/webapi/*, /webapi/*, /deepanshu/api/webapi/*
if (preg_match('#/(?:deepanshu/)?(?:api/)?webapi(?:/|$)#i', $uri)) {
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
        'version' => '3.0.0',
        'webapi_endpoints' => [
            '1. History Endpoint' => 'POST /api/webapi/GetNoaverageEmerdList  Payload: {"typeId": 1, "pageSize": 10}',
            '2. Timer & Period'   => 'POST /api/webapi/GetGameIssue           Payload: {"typeId": 1}',
            '3. Bet Place'        => 'POST /api/webapi/WinGoBet               Payload: {"typeId": 1, "selectType": "green", "amount": 10}',
            '4. Bet History'      => 'POST /api/webapi/GetMyEmerdList         Payload: {"typeId": 1, "userId": 1001}'
        ],
        'rest_endpoints' => [
            'GET /api/issue?game=WinGo_1M' => 'Current issue & timer',
            'GET /api/history?game=WinGo_1M&limit=50' => 'Draw results history',
            'POST /api/bet' => 'Place bet'
        ],
        'server_time' => date('Y-m-d H:i:s')
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
