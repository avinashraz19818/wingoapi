<?php

declare(strict_types=1);

namespace Lottery\Follow;

use Lottery\Betting\BetService;
use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Games\GameDefinition;
use Lottery\Games\GameRegistry;
use Lottery\Games\IssueScheduler;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Money;
use PDOException;
use Throwable;

/**
 * Follow / copy-trading.
 *
 * An admin curates plans (e.g. "BigSmall — always Big on WinGo 1M"). A user
 * subscribes with an amount, a multiplier and an optional round budget; the
 * worker then places one bet per issue on their behalf until the budget runs
 * out, the stop-loss trips, the wallet runs dry, or the user stops the plan.
 *
 * Duplicate protection: lot_follow_orders is unique on
 * (subscription_id, issue_number), and each generated bet reuses the same
 * idempotency keys, so a worker restart never double-bets a round.
 */
class FollowService
{
    private Connection $db;
    private BetService $bets;
    private GameRegistry $registry;
    private IssueScheduler $scheduler;

    public function __construct(Connection $db, BetService $bets, GameRegistry $registry, IssueScheduler $scheduler)
    {
        $this->db        = $db;
        $this->bets      = $bets;
        $this->registry  = $registry;
        $this->scheduler = $scheduler;
    }

    /** @return array<int,array<string,mixed>> */
    public function plans(?string $gameCode = null): array
    {
        if ($gameCode !== null && $gameCode !== '') {
            $rows = $this->db->fetchAll(
                'SELECT * FROM ' . Tables::FOLLOW_PLANS . ' WHERE state = 1 AND game_code = ? ORDER BY sort ASC, id ASC',
                [$gameCode]
            );
        } else {
            $rows = $this->db->fetchAll(
                'SELECT * FROM ' . Tables::FOLLOW_PLANS . ' WHERE state = 1 ORDER BY sort ASC, id ASC'
            );
        }

        return array_map(static fn(array $row): array => [
            'planId'      => (int) $row['id'],
            'planCode'    => $row['plan_code'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'gameCode'    => $row['game_code'],
            'betType'     => $row['bet_type'],
            'betContent'  => $row['bet_content'],
            'strategy'    => $row['strategy'],
            'minAmount'   => Money::format((float) $row['min_amount']),
        ], $rows);
    }

    public function findPlan(string $planCode): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM ' . Tables::FOLLOW_PLANS . ' WHERE plan_code = ? AND state = 1',
            [$planCode]
        );
    }

    /**
     * Subscribe a user to a plan (AddFollowRecord).
     */
    public function subscribe(int $userId, array $input): array
    {
        $planCode = trim((string) ($input['planCode'] ?? ''));
        $plan     = $this->findPlan($planCode);
        if ($plan === null) {
            throw ApiException::notFound("Unknown follow plan: {$planCode}");
        }

        $game       = $this->registry->get((string) $plan['game_code']);
        $amount     = Money::round((float) ($input['amount'] ?? $plan['min_amount']));
        $multiplier = (int) ($input['multiplier'] ?? 1);
        $rounds     = (int) ($input['rounds'] ?? 0);
        $stopLoss   = Money::round((float) ($input['stopLoss'] ?? 0));

        if ($amount < (float) $plan['min_amount']) {
            throw ApiException::validation('Amount is below the plan minimum of ' . Money::format((float) $plan['min_amount']));
        }
        if ($multiplier < 1 || $multiplier > 1000) {
            throw ApiException::validation('Multiplier must be between 1 and 1000');
        }
        if ($rounds < 0 || $rounds > 1000) {
            throw ApiException::validation('Rounds must be between 0 (unlimited) and 1000');
        }

        $active = $this->db->fetch(
            'SELECT id FROM ' . Tables::FOLLOW_SUBS . ' WHERE user_id = ? AND plan_id = ? AND status = ?',
            [$userId, $plan['id'], 'active']
        );
        if ($active !== null) {
            throw ApiException::conflict('You are already following this plan');
        }

        $id = $this->db->insertGetId(
            'INSERT INTO ' . Tables::FOLLOW_SUBS . '
                (user_id, plan_id, plan_code, game_code, amount, multiplier, total_rounds, completed_rounds,
                 stop_loss, net_profit, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?)',
            [
                $userId, $plan['id'], $plan['plan_code'], $game->code,
                Money::format($amount), $multiplier, $rounds,
                Money::format($stopLoss), 'active', Clock::dateTime(),
            ]
        );

        Log::info('follow subscription created', ['user' => $userId, 'plan' => $plan['plan_code'], 'id' => $id]);

        return $this->presentSubscription($this->subscription($id) ?? []);
    }

    /** Stop a subscription (StopFollowRecord). */
    public function stop(int $userId, array $input): array
    {
        $id       = (int) ($input['followId'] ?? $input['id'] ?? 0);
        $planCode = trim((string) ($input['planCode'] ?? ''));

        if ($id > 0) {
            $row = $this->db->fetch(
                'SELECT * FROM ' . Tables::FOLLOW_SUBS . ' WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
        } elseif ($planCode !== '') {
            $row = $this->db->fetch(
                'SELECT * FROM ' . Tables::FOLLOW_SUBS . ' WHERE user_id = ? AND plan_code = ? AND status = ?',
                [$userId, $planCode, 'active']
            );
        } else {
            throw ApiException::validation('followId or planCode is required');
        }

        if ($row === null) {
            throw ApiException::notFound('Follow record not found');
        }
        if ($row['status'] !== 'active') {
            return $this->presentSubscription($row);
        }

        $this->db->execute(
            'UPDATE ' . Tables::FOLLOW_SUBS . ' SET status = ?, stopped_at = ? WHERE id = ?',
            ['stopped', Clock::dateTime(), $row['id']]
        );

        Log::info('follow subscription stopped', ['user' => $userId, 'id' => $row['id']]);

        return $this->presentSubscription($this->subscription((int) $row['id']) ?? $row);
    }

    public function subscription(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM ' . Tables::FOLLOW_SUBS . ' WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function userSubscriptions(int $userId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::FOLLOW_SUBS . ' WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$userId]
        );

        return array_map([$this, 'presentSubscription'], $rows);
    }

    /**
     * Place the copy-trade bets for the currently open issue of a game.
     *
     * @return array{placed:int,skipped:int,failed:int,issueNumber:string}
     */
    public function runForGame(GameDefinition $game, ?int $now = null): array
    {
        $now   = $now ?? Clock::now();
        $issue = $this->scheduler->current($game, $now);

        $report = ['placed' => 0, 'skipped' => 0, 'failed' => 0, 'issueNumber' => $issue->issueNumber];
        if (!$issue->isOpenAt($now)) {
            return $report;
        }

        $subscriptions = $this->db->fetchAll(
            'SELECT s.*, p.bet_type, p.bet_content, p.strategy
               FROM ' . Tables::FOLLOW_SUBS . ' s
               JOIN ' . Tables::FOLLOW_PLANS . ' p ON p.id = s.plan_id
              WHERE s.status = ? AND s.game_code = ?',
            ['active', $game->code]
        );

        foreach ($subscriptions as $subscription) {
            $outcome = $this->placeForSubscription($subscription, $game, $issue->issueNumber);
            $report[$outcome]++;
        }

        return $report;
    }

    private function placeForSubscription(array $subscription, GameDefinition $game, string $issueNumber): string
    {
        $subscriptionId = (int) $subscription['id'];

        // Round budget reached?
        if ((int) $subscription['total_rounds'] > 0
            && (int) $subscription['completed_rounds'] >= (int) $subscription['total_rounds']) {
            $this->complete($subscriptionId, 'completed');
            return 'skipped';
        }

        // Claim the round first: the unique index makes this the lock.
        try {
            $this->db->execute(
                'INSERT INTO ' . Tables::FOLLOW_ORDERS . '
                    (subscription_id, user_id, game_code, issue_number, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $subscriptionId, (int) $subscription['user_id'], $game->code,
                    $issueNumber, 'pending', Clock::dateTime(),
                ]
            );
        } catch (PDOException $e) {
            if ($this->db->isDuplicateKey($e)) {
                return 'skipped';
            }
            throw $e;
        }

        try {
            $placement = $this->bets->place((int) $subscription['user_id'], [
                'gameCode'        => $game->code,
                'betType'         => (string) $subscription['bet_type'],
                'betContent'      => (string) $subscription['bet_content'],
                'amount'          => (float) $subscription['amount'],
                'multiplier'      => (int) $subscription['multiplier'],
                'issueNumber'     => $issueNumber,
                'requestGroupKey' => 'follow' . $subscriptionId,
                'requestKey'      => substr(hash('sha256', 'follow|' . $subscriptionId . '|' . $issueNumber), 0, 64),
                'source'          => 'follow',
            ]);
        } catch (Throwable $e) {
            $this->db->execute(
                'UPDATE ' . Tables::FOLLOW_ORDERS . ' SET status = ?, message = ? WHERE subscription_id = ? AND issue_number = ?',
                ['failed', mb_substr($e->getMessage(), 0, 180), $subscriptionId, $issueNumber]
            );

            // Wallet problems stop the plan; validation problems too.
            $this->complete($subscriptionId, 'stopped');
            Log::warning('follow bet failed, subscription stopped', [
                'subscription' => $subscriptionId, 'error' => $e->getMessage(),
            ]);

            return 'failed';
        }

        $this->db->execute(
            'UPDATE ' . Tables::FOLLOW_ORDERS . ' SET status = ?, bet_id = ? WHERE subscription_id = ? AND issue_number = ?',
            ['placed', $placement['betId'], $subscriptionId, $issueNumber]
        );
        $this->db->execute(
            'UPDATE ' . Tables::FOLLOW_SUBS . ' SET completed_rounds = completed_rounds + 1 WHERE id = ?',
            [$subscriptionId]
        );

        $refreshed = $this->subscription($subscriptionId);
        if ($refreshed !== null
            && (int) $refreshed['total_rounds'] > 0
            && (int) $refreshed['completed_rounds'] >= (int) $refreshed['total_rounds']) {
            $this->complete($subscriptionId, 'completed');
        }

        return 'placed';
    }

    private function complete(int $subscriptionId, string $status): void
    {
        $this->db->execute(
            'UPDATE ' . Tables::FOLLOW_SUBS . ' SET status = ?, stopped_at = ? WHERE id = ? AND status = ?',
            [$status, Clock::dateTime(), $subscriptionId, 'active']
        );
    }

    public function presentSubscription(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'followId'        => (int) $row['id'],
            'planCode'        => $row['plan_code'],
            'gameCode'        => $row['game_code'],
            'amount'          => Money::format((float) $row['amount']),
            'multiplier'      => (int) $row['multiplier'],
            'totalRounds'     => (int) $row['total_rounds'],
            'completedRounds' => (int) $row['completed_rounds'],
            'stopLoss'        => Money::format((float) $row['stop_loss']),
            'status'          => $row['status'],
            'createdAt'       => $row['created_at'],
            'stoppedAt'       => $row['stopped_at'],
        ];
    }
}
