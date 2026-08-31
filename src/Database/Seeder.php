<?php

declare(strict_types=1);

namespace Lottery\Database;

use Lottery\Games\GameRegistry;
use Lottery\Support\Clock;

/**
 * Idempotent reference data: the game catalogue and the default follow plans.
 * Safe to run on every deploy — existing rows are updated, never duplicated.
 */
class Seeder
{
    private Connection $db;
    private array $config;

    public function __construct(Connection $db, ?array $config = null)
    {
        $this->db     = $db;
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config.php';
    }

    public function run(): void
    {
        $this->seedGames();
        $this->seedFollowPlans();
    }

    public function seedGames(): void
    {
        $registry = new GameRegistry($this->config);

        foreach ($registry->all() as $game) {
            $existing = $this->db->fetch(
                'SELECT id FROM ' . Tables::GAMES . ' WHERE game_code = ?',
                [$game->code]
            );

            $params = [
                $game->family, $game->familyCode, $game->intervalKey, $game->intervalCode,
                $game->seconds, $game->lockSeconds, $game->sort, $game->state,
            ];

            if ($existing === null) {
                $this->db->execute(
                    'INSERT INTO ' . Tables::GAMES . '
                        (game_code, family, family_code, interval_key, interval_code, interval_seconds,
                         lock_seconds, sort, state, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge([$game->code], $params, [Clock::dateTime()])
                );
            } else {
                $this->db->execute(
                    'UPDATE ' . Tables::GAMES . '
                        SET family = ?, family_code = ?, interval_key = ?, interval_code = ?,
                            interval_seconds = ?, lock_seconds = ?, sort = ?, state = ?
                      WHERE game_code = ?',
                    array_merge($params, [$game->code])
                );
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function defaultPlans(): array
    {
        return [
            [
                'plan_code'   => 'wingo1m-bigsmall-big',
                'name'        => 'BigSmall · Big',
                'description' => 'Bets Big on every WinGo 1 Minute round',
                'game_code'   => 'WinGo_1M',
                'bet_type'    => 'size',
                'bet_content' => 'big',
                'min_amount'  => 1.00,
                'sort'        => 1,
            ],
            [
                'plan_code'   => 'wingo1m-bigsmall-small',
                'name'        => 'BigSmall · Small',
                'description' => 'Bets Small on every WinGo 1 Minute round',
                'game_code'   => 'WinGo_1M',
                'bet_type'    => 'size',
                'bet_content' => 'small',
                'min_amount'  => 1.00,
                'sort'        => 2,
            ],
            [
                'plan_code'   => 'wingo1m-color-green',
                'name'        => 'Color · Green',
                'description' => 'Bets Green on every WinGo 1 Minute round',
                'game_code'   => 'WinGo_1M',
                'bet_type'    => 'color',
                'bet_content' => 'green',
                'min_amount'  => 1.00,
                'sort'        => 3,
            ],
            [
                'plan_code'   => 'wingo1m-color-red',
                'name'        => 'Color · Red',
                'description' => 'Bets Red on every WinGo 1 Minute round',
                'game_code'   => 'WinGo_1M',
                'bet_type'    => 'color',
                'bet_content' => 'red',
                'min_amount'  => 1.00,
                'sort'        => 4,
            ],
            [
                'plan_code'   => 'wingo3m-bigsmall-big',
                'name'        => 'BigSmall · Big (3M)',
                'description' => 'Bets Big on every WinGo 3 Minute round',
                'game_code'   => 'WinGo_3M',
                'bet_type'    => 'size',
                'bet_content' => 'big',
                'min_amount'  => 1.00,
                'sort'        => 5,
            ],
            [
                'plan_code'   => 'k31m-bigsmall-big',
                'name'        => 'K3 · Big',
                'description' => 'Bets Big (sum 11-18) on every K3 1 Minute round',
                'game_code'   => 'K3_1M',
                'bet_type'    => 'size',
                'bet_content' => 'big',
                'min_amount'  => 1.00,
                'sort'        => 6,
            ],
            [
                'plan_code'   => 'd51m-a-big',
                'name'        => '5D · A Big',
                'description' => 'Bets position A Big on every 5D 1 Minute round',
                'game_code'   => 'D5_1M',
                'bet_type'    => 'size',
                'bet_content' => 'a:big',
                'min_amount'  => 1.00,
                'sort'        => 7,
            ],
            [
                'plan_code'   => 'trx1m-color-green',
                'name'        => 'TRX · Green',
                'description' => 'Bets Green on every TRX WinGo 1 Minute round',
                'game_code'   => 'TrxWinGo_1M',
                'bet_type'    => 'color',
                'bet_content' => 'green',
                'min_amount'  => 1.00,
                'sort'        => 8,
            ],
        ];
    }

    public function seedFollowPlans(): void
    {
        foreach (self::defaultPlans() as $plan) {
            $existing = $this->db->fetch(
                'SELECT id FROM ' . Tables::FOLLOW_PLANS . ' WHERE plan_code = ?',
                [$plan['plan_code']]
            );

            if ($existing !== null) {
                continue;
            }

            $this->db->execute(
                'INSERT INTO ' . Tables::FOLLOW_PLANS . '
                    (plan_code, name, description, game_code, bet_type, strategy, bet_content, min_amount, sort, state, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [
                    $plan['plan_code'], $plan['name'], $plan['description'], $plan['game_code'],
                    $plan['bet_type'], 'fixed', $plan['bet_content'],
                    number_format((float) $plan['min_amount'], 2, '.', ''), $plan['sort'], Clock::dateTime(),
                ]
            );
        }
    }
}
