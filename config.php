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

/**
 * Period Offset.
 *
 *  0  = LIVE / exact (DEFAULT). The period number served to the client is the period that is
 *       actually running right now on the draw provider, so its result lands the instant the
 *       countdown hits 00 and history / bet popups update with zero delay.
 * -1  = legacy behaviour (period number lags one period behind the provider). This is what
 *       caused the ~5s stale history / late bet popup, because the period shown as "open"
 *       already had its result published upstream.
 * +1  = one period ahead.
 */
if (!defined('ISSUE_OFFSET')) {
    $rawOffset = getenv('ISSUE_OFFSET');
    define('ISSUE_OFFSET', ($rawOffset === false || $rawOffset === '') ? 0 : (int)$rawOffset);
}

// ---- Upstream (draw provider) fetch tuning -------------------------------
// Kept deliberately short: these calls run inside live client requests as well as the worker,
// so a slow provider must never stall a player's countdown / result popup.
if (!defined('UPSTREAM_TIMEOUT')) define('UPSTREAM_TIMEOUT', (float)(getenv('UPSTREAM_TIMEOUT') ?: 3.0));
if (!defined('UPSTREAM_CONNECT_TIMEOUT')) define('UPSTREAM_CONNECT_TIMEOUT', (float)(getenv('UPSTREAM_CONNECT_TIMEOUT') ?: 2.0));

// ---- Zero-delay ("live pull") settings -----------------------------------
// When a period has just closed and its result is not in the DB yet, the very next client
// request pulls it from the provider immediately instead of waiting for the next cron cycle.
if (!defined('LIVE_PULL_ENABLED')) define('LIVE_PULL_ENABLED', (getenv('LIVE_PULL_ENABLED') ?? '1') !== '0');
// Minimum seconds between two on-demand upstream pulls for the same game (anti-hammer).
if (!defined('LIVE_PULL_MIN_GAP')) define('LIVE_PULL_MIN_GAP', (float)(getenv('LIVE_PULL_MIN_GAP') ?: 0.8));
// Only auto-pull during the first N seconds of a new period; after that the background
// worker owns the refresh, so a slow provider can never stall mid-period polls.
if (!defined('LIVE_PULL_WINDOW')) define('LIVE_PULL_WINDOW', (float)(getenv('LIVE_PULL_WINDOW') ?: 10.0));
// Max seconds a client request may wait for an in-flight pull before answering from the DB.
if (!defined('LIVE_PULL_MAX_WAIT')) define('LIVE_PULL_MAX_WAIT', (float)(getenv('LIVE_PULL_MAX_WAIT') ?: 2.5));

/**
 * Fallback simulator: when the provider is unreachable the sync engine can invent deterministic
 * results so the game never stalls. Those numbers are FAKE and would settle real bets, so this
 * is OFF by default. Set UPSTREAM_FALLBACK=1 only if you understand that trade-off.
 */
if (!defined('UPSTREAM_FALLBACK')) define('UPSTREAM_FALLBACK', (getenv('UPSTREAM_FALLBACK') ?? '0') === '1');

// Game Definitions & External Sources
return [
    'domain' => getenv('API_DOMAIN') ?: 'api.devlopedwithzayro.site',
    'issue_offset' => ISSUE_OFFSET,
    // Period timing.
    'period' => [
        // The provider hands over each result this many seconds BEFORE its own minute ends
        // (it publishes 841 while 840 is still counting down). We shift our countdown by the
        // same amount, so our rollover lands that many seconds ahead of the plain minute and
        // the next result is always already stored when the timer hits 00.
        // WinGo_1M with 2 => our periods tick at :58. Set RESULT_LEAD_SECONDS=0 to tick on
        // the plain minute instead.
        'result_lead_seconds' => (int)(getenv('RESULT_LEAD_SECONDS') !== false
            ? getenv('RESULT_LEAD_SECONDS') : 2),
    ],
    'live_pull' => [
        'enabled'        => LIVE_PULL_ENABLED,
        'min_gap'        => LIVE_PULL_MIN_GAP,
        'window'         => LIVE_PULL_WINDOW,
        'max_wait'       => LIVE_PULL_MAX_WAIT,
        'timeout'        => UPSTREAM_TIMEOUT,
        'connect_timeout'=> UPSTREAM_CONNECT_TIMEOUT,
        'allow_fallback' => UPSTREAM_FALLBACK,
    ],
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
