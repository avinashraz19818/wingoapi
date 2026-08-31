<?php

declare(strict_types=1);

namespace Lottery;

use Lottery\Admin\OverrideService;
use Lottery\Auth\AdminAuth;
use Lottery\Auth\Authenticator;
use Lottery\Auth\Jwt;
use Lottery\Auth\Signature;
use Lottery\Betting\BetService;
use Lottery\Database\Connection;
use Lottery\Database\Migrator;
use Lottery\Draw\DrawFetcher;
use Lottery\Draw\DrawService;
use Lottery\Draw\LocalDrawGenerator;
use Lottery\Follow\FollowService;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameRegistry;
use Lottery\Games\IssueScheduler;
use Lottery\Settlement\SettlementService;
use Lottery\Stats\TrendService;
use Lottery\Support\Http;
use Lottery\Support\Log;
use Lottery\Support\RateLimiter;
use Lottery\Vip\VipService;
use Lottery\Wallet\WalletService;

/**
 * Lazily-built service container. One instance per request / CLI run.
 */
class App
{
    private array $config;
    /** @var array<string,mixed> */
    private array $instances = [];
    private static ?App $current = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__) . '/config.php';

        date_default_timezone_set((string) ($this->config['app']['timezone'] ?? 'Asia/Kolkata'));
        Log::configure(
            (string) ($this->config['log']['path'] ?? ''),
            (string) ($this->config['log']['level'] ?? 'info')
        );
    }

    public static function boot(?array $config = null): App
    {
        if (self::$current === null || $config !== null) {
            self::$current = new self($config);
        }
        return self::$current;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public function config(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }

        $cursor = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /** @param callable():mixed $factory */
    private function singleton(string $key, callable $factory)
    {
        if (!array_key_exists($key, $this->instances)) {
            $this->instances[$key] = $factory();
        }
        return $this->instances[$key];
    }

    public function db(): Connection
    {
        return $this->singleton(Connection::class, fn() => new Connection($this->config['database']));
    }

    public function migrator(): Migrator
    {
        return $this->singleton(Migrator::class, fn() => new Migrator($this->db()));
    }

    public function registry(): GameRegistry
    {
        return $this->singleton(GameRegistry::class, fn() => new GameRegistry($this->config));
    }

    public function rules(): RulesFactory
    {
        return $this->singleton(RulesFactory::class, fn() => new RulesFactory());
    }

    public function scheduler(): IssueScheduler
    {
        return $this->singleton(IssueScheduler::class, fn() => new IssueScheduler());
    }

    public function http(): Http
    {
        return $this->singleton(Http::class, fn() => new Http((int) $this->config['draw_timeout']));
    }

    public function fetcher(): DrawFetcher
    {
        return $this->singleton(DrawFetcher::class, fn() => new DrawFetcher(
            $this->http(),
            $this->rules(),
            (string) $this->config['draw_base_url'],
            (string) $this->config['draw_url_template']
        ));
    }

    public function localDraw(): LocalDrawGenerator
    {
        return $this->singleton(LocalDrawGenerator::class, fn() => new LocalDrawGenerator(
            (string) $this->config['draw_secret'],
            $this->rules()
        ));
    }

    public function overrides(): OverrideService
    {
        return $this->singleton(OverrideService::class, fn() => new OverrideService($this->db(), $this->rules()));
    }

    public function draws(): DrawService
    {
        return $this->singleton(DrawService::class, fn() => new DrawService(
            $this->db(),
            $this->rules(),
            $this->scheduler(),
            $this->fetcher(),
            $this->localDraw(),
            $this->overrides(),
            (bool) $this->config['force_remote_draw']
        ));
    }

    public function wallet(): WalletService
    {
        return $this->singleton(WalletService::class, fn() => new WalletService($this->db()));
    }

    public function vip(): VipService
    {
        return $this->singleton(VipService::class, fn() => new VipService($this->db(), $this->config['vip']));
    }

    public function bets(): BetService
    {
        return $this->singleton(BetService::class, fn() => new BetService(
            $this->db(),
            $this->registry(),
            $this->rules(),
            $this->scheduler(),
            $this->wallet(),
            $this->vip(),
            $this->config['betting']
        ));
    }

    public function settlement(): SettlementService
    {
        return $this->singleton(SettlementService::class, fn() => new SettlementService(
            $this->db(),
            $this->rules(),
            $this->draws(),
            $this->scheduler(),
            $this->wallet(),
            (float) $this->config['betting']['payout_tax_rate']
        ));
    }

    public function follow(): FollowService
    {
        return $this->singleton(FollowService::class, fn() => new FollowService(
            $this->db(),
            $this->bets(),
            $this->registry(),
            $this->scheduler()
        ));
    }

    public function trends(): TrendService
    {
        return $this->singleton(TrendService::class, fn() => new TrendService($this->draws(), $this->rules()));
    }

    public function jwt(): Jwt
    {
        return $this->singleton(Jwt::class, fn() => new Jwt(
            (string) $this->config['auth']['jwt_secret'],
            (int) $this->config['auth']['jwt_ttl'],
            (int) $this->config['auth']['jwt_leeway']
        ));
    }

    public function signature(): Signature
    {
        return $this->singleton(Signature::class, fn() => new Signature(
            (string) $this->config['auth']['signature_secret'],
            (int) $this->config['auth']['signature_ttl']
        ));
    }

    public function auth(): Authenticator
    {
        return $this->singleton(Authenticator::class, fn() => new Authenticator(
            $this->db(),
            $this->jwt(),
            $this->wallet()
        ));
    }

    public function adminAuth(): AdminAuth
    {
        return $this->singleton(AdminAuth::class, fn() => new AdminAuth(
            (string) $this->config('auth.jwt_secret'),
            (array) $this->config('admin', []),
            (string) $this->config('security.admin_token')
        ));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->singleton(RateLimiter::class, fn() => new RateLimiter(
            (string) $this->config['security']['rate_limit_store'],
            (int) $this->config['security']['rate_limit'],
            (int) $this->config['security']['rate_limit_window']
        ));
    }

    /** Run pending migrations + seeds (called once per process). */
    public function bootstrapDatabase(): void
    {
        $this->singleton('db.bootstrapped', function () {
            $this->migrator()->bootstrap();
            return true;
        });
    }
}
