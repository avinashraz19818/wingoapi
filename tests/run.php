<?php
/**
 * Minimal zero-dependency test harness.
 *
 *   php tests/run.php
 *
 * Tests run against an isolated SQLite database so they exercise the same
 * migrations, services and SQL as production.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Support\Clock;

final class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    /** @var array<int,string> */
    private static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32mPASS\033[0m {$name}\n";
            return;
        }
        self::$failed++;
        self::$failures[] = self::$group . ' :: ' . $name . ($detail !== '' ? ' — ' . $detail : '');
        echo "  \033[31mFAIL\033[0m {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }

    /** @param mixed $expected @param mixed $actual */
    public static function equals(string $name, $expected, $actual): void
    {
        self::ok(
            $name,
            $expected === $actual,
            $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public static function nearly(string $name, float $expected, float $actual, float $epsilon = 0.005): void
    {
        self::ok($name, abs($expected - $actual) <= $epsilon, "expected {$expected}, got {$actual}");
    }

    public static function throws(string $name, callable $callback, ?string $contains = null): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($contains !== null && stripos($e->getMessage(), $contains) === false) {
                self::ok($name, false, "message '{$e->getMessage()}' does not contain '{$contains}'");
                return;
            }
            self::ok($name, true);
            return;
        }
        self::ok($name, false, 'no exception thrown');
    }

    public static function summary(): int
    {
        $total = self::$passed + self::$failed;
        echo "\n" . str_repeat('-', 60) . "\n";
        echo sprintf("%d/%d passed, %d failed\n", self::$passed, $total, self::$failed);
        foreach (self::$failures as $failure) {
            echo "  \033[31m*\033[0m {$failure}\n";
        }

        return self::$failed === 0 ? 0 : 1;
    }
}

/** Fresh app instance backed by a throwaway SQLite file. */
function makeTestApp(array $overrides = []): App
{
    $config = require dirname(__DIR__) . '/config.php';

    $file = sys_get_temp_dir() . '/lottery-test-' . bin2hex(random_bytes(6)) . '.sqlite';
    $config['database'] = [
        'driver'      => 'sqlite',
        'sqlite_file' => $file,
        'allow_sqlite_fallback' => true,
    ];
    $config['log']['path']              = '';
    $config['draw_base_url']            = 'https://draw.invalid';
    $config['force_remote_draw']        = false;
    // Tests want deterministic, immediate local draws; the production default
    // waits for the provider first (covered by its own test below).
    $config['draw_fallback_delay']      = 0;
    $config['draw_secret']              = 'test-draw-secret';
    $config['auth']['jwt_secret']       = 'test-jwt-secret';
    $config['auth']['signature_secret'] = 'test-signature-secret';
    $config['security']['admin_token']  = 'test-admin-token';
    $config['security']['rate_limit']       = 120;
    $config['security']['rate_limit_window']= 60;
    $config['security']['cors_origins']     = ['*'];
    $config['security']['rate_limit_store'] = sys_get_temp_dir() . '/lottery-test-rl-' . bin2hex(random_bytes(4));
    $config['admin'] = ['user' => 'admin', 'password' => 'test-admin-password', 'session_ttl' => 3600, 'enabled' => true];
    $config['auth']['require_signature']    = false;

    foreach ($overrides as $key => $value) {
        $config = array_replace_recursive($config, [$key => $value]);
    }

    App::reset();
    $app = App::boot($config);
    $app->bootstrapDatabase();

    return $app;
}

/** Give a user a starting balance. */
function fundWallet(App $app, int $userId, float $amount): void
{
    $app->wallet()->credit(
        $userId,
        $amount,
        'test:fund:' . $userId . ':' . bin2hex(random_bytes(4)),
        'adjustment',
        null,
        'test funding'
    );
}

$files = [
    __DIR__ . '/IssueNumberTest.php',
    __DIR__ . '/GameRulesTest.php',
    __DIR__ . '/DrawTest.php',
    __DIR__ . '/WalletTest.php',
    __DIR__ . '/BettingTest.php',
    __DIR__ . '/SettlementTest.php',
    __DIR__ . '/VipTest.php',
    __DIR__ . '/FollowTest.php',
    __DIR__ . '/TrendTest.php',
    __DIR__ . '/AuthTest.php',
    __DIR__ . '/PlayerAuthTest.php',
    __DIR__ . '/PartnerTest.php',
    __DIR__ . '/CompatTest.php',
    __DIR__ . '/ApiTest.php',
    __DIR__ . '/AdminTest.php',
    __DIR__ . '/FeedTest.php',
];

foreach ($files as $file) {
    require $file;
}

Clock::unfreeze();
exit(TestRunner::summary());
