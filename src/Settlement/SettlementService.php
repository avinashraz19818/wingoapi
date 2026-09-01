<?php

declare(strict_types=1);

namespace Lottery\Settlement;

use Lottery\Betting\BetService;
use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Draw\DrawService;
use Lottery\Draw\ResultPresenter;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Games\Issue;
use Lottery\Games\IssueScheduler;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Money;
use Lottery\Wallet\WalletService;
use PDOException;
use Throwable;

/**
 * Settles every pending bet of an issue against the drawn result.
 *
 *   gross = SUM(unitAmount x multiplier x odds) over winning selections
 *   tax   = gross x payout_tax_rate            (2% by default)
 *   net   = gross - tax                        -> credited to the wallet
 *
 * The whole operation is replay-safe: bets move out of `pending` inside the
 * same transaction as the wallet credit, and the credit carries a deterministic
 * ledger key, so a re-run (cron overlap, retry, manual settle) is a no-op.
 */
class SettlementService
{
    private Connection $db;
    private RulesFactory $rules;
    private DrawService $draws;
    private IssueScheduler $scheduler;
    private WalletService $wallet;
    private float $taxRate;

    public function __construct(
        Connection $db,
        RulesFactory $rules,
        DrawService $draws,
        IssueScheduler $scheduler,
        WalletService $wallet,
        float $taxRate
    ) {
        $this->db        = $db;
        $this->rules     = $rules;
        $this->draws     = $draws;
        $this->scheduler = $scheduler;
        $this->wallet    = $wallet;
        $this->taxRate   = $taxRate;
    }

    /**
     * Draw (if needed) and settle a single issue.
     *
     * @return array{settled:bool,issueNumber:string,bets:int,won:int,stake:string,payout:string,tax:string}
     */
    public function settleIssue(GameDefinition $game, Issue $issue, ?int $now = null): array
    {
        $now    = $now ?? Clock::now();
        $result = $this->draws->ensureResult($game, $issue, $now);

        if ($result === null) {
            return $this->summary($issue->issueNumber, false, 0, 0, 0.0, 0.0, 0.0);
        }

        $rules   = $this->rules->forGame($game);
        $payload = $result['result'];
        $pending = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ? AND status = ? ORDER BY id ASC',
            [$game->code, $issue->issueNumber, 'pending']
        );

        $bets = 0;
        $won  = 0;
        $stakeTotal  = 0.0;
        $payoutTotal = 0.0;
        $taxTotal    = 0.0;

        foreach ($pending as $bet) {
            try {
                $outcome = $this->settleBet($bet, $payload, $rules);
            } catch (Throwable $e) {
                Log::exception($e, ['stage' => 'settle-bet', 'betNo' => $bet['bet_no']]);
                continue;
            }

            $bets++;
            $stakeTotal += (float) $bet['stake'];
            if ($outcome['won']) {
                $won++;
                $payoutTotal += $outcome['net'];
                $taxTotal    += $outcome['tax'];
            }
        }

        $this->recordSettlement($game, $issue, $bets, $won, $stakeTotal, $payoutTotal, $taxTotal);

