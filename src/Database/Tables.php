<?php

declare(strict_types=1);

namespace Lottery\Database;

/**
 * Single source of truth for physical table names.
 */
final class Tables
{
    public const MIGRATIONS    = 'lot_migrations';
    public const USERS         = 'lot_users';
    public const WALLETS       = 'lot_wallets';
    public const LEDGER        = 'lot_wallet_ledger';
    public const GAMES         = 'lot_games';
    public const ISSUES        = 'lot_issues';
    public const RESULTS       = 'lot_results';
    public const BETS          = 'lot_bets';
    public const IDEMPOTENCY   = 'lot_idempotency';
    public const OVERRIDES     = 'lot_result_overrides';
    public const VIP           = 'lot_user_vip';
    public const VIP_LOG       = 'lot_vip_log';
    public const FOLLOW_PLANS  = 'lot_follow_plans';
    public const FOLLOW_SUBS   = 'lot_follow_subscriptions';
    public const FOLLOW_ORDERS = 'lot_follow_orders';
    public const SETTLEMENTS   = 'lot_settlements';
    public const AUDIT         = 'lot_admin_audit';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::MIGRATIONS, self::USERS, self::WALLETS, self::LEDGER, self::GAMES,
            self::ISSUES, self::RESULTS, self::BETS, self::IDEMPOTENCY, self::OVERRIDES,
            self::VIP, self::VIP_LOG, self::FOLLOW_PLANS, self::FOLLOW_SUBS,
            self::FOLLOW_ORDERS, self::SETTLEMENTS, self::AUDIT,
        ];
    }
}
