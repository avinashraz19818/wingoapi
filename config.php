<?php
/**
 * WinGo Lottery System - Master Configuration File
 * Supports MySQL / MariaDB (phpMyAdmin / cPanel / VPS) and SQLite (Local testing)
 */

declare(strict_types=1);

// Set timezone (adjust as needed: 'Asia/Kolkata', 'UTC', 'Asia/Shanghai')
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Kolkata');
}

// Database Configuration
if (!defined('DB_TYPE')) define('DB_TYPE', getenv('DB_TYPE') ?: 'sqlite'); // Change to 'mysql' in production
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'club532583_in999');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'club532583_in999');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'club532583_in999');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('SQLITE_FILE')) define('SQLITE_FILE', __DIR__ . '/data/wingo.sqlite');

// User balance table configuration (supports custom table names)
if (!defined('USER_TABLE')) define('USER_TABLE', 'shonu_kaichila'); // in999 / TC Lottery default: shonu_kaichila or users
if (!defined('USER_ID_COL')) define('USER_ID_COL', 'balakedara');    // in999 default: balakedara or id
if (!defined('USER_BAL_COL')) define('USER_BAL_COL', 'motta');        // in999 default: motta or balance

// Platform Commission / Service Fee (e.g. 0.02 = 2% fee, 0.00 = 0% fee)
if (!defined('PLATFORM_FEE_RATE')) define('PLATFORM_FEE_RATE', 0.02);

// Game Definitions & External Sources
return [
    'games' => [
        'WinGo_30S' => [
            'name' => 'WinGo 30 Seconds',
            'interval' => 30,
            'lock_seconds' => 5, // Lock betting 5s before draw
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json',
            'daily_issues' => 2880,
            'issue_prefix' => ''
        ],
        'WinGo_1M' => [
            'name' => 'WinGo 1 Minute',
            'interval' => 60,
            'lock_seconds' => 5, // Lock betting 5s before draw
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json',
            'daily_issues' => 1440,
            'issue_prefix' => ''
        ],
        'WinGo_3M' => [
            'name' => 'WinGo 3 Minutes',
            'interval' => 180,
            'lock_seconds' => 10, // Lock betting 10s before draw
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json',
            'daily_issues' => 480,
            'issue_prefix' => ''
        ],
        'WinGo_5M' => [
            'name' => 'WinGo 5 Minutes',
            'interval' => 300,
            'lock_seconds' => 15, // Lock betting 15s before draw
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json',
            'daily_issues' => 288,
            'issue_prefix' => ''
        ],
        'WinGo_10M' => [
            'name' => 'WinGo 10 Minutes',
            'interval' => 600,
            'lock_seconds' => 30, // Lock betting 30s before draw
            'external_url' => 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json',
            'daily_issues' => 144,
            'issue_prefix' => ''
        ]
    ],
    // WinGo Odds Multiplier Table
    'odds' => [
        'number' => 9.0,         // Exact number match (0-9): 9x
        'color_pure' => 2.0,     // Pure Green (1,3,7,9) or Pure Red (2,4,6,8): 2x
        'color_half' => 1.5,     // Green on 5 or Red on 0: 1.5x (Half-win violet mix)
        'color_violet' => 4.5,   // Violet (0 or 5): 4.5x
        'big_small' => 2.0,      // Big (5-9) or Small (0-4): 2x
        'odd_even' => 2.0        // Odd (1,3,5,7,9) or Even (0,2,4,6,8): 2x
    ]
];