        return $this->summary($issue->issueNumber, true, $bets, $won, $stakeTotal, $payoutTotal, $taxTotal);
    }

    /**
     * Settle every finished-but-unsettled issue of a game.
     *
     * @return array<int,array<string,mixed>>
     */
    public function settleDue(GameDefinition $game, int $lookback = 10, ?int $now = null): array
    {
        $now      = $now ?? Clock::now();
        $reports  = [];

        // Start every sweep with a fresh view of the provider.
        $this->draws->flushProviderCache();

        foreach (array_reverse($this->scheduler->recentClosed($game, $lookback, $now)) as $issue) {
            $hasPending = (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ? AND status = ?',
                [$game->code, $issue->issueNumber, 'pending']
            );
            $hasResult = $this->draws->find($game, $issue->issueNumber) !== null;

            if ($hasResult && $hasPending === 0) {
                continue;
            }

            $reports[] = $this->settleIssue($game, $issue, $now);
        }

        return $reports;
    }

    /**
     * @return array{won:bool,gross:float,tax:float,net:float}
     */
    private function settleBet(array $bet, array $result, $rules): array
    {
        $unitStake = Money::round((float) $bet['unit_amount'] * (int) $bet['multiplier']);
        $gross     = 0.0;
        $bestOdds  = 0.0;
        $winners   = [];

        foreach (explode(',', (string) $bet['bet_content']) as $selection) {
            $selection = trim($selection);
            if ($selection === '') {
                continue;
            }
            $outcome = $rules->evaluateSelection((string) $bet['bet_type'], $selection, $result);
            if ($outcome['won']) {
                $gross    += $unitStake * (float) $outcome['odds'];
                $bestOdds  = max($bestOdds, (float) $outcome['odds']);
                $winners[] = $selection;
            }
        }

        $taxed  = Money::applyTax($gross, $this->taxRate);
        $won    = $taxed['gross'] > 0;
        $status = $won ? 'won' : 'lost';

        $this->db->transaction(function (Connection $db) use ($bet, $won, $status, $taxed, $bestOdds) {
            // Re-read under lock so a concurrent settle cannot double-pay.
            $current = $db->fetch(
                'SELECT status FROM ' . Tables::BETS . ' WHERE id = ?' . $db->forUpdate(),
                [$bet['id']]
            );
            if (($current['status'] ?? '') !== 'pending') {
                return;
            }

            $db->execute(
                'UPDATE ' . Tables::BETS . '
                    SET status = ?, payout_gross = ?, payout_tax = ?, payout_net = ?, odds = ?, settled_at = ?
                  WHERE id = ? AND status = ?',
                [
                    $status,
                    Money::format($taxed['gross']),
                    Money::format($taxed['tax']),
                    Money::format($taxed['net']),
                    $bestOdds > 0 ? $bestOdds : (float) $bet['odds'],
                    Clock::dateTime(),
                    $bet['id'],
                    'pending',
                ]
            );

            if ($won && $taxed['net'] > 0) {
                $this->wallet->credit(
                    (int) $bet['user_id'],
                    $taxed['net'],
                    WalletService::entryKey('payout', (string) $bet['id'], (string) $bet['bet_no']),
                    'payout',
                    (string) $bet['bet_no'],
                    $bet['game_code'] . ' ' . $bet['issue_number'] . ' win'
                );
            }
        });

        return ['won' => $won, 'gross' => $taxed['gross'], 'tax' => $taxed['tax'], 'net' => $taxed['net']];
    }

    private function recordSettlement(
        GameDefinition $game,
        Issue $issue,
        int $bets,
        int $won,
        float $stake,
        float $payout,
        float $tax
    ): void {
        $existing = $this->db->fetch(
            'SELECT id FROM ' . Tables::SETTLEMENTS . ' WHERE game_code = ? AND issue_number = ?',
            [$game->code, $issue->issueNumber]
        );

        if ($existing === null) {
            try {
                $this->db->execute(
                    'INSERT INTO ' . Tables::SETTLEMENTS . '
                        (game_code, issue_number, bets_total, bets_won, stake_total, payout_total, tax_total, settled_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $game->code, $issue->issueNumber, $bets, $won,
                        Money::format($stake), Money::format($payout), Money::format($tax), Clock::dateTime(),
                    ]
                );
                return;
            } catch (PDOException $e) {
                if (!$this->db->isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }

        if ($bets === 0) {
            return;
        }

        // A later run picked up bets that were still pending: accumulate.
        $this->db->execute(
            'UPDATE ' . Tables::SETTLEMENTS . '
                SET bets_total = bets_total + ?, bets_won = bets_won + ?,
                    stake_total = stake_total + ?, payout_total = payout_total + ?,
                    tax_total = tax_total + ?, settled_at = ?
              WHERE game_code = ? AND issue_number = ?',
            [
                $bets, $won, Money::format($stake), Money::format($payout), Money::format($tax),
                Clock::dateTime(), $game->code, $issue->issueNumber,
            ]
        );
    }

    /**
     * Per-user win/loss for one issue (GetWinLossResult).
     */
    public function winLossForUser(int $userId, GameDefinition $game, string $issueNumber): array
    {
        $bets = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE user_id = ? AND game_code = ? AND issue_number = ?',
            [$userId, $game->code, $issueNumber]
        );

        $stake  = 0.0;
        $payout = 0.0;
        $status = 'pending';
        foreach ($bets as $bet) {
            $stake  += (float) $bet['stake'];
            $payout += (float) $bet['payout_net'];
        }
        if ($bets !== []) {
            $statuses = array_column($bets, 'status');
            if (!in_array('pending', $statuses, true)) {
                $status = $payout > 0 ? 'won' : 'lost';
            }
        }

        $result = $this->draws->find($game, $issueNumber);

        return [
            'gameCode'    => $game->code,
            'issueNumber' => $issueNumber,
            'status'      => $status,
            'hasResult'   => $result !== null,
            'result'      => $result === null ? null : ResultPresenter::present($result),
            'betCount'    => count($bets),
            'stake'       => Money::format($stake),
            'payout'      => Money::format($payout),
            'profit'      => Money::format($payout - $stake),
            'bets'        => array_map([BetService::class, 'presentBet'], $bets),
        ];
    }

    private function summary(
        string $issueNumber,
        bool $settled,
        int $bets,
        int $won,
        float $stake,
        float $payout,
        float $tax
    ): array {
        return [
            'settled'     => $settled,
            'issueNumber' => $issueNumber,
            'bets'        => $bets,
            'won'         => $won,
            'stake'       => Money::format($stake),
            'payout'      => Money::format($payout),
            'tax'         => Money::format($tax),
        ];
    }
}
