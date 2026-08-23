<?php
/**
 * WinGo Master API Configuration
 * Supports .env file parsing, environment variables, and fallback defaults.
 */

declare(strict_types=1);

// Parse .env if exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if (!isset($_ENV[$key]) && !getenv($key)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
            }
        }
    }
}

$tz = getenv('TIMEZONE') ?: 'Asia/Kolkata';
date_default_timezone_set($tz);

// Database Configuration
if (!defined('DB_TYPE')) define('DB_TYPE', getenv('DB_TYPE') ?: 'mysql');
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'club532583_in999');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'club532583_in999');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'club532583_in999');
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
if (!defined('SQLITE_FILE')) define('SQLITE_FILE', __DIR__ . '/data/wingo.sqlite');

// User Wallet Table (shonu_kaichila / users)
if (!defined('USER_TABLE')) define('USER_TABLE', getenv('USER_TABLE') ?: 'shonu_kaichila');
if (!defined('USER_ID_COL')) define('USER_ID_COL', getenv('USER_ID_COL') ?: 'balakedara');
if (!defined('USER_BAL_COL')) define('USER_BAL_COL', getenv('USER_BAL_COL') ?: 'motta');

// Platform Commission Rate
if (!defined('PLATFORM_FEE_RATE')) define('PLATFORM_FEE_RATE', (float)(getenv('PLATFORM_FEE_RATE') ?: 0.02));

// Game Definitions & External Sources
return [
    'domain' => getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site',
    'games' => [
        'WinGo_30S' => [
            'name' => 'WinGo 30 Seconds',
            'interval' => 30,
            'lock_seconds' => 5,
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json',
            'daily_issues' => 2880
        ],
        'WinGo_1M' => [
            'name' => 'WinGo 1 Minute',
            'interval' => 60,
            'lock_seconds' => 5,
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json',
            'daily_issues' => 1440
        ],
        'WinGo_3M' => [
            'name' => 'WinGo 3 Minutes',
            'interval' => 180,
            'lock_seconds' => 10,
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json',
            'daily_issues' => 480
        ],
        'WinGo_5M' => [
            'name' => 'WinGo 5 Minutes',
            'interval' => 300,
            'lock_seconds' => 15,
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json',
            'daily_issues' => 288
        ],
        'WinGo_10M' => [
            'name' => 'WinGo 10 Minutes',
            'interval' => 600,
            'lock_seconds' => 30,
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json',
            'daily_issues' => 144
        ]
    ],
    'odds' => [
        'number' => 9.0,
        'color_pure' => 2.0,
        'color_half' => 1.5,
        'color_violet' => 4.5,
        'big_small' => 2.0,
        'odd_even' => 2.0
    ]
];
