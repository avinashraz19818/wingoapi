<?php

declare(strict_types=1);

namespace Lottery\Tenant;

use Lottery\Auth\Jwt;
use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Money;
use Lottery\Vip\VipService;
use Lottery\Wallet\WalletService;
use PDOException;
use Throwable;

/**
 * Third-game / partner-site integration.
 *
 * A partner site (whitelisted domain + API key) keeps its own users and its own
 * main wallet. It plugs the lottery in exactly like any other third-party game:
 *
 *   PartnerLogin     map their user id to a local player and get a JWT
 *   PartnerTransfer  move money in ("transfer in") or out ("transfer out")
 *   PartnerBalance   read the game wallet of one of their users
 *   PartnerBetList   what that user has staked here
 *
 * Their user ids are namespaced per domain, so two partners can both have a
 * user "1001" without ever colliding.
 */
class PartnerService
{
    private Connection $db;
    private DomainService $domains;
    private Jwt $jwt;
    private WalletService $wallet;
    private VipService $vip;

    public function __construct(
        Connection $db,
        DomainService $domains,
        Jwt $jwt,
        WalletService $wallet,
        VipService $vip
    ) {
        $this->db      = $db;
        $this->domains = $domains;
        $this->jwt     = $jwt;
        $this->wallet  = $wallet;
        $this->vip     = $vip;
    }

    /**
     * Identify the calling partner. An API key is mandatory here — this is a
     * server-to-server surface, an Origin header alone is not enough.
     *
     * @return array<string,mixed> the lot_domains row
     */
    public function requirePartner(array $server, array $input): array
    {
        $key = (string) ($server['HTTP_X_API_KEY'] ?? $input['key'] ?? $input['apiKey'] ?? '');
        if ($key === '') {
            throw new ApiException(
                'Partner API key required: send it as the X-Api-Key header (create one in the admin panel → Domains)',
                \Lottery\Support\Response::ERR_AUTH,
                'API_KEY_REQUIRED',
                401
            );
        }

        $domain = $this->domains->findByKey($key);
        if ($domain === null) {
            throw DomainService::reject('Unknown partner API key');
        }
        if ((int) $domain['status'] !== 1) {
            throw DomainService::reject('Partner access is disabled');
        }
        if (!empty($domain['expires_at']) && strtotime((string) $domain['expires_at']) < Clock::now()) {
            throw DomainService::reject('Partner subscription expired');
        }

        return $domain;
    }

    /**
     * Map an external user to a local player, creating it on first sight, and
     * hand back a JWT the partner's front-end can use for every game call.
     */
    public function login(array $domain, string $externalId, string $nickname = '', string $mobile = ''): array
    {
        $externalId = trim($externalId);
        if ($externalId === '' || !preg_match('/^[A-Za-z0-9_\-.@]{1,64}$/', $externalId)) {
            throw ApiException::validation('externalUserId must be 1-64 chars (letters, digits, _ - . @)');
        }

        $userId = $this->resolveUserId($domain, $externalId, $nickname, $mobile);
        $token  = $this->jwt->issue($userId, 'p' . $domain['id'] . '_' . $externalId, null, [
            'partner'    => (int) $domain['id'],
            'externalId' => $externalId,
        ]);

        $this->db->execute(
            'UPDATE ' . Tables::PARTNER_USERS . ' SET last_login_at = ? WHERE domain_id = ? AND external_id = ?',
            [Clock::dateTime(), $domain['id'], $externalId]
        );

        $balance = $this->wallet->balance($userId);

        return [
            'token'          => $token,
            'accessToken'    => $token,
            'userToken'      => $token,
            'tokenType'      => 'Bearer',
            'expiresIn'      => max(0, (int) $this->jwt->verify($token)['exp'] - Clock::now()),
            'userId'         => $userId,
            'uid'            => $userId,
            'externalUserId' => $externalId,
            'partner'        => $domain['domain'],
            'balance'        => Money::format($balance),
            'amount'         => Money::format($balance),
            'vipLevel'       => (int) $this->vip->status($userId)['level'],
        ];
    }

