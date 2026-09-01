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
 * Draw provider profiles.
 *
 * A profile bundles everything a provider needs: URL shapes, the family name it
 * uses in the path, the HTTP headers it expects, and the 5-digit issue prefix
 * per game so our issue numbers line up 1:1 with theirs.
 */
$drawProfiles = [
    'generic' => [
        'base'      => 'https://draw.yourdomain.com',
        'templates' => ['{base}/{game}/{interval}.json'],
        'families'  => [],
        'headers'   => [],
        'prefixes'  => [],
        // Families the provider actually serves (empty = try them all).
        'supports'  => [],
        // Day boundary used for the issue sequence (empty = app timezone).
        'issue_tz'  => '',
    ],

    // https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json
    'ar-lottery01' => [
        'base'      => 'https://draw.ar-lottery01.com',
        'templates' => [
            '{base}/{family}/{code}/GetHistoryIssuePage.json',
            '{base}/{family}/{code}/GetNoaverageEmerdList.json',
        ],
        'families'  => [],
        // They publish these four; MotoRace is drawn locally.
        'supports'  => ['WinGo', 'TrxWinGo', 'K3', 'D5'],
        // Their sequence restarts at 00:00 UTC (05:30 IST), not local midnight.
        'issue_tz'  => 'UTC',
        'headers'   => [
            'Referer: https://ar-lottery01.com/',
            'Origin: https://ar-lottery01.com',
            'Accept-Language: en-US,en;q=0.9',
        ],
        // Their 17-digit format is YYYYMMDD(UTC) + <5 digit game prefix> + <4 digit seq>.
        // Confirmed live: WinGo 10001, K3 10101, 5D 10201, TrxWinGo 10301 (1M).
        'prefixes'  => [
            'WinGo_1M'     => '10001',
            'WinGo_3M'     => '10002',
            'WinGo_30S'    => '10003',
            'WinGo_5M'     => '10004',
            'WinGo_10M'    => '10005',
            'K3_1M'        => '10101',
            'K3_3M'        => '10102',
            'K3_5M'        => '10104',
            'K3_10M'       => '10105',
            'D5_1M'        => '10201',
            'D5_3M'        => '10202',
            'D5_5M'        => '10204',
            'D5_10M'       => '10205',
            'TrxWinGo_1M'  => '10301',
            'TrxWinGo_3M'  => '10302',
            'TrxWinGo_5M'  => '10304',
            'TrxWinGo_10M' => '10305',
        ],
    ],
];

$profileName = Env::get('DRAW_PROFILE', 'generic');
$profile     = $drawProfiles[$profileName] ?? $drawProfiles['generic'];

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
    'MotoRace'  => ['1M', '3M', '5M', '10M'],
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
    'draw_profile'      => $profileName,
    'draw_base_url'     => rtrim(Env::get('DRAW_BASE_URL', (string) $profile['base']), '/'),
    'draw_url_template' => Env::get('DRAW_URL_TEMPLATE', (string) $profile['templates'][0]),
    // Extra URL shapes tried (in order) until one returns usable rows.
    'draw_url_templates'=> $profile['templates'],
    // Family name as it appears in the provider path (e.g. D5 -> 5D).
    'draw_family_names' => $profile['families'],
    'draw_supported_families' => $profile['supports'] ?? [],
    'draw_headers'      => $profile['headers'],
    // Timezone whose midnight starts the issue sequence (upstream parity).
    'issue_timezone'    => Env::get('ISSUE_TIMEZONE', (string) ($profile['issue_tz'] ?? '')),
    'draw_verify_ssl'   => Env::bool('DRAW_VERIFY_SSL', true),
    // Adopt the provider's 5-digit game prefix so our issue numbers match theirs.
    'issue_prefixes'    => Env::bool('DRAW_ADOPT_ISSUE_PREFIXES', true) ? $profile['prefixes'] : [],
    'draw_timeout'      => (int) Env::get('DRAW_TIMEOUT', '5'),
    // Set DRAW_ENABLED=false (or leave DRAW_BASE_URL as the sample host) to run
    // purely on the local provably-fair generator.
    'draw_enabled'      => Env::bool('DRAW_ENABLED', true),
    // Seconds to skip a provider endpoint after it fails, so an outage cannot
    // flood the log or slow the worker down.
    'draw_failure_cooldown' => (int) Env::get('DRAW_FAILURE_COOLDOWN', '60'),
    // Seconds to wait for the provider to publish a freshly finished round
    // before falling back to the local generator. Too small and every round is
    // drawn locally a second after it ends; too large and payouts are delayed.
    'draw_fallback_delay'   => (int) Env::get('DRAW_FALLBACK_DELAY', '25'),
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
        // Credited once when a player registers (0 = off).
        'signup_bonus'      => Env::float('SIGNUP_BONUS', 0.0),
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

    /* ------------------------------------------- public result feed (SaaS) */
    'feed' => [
        // Requests/minute per whitelisted domain (per-domain override wins).
        'rate_limit'   => (int) Env::get('FEED_RATE_LIMIT', '600'),
        // Public results board at /results
        'board_enabled'=> Env::bool('FEED_BOARD_ENABLED', true),
        'brand'        => Env::get('FEED_BRAND', 'Lottery Results'),
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
