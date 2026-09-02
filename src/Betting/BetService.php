<?php

declare(strict_types=1);

namespace Lottery\Betting;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameRegistry;
use Lottery\Games\IssueScheduler;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Money;
use Lottery\Vip\VipService;
use Lottery\Wallet\WalletService;
use PDOException;

/**
 * Bet placement engine.
 *
 *   stake = unitAmount x multiplier x units
 *
 * where `units` is the number of distinct selections inside bet_content
 * ("1,2,3" on WinGo number = 3 units). Stake limits, wallet debit, VIP
 * experience and idempotency are all handled here inside one transaction.
 */
class BetService
{
    private Connection $db;
    private GameRegistry $registry;
    private RulesFactory $rules;
    private IssueScheduler $scheduler;
    private WalletService $wallet;
    private VipService $vip;
    private array $config;

    public function __construct(
        Connection $db,
        GameRegistry $registry,
        RulesFactory $rules,
        IssueScheduler $scheduler,
        WalletService $wallet,
        VipService $vip,
        array $bettingConfig
    ) {
        $this->db        = $db;
        $this->registry  = $registry;
        $this->rules     = $rules;
        $this->scheduler = $scheduler;
        $this->wallet    = $wallet;
        $this->vip       = $vip;
        $this->config    = $bettingConfig;
    }

    /**
     * @param array{
     *   gameCode:string, betType:string, betContent:string, amount:float,
     *   multiplier?:int, issueNumber?:string, requestGroupKey?:string,
     *   requestKey?:string, source?:string
     * } $input
     */
    public function place(int $userId, array $input): array
    {
        $game       = $this->registry->get((string) $input['gameCode']);
        $rules      = $this->rules->forGame($game);
        $betType    = strtolower(trim((string) $input['betType']));
        $betContent = strtolower(trim((string) $input['betContent']));
        $selections = $rules->parseSelections($betType, $betContent);
        $units      = count($selections);

        $unitAmount = Money::round((float) $input['amount']);
        $multiplier = (int) ($input['multiplier'] ?? 1);
        $source     = (string) ($input['source'] ?? 'manual');

        if ($multiplier < 1 || $multiplier > 10000) {
            throw ApiException::validation('Multiplier must be between 1 and 10000');
        }
        if ($unitAmount <= 0) {
            throw ApiException::validation('Bet amount must be greater than zero');
        }

        $stake    = Money::round($unitAmount * $multiplier * $units);
        $minStake = (float) $this->config['min_stake'];
        $maxStake = (float) $this->config['max_stake'];

        if ($stake < $minStake) {
            throw ApiException::validation('Minimum total stake is ' . Money::format($minStake));
        }
        if ($stake > $maxStake) {
            throw ApiException::validation('Maximum total stake is ' . Money::format($maxStake));
        }

        /* ------------------------------------------------------ target issue */
        $now   = Clock::now();
        $issue = $this->scheduler->current($game, $now);

        $requestedIssue = trim((string) ($input['issueNumber'] ?? ''));
        if ($requestedIssue !== '' && $requestedIssue !== $issue->issueNumber) {
            throw ApiException::closed('Issue ' . $requestedIssue . ' is not accepting bets; current issue is ' . $issue->issueNumber);
        }
        if (!$issue->isOpenAt($now)) {
            throw ApiException::closed('Betting for issue ' . $issue->issueNumber . ' is closed');
        }

        /* ------------------------------------------------------ idempotency */
        $groupKey   = $this->normaliseKey((string) ($input['requestGroupKey'] ?? ''));
        $requestKey = $this->normaliseKey((string) ($input['requestKey'] ?? ''));

        if ($requestKey === '') {
            $requestKey = hash('sha256', implode('|', [
                $userId, $game->code, $issue->issueNumber, $betType, $betContent,
                Money::format($unitAmount), $multiplier, $groupKey,
            ]));
        }
        if ($groupKey === '') {
            $groupKey = substr(hash('sha256', $userId . '|' . $issue->issueNumber . '|' . $requestKey), 0, 32);
        }

        $existing = $this->findByRequest($userId, $groupKey, $requestKey);
        if ($existing !== null) {
            return $this->acceptedPayload($existing, $this->wallet->balance($userId), 0.0, true);
        }

        /* ------------------------------------------------- odds & bookkeeping */
        $bestOdds  = 0.0;
        foreach ($selections as $selection) {
            $bestOdds = max($bestOdds, $rules->baseOdds($betType, $selection));
        }
        $unitStake        = Money::round($unitAmount * $multiplier);
        $potentialGross   = Money::round($unitStake * $bestOdds);
        $taxRate          = (float) $this->config['payout_tax_rate'];
        $potentialPayout  = Money::applyTax($potentialGross, $taxRate)['net'];
        $betNo            = $this->generateBetNo();
        $canonicalContent = implode(',', $selections);

        try {
            $placement = $this->db->transaction(function (Connection $db) use (
                $userId, $game, $issue, $betType, $canonicalContent, $unitAmount, $multiplier,
                $units, $stake, $bestOdds, $potentialPayout, $betNo, $groupKey, $requestKey, $source
            ) {
                $entryKey = WalletService::entryKey('bet', (string) $userId, $groupKey, $requestKey);
                $this->wallet->debit(
                    $userId,
                    $stake,
                    $entryKey,
                    'bet',
                    $betNo,
                    $game->code . ' ' . $issue->issueNumber
                );

                $betId = $db->insertGetId(
                    'INSERT INTO ' . Tables::BETS . '
                        (bet_no, user_id, game_code, family, issue_number, bet_type, bet_content,
                         unit_amount, multiplier, units, stake, odds, potential_payout, status,
                         vip_experience, vip_counted, source, request_group_key, request_key, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $betNo, $userId, $game->code, $game->family, $issue->issueNumber,
                        $betType, $canonicalContent, Money::format($unitAmount), $multiplier, $units,
                        Money::format($stake), $bestOdds, Money::format($potentialPayout), 'pending',
                        Money::format(0), 1, $source, $groupKey, $requestKey, Clock::dateTime(),
                    ]
                );

                $vip = $this->vip->award($userId, $stake, 'bet', $betNo);
                $db->execute(
                    'UPDATE ' . Tables::BETS . ' SET vip_experience = ? WHERE id = ?',
                    [Money::format($vip['added']), $betId]
                );

                return ['betId' => $betId, 'vip' => $vip];
            });
        } catch (PDOException $e) {
            if ($this->db->isDuplicateKey($e)) {
                // Concurrent duplicate of the same idempotent request.
                $existing = $this->findByRequest($userId, $groupKey, $requestKey);
                if ($existing !== null) {
                    return $this->acceptedPayload($existing, $this->wallet->balance($userId), 0.0, true);
                }
            }
            throw $e;
        }

        $bet = $this->findById((int) $placement['betId']);
        if ($bet === null) {
            throw ApiException::server('Bet was not persisted');
        }

        Log::info('bet accepted', [
            'betNo' => $betNo, 'user' => $userId, 'game' => $game->code,
            'issue' => $issue->issueNumber, 'stake' => Money::format($stake),
        ]);

        return $this->acceptedPayload(
            $bet,
            $this->wallet->balance($userId),
            (float) $placement['vip']['added'],
            false
        );
    }

