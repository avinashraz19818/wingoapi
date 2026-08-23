<?php
/**
 * WinGo API Gateway & Dynamic Router
 * Host: api.devlopedwithzayro.site
 */

declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/api/common.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// 1. Root / Health Check
if ($uri === '' || $uri === '/' || $uri === '/health' || $uri === '/api/health') {
    jsonSuccess([
        'system' => 'WinGo Automated Lottery & Betting API Engine',
        'domain' => getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site',
        'status' => 'ONLINE',
        'version' => '2.4.0',
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'endpoints' => [
            'GET  /api/issue?game=WinGo_1M' => 'Current issue, countdown seconds, and lock state',
            'GET  /api/history?game=WinGo_1M&limit=50' => 'Historical draw results list',
            'POST /api/bet' => 'Place user bet atomically with balance check',
            'GET  /api/user-bets?user_id=1001' => 'User bet history with win/loss/payout',
            'GET  /api/sync' => 'Sync draws from external provider (ar-lottery01) & auto-settle',
            'GET  /api/settle' => 'Trigger manual bet settlement for completed draws',
            'GET  /api/wallet?user_id=1001' => 'User balance check',
            'POST /api/wallet' => 'Deposit/recharge balance',
            'GET  /api/test_api' => 'Full diagnostic & mathematical check'
        ],
        'supported_games' => ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M']
    ], 'WinGo API is operational');
}

// 2. Clean Route Mapping
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
    '/api/docs' => __DIR__ . '/api/test_api.php'
];

if (isset($routes[$uri]) && file_exists($routes[$uri])) {
    require $routes[$uri];
    exit;
}

// If file exists under /api/ directly (e.g. /api/get_issue.php)
$directFile = __DIR__ . $uri;
if (file_exists($directFile) && is_file($directFile)) {
    require $directFile;
    exit;
}

// 404 Not Found
jsonError("Endpoint not found: {$uri}. Check / for full documentation.", 404, 404);