    /** Local player id for a partner's user (creates the mapping if needed). */
    public function resolveUserId(array $domain, string $externalId, string $nickname = '', string $mobile = ''): int
    {
        $row = $this->db->fetch(
            'SELECT user_id FROM ' . Tables::PARTNER_USERS . ' WHERE domain_id = ? AND external_id = ?',
            [$domain['id'], $externalId]
        );

        if ($row !== null) {
            return (int) $row['user_id'];
        }

        // Namespaced login id: two partners can both have a user "1001".
        $login = $mobile !== '' ? $mobile : 'p' . $domain['id'] . '_' . $externalId;

        $existing = $this->db->fetch('SELECT id FROM ' . Tables::USERS . ' WHERE mobile = ?', [$login]);
        if ($existing !== null) {
            $userId = (int) $existing['id'];
        } else {
            $userId = $this->db->insertGetId(
                'INSERT INTO ' . Tables::USERS . ' (mobile, nickname, status, created_at) VALUES (?, ?, 1, ?)',
                [$login, $nickname !== '' ? mb_substr($nickname, 0, 64) : null, Clock::dateTime()]
            );
        }

        try {
            $this->db->execute(
                'INSERT INTO ' . Tables::PARTNER_USERS . ' (domain_id, external_id, user_id, nickname, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$domain['id'], $externalId, $userId, $nickname !== '' ? $nickname : null, Clock::dateTime()]
            );
        } catch (PDOException $e) {
            if (!$this->db->isDuplicateKey($e)) {
                throw $e;
            }
            $row = $this->db->fetch(
                'SELECT user_id FROM ' . Tables::PARTNER_USERS . ' WHERE domain_id = ? AND external_id = ?',
                [$domain['id'], $externalId]
            );
            $userId = (int) ($row['user_id'] ?? $userId);
        }

        $this->wallet->ensureWallet($userId);
        Log::info('partner user mapped', ['partner' => $domain['domain'], 'external' => $externalId, 'userId' => $userId]);

        return $userId;
    }

    /**
     * Move money between the partner's main wallet and the game wallet.
     *
     * `orderId` makes it idempotent: replaying the same transfer returns the
     * original result instead of moving money twice.
     */
    public function transfer(array $domain, string $externalId, float $amount, string $direction, string $orderId): array
    {
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['in', 'out', 'credit', 'debit'], true)) {
            throw ApiException::validation('direction must be "in" (deposit) or "out" (withdraw)');
        }
        $isIn   = in_array($direction, ['in', 'credit'], true);
        $amount = Money::round($amount);

        if ($amount <= 0) {
            throw ApiException::validation('amount must be greater than zero');
        }
        if ($orderId === '' || !preg_match('/^[A-Za-z0-9_\-.:]{1,64}$/', $orderId)) {
            throw ApiException::validation('orderId is required (1-64 chars) and must be unique per transfer');
        }

        $userId   = $this->resolveUserId($domain, $externalId);
        $entryKey = WalletService::entryKey('partner', (string) $domain['id'], $orderId);

        $result = $isIn
            ? $this->wallet->credit($userId, $amount, $entryKey, 'transfer_in', $orderId, $domain['domain'])
            : $this->wallet->debit($userId, $amount, $entryKey, 'transfer_out', $orderId, $domain['domain']);

        return [
            'orderId'        => $orderId,
            'externalUserId' => $externalId,
            'userId'         => $userId,
            'direction'      => $isIn ? 'in' : 'out',
            'amount'         => Money::format($amount),
            'applied'        => $result['applied'],       // false = replay of a known orderId
            'duplicate'      => !$result['applied'],
            'balance'        => Money::format($result['balance']),
        ];
    }

    public function balance(array $domain, string $externalId): array
    {
        $userId = $this->resolveUserId($domain, $externalId);
        $wallet = $this->wallet->snapshot($userId);
        $vip    = $this->vip->status($userId);

        return [
            'externalUserId' => $externalId,
            'userId'         => $userId,
            'balance'        => $wallet['balance'],
            'amount'         => $wallet['balance'],
            'totalStake'     => $wallet['totalStake'],
            'totalPayout'    => $wallet['totalPayout'],
            'vipLevel'       => $vip['level'],
        ];
    }

    /** Bets placed here by one of the partner's users. */
    public function bets(array $domain, string $externalId, int $pageNo, int $pageSize): array
    {
        $userId = $this->resolveUserId($domain, $externalId);

        $total = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE user_id = ?',
            [$userId]
        );
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE user_id = ?
              ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            [$userId]
        );

        return [
            'externalUserId' => $externalId,
            'userId'         => $userId,
            'pageNo'         => $pageNo,
            'pageSize'       => $pageSize,
            'totalCount'     => $total,
            'list'           => array_map([\Lottery\Betting\BetService::class, 'presentBet'], $rows),
        ];
    }

    /**
     * Accept a token minted by the partner itself.
     *
     * When a domain has `player_secret` set, tokens signed with that secret are
     * trusted: the user id claim is mapped to a local player, so the partner's
     * existing front-end keeps sending its own token and everything works.
     *
     * @return array{id:int,mobile:string}|null
     */
    public function resolvePartnerToken(string $token, string $originHost): ?array
    {
        $domain = $originHost === '' ? null : $this->domains->findByDomain($originHost);
        if ($domain === null || (int) $domain['status'] !== 1 || empty($domain['player_secret'])) {
            return null;
        }

        try {
            $claims = (new Jwt((string) $domain['player_secret'], 86400, 60))->verify($token);
        } catch (Throwable $e) {
            return null;
        }

        $externalId = (string) ($claims['externalId'] ?? $claims['uid'] ?? $claims['userId'] ?? $claims['id'] ?? $claims['sub'] ?? '');
        if ($externalId === '' || $externalId === '0') {
            return null;
        }

        $userId = $this->resolveUserId($domain, $externalId, (string) ($claims['nickname'] ?? ''));

        return ['id' => $userId, 'mobile' => 'p' . $domain['id'] . '_' . $externalId];
    }
}