    public function findById(int $betId): ?array
    {
        return $this->db->fetch('SELECT * FROM ' . Tables::BETS . ' WHERE id = ?', [$betId]);
    }

    public function findByRequest(int $userId, string $groupKey, string $requestKey): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM ' . Tables::BETS . ' WHERE user_id = ? AND request_group_key = ? AND request_key = ?',
            [$userId, $groupKey, $requestKey]
        );
    }

    /**
     * Paginated bet history for a user.
     *
     * @return array{list:array<int,array<string,mixed>>,total:int}
     */
    public function history(int $userId, ?string $gameCode, int $pageNo, int $pageSize): array
    {
        $params = [$userId];
        $where  = 'user_id = ?';
        if ($gameCode !== null && $gameCode !== '') {
            $where   .= ' AND game_code = ?';
            $params[] = $gameCode;
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE ' . $where, $params);
        $rows  = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE ' . $where . '
              ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        return ['list' => array_map([self::class, 'presentBet'], $rows), 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingForIssue(string $gameCode, string $issueNumber): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ? AND status = ? ORDER BY id ASC',
            [$gameCode, $issueNumber, 'pending']
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function userBetsForIssue(int $userId, string $gameCode, string $issueNumber): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE user_id = ? AND game_code = ? AND issue_number = ? ORDER BY id ASC',
            [$userId, $gameCode, $issueNumber]
        );
    }

    public static function presentBet(array $row): array
    {
        return [
            'betId'        => (int) $row['id'],
            'betNo'        => $row['bet_no'],
            'gameCode'     => $row['game_code'],
            'issueNumber'  => $row['issue_number'],
            'betType'      => $row['bet_type'],
            'betContent'   => $row['bet_content'],
            'amount'       => Money::format((float) $row['unit_amount']),
            'multiplier'   => (int) $row['multiplier'],
            'units'        => (int) $row['units'],
            'stake'        => Money::format((float) $row['stake']),
            'odds'         => (float) $row['odds'],
            'status'       => $row['status'],
            'payoutGross'  => Money::format((float) $row['payout_gross']),
            'payoutTax'    => Money::format((float) $row['payout_tax']),
            'payout'       => Money::format((float) $row['payout_net']),
            'profit'       => Money::format((float) $row['payout_net'] - (float) $row['stake']),
            'source'       => $row['source'],
            'createdAt'    => $row['created_at'],
            'settledAt'    => $row['settled_at'],
        ];
    }

    private function acceptedPayload(array $bet, float $balance, float $vipAdded, bool $duplicate): array
    {
        return [
            'betId'              => (int) $bet['id'],
            'betNo'              => $bet['bet_no'],
            'accepted'           => true,
            'duplicate'          => $duplicate,
            'balance'            => Money::format($balance),
            'stake'              => Money::format((float) $bet['stake']),
            'units'              => (int) $bet['units'],
            'odds'               => (float) $bet['odds'],
            'potentialPayout'    => Money::format((float) $bet['potential_payout']),
            'gameCode'           => $bet['game_code'],
            'issueNumber'        => $bet['issue_number'],
            'betType'            => $bet['bet_type'],
            'betContent'         => $bet['bet_content'],
            'vipExperienceAdded' => Money::format($vipAdded),
        ];
    }

    private function normaliseKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $key)) {
            // Any client-supplied shape is accepted but stored as a safe hash.
            return substr(hash('sha256', $key), 0, 64);
        }
        return $key;
    }

    private function generateBetNo(): string
    {
        return date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }
}
