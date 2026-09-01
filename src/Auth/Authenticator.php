<?php

declare(strict_types=1);

namespace Lottery\Auth;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Wallet\WalletService;
use PDOException;

/**
 * Resolves the caller from the `Authorization: Bearer <jwt>` header.
 */
class Authenticator
{
    private Connection $db;
    private Jwt $jwt;
    private WalletService $wallet;
    private ?array $user = null;
    private string $userToken = '';

    public function __construct(Connection $db, Jwt $jwt, WalletService $wallet)
    {
        $this->db     = $db;
        $this->jwt    = $jwt;
        $this->wallet = $wallet;
    }

    /** @return array{id:int,mobile:string} */
    /**
     * @param array<string,mixed>|null $input request parameters (query + body)
     */
    public function requireUser(array $server = null, array $input = []): array
    {
        $token = $this->extractToken($server ?? $_SERVER, $input);

        // Memoised per token: the same request may resolve the caller several
        // times, but a different (or missing) token must be re-evaluated.
        if ($this->user !== null && $token !== '' && $token === $this->userToken) {
            return $this->user;
        }

        if ($token === '') {
            throw new ApiException(
                'Login required: no token was sent. Call action=Login first, then send '
                . 'Authorization: Bearer <token> with every request.',
                \Lottery\Support\Response::ERR_AUTH,
                'AUTH_REQUIRED',
                401,
                ['tokenReceived' => false]
            );
        }

        $claims = $this->jwt->verify($token);
        $user   = $this->resolveUser($claims['id'], $claims['mobile']);

        $this->userToken = $token;

        return $this->user = $user;
    }

    public function optionalUser(array $server = null, array $input = []): ?array
    {
        try {
            return $this->requireUser($server, $input);
        } catch (ApiException $e) {
            return null;
        }
    }

    /**
     * Find the caller's token.
     *
     * Front-ends built for different back-ends put it in different places, so
     * we accept all of the common ones:
     *
     *   Authorization: Bearer <jwt>      (preferred)
     *   Authorization: <jwt>
     *   Token / X-Token / X-Access-Token / Auth / X-Auth-Token headers
     *   ?token= / ?access_token= / ?ar_token= / ?authorization=  (query or body)
     */
    public function extractToken(array $server, array $input = []): string
    {
        $candidates = [];

        $authorization = (string) ($server['HTTP_AUTHORIZATION']
            ?? $server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');

        if ($authorization === '' && function_exists('apache_request_headers')) {
            foreach ((apache_request_headers() ?: []) as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $authorization = (string) $value;
                    break;
                }
            }
        }

        if ($authorization !== '') {
            $candidates[] = preg_match('/Bearer\s+(\S+)/i', $authorization, $m) ? $m[1] : trim($authorization);
        }

        foreach ([
            'HTTP_TOKEN', 'HTTP_X_TOKEN', 'HTTP_X_ACCESS_TOKEN', 'HTTP_ACCESS_TOKEN',
            'HTTP_AUTH', 'HTTP_X_AUTH_TOKEN', 'HTTP_X_AUTHORIZATION', 'HTTP_AR_TOKEN',
        ] as $header) {
            if (!empty($server[$header])) {
                $raw         = (string) $server[$header];
                $candidates[] = preg_match('/Bearer\s+(\S+)/i', $raw, $m) ? $m[1] : trim($raw);
            }
        }

        foreach (['token', 'access_token', 'accessToken', 'ar_token', 'authorization', 'auth'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && (string) $input[$key] !== '') {
                $raw         = (string) $input[$key];
                $candidates[] = preg_match('/Bearer\s+(\S+)/i', $raw, $m) ? $m[1] : trim($raw);
            }
        }

        foreach ($candidates as $token) {
            if ($this->looksLikeToken($token)) {
                return $token;
            }
        }

        return '';
    }

    /**
     * Front-ends commonly send the literal strings "null"/"undefined" before
     * the user has logged in — those are not tokens.
     */
    private function looksLikeToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (in_array(strtolower($token), ['null', 'undefined', 'nil', 'false', '0', 'bearer'], true)) {
            return false;
        }

        return true;
    }

    /** Users are provisioned on first authenticated call. */
    private function resolveUser(int $userId, string $mobile): array
    {
        $row = $this->db->fetch(
            'SELECT id, mobile, status FROM ' . Tables::USERS . ' WHERE id = ?',
            [$userId]
        );

        if ($row === null) {
            try {
                $this->db->execute(
                    $this->db->insertIgnore() . ' ' . Tables::USERS . ' (id, mobile, status, created_at) VALUES (?, ?, 1, ?)',
                    [$userId, $mobile !== '' ? $mobile : 'u' . $userId, Clock::dateTime()]
                );
            } catch (PDOException $e) {
                if (!$this->db->isDuplicateKey($e)) {
                    throw $e;
                }
            }
            $row = ['id' => $userId, 'mobile' => $mobile, 'status' => 1];
        }

        if ((int) $row['status'] !== 1) {
            throw ApiException::auth('Account is disabled');
        }

        $this->wallet->ensureWallet($userId);

        return ['id' => (int) $row['id'], 'mobile' => (string) $row['mobile']];
    }
}
