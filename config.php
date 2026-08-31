<?php
/**
 * Lottery SaaS API — central configuration.
 *
 * Values resolve in this order: real environment variable -> .env file -> default.
 * Never commit a real .env; see .env.example for the full list of supported keys.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Support/Env.php';

use Lottery\Support\Env;

Env::load(__DIR__ . '/.env');

date_default_timezone_set(Env::get('TIMEZONE', 'Asia/Kolkata'));

/* ---------------------------------------------------------------------------
 | Legacy constants (kept so pre-existing scripts in this repo keep working)
 * ------------------------------------------------------------------------ */
if (!defined('DB_TYPE'))    define('DB_TYPE', Env::get('DB_TYPE', 'mysql'));
if (!defined('DB_HOST'))    define('DB_HOST', Env::get('DB_HOST', '127.0.0.1'));
if (!defined('DB_PORT'))    define('DB_PORT', Env::get('DB_PORT', '3306'));
if (!defined('DB_NAME'))    define('DB_NAME', Env::get('DB_NAME', 'lottery'));
if (!defined('DB_USER'))    define('DB_USER', Env::get('DB_USER', 'lottery'));
if (!defined('DB_PASS'))    define('DB_PASS', Env::get('DB_PASS', ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', Env::get('DB_CHARSET', 'utf8mb4'));
if (!defined('SQLITE_FILE'))define('SQLITE_FILE', Env::get('SQLITE_FILE', __DIR__ . '/data/lottery.sqlite'));
if (!defined('PLATFORM_FEE_RATE')) define('PLATFORM_FEE_RATE', Env::float('PAYOUT_TAX_RATE', 0.02));

/**
 * Interval catalogue.
 *   code    -> suffix used in the game code (WinGo_1M)
 *   seconds -> round length
 *   issue   -> 2 digit interval code used inside the 17 digit issue number
 */
$intervals = [
    '30S' => ['seconds' => 30,  'issue_code' => '00', 'label' => '30 Seconds'],
    '1M'  => ['seconds' => 60,  'issue_code' => '01', 'label' => '1 Minute'],
    '3M'  => ['seconds' => 180, 'issue_code' => '03', 'label' => '3 Minutes'],
    '5M'  => ['seconds' => 300, 'issue_code' => '05', 'label' => '5 Minutes'],
    '10M' => ['seconds' => 600, 'issue_code' => '10', 'label' => '10 Minutes'],
];

/**
 * Every playable game = one {lottery family, interval} pair.
 * `sort` drives ordering in GetGameList.
 */
$games = [];
$families = [
    'WinGo'     => ['30S', '1M', '3M', '5M', '10M'],
    'TrxWinGo'  => ['1M', '3M', '5M', '10M'],
    'K3'        => ['1M', '3M', '5M', '10M'],
    'D5'        => ['1M', '3M', '5M', '10M'],
    'MotoRace'  => ['1M'],
];
$sort = 0;
foreach ($families as $family => $list) {
    foreach ($list as $interval) {
        $games[] = [
            'lottery'  => $family,
            'interval' => $interval,
            'sort'     => ++$sort,
            'state'    => 1,
        ];
    }
}

return [
    /* ---------------------------------------------------------- application */
    'app' => [
        'name'      => Env::get('APP_NAME', 'Lottery SaaS API'),
        'env'       => Env::get('APP_ENV', 'production'),
        'debug'     => Env::bool('APP_DEBUG', false),
        'timezone'  => Env::get('TIMEZONE', 'Asia/Kolkata'),
        'domain'    => Env::get('API_DOMAIN', 'api.example.com'),
        'version'   => '4.0.0',
    ],

    /* ------------------------------------------------------------- database */
    'database' => [
        'driver'      => Env::get('DB_TYPE', 'mysql'),      // mysql | sqlite
        'host'        => Env::get('DB_HOST', '127.0.0.1'),
        'port'        => (int) Env::get('DB_PORT', '3306'),
        'name'        => Env::get('DB_NAME', 'lottery'),
        'user'        => Env::get('DB_USER', 'lottery'),
        'pass'        => Env::get('DB_PASS', ''),
        'charset'     => Env::get('DB_CHARSET', 'utf8mb4'),
        'sqlite_file' => Env::get('SQLITE_FILE', __DIR__ . '/data/lottery.sqlite'),
        // Allow silent SQLite fallback when MySQL is unreachable (dev only).
        'allow_sqlite_fallback' => Env::bool('ALLOW_SQLITE_FALLBACK', false),
        'timeout'     => (int) Env::get('DB_TIMEOUT', '5'),
    ],

    /* ----------------------------------------------------------------- draw */
    // Provider endpoint template: {base}/{game}/{interval}.json
    'draw_base_url'     => rtrim(Env::get('DRAW_BASE_URL', 'https://draw.yourdomain.com'), '/'),
    'draw_url_template' => Env::get('DRAW_URL_TEMPLATE', '{base}/{game}/{interval}.json'),
    'draw_timeout'      => (int) Env::get('DRAW_TIMEOUT', '5'),
    // true  -> a round stays unresolved until the provider answers
    // false -> fall back to the local HMAC-SHA256 deterministic generator
    'force_remote_draw' => Env::bool('FORCE_REMOTE_DRAW', false),
    'draw_secret'       => Env::get('DRAW_SECRET', 'change-me-draw-secret'),

    /* ---------------------------------------------------------------- games */
    'intervals' => $intervals,
    'games'     => $games,

    /* -------------------------------------------------------------- betting */
    'betting' => [
        'min_stake'        => Env::float('BET_MIN_STAKE', 1.0),          // ₹1
        'max_stake'        => Env::float('BET_MAX_STAKE', 1000000.0),    // ₹10 lakh
        'payout_tax_rate'  => Env::float('PAYOUT_TAX_RATE', 0.02),       // 2%
        'multiples'        => [1, 2, 3, 5, 10, 20, 50, 100],
        'bet_scopes'       => [1, 10, 100, 1000],
        // Betting closes N seconds before the round ends.
        'lock_seconds'     => [
            '30S' => 5, '1M' => 5, '3M' => 10, '5M' => 15, '10M' => 30,
        ],
    ],

    /* ------------------------------------------------------------------ vip */
    'vip' => [
        'exp_per_rupee' => Env::float('VIP_EXP_PER_RUPEE', 1.0),
        'levels' => [
            0 => 0,
            1 => 3000,
            2 => 30000,
            3 => 400000,
            4 => 4000000,
            5 => 20000000,
        ],
    ],

    /* ----------------------------------------------------------------- auth */
    'auth' => [
        'jwt_secret'        => Env::get('JWT_SECRET', 'change-me-jwt-secret'),
        'jwt_ttl'           => (int) Env::get('JWT_TTL', '86400'),
        'jwt_leeway'        => (int) Env::get('JWT_LEEWAY', '30'),
        'signature_secret'  => Env::get('SIGNATURE_SECRET', 'change-me-signature-secret'),
        'require_signature' => Env::bool('REQUIRE_SIGNATURE', false),
        'signature_ttl'     => (int) Env::get('SIGNATURE_TTL', '300'),
    ],

    /* ------------------------------------------------------------- security */
    'security' => [
        'cors_origins'      => array_values(array_filter(array_map(
            'trim',
            explode(',', Env::get('CORS_ORIGINS', '*'))
        ))),
        'rate_limit'        => (int) Env::get('RATE_LIMIT_PER_MIN', '120'),
        'rate_limit_window' => 60,
        'rate_limit_store'  => Env::get('RATE_LIMIT_STORE', sys_get_temp_dir() . '/lottery-rl'),
        'trusted_proxies'   => array_values(array_filter(array_map(
            'trim',
            explode(',', Env::get('TRUSTED_PROXIES', ''))
        ))),
        'admin_token'       => Env::get('ADMIN_TOKEN', ''),
    ],

    /* ------------------------------------------------------- admin panel */
    'admin' => [
        'user'        => Env::get('ADMIN_USER', 'admin'),
        // Password for the web panel. Falls back to ADMIN_TOKEN when unset.
        'password'    => Env::get('ADMIN_PASSWORD', Env::get('ADMIN_TOKEN', '')),
        'session_ttl' => (int) Env::get('ADMIN_SESSION_TTL', '28800'),
        'enabled'     => Env::bool('ADMIN_PANEL_ENABLED', true),
    ],

    /* --------------------------------------------------------------- logging */
    'log' => [
        'path'  => Env::get('LOG_PATH', __DIR__ . '/data/app.log'),
        'level' => Env::get('LOG_LEVEL', 'info'),
    ],
];
