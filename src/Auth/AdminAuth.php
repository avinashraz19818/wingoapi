<?php

declare(strict_types=1);

namespace Lottery\Auth;

use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;

/**
 * Authentication for the web admin panel.
 *
 * Login exchanges the admin user/password for a short-lived HS256 session
 * token that is signed with a *derived* secret, so a normal player JWT can
 * never be replayed against admin endpoints. Machine clients may keep using
 * the `X-Admin-Token` header instead.
 */
class AdminAuth
{
    private Jwt $jwt;
    private string $user;
    private string $password;
    private string $apiToken;
    private int $ttl;
    private bool $enabled;

    public function __construct(string $jwtSecret, array $adminConfig, string $apiToken)
    {
        $this->ttl      = max(300, (int) ($adminConfig['session_ttl'] ?? 28800));
        $this->jwt      = new Jwt($jwtSecret . '|admin-panel', $this->ttl, 30);
        $this->user     = (string) ($adminConfig['user'] ?? 'admin');
        $this->password = (string) ($adminConfig['password'] ?? '');
        $this->apiToken = $apiToken;
        $this->enabled  = (bool) ($adminConfig['enabled'] ?? true);
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->password !== '';
    }

    /**
     * @return array{token:string,expiresAt:int,user:string}
     */
    public function login(string $user, string $password, string $ip = ''): array
    {
        if (!$this->enabled()) {
            throw ApiException::auth('Admin panel is disabled (set ADMIN_PASSWORD or ADMIN_TOKEN)');
        }

        $userOk = hash_equals($this->user, $user);
        $passOk = hash_equals($this->password, $password);

        if (!$userOk || !$passOk) {
            Log::warning('admin login failed', ['user' => $user, 'ip' => $ip]);
            // Constant-ish delay to slow down online guessing.
            usleep(300000);
            throw ApiException::auth('Invalid administrator credentials');
        }

        $expiresAt = Clock::now() + $this->ttl;
        $token     = $this->jwt->issue(1, $this->user, $this->ttl, ['role' => 'admin']);

        Log::info('admin login', ['user' => $user, 'ip' => $ip]);

        return ['token' => $token, 'expiresAt' => $expiresAt, 'user' => $this->user];
    }

    /**
     * Authorise a request: session token (Bearer) or the static X-Admin-Token.
     *
     * @return array{actor:string,via:string}
     */
    public function authorise(array $server): array
    {
        $headerToken = (string) ($server['HTTP_X_ADMIN_TOKEN'] ?? '');
        if ($headerToken !== '' && $this->apiToken !== '' && hash_equals($this->apiToken, $headerToken)) {
            return ['actor' => 'api-token', 'via' => 'x-admin-token'];
        }

        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authorization, $m)) {
            $claims = $this->jwt->verify($m[1]);
            if (($claims['role'] ?? '') !== 'admin') {
                throw ApiException::auth('Token is not an administrator session');
            }
            return ['actor' => (string) ($claims['mobile'] ?: 'admin'), 'via' => 'session'];
        }

        throw ApiException::auth('Administrator session required');
    }
}
