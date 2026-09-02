<?php

declare(strict_types=1);

namespace Lottery\Vip;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use PDOException;

/**
 * VIP experience: 1 EXP per ₹1 staked (configurable multiplier).
 *
 * Levels (cumulative EXP):
 *   L0 0 · L1 3,000 · L2 30,000 · L3 400,000 · L4 4,000,000 · L5 20,000,000
 *
 * Experience is awarded when a bet is accepted and each award is written to an
 * append-only log keyed by `entry_key`, so replays never double-count. Historic
 * bets placed before VIP existed are imported once by {@see backfill()}.
 */
class VipService
{
    private Connection $db;
    private float $expPerRupee;
    /** @var array<int,int> level => threshold */
    private array $levels;

    public function __construct(Connection $db, array $config)
    {
        $this->db          = $db;
        $this->expPerRupee = (float) ($config['exp_per_rupee'] ?? 1.0);
        $levels            = $config['levels'] ?? [0 => 0];
        ksort($levels);
        $this->levels = $levels;
    }

    public function levelForExperience(float $experience): int
    {
        $level = 0;
        foreach ($this->levels as $candidate => $threshold) {
            if ($experience + 0.0001 >= (float) $threshold) {
                $level = (int) $candidate;
            }
        }
        return $level;
    }

    public function experienceForStake(float $stake): float
    {
        return Money::round($stake * $this->expPerRupee);
    }

    public function status(int $userId): array
    {
        $this->ensureRow($userId);
        $row = $this->db->fetch(
            'SELECT experience, level, backfilled_at FROM ' . Tables::VIP . ' WHERE user_id = ?',
            [$userId]
        ) ?? ['experience' => 0, 'level' => 0, 'backfilled_at' => null];

        $experience = Money::round((float) $row['experience']);
        $level      = (int) $row['level'];
        $next       = $this->nextThreshold($level);

        return [
            'userId'          => $userId,
            'experience'      => Money::format($experience),
            'level'           => $level,
            'nextLevel'       => $next === null ? null : $level + 1,
            'nextLevelExp'    => $next === null ? null : Money::format((float) $next),
            'expToNextLevel'  => $next === null ? null : Money::format(max(0.0, $next - $experience)),
            'backfilled'      => $row['backfilled_at'] !== null,
        ];
    }

    /**
     * Award experience for a stake.
     *
     * @return array{added:float,experience:float,levelBefore:int,levelAfter:int,applied:bool}
     */
    public function award(int $userId, float $stake, string $source, string $refId): array
    {
        $added = $this->experienceForStake($stake);
        if ($added <= 0) {
            $status = $this->status($userId);
            return [
                'added'       => 0.0,
                'experience'  => (float) $status['experience'],
                'levelBefore' => (int) $status['level'],
                'levelAfter'  => (int) $status['level'],
                'applied'     => false,
            ];
        }

        $entryKey = 'vip:' . substr(hash('sha256', $source . '|' . $refId), 0, 48);
        $this->ensureRow($userId);

        return $this->db->transaction(function (Connection $db) use ($userId, $added, $source, $refId, $entryKey) {
            $existing = $db->fetch(
                'SELECT experience_after, level_before, level_after FROM ' . Tables::VIP_LOG . ' WHERE entry_key = ?',
                [$entryKey]
            );
            if ($existing !== null) {
                return [
                    'added'       => 0.0,
                    'experience'  => Money::round((float) $existing['experience_after']),
                    'levelBefore' => (int) $existing['level_before'],
                    'levelAfter'  => (int) $existing['level_after'],
                    'applied'     => false,
                ];
            }

            $row = $db->fetch(
                'SELECT experience, level FROM ' . Tables::VIP . ' WHERE user_id = ?' . $db->forUpdate(),
                [$userId]
            ) ?? ['experience' => 0, 'level' => 0];

            $before      = Money::round((float) $row['experience']);
            $levelBefore = (int) $row['level'];
            $after       = Money::round($before + $added);
            $levelAfter  = $this->levelForExperience($after);

            $db->execute(
                'UPDATE ' . Tables::VIP . ' SET experience = ?, level = ?, updated_at = ? WHERE user_id = ?',
                [Money::format($after), $levelAfter, Clock::dateTime(), $userId]
            );

            try {
                $db->execute(
                    'INSERT INTO ' . Tables::VIP_LOG . '
                        (entry_key, user_id, source, ref_id, experience_added, experience_after, level_before, level_after, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $entryKey, $userId, $source, $refId,
                        Money::format($added), Money::format($after),
                        $levelBefore, $levelAfter, Clock::dateTime(),
                    ]
                );
            } catch (PDOException $e) {
                if (!$db->isDuplicateKey($e)) {
                    throw $e;
                }
            }

            return [
                'added'       => $added,
                'experience'  => $after,
                'levelBefore' => $levelBefore,
                'levelAfter'  => $levelAfter,
                'applied'     => true,
            ];
        });
    }

