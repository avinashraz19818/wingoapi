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

    public function __construct(Connection $db, Jwt $jwt, WalletService $wallet)
    {
        $this->db     = $db;
        $this->jwt    = $jwt;
        $this->wallet = $wallet;
    }

    /** @return array{id:int,mobile:string} */
    public function requireUser(array $server = null): array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->extractToken($server ?? $_SERVER);
        if ($token === '') {
            throw ApiException::auth('Authorization header with a Bearer token is required');
        }

        $claims = $this->jwt->verify($token);
        $user   = $this->resolveUser($claims['id'], $claims['mobile']);

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

        if (preg_match('/Bearer\s+(\S+)/i', (string) $header, $m)) {
            return $m[1];
        }

        return trim((string) $header);
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
