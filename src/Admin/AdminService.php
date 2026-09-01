<?php

declare(strict_types=1);

namespace Lottery\Admin;

use Lottery\App;
use Lottery\Betting\BetService;
use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Draw\ResultPresenter;
use Lottery\Games\Families\D5Rules;
use Lottery\Games\Families\K3Rules;
use Lottery\Games\Families\MotoRaceRules;
use Lottery\Games\Families\WinGoRules;
use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use Lottery\Support\Validator;
use Lottery\Wallet\WalletService;
use PDOException;

/**
 * Read/write operations that back the web admin panel.
 *
 * Everything here is parameterised SQL over the same tables the public API
 * uses — the panel never bypasses the wallet, settlement or override services.
 */
class AdminService
{
    private App $app;
    private Connection $db;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->db  = $app->db();
    }

    /* ===================================================================
     |  Dashboard
     * ================================================================ */

    public function dashboard(int $days = 7): array
    {
        $todayStart = date('Y-m-d 00:00:00', Clock::now());
        $since      = date('Y-m-d 00:00:00', Clock::now() - (max(1, $days) - 1) * 86400);

        $today = $this->db->fetch(
            'SELECT COUNT(*) AS bets, COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout,
                    COALESCE(SUM(payout_tax),0) AS tax, COUNT(DISTINCT user_id) AS players
               FROM ' . Tables::BETS . ' WHERE created_at >= ?',
            [$todayStart]
        ) ?? [];

        $allTime = $this->db->fetch(
            'SELECT COUNT(*) AS bets, COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout
               FROM ' . Tables::BETS
        ) ?? [];

        $wallets = $this->db->fetch(
            'SELECT COUNT(*) AS wallets, COALESCE(SUM(balance),0) AS balance FROM ' . Tables::WALLETS
        ) ?? [];

        $series = $this->db->fetchAll(
            'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS bets,
                    COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout
               FROM ' . Tables::BETS . '
              WHERE created_at >= ?
              GROUP BY substr(created_at, 1, 10)
              ORDER BY day ASC',
            [$since]
        );
        if ($this->db->isMysql()) {
            $series = $this->db->fetchAll(
                'SELECT DATE(created_at) AS day, COUNT(*) AS bets,
                        COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout
                   FROM ' . Tables::BETS . '
                  WHERE created_at >= ?
                  GROUP BY DATE(created_at)
                  ORDER BY day ASC',
                [$since]
            );
        }

        $perGame = $this->db->fetchAll(
            'SELECT game_code, COUNT(*) AS bets, COALESCE(SUM(stake),0) AS stake,
                    COALESCE(SUM(payout_net),0) AS payout
               FROM ' . Tables::BETS . '
              WHERE created_at >= ?
              GROUP BY game_code
              ORDER BY stake DESC',
            [$todayStart]
        );

        $stakeToday  = (float) ($today['stake'] ?? 0);
        $payoutToday = (float) ($today['payout'] ?? 0);

        return [
            'today' => [
                'bets'    => (int) ($today['bets'] ?? 0),
                'players' => (int) ($today['players'] ?? 0),
                'stake'   => Money::format($stakeToday),
                'payout'  => Money::format($payoutToday),
                'tax'     => Money::format((float) ($today['tax'] ?? 0)),
                'ggr'     => Money::format($stakeToday - $payoutToday),
                'margin'  => $stakeToday > 0 ? round((($stakeToday - $payoutToday) / $stakeToday) * 100, 2) : 0.0,
            ],
            'allTime' => [
                'bets'   => (int) ($allTime['bets'] ?? 0),
                'stake'  => Money::format((float) ($allTime['stake'] ?? 0)),
                'payout' => Money::format((float) ($allTime['payout'] ?? 0)),
                'ggr'    => Money::format((float) ($allTime['stake'] ?? 0) - (float) ($allTime['payout'] ?? 0)),
            ],
            'users' => [
                'total'    => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::USERS) ?? 0),
                'blocked'  => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::USERS . ' WHERE status <> 1') ?? 0),
                'wallets'  => (int) ($wallets['wallets'] ?? 0),
                'balance'  => Money::format((float) ($wallets['balance'] ?? 0)),
            ],
            'pendingBets'   => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE status = ?', ['pending']) ?? 0),
            'activeFollows' => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::FOLLOW_SUBS . ' WHERE status = ?', ['active']) ?? 0),
            'openOverrides' => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::OVERRIDES . ' WHERE status = ?', ['pending']) ?? 0),
            'series'        => array_map(static fn(array $r): array => [
                'day'    => (string) $r['day'],
                'bets'   => (int) $r['bets'],
                'stake'  => Money::format((float) $r['stake']),
                'payout' => Money::format((float) $r['payout']),
                'ggr'    => Money::format((float) $r['stake'] - (float) $r['payout']),
            ], $series),
            'perGame' => array_map(static fn(array $r): array => [
                'gameCode' => $r['game_code'],
                'bets'     => (int) $r['bets'],
                'stake'    => Money::format((float) $r['stake']),
                'payout'   => Money::format((float) $r['payout']),
                'ggr'      => Money::format((float) $r['stake'] - (float) $r['payout']),
            ], $perGame),
            'recentResults' => ResultPresenter::presentMany(array_map(function (array $row): array {
                $row['result'] = json_decode((string) $row['result_json'], true) ?: [];
                return $row;
            }, $this->db->fetchAll(
                'SELECT * FROM ' . Tables::RESULTS . ' ORDER BY id DESC LIMIT 12'
            ))),
            'system' => $this->systemInfo(),
        ];
    }

    public function systemInfo(): array
    {
        $lastResult     = $this->db->fetchValue('SELECT MAX(created_at) FROM ' . Tables::RESULTS);
        $lastSettlement = $this->db->fetchValue('SELECT MAX(settled_at) FROM ' . Tables::SETTLEMENTS);
        $lag            = $lastResult === null ? null : max(0, Clock::now() - (int) strtotime((string) $lastResult));

        return [
            'version'        => $this->app->config('app.version'),
            'env'            => $this->app->config('app.env'),
            'timezone'       => $this->app->config('app.timezone'),
            'serverTime'     => Clock::dateTime(),
            'driver'         => $this->db->driver(),
            'serverVersion'  => $this->db->serverVersion(),
            'schemaVersion'  => $this->app->migrator()->currentVersion(),
            'latestSchema'   => $this->app->migrator()->latestVersion(),
            'games'          => count($this->app->registry()->all()),
            'drawBaseUrl'    => $this->app->config('draw_base_url'),
            'forceRemote'    => (bool) $this->app->config('force_remote_draw'),
            'payoutTaxRate'  => (float) $this->app->config('betting.payout_tax_rate'),
            'minStake'       => Money::format((float) $this->app->config('betting.min_stake')),
            'maxStake'       => Money::format((float) $this->app->config('betting.max_stake')),
            'rateLimit'      => (int) $this->app->config('security.rate_limit'),
            'requireSign'    => (bool) $this->app->config('auth.require_signature'),
            'lastResultAt'   => $lastResult,
            'lastSettledAt'  => $lastSettlement,
            'workerLagSecs'  => $lag,
            'workerHealthy'  => $lag === null ? false : $lag < 900,
        ];
    }

    /* ===================================================================
     |  Games & live rounds
     * ================================================================ */

    public function games(): array
    {
        $now  = Clock::now();
        $rows = [];

        foreach ($this->app->registry()->all() as $game) {
            $issue   = $this->app->scheduler()->current($game, $now);
            $summary = $this->db->fetch(
                'SELECT COUNT(*) AS bets, COALESCE(SUM(stake),0) AS stake
                   FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ?',
                [$game->code, $issue->issueNumber]
            ) ?? [];

            $override = $this->db->fetch(
                'SELECT override_value, mode, issue_number FROM ' . Tables::OVERRIDES . '
                  WHERE game_code = ? AND status = ? ORDER BY id DESC',
                [$game->code, 'pending']
            );

            $rows[] = $game->toArray() + [
                'currentIssue'    => $issue->toArray($now),
                'liveBets'        => (int) ($summary['bets'] ?? 0),
                'liveStake'       => Money::format((float) ($summary['stake'] ?? 0)),
                'pendingOverride' => $override === null ? null : [
                    'value'       => $override['override_value'],
                    'mode'        => $override['mode'],
                    'issueNumber' => $override['issue_number'],
                ],
            ];
        }

        return ['list' => $rows, 'serverTime' => Clock::dateTime($now)];
    }

    /**
     * Risk view for one round: stake per selection plus the payout the house
     * would owe for each possible outcome (WinGo/TRX digits, K3 dice combos,
     * MotoRace champions). Lets an operator see the exposure before deciding
     * whether to force a result.
     */
    public function exposure(GameDefinition $game, string $issueNumber): array
    {
        $bets = $this->db->fetchAll(
            'SELECT bet_type, bet_content, unit_amount, multiplier, stake, user_id
               FROM ' . Tables::BETS . '
              WHERE game_code = ? AND issue_number = ? AND status = ?',
            [$game->code, $issueNumber, 'pending']
        );

        $rules    = $this->app->rules()->forGame($game);
        $taxRate  = (float) $this->app->config('betting.payout_tax_rate');
        $totals   = ['bets' => count($bets), 'stake' => 0.0, 'players' => count(array_unique(array_column($bets, 'user_id')))];
        $bySelect = [];

        foreach ($bets as $bet) {
            $totals['stake'] += (float) $bet['stake'];
            foreach (explode(',', (string) $bet['bet_content']) as $selection) {
                $selection = trim($selection);
                if ($selection === '') {
                    continue;
                }
                $key = $bet['bet_type'] . ':' . $selection;
                $bySelect[$key] = ($bySelect[$key] ?? 0.0)
                    + Money::round((float) $bet['unit_amount'] * (int) $bet['multiplier']);
            }
        }

        $outcomes = [];
        foreach ($this->candidateOutcomes($game) as $label => $result) {
            $payout = 0.0;
            foreach ($bets as $bet) {
                $unitStake = Money::round((float) $bet['unit_amount'] * (int) $bet['multiplier']);
                foreach (explode(',', (string) $bet['bet_content']) as $selection) {
                    $selection = trim($selection);
                    if ($selection === '') {
                        continue;
                    }
                    $outcome = $rules->evaluateSelection((string) $bet['bet_type'], $selection, $result);
                    if ($outcome['won']) {
                        $payout += $unitStake * (float) $outcome['odds'];
                    }
                }
            }
            $net        = Money::applyTax($payout, $taxRate)['net'];
            $outcomes[] = [
                'outcome'  => (string) $label,
                'payout'   => Money::format($net),
                'profit'   => Money::format($totals['stake'] - $net),
                'override' => (string) $label,
            ];
        }

        usort($outcomes, static fn(array $a, array $b) => (float) $b['profit'] <=> (float) $a['profit']);

        $selections = [];
        foreach ($bySelect as $key => $stake) {
            [$type, $value] = explode(':', $key, 2);
            $selections[] = ['betType' => $type, 'selection' => $value, 'stake' => Money::format($stake)];
        }
        usort($selections, static fn(array $a, array $b) => (float) $b['stake'] <=> (float) $a['stake']);

        return [
            'gameCode'    => $game->code,
            'issueNumber' => $issueNumber,
            'bets'        => $totals['bets'],
            'players'     => $totals['players'],
            'stake'       => Money::format($totals['stake']),
            'selections'  => $selections,
            'outcomes'    => $outcomes,
            'note'        => $outcomes === []
                ? 'Outcome simulation is not available for this family (too many combinations).'
                : null,
        ];
    }

    /**
     * @return array<string,array> label => canonical result
     */
    private function candidateOutcomes(GameDefinition $game): array
    {
        $rules     = $this->app->rules()->forGame($game);
        $outcomes  = [];

        if ($rules instanceof WinGoRules) {
            for ($digit = 0; $digit <= 9; $digit++) {
                $outcomes[(string) $digit] = $rules->build($digit);
            }
            return $outcomes;
        }

        if ($rules instanceof K3Rules) {
            for ($a = 1; $a <= 6; $a++) {
                for ($b = $a; $b <= 6; $b++) {
                    for ($c = $b; $c <= 6; $c++) {
                        $outcomes["{$a},{$b},{$c}"] = $rules->build([$a, $b, $c]);
                    }
                }
            }
            return $outcomes;
        }

        if ($rules instanceof MotoRaceRules) {
            for ($rider = 1; $rider <= MotoRaceRules::RIDERS; $rider++) {
                $outcomes[(string) $rider] = $rules->fromOverride((string) $rider);
            }
            return $outcomes;
        }

        // D5 has 100k combinations — skip the simulation.
        if ($rules instanceof D5Rules) {
            return [];
        }

        return [];
    }

    /* ===================================================================
     |  Results, overrides & settlement
     * ================================================================ */

    public function results(?string $gameCode, int $pageNo, int $pageSize): array
    {
        $where  = '1 = 1';
        $params = [];
        if ($gameCode !== null && $gameCode !== '') {
            $where   .= ' AND game_code = ?';
            $params[] = $gameCode;
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::RESULTS . ' WHERE ' . $where, $params);
        $rows  = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::RESULTS . ' WHERE ' . $where . '
              ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        $list = [];
        foreach ($rows as $row) {
            $row['result'] = json_decode((string) $row['result_json'], true) ?: [];
            $summary       = $this->db->fetch(
                'SELECT COUNT(*) AS bets, COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout
                   FROM ' . Tables::BETS . ' WHERE game_code = ? AND issue_number = ?',
                [$row['game_code'], $row['issue_number']]
            ) ?? [];

            $list[] = ResultPresenter::present($row) + [
                'bets'   => (int) ($summary['bets'] ?? 0),
                'stake'  => Money::format((float) ($summary['stake'] ?? 0)),
                'payout' => Money::format((float) ($summary['payout'] ?? 0)),
                'ggr'    => Money::format((float) ($summary['stake'] ?? 0) - (float) ($summary['payout'] ?? 0)),
            ];
        }

        return $this->page($list, $total, $pageNo, $pageSize);
    }

    /* ===================================================================
     |  Bets
     * ================================================================ */

    public function bets(array $filters, int $pageNo, int $pageSize): array
    {
        $where  = '1 = 1';
        $params = [];

        foreach ([
            'gameCode'    => 'game_code',
            'issueNumber' => 'issue_number',
            'status'      => 'status',
            'source'      => 'source',
        ] as $input => $column) {
            $value = trim((string) ($filters[$input] ?? ''));
            if ($value !== '') {
                $where   .= " AND {$column} = ?";
                $params[] = $value;
            }
        }

        $userId = (int) ($filters['userId'] ?? 0);
        if ($userId > 0) {
            $where   .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::BETS . ' WHERE ' . $where, $params);
        $sums  = $this->db->fetch(
            'SELECT COALESCE(SUM(stake),0) AS stake, COALESCE(SUM(payout_net),0) AS payout
               FROM ' . Tables::BETS . ' WHERE ' . $where,
            $params
        ) ?? [];

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::BETS . ' WHERE ' . $where . '
              ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        $list = array_map(static function (array $row): array {
            return BetService::presentBet($row) + ['userId' => (int) $row['user_id']];
        }, $rows);

        return $this->page($list, $total, $pageNo, $pageSize) + [
            'totals' => [
                'stake'  => Money::format((float) ($sums['stake'] ?? 0)),
                'payout' => Money::format((float) ($sums['payout'] ?? 0)),
                'ggr'    => Money::format((float) ($sums['stake'] ?? 0) - (float) ($sums['payout'] ?? 0)),
            ],
        ];
    }

    /* ===================================================================
     |  Users & wallets
     * ================================================================ */

    public function users(string $search, int $pageNo, int $pageSize): array
    {
        $where  = '1 = 1';
        $params = [];

        if ($search !== '') {
            $where   .= ' AND (u.mobile LIKE ? OR CAST(u.id AS CHAR) = ?)';
            $params[] = '%' . $search . '%';
            $params[] = $search;
            if (!$this->db->isMysql()) {
                $where  = '1 = 1 AND (u.mobile LIKE ? OR CAST(u.id AS TEXT) = ?)';
            }
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::USERS . ' u WHERE ' . $where, $params);
        $rows  = $this->db->fetchAll(
            'SELECT u.id, u.mobile, u.nickname, u.status, u.created_at,
                    COALESCE(w.balance,0) AS balance, COALESCE(w.total_stake,0) AS total_stake,
                    COALESCE(w.total_payout,0) AS total_payout,
                    COALESCE(v.experience,0) AS experience, COALESCE(v.level,0) AS level
               FROM ' . Tables::USERS . ' u
               LEFT JOIN ' . Tables::WALLETS . ' w ON w.user_id = u.id
               LEFT JOIN ' . Tables::VIP . ' v ON v.user_id = u.id
              WHERE ' . $where . '
              ORDER BY u.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        return $this->page(array_map([$this, 'presentUser'], $rows), $total, $pageNo, $pageSize);
    }

    public function userDetail(int $userId): array
    {
        $row = $this->db->fetch(
            'SELECT u.id, u.mobile, u.nickname, u.status, u.created_at,
                    COALESCE(w.balance,0) AS balance, COALESCE(w.total_stake,0) AS total_stake,
                    COALESCE(w.total_payout,0) AS total_payout,
                    COALESCE(v.experience,0) AS experience, COALESCE(v.level,0) AS level
               FROM ' . Tables::USERS . ' u
               LEFT JOIN ' . Tables::WALLETS . ' w ON w.user_id = u.id
               LEFT JOIN ' . Tables::VIP . ' v ON v.user_id = u.id
              WHERE u.id = ?',
            [$userId]
        );

        if ($row === null) {
            throw ApiException::notFound('User not found');
        }

        return [
            'user'   => $this->presentUser($row),
            'vip'    => $this->app->vip()->status($userId),
            'bets'   => $this->app->bets()->history($userId, null, 1, 10)['list'],
            'ledger' => $this->app->wallet()->ledger($userId, 10),
            'follows'=> $this->app->follow()->userSubscriptions($userId, 10),
        ];
    }

    public function createUser(string $mobile, string $nickname, float $balance, string $actor, string $password = ''): array
    {
        Validator::mobile($mobile);

        $existing = $this->db->fetch('SELECT id FROM ' . Tables::USERS . ' WHERE mobile = ?', [$mobile]);
        if ($existing !== null) {
            throw ApiException::conflict('A user with this mobile already exists (id ' . $existing['id'] . ')');
        }

        try {
            $userId = $this->db->insertGetId(
                'INSERT INTO ' . Tables::USERS . ' (mobile, nickname, status, created_at) VALUES (?, ?, 1, ?)',
                [$mobile, $nickname !== '' ? $nickname : null, Clock::dateTime()]
            );
        } catch (PDOException $e) {
            if ($this->db->isDuplicateKey($e)) {
                throw ApiException::conflict('A user with this mobile already exists');
            }
            throw $e;
        }

        $this->app->wallet()->ensureWallet($userId);
        if ($password !== '') {
            $this->app->players()->setPassword($userId, $password);
        }
        if ($balance > 0) {
            $this->adjustWallet($userId, $balance, 'credit', 'initial balance', $actor);
        }

        $this->audit($actor, 'user.create', (string) $userId, [
            'mobile' => $mobile, 'balance' => $balance, 'password' => $password !== '',
        ]);

        return [
            'userId'      => $userId,
            'mobile'      => $mobile,
            'canLogin'    => $password !== '',
            'token'       => $this->app->jwt()->issue($userId, $mobile),
        ];
    }

    /** Reset a player's password (support desk / onboarding). */
    public function setUserPassword(int $userId, string $password, string $actor): array
    {
        if ($this->db->fetch('SELECT id FROM ' . Tables::USERS . ' WHERE id = ?', [$userId]) === null) {
            throw ApiException::notFound('User not found');
        }

        $this->app->players()->setPassword($userId, $password);
        $this->audit($actor, 'user.password', (string) $userId, []);

        return ['userId' => $userId, 'passwordSet' => true];
    }

    public function adjustWallet(int $userId, float $amount, string $direction, string $remark, string $actor): array
    {
        if (!in_array($direction, ['credit', 'debit'], true)) {
            throw ApiException::validation('Direction must be credit or debit');
        }
        if ($amount <= 0) {
            throw ApiException::validation('Amount must be greater than zero');
        }
        if ($this->db->fetch('SELECT id FROM ' . Tables::USERS . ' WHERE id = ?', [$userId]) === null) {
            throw ApiException::notFound('User not found');
        }

        $key    = WalletService::entryKey('admin', $direction, (string) $userId, (string) $amount, (string) Clock::nowMillis(), bin2hex(random_bytes(4)));
        $wallet = $this->app->wallet();

        $result = $direction === 'credit'
            ? $wallet->credit($userId, $amount, $key, 'adjustment', $actor, $remark)
            : $wallet->debit($userId, $amount, $key, 'adjustment', $actor, $remark);

        $this->audit($actor, 'wallet.' . $direction, (string) $userId, ['amount' => $amount, 'remark' => $remark]);

        return ['userId' => $userId, 'direction' => $direction, 'balance' => Money::format($result['balance'])];
    }

    public function setUserStatus(int $userId, int $status, string $actor): array
    {
        if ($this->db->execute('UPDATE ' . Tables::USERS . ' SET status = ?, updated_at = ? WHERE id = ?',
            [$status === 1 ? 1 : 0, Clock::dateTime(), $userId]) === 0) {
            throw ApiException::notFound('User not found');
        }

        $this->audit($actor, 'user.status', (string) $userId, ['status' => $status]);

        return ['userId' => $userId, 'status' => $status === 1 ? 1 : 0];
    }

    public function ledger(int $userId, int $pageNo, int $pageSize): array
    {
        if ($userId <= 0) {
            $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::LEDGER);
            $rows  = $this->db->fetchAll(
                'SELECT * FROM ' . Tables::LEDGER . ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize)
            );
        } else {
            $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::LEDGER . ' WHERE user_id = ?', [$userId]);
            $rows  = $this->db->fetchAll(
                'SELECT * FROM ' . Tables::LEDGER . ' WHERE user_id = ?
                  ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
                [$userId]
            );
        }

        return $this->page($rows, $total, $pageNo, $pageSize);
    }

    /* ===================================================================
     |  Follow plans
     * ================================================================ */

    public function planList(): array
    {
        return ['list' => $this->db->fetchAll('SELECT * FROM ' . Tables::FOLLOW_PLANS . ' ORDER BY sort ASC, id ASC')];
    }

    public function savePlan(array $input, string $actor): array
    {
        $planCode = strtolower(trim((string) ($input['planCode'] ?? '')));
        if (!preg_match('/^[a-z0-9\-]{3,48}$/', $planCode)) {
            throw ApiException::validation('planCode must be 3-48 chars: a-z, 0-9 and dashes');
        }

        $game       = $this->app->registry()->get((string) ($input['gameCode'] ?? ''));
        $betType    = strtolower(trim((string) ($input['betType'] ?? '')));
        $betContent = strtolower(trim((string) ($input['betContent'] ?? '')));

        // Validate against the family rules so a plan can never place a bad bet.
        $this->app->rules()->forGame($game)->parseSelections($betType, $betContent);

        $name      = trim((string) ($input['name'] ?? $planCode));
        $desc      = trim((string) ($input['description'] ?? ''));
        $minAmount = Money::round((float) ($input['minAmount'] ?? 1));
        $sort      = (int) ($input['sort'] ?? 0);
        $state     = (int) ($input['state'] ?? 1) === 1 ? 1 : 0;

        if ($minAmount < (float) $this->app->config('betting.min_stake')) {
            throw ApiException::validation('minAmount is below the platform minimum stake');
        }

        $existing = $this->db->fetch('SELECT id FROM ' . Tables::FOLLOW_PLANS . ' WHERE plan_code = ?', [$planCode]);

        if ($existing === null) {
            $id = $this->db->insertGetId(
                'INSERT INTO ' . Tables::FOLLOW_PLANS . '
                    (plan_code, name, description, game_code, bet_type, strategy, bet_content, min_amount, sort, state, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$planCode, $name, $desc, $game->code, $betType, 'fixed', $betContent,
                 Money::format($minAmount), $sort, $state, Clock::dateTime()]
            );
        } else {
            $id = (int) $existing['id'];
            $this->db->execute(
                'UPDATE ' . Tables::FOLLOW_PLANS . '
                    SET name = ?, description = ?, game_code = ?, bet_type = ?, bet_content = ?,
                        min_amount = ?, sort = ?, state = ?
                  WHERE id = ?',
                [$name, $desc, $game->code, $betType, $betContent, Money::format($minAmount), $sort, $state, $id]
            );
        }

        $this->audit($actor, 'plan.save', $planCode, ['gameCode' => $game->code, 'betContent' => $betContent, 'state' => $state]);

        return $this->db->fetch('SELECT * FROM ' . Tables::FOLLOW_PLANS . ' WHERE id = ?', [$id]) ?? [];
    }

    public function deletePlan(string $planCode, string $actor): array
    {
        $plan = $this->db->fetch('SELECT id FROM ' . Tables::FOLLOW_PLANS . ' WHERE plan_code = ?', [$planCode]);
        if ($plan === null) {
            throw ApiException::notFound('Plan not found');
        }

        $active = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . Tables::FOLLOW_SUBS . ' WHERE plan_id = ? AND status = ?',
            [$plan['id'], 'active']
        );
        if ($active > 0) {
            // Keep history intact: disable instead of deleting.
            $this->db->execute('UPDATE ' . Tables::FOLLOW_PLANS . ' SET state = 0 WHERE id = ?', [$plan['id']]);
            $this->audit($actor, 'plan.disable', $planCode, ['activeSubscriptions' => $active]);

            return ['deleted' => false, 'disabled' => true, 'activeSubscriptions' => $active];
        }

        $this->db->execute('DELETE FROM ' . Tables::FOLLOW_PLANS . ' WHERE id = ?', [$plan['id']]);
        $this->audit($actor, 'plan.delete', $planCode, []);

        return ['deleted' => true, 'disabled' => false];
    }

    public function subscriptions(string $status, int $pageNo, int $pageSize): array
    {
        $where  = '1 = 1';
        $params = [];
        if ($status !== '') {
            $where   .= ' AND s.status = ?';
            $params[] = $status;
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::FOLLOW_SUBS . ' s WHERE ' . $where, $params);
        $rows  = $this->db->fetchAll(
            'SELECT s.*, u.mobile FROM ' . Tables::FOLLOW_SUBS . ' s
               LEFT JOIN ' . Tables::USERS . ' u ON u.id = s.user_id
              WHERE ' . $where . '
              ORDER BY s.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        $list = array_map(static fn(array $r): array => [
            'followId'        => (int) $r['id'],
            'userId'          => (int) $r['user_id'],
            'mobile'          => $r['mobile'],
            'planCode'        => $r['plan_code'],
            'gameCode'        => $r['game_code'],
            'amount'          => Money::format((float) $r['amount']),
            'multiplier'      => (int) $r['multiplier'],
            'totalRounds'     => (int) $r['total_rounds'],
            'completedRounds' => (int) $r['completed_rounds'],
            'status'          => $r['status'],
            'createdAt'       => $r['created_at'],
        ], $rows);

        return $this->page($list, $total, $pageNo, $pageSize);
    }

    public function stopSubscription(int $followId, string $actor): array
    {
        $row = $this->db->fetch('SELECT user_id FROM ' . Tables::FOLLOW_SUBS . ' WHERE id = ?', [$followId]);
        if ($row === null) {
            throw ApiException::notFound('Follow record not found');
        }

        $result = $this->app->follow()->stop((int) $row['user_id'], ['followId' => $followId]);
        $this->audit($actor, 'follow.stop', (string) $followId, []);

        return $result;
    }

    /* ===================================================================
     |  VIP
     * ================================================================ */

    public function vipOverview(int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            'SELECT v.user_id, v.experience, v.level, v.backfilled_at, u.mobile
               FROM ' . Tables::VIP . ' v
               LEFT JOIN ' . Tables::USERS . ' u ON u.id = v.user_id
              ORDER BY v.experience DESC LIMIT ' . max(1, $limit)
        );

        $distribution = $this->db->fetchAll(
            'SELECT level, COUNT(*) AS players FROM ' . Tables::VIP . ' GROUP BY level ORDER BY level ASC'
        );

        return [
            'levels'       => $this->app->vip()->levelTable(),
            'distribution' => array_map(static fn(array $r): array => [
                'level' => (int) $r['level'], 'players' => (int) $r['players'],
            ], $distribution),
            'top' => array_map(static fn(array $r): array => [
                'userId'     => (int) $r['user_id'],
                'mobile'     => $r['mobile'],
                'experience' => Money::format((float) $r['experience']),
                'level'      => (int) $r['level'],
                'backfilled' => $r['backfilled_at'] !== null,
            ], $rows),
        ];
    }

    /* ===================================================================
     |  Feed domains (SaaS whitelist)
     * ================================================================ */

    public function domains(string $search, int $pageNo, int $pageSize): array
    {
        $page = $this->app->domains()->paginate($search, $pageNo, $pageSize);

        $page['summary'] = [
            'total'    => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::DOMAINS) ?? 0),
            'active'   => (int) ($this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::DOMAINS . ' WHERE status = 1') ?? 0),
            'requests' => (int) ($this->db->fetchValue('SELECT COALESCE(SUM(requests_total),0) FROM ' . Tables::DOMAINS) ?? 0),
            'blocked'  => (int) ($this->db->fetchValue('SELECT COALESCE(SUM(blocked_total),0) FROM ' . Tables::DOMAINS) ?? 0),
        ];

        return $page;
    }

    public function saveDomain(array $input, string $actor): array
    {
        $service = $this->app->domains();
        $id      = (int) ($input['id'] ?? 0);

        $games = $input['games'] ?? '';
        if (is_string($games)) {
            $games = array_values(array_filter(array_map('trim', explode(',', $games))));
        }
        foreach ((array) $games as $gameCode) {
            $this->app->registry()->get((string) $gameCode);   // validates
        }

        if ($id > 0) {
            $row = $service->update($id, $input + ['games' => $games]);
            $this->audit($actor, 'domain.update', $row['domain'], ['id' => $id]);
            return $row;
        }

        $row = $service->create(
            (string) ($input['domain'] ?? ''),
            (string) ($input['label'] ?? ''),
            (array) $games,
            (string) ($input['note'] ?? ''),
            ($input['expiresAt'] ?? '') !== '' ? (string) $input['expiresAt'] : null
        );
        $this->audit($actor, 'domain.create', $row['domain'], ['games' => $row['games']]);

        return $row;
    }

    public function setDomainStatus(int $id, int $status, string $actor): array
    {
        $row = $this->app->domains()->setStatus($id, $status);
        $this->audit($actor, 'domain.status', $row['domain'] ?? (string) $id, ['status' => $status]);

        return $row;
    }

    public function rotateDomainKey(int $id, string $actor): array
    {
        $row = $this->app->domains()->rotateKey($id);
        $this->audit($actor, 'domain.rotate', $row['domain'] ?? (string) $id, []);

        return $row;
    }

    public function deleteDomain(int $id, string $actor): array
    {
        $row     = $this->app->domains()->find($id);
        $deleted = $this->app->domains()->delete($id);
        if ($deleted) {
            $this->audit($actor, 'domain.delete', (string) ($row['domain'] ?? $id), []);
        }

        return ['deleted' => $deleted];
    }

    public function domainUsage(int $id, int $days = 14): array
    {
        return [
            'domain' => $this->app->domains()->present($this->app->domains()->find($id) ?? []),
            'usage'  => $this->app->domains()->usage($id, $days),
        ];
    }

    /** Everything an operator needs to hand the feed to a customer. */
    public function feedInfo(string $baseUrl): array
    {
        $games = [];
        foreach ($this->app->registry()->all() as $game) {
            $games[] = [
                'gameCode'    => $game->code,
                'lottery'     => $game->family,
                'interval'    => $game->intervalKey,
                'issuePrefix' => $game->familyCode . $game->intervalCode,
                'history'     => $baseUrl . '/' . $game->family . '/' . $game->code . '/GetHistoryIssuePage.json',
                'issue'       => $baseUrl . '/' . $game->family . '/' . $game->code . '/GetGameIssue.json',
            ];
        }

        $fetcher = $this->app->fetcher();

        return [
            'baseUrl'  => $baseUrl,
            'board'    => $baseUrl . '/results',
            'gameList' => $baseUrl . '/api/Feed?action=GameList',
            'games'    => $games,
            'upstream' => [
                'profile'  => $this->app->config('draw_profile'),
                'enabled'  => $fetcher->enabled(),
                'baseUrl'  => $this->app->config('draw_base_url'),
                'sample'   => $fetcher->endpoint($this->app->registry()->all()[0]),
            ],
            'rateLimit' => (int) $this->app->config('feed.rate_limit'),
        ];
    }

    /* ===================================================================
     |  Audit trail
     * ================================================================ */

    public function audit(string $actor, string $action, ?string $target, array $detail, string $ip = ''): void
    {
        try {
            $this->db->execute(
                'INSERT INTO ' . Tables::AUDIT . ' (actor, action, target, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($actor, 0, 64),
                    mb_substr($action, 0, 48),
                    $target === null ? null : mb_substr($target, 0, 64),
                    json_encode($detail, JSON_UNESCAPED_SLASHES),
                    $ip !== '' ? $ip : null,
                    Clock::dateTime(),
                ]
            );
        } catch (PDOException $e) {
            // Auditing must never break the operation it is recording.
        }
    }

    public function auditLog(int $pageNo, int $pageSize): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::AUDIT);
        $rows  = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::AUDIT . ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize)
        );

        return $this->page($rows, $total, $pageNo, $pageSize);
    }

    /* ===================================================================
     |  Helpers
     * ================================================================ */

    private function presentUser(array $row): array
    {
        return [
            'userId'      => (int) $row['id'],
            'mobile'      => $row['mobile'],
            'nickname'    => $row['nickname'],
            'status'      => (int) $row['status'],
            'balance'     => Money::format((float) $row['balance']),
            'totalStake'  => Money::format((float) $row['total_stake']),
            'totalPayout' => Money::format((float) $row['total_payout']),
            'experience'  => Money::format((float) $row['experience']),
            'level'       => (int) $row['level'],
            'createdAt'   => $row['created_at'],
        ];
    }

    private function page(array $list, int $total, int $pageNo, int $pageSize): array
    {
        return [
            'list'       => $list,
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalCount' => $total,
            'totalPage'  => (int) ceil($total / max(1, $pageSize)),
        ];
    }
}