    /**
     * One-time import of experience from bets placed before VIP tracking.
     * Runs at most once per user (guarded by lot_user_vip.backfilled_at).
     */
    public function backfill(int $userId): array
    {
        $this->ensureRow($userId);

        $row = $this->db->fetch(
            'SELECT experience, level, backfilled_at FROM ' . Tables::VIP . ' WHERE user_id = ?',
            [$userId]
        );
        if (($row['backfilled_at'] ?? null) !== null) {
            return ['backfilled' => false, 'experience' => Money::round((float) $row['experience']), 'level' => (int) $row['level']];
        }

        return $this->db->transaction(function (Connection $db) use ($userId) {
            $locked = $db->fetch(
                'SELECT experience, level, backfilled_at FROM ' . Tables::VIP . ' WHERE user_id = ?' . $db->forUpdate(),
                [$userId]
            ) ?? ['experience' => 0, 'level' => 0, 'backfilled_at' => null];

            if ($locked['backfilled_at'] !== null) {
                return [
                    'backfilled' => false,
                    'experience' => Money::round((float) $locked['experience']),
                    'level'      => (int) $locked['level'],
                ];
            }

            $stake = (float) ($db->fetchValue(
                'SELECT COALESCE(SUM(stake), 0) FROM ' . Tables::BETS . ' WHERE user_id = ? AND vip_counted = 0',
                [$userId]
            ) ?? 0);

            $added      = $this->experienceForStake($stake);
            $experience = Money::round((float) $locked['experience'] + $added);
            $level      = $this->levelForExperience($experience);

            $db->execute(
                'UPDATE ' . Tables::VIP . '
                    SET experience = ?, level = ?, backfilled_at = ?, updated_at = ?
                  WHERE user_id = ?',
                [Money::format($experience), $level, Clock::dateTime(), Clock::dateTime(), $userId]
            );
            $db->execute(
                'UPDATE ' . Tables::BETS . ' SET vip_counted = 1 WHERE user_id = ? AND vip_counted = 0',
                [$userId]
            );

            if ($added > 0) {
                $db->execute(
                    'INSERT INTO ' . Tables::VIP_LOG . '
                        (entry_key, user_id, source, ref_id, experience_added, experience_after, level_before, level_after, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        'vip:backfill:' . $userId, $userId, 'backfill', (string) $userId,
                        Money::format($added), Money::format($experience),
                        (int) $locked['level'], $level, Clock::dateTime(),
                    ]
                );
            }

            return ['backfilled' => true, 'experience' => $experience, 'level' => $level, 'imported' => Money::format($added)];
        });
    }

    private function nextThreshold(int $level): ?int
    {
        $next = $level + 1;
        return isset($this->levels[$next]) ? (int) $this->levels[$next] : null;
    }

    private function ensureRow(int $userId): void
    {
        try {
            $this->db->execute(
                $this->db->insertIgnore() . ' ' . Tables::VIP . ' (user_id, experience, level, updated_at) VALUES (?, 0, 0, ?)',
                [$userId, Clock::dateTime()]
            );
        } catch (PDOException $e) {
            if (!$this->db->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /** @return array<int,array{level:int,experience:int}> */
    public function levelTable(): array
    {
        $table = [];
        foreach ($this->levels as $level => $threshold) {
            $table[] = ['level' => (int) $level, 'experience' => (int) $threshold];
        }
        return $table;
    }
}
