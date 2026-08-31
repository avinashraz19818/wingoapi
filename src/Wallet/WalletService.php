<?php

declare(strict_types=1);

namespace Lottery\Wallet;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use PDOException;

/**
 * Wallet + immutable double-entry style ledger.
 *
 * Guarantees
 *   - every balance change is written together with exactly one ledger row
 *     inside a single database transaction;
 *   - the wallet row is locked (SELECT ... FOR UPDATE on MySQL) for the whole
 *     mutation, so concurrent bets cannot interleave;
 *   - each ledger row carries a unique `entry_key`; replaying the same
 *     operation (retry, duplicate settlement, cron overlap) is a no-op.
 */
class WalletService
{
    public const DIR_DEBIT  = 'debit';
    public const DIR_CREDIT = 'credit';

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function ensureWallet(int $userId): void
    {
        try {
            $this->db->execute(
                $this->db->insertIgnore() . ' ' . Tables::WALLETS . ' (user_id, balance, updated_at) VALUES (?, 0.00, ?)',
                [$userId, Clock::dateTime()]
            );
        } catch (PDOException $e) {
            if (!$this->db->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    public function balance(int $userId): float
    {
        $this->ensureWallet($userId);
        $value = $this->db->fetchValue(
            'SELECT balance FROM ' . Tables::WALLETS . ' WHERE user_id = ?',
            [$userId]
        );

        return Money::round((float) ($value ?? 0));
    }

    public function snapshot(int $userId): array
    {
        $this->ensureWallet($userId);
        $row = $this->db->fetch(
            'SELECT user_id, balance, frozen, total_stake, total_payout FROM ' . Tables::WALLETS . ' WHERE user_id = ?',
            [$userId]
        ) ?? [];

        return [
            'userId'      => $userId,
            'balance'     => Money::format((float) ($row['balance'] ?? 0)),
            'frozen'      => Money::format((float) ($row['frozen'] ?? 0)),
            'totalStake'  => Money::format((float) ($row['total_stake'] ?? 0)),
            'totalPayout' => Money::format((float) ($row['total_payout'] ?? 0)),
        ];
    }

    /**
     * Deduct funds. Throws when the balance is insufficient.
     *
     * @return array{applied:bool,balance:float,before:float,entry_id:int}
     */
    public function debit(
        int $userId,
        float $amount,
        string $entryKey,
        string $refType,
        ?string $refId = null,
        ?string $remark = null
    ): array {
        return $this->mutate($userId, self::DIR_DEBIT, $amount, $entryKey, $refType, $refId, $remark);
    }

    /**
     * Add funds (winnings, refunds, adjustments).
     *
     * @return array{applied:bool,balance:float,before:float,entry_id:int}
     */
    public function credit(
        int $userId,
        float $amount,
        string $entryKey,
        string $refType,
        ?string $refId = null,
        ?string $remark = null
    ): array {
        return $this->mutate($userId, self::DIR_CREDIT, $amount, $entryKey, $refType, $refId, $remark);
    }

    private function mutate(
        int $userId,
        string $direction,
        float $amount,
        string $entryKey,
        string $refType,
        ?string $refId,
        ?string $remark
    ): array {
        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw ApiException::validation('Ledger amount must be greater than zero');
        }

        $this->ensureWallet($userId);

        return $this->db->transaction(function (Connection $db) use (
            $userId, $direction, $amount, $entryKey, $refType, $refId, $remark
        ) {
            // Replay protection: the ledger key is the idempotency boundary.
            $existing = $db->fetch(
                'SELECT id, balance_after FROM ' . Tables::LEDGER . ' WHERE entry_key = ?',
                [$entryKey]
            );
            if ($existing !== null) {
                return [
                    'applied'  => false,
                    'balance'  => Money::round((float) $existing['balance_after']),
                    'before'   => Money::round((float) $existing['balance_after']),
                    'entry_id' => (int) $existing['id'],
                ];
            }

            $wallet = $db->fetch(
                'SELECT balance FROM ' . Tables::WALLETS . ' WHERE user_id = ?' . $db->forUpdate(),
                [$userId]
            );
            $before = Money::round((float) ($wallet['balance'] ?? 0));

            if ($direction === self::DIR_DEBIT) {
                if ($before + 0.0001 < $amount) {
                    throw ApiException::insufficientBalance(
                        'Insufficient balance: available ' . Money::format($before) . ', required ' . Money::format($amount)
                    );
                }
                $after = Money::round($before - $amount);
                $db->execute(
                    'UPDATE ' . Tables::WALLETS . '
                        SET balance = ?, total_stake = total_stake + ?, version = version + 1, updated_at = ?
                      WHERE user_id = ?',
                    [Money::format($after), Money::format($amount), Clock::dateTime(), $userId]
                );
            } else {
                $after = Money::round($before + $amount);
                $db->execute(
                    'UPDATE ' . Tables::WALLETS . '
                        SET balance = ?, total_payout = total_payout + ?, version = version + 1, updated_at = ?
                      WHERE user_id = ?',
                    [Money::format($after), Money::format($amount), Clock::dateTime(), $userId]
                );
            }

            try {
                $entryId = $db->insertGetId(
                    'INSERT INTO ' . Tables::LEDGER . '
                        (entry_key, user_id, direction, amount, balance_before, balance_after, ref_type, ref_id, remark, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $entryKey,
                        $userId,
                        $direction,
                        Money::format($amount),
                        Money::format($before),
                        Money::format($after),
                        $refType,
                        $refId,
                        $remark,
                        Clock::dateTime(),
                    ]
                );
            } catch (PDOException $e) {
                if ($db->isDuplicateKey($e)) {
                    // Lost a race with a concurrent identical operation.
                    throw ApiException::conflict('Duplicate ledger entry: ' . $entryKey);
                }
                throw $e;
            }

            return [
                'applied'  => true,
                'balance'  => $after,
                'before'   => $before,
                'entry_id' => $entryId,
            ];
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function ledger(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT id, entry_key, direction, amount, balance_before, balance_after, ref_type, ref_id, remark, created_at
               FROM ' . Tables::LEDGER . '
              WHERE user_id = ?
              ORDER BY id DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$userId]
        );
    }

    /** Deterministic ledger keys keep every money path replay-safe. */
    public static function entryKey(string $scope, string ...$parts): string
    {
        return $scope . ':' . substr(hash('sha256', implode('|', $parts)), 0, 48);
    }
}
