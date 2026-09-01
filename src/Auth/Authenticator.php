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
    public function requireUser(array $server = null): array
    {
        $token = $this->extractToken($server ?? $_SERVER);

        // Memoised per token: the same request may resolve the caller several
        // times, but a different (or missing) token must be re-evaluated.
        if ($this->user !== null && $token !== '' && $token === $this->userToken) {
            return $this->user;
        }

        if ($token === '') {
            throw ApiException::auth('Authorization header with a Bearer token is required');
        }

        $claims = $this->jwt->verify($token);
        $user   = $this->resolveUser($claims['id'], $claims['mobile']);

        $this->userToken = $token;

        return $this->user = $user;
    }

    public function optionalUser(array $server = null): ?array
    {
        try {
            return $this->requireUser($server);
        } catch (ApiException $e) {
            return null;
        }
    }

    public function extractToken(array $server): string
    {
        $header = $server['HTTP_AUTHORIZATION']
            ?? $server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers() ?: [];
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        $token = '';
        if (preg_match('/Bearer\s+(\S+)/i', (string) $header, $m)) {
            $token = $m[1];
        } else {
            $token = trim((string) $header);
        }

        // Front-ends commonly send the literal string "null" or "undefined"
        // before the user has logged in — treat those as "no token at all".
        if (in_array(strtolower($token), ['null', 'undefined', 'nil', 'false', '0'], true)) {
            return '';
        }

        return $token;
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
