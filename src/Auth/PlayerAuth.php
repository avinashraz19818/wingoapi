<?php

declare(strict_types=1);

namespace Lottery\Auth;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Money;
use Lottery\Support\Validator;
use Lottery\Vip\VipService;
use Lottery\Wallet\WalletService;
use PDOException;

/**
 * Player account handling: register, login, profile and password changes.
 *
 * Front-ends store the returned JWT (e.g. localStorage `ar_token`) and send it
 * as `Authorization: Bearer <token>` on every authenticated call.
 */
class PlayerAuth
{
    private Connection $db;
    private Jwt $jwt;
    private WalletService $wallet;
    private VipService $vip;
    private float $signupBonus;

    public function __construct(Connection $db, Jwt $jwt, WalletService $wallet, VipService $vip, float $signupBonus = 0.0)
    {
        $this->db          = $db;
        $this->jwt         = $jwt;
        $this->wallet      = $wallet;
        $this->vip         = $vip;
        $this->signupBonus = $signupBonus;
    }

    /**
     * Create an account. Mobile is the login id.
     */
    public function register(string $mobile, string $password, string $nickname = ''): array
    {
        $mobile = Validator::mobile(trim($mobile));
        $this->assertPassword($password);

        if ($this->findByMobile($mobile) !== null) {
            throw ApiException::conflict('This mobile number is already registered');
        }

        try {
            $userId = $this->db->insertGetId(
                'INSERT INTO ' . Tables::USERS . ' (mobile, nickname, password_hash, status, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [
                    $mobile,
                    $nickname !== '' ? mb_substr($nickname, 0, 64) : null,
                    password_hash($password, PASSWORD_BCRYPT),
                    Clock::dateTime(),
                ]
            );
        } catch (PDOException $e) {
            if ($this->db->isDuplicateKey($e)) {
                throw ApiException::conflict('This mobile number is already registered');
            }
            throw $e;
        }

        $this->wallet->ensureWallet($userId);
        if ($this->signupBonus > 0) {
            $this->wallet->credit(
                $userId,
                $this->signupBonus,
                WalletService::entryKey('signup', (string) $userId),
                'bonus',
                'signup',
                'signup bonus'
            );
        }

        Log::info('player registered', ['userId' => $userId, 'mobile' => $mobile]);

        return $this->session($userId, $mobile);
    }

    /**
     * Exchange credentials for a JWT.
     */
    public function login(string $mobile, string $password): array
    {
        $mobile = trim($mobile);
        $row    = $this->findByMobile($mobile);

        // Same message + delay for "unknown user" and "wrong password" so the
        // endpoint cannot be used to enumerate accounts.
        if ($row === null || (string) ($row['password_hash'] ?? '') === '') {
            usleep(250000);
            throw ApiException::auth('Mobile number or password is incorrect');
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            usleep(250000);
            Log::warning('failed player login', ['mobile' => $mobile]);
            throw ApiException::auth('Mobile number or password is incorrect');
        }
        if ((int) $row['status'] !== 1) {
            throw ApiException::auth('Account is disabled, contact support');
        }

        $this->db->execute(
            'UPDATE ' . Tables::USERS . ' SET updated_at = ? WHERE id = ?',
            [Clock::dateTime(), $row['id']]
        );

        return $this->session((int) $row['id'], (string) $row['mobile']);
    }

    /** Profile + wallet + VIP for the logged-in player. */
    public function profile(int $userId): array
    {
        $row = $this->db->fetch(
            'SELECT id, mobile, nickname, status, created_at FROM ' . Tables::USERS . ' WHERE id = ?',
            [$userId]
        );
        if ($row === null) {
            throw ApiException::notFound('User not found');
        }

        $wallet = $this->wallet->snapshot($userId);
        $vip    = $this->vip->status($userId);

        return [
            'userId'     => (int) $row['id'],
            'mobile'     => $this->maskMobile((string) $row['mobile']),
            'mobileFull' => (string) $row['mobile'],
            'nickname'   => $row['nickname'] ?? ('Player' . $row['id']),
            'status'     => (int) $row['status'],
            'createdAt'  => $row['created_at'],
            'balance'    => $wallet['balance'],
            'amount'     => $wallet['balance'],
            'uid'        => (int) $row['id'],
            'totalStake' => $wallet['totalStake'],
            'totalPayout'=> $wallet['totalPayout'],
            'vipLevel'   => $vip['level'],
            'experience' => $vip['experience'],
        ];
    }

    public function changePassword(int $userId, string $current, string $new): array
    {
        $this->assertPassword($new);

        $row = $this->db->fetch('SELECT password_hash FROM ' . Tables::USERS . ' WHERE id = ?', [$userId]);
        if ($row === null) {
            throw ApiException::notFound('User not found');
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash !== '' && !password_verify($current, $hash)) {
            throw ApiException::auth('Current password is incorrect');
        }

        $this->db->execute(
            'UPDATE ' . Tables::USERS . ' SET password_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), Clock::dateTime(), $userId]
        );

        Log::info('player password changed', ['userId' => $userId]);

        return ['changed' => true];
    }

    /** Admin/ops helper: set a password without knowing the old one. */
    public function setPassword(int $userId, string $password): bool
    {
        $this->assertPassword($password);

        return $this->db->execute(
            'UPDATE ' . Tables::USERS . ' SET password_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), Clock::dateTime(), $userId]
        ) > 0;
    }

    /** Issue a fresh token for an already authenticated caller. */
    public function refresh(int $userId, string $mobile): array
    {
        return $this->session($userId, $mobile);
    }

    public function findByMobile(string $mobile): ?array
    {
        return $this->db->fetch('SELECT * FROM ' . Tables::USERS . ' WHERE mobile = ?', [trim($mobile)]);
    }

    /**
     * @return array{token:string,tokenType:string,expiresIn:int,userId:int,mobile:string,balance:string,vipLevel:int}
     */
    private function session(int $userId, string $mobile): array
    {
        $token   = $this->jwt->issue($userId, $mobile);
        $claims  = $this->jwt->verify($token);
        $balance = $this->wallet->balance($userId);

        // The same values are repeated under the names different front-ends
        // look for, so a client can be pointed at this API without edits.
        return [
            'token'       => $token,
            'accessToken' => $token,
            'userToken'   => $token,
            'jwt'         => $token,
            'tokenType'   => 'Bearer',
            'expiresIn'   => max(0, (int) $claims['exp'] - Clock::now()),
            'expiresAt'   => date('Y-m-d H:i:s', (int) $claims['exp']),
            'userId'      => $userId,
            'uid'         => $userId,
            'id'          => $userId,
            'mobile'      => $this->maskMobile($mobile),
            'balance'     => Money::format($balance),
            'amount'      => Money::format($balance),
            'vipLevel'    => (int) $this->vip->status($userId)['level'],
        ];
    }

    private function assertPassword(string $password): void
    {
        $length = strlen($password);
        if ($length < 6 || $length > 64) {
            throw ApiException::validation('Password must be between 6 and 64 characters');
        }
    }

    private function maskMobile(string $mobile): string
    {
        $length = strlen($mobile);
        if ($length <= 4) {
            return $mobile;
        }

        return substr($mobile, 0, 2) . str_repeat('*', max(0, $length - 4)) . substr($mobile, -2);
    }
}
