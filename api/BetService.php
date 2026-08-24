<?php
/**
 * Bet Management & High-Performance ACID Settlement Service
 * Accurately implements WinGo Color Trading rules, odds multipliers, half-violet wins, and wallet transactions.
 */

declare(strict_types=1);

require_once __DIR__ . '/ResultSyncService.php';

class BetService {
    private PDO $pdo;
    private ResultSyncService $syncService;
    private array $config;
    private string $driver;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->syncService = new ResultSyncService($pdo);
        $this->config = require __DIR__ . '/../config.php';
        // Ask the connection, not the DB_TYPE constant: conn.php silently falls back to SQLite
        // when MySQL is unreachable, and appending "FOR UPDATE" there breaks every bet with a
        // SQL syntax error.
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Determine odds multiplier based on bet type & bet value
     */
    public function calculateOdds(string $betType, string $betValue): float {
        $betType = strtolower(trim($betType));
        $betValue = strtolower(trim($betValue));

        if ($betType === 'number') {
            return 9.0;
        }

        if ($betType === 'color') {
            if ($betValue === 'violet') {
                return 4.5;
            }
            return 2.0; // Standard green/red base multiplier
        }

        if ($betType === 'big_small') {
            return 2.0;
        }

        if ($betType === 'odd_even') {
            return 2.0;
        }

        return 2.0;
    }

    /**
     * Validate bet input parameters
     */
    private function validateBetInput(string $betType, string $betValue, float $amount): void {
        if ($amount < 1.0) {
            throw new InvalidArgumentException("Minimum bet amount is 1.00");
        }

        $validTypes = ['number', 'color', 'big_small', 'odd_even'];
        if (!in_array($betType, $validTypes, true)) {
            throw new InvalidArgumentException("Invalid bet type. Allowed: " . implode(', ', $validTypes));
        }

        switch ($betType) {
            case 'number':
                if (!is_numeric($betValue) || (int)$betValue < 0 || (int)$betValue > 9) {
                    throw new InvalidArgumentException("Number bet value must be between 0 and 9");
                }
                break;
            case 'color':
                if (!in_array($betValue, ['green', 'red', 'violet'], true)) {
                    throw new InvalidArgumentException("Color bet must be 'green', 'red', or 'violet'");
                }
                break;
            case 'big_small':
                if (!in_array($betValue, ['big', 'small'], true)) {
                    throw new InvalidArgumentException("Big/Small bet must be 'big' or 'small'");
                }
                break;
            case 'odd_even':
                if (!in_array($betValue, ['odd', 'even'], true)) {
                    throw new InvalidArgumentException("Odd/Even bet must be 'odd' or 'even'");
                }
                break;
        }
    }

    /**
     * Place a bet atomically with balance verification & period lock checks
     */
    public function placeBet(int $userId, string $gameCode, string $betType, string $betValue, float $amount): array {
        $betType = strtolower(trim($betType));
        $betValue = strtolower(trim($betValue));
        $this->validateBetInput($betType, $betValue, $amount);

        // Get current period and verify betting is not locked.
        // autoPull=false: placing a bet must never wait on an upstream HTTP call.
        $issueData = $this->syncService->getCurrentIssue($gameCode, false);
        if ($issueData['is_locked']) {
            throw new RuntimeException("Betting is currently locked for issue #{$issueData['issue_number']}. Next issue opens in {$issueData['seconds_left']}s.");
        }

        $odds = $this->calculateOdds($betType, $betValue);
        $issueNumber = $issueData['issue_number'];

        // Begin ACID transaction
        $this->pdo->beginTransaction();
        try {
            // Lock and fetch user balance
            $walletSql = sprintf(
                "SELECT %s FROM %s WHERE %s = ? %s",
                USER_BAL_COL,
                USER_TABLE,
                USER_ID_COL,
                ($this->driver === 'mysql' ? 'FOR UPDATE' : '')
            );
            $stmt = $this->pdo->prepare($walletSql);
            $stmt->execute([$userId]);
            $currentBalance = $stmt->fetchColumn();

            if ($currentBalance === false) {
                // Auto create demo wallet entry if not found
                $initSql = sprintf("INSERT INTO %s (%s, %s) VALUES (?, ?)", USER_TABLE, USER_ID_COL, USER_BAL_COL);
                $this->pdo->prepare($initSql)->execute([$userId, 10000.00]);
                $currentBalance = 10000.00;
            } else {
                $currentBalance = (float)$currentBalance;
            }

            if ($currentBalance < $amount) {
                throw new RuntimeException("Insufficient wallet balance. Current: " . number_format($currentBalance, 2) . ", Required: " . number_format($amount, 2));
            }

            // Deduct balance
            $deductSql = sprintf(
                "UPDATE %s SET %s = %s - ? WHERE %s = ?",
                USER_TABLE,
                USER_BAL_COL,
                USER_BAL_COL,
                USER_ID_COL
            );
            $this->pdo->prepare($deductSql)->execute([$amount, $userId]);
            $newBalance = $currentBalance - $amount;

            // Insert bet record
            $betInsertSql = "
                INSERT INTO wingo_bets (user_id, game_code, issue_number, bet_type, bet_value, amount, odds, status, payout)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0.00)
            ";
            $stmt = $this->pdo->prepare($betInsertSql);
            $stmt->execute([
                $userId,
                $gameCode,
                $issueNumber,
                $betType,
                $betValue,
                $amount,
                $odds
            ]);
            $betId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();

            return [
                'bet_id' => $betId,
                'user_id' => $userId,
                'game_code' => $gameCode,
                'issue_number' => $issueNumber,
                'bet_type' => $betType,
                'bet_value' => $betValue,
                'amount' => $amount,
                'odds' => $odds,
                'potential_payout' => round($amount * $odds, 2),
                'wallet_balance' => round($newBalance, 2),
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zero-delay settlement used by the player-facing endpoints.
     *
     * Pulls the just-closed result on demand (if the worker has not landed it yet) and then
     * settles, so the win/lose popup and the wallet balance update the moment the countdown
     * hits 00 instead of on the next cron tick.
     */
    public function ensureSettled(string $gameCode, bool $force = false): array {
        try {
            $this->syncService->ensureLiveResult($gameCode, $force);
        } catch (Throwable $e) {
            error_log("BetService::ensureSettled live pull failed: " . $e->getMessage());
        }

        if ($this->countSettleableBets($gameCode) === 0) {
            return [
                'settled_count' => 0,
                'won_count' => 0,
                'lost_count' => 0,
                'total_payout' => 0.0,
                'settled_items' => [],
                'settled_at' => date('Y-m-d H:i:s')
            ];
        }

        return $this->settlePendingBets($gameCode);
    }

    /**
     * How many pending bets already have a published result AND belong to a closed period.
     */
    public function countSettleableBets(?string $gameCode = null): int {
        [$sql, $params] = $this->settleableBetQuery(
            "SELECT COUNT(*) FROM wingo_bets b
             INNER JOIN wingo_results r ON b.game_code = r.game_code AND b.issue_number = r.issue_number",
            $gameCode
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Shared WHERE clause for settleable bets.
     *
     * A bet is settleable once the window its draw arrived in has CLOSED (fetched_at is older
     * than the current window start). This is numbering-agnostic - the provider's issue counter
     * can jump around - and it guarantees a draw that is still accepting bets is never settled
     * or revealed early.
     */
    private function settleableBetQuery(string $select, ?string $gameCode): array {
        $sql = $select . " WHERE b.status = 'pending'";
        $params = [];

        if ($gameCode !== null) {
            $sql .= " AND b.game_code = ? AND r.fetched_at < ?";
            $params[] = $gameCode;
            $params[] = $this->syncService->visibleBefore($gameCode);
        }

        return [$sql, $params];
    }

    /**
     * Settle all pending bets where drawn results exist in database.
     * With no game code it walks every game that has pending bets, so the "period closed"
     * guard is applied per game exactly like the single-game path.
     */
    public function settlePendingBets(?string $gameCode = null): array {
        if ($gameCode === null) {
            return $this->settleEveryGame();
        }

        // Query pending bets joined with results
        [$sql, $params] = $this->settleableBetQuery("
            SELECT b.id AS bet_id, b.user_id, b.game_code, b.issue_number, b.bet_type,
                   b.bet_value, b.amount, b.odds, r.number AS draw_number, r.color AS draw_color
            FROM wingo_bets b
            INNER JOIN wingo_results r ON b.game_code = r.game_code AND b.issue_number = r.issue_number",
            $gameCode
        );

        $sql .= " ORDER BY b.id ASC LIMIT 500";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $pendingBets = $stmt->fetchAll();

        $settledCount = 0;
        $wonCount = 0;
        $lostCount = 0;
        $totalPayout = 0.0;
        $settledList = [];

        foreach ($pendingBets as $bet) {
            $eval = $this->evaluateBet(
                $bet['bet_type'],
                $bet['bet_value'],
                (int)$bet['draw_number'],
                $bet['draw_color'],
                (float)$bet['amount'],
                (float)$bet['odds']
            );

            $this->pdo->beginTransaction();
            try {
                if ($eval['is_won'] && $eval['payout'] > 0) {
                    // Credit user wallet
                    $creditSql = sprintf(
                        "UPDATE %s SET %s = %s + ? WHERE %s = ?",
                        USER_TABLE,
                        USER_BAL_COL,
                        USER_BAL_COL,
                        USER_ID_COL
                    );
                    $this->pdo->prepare($creditSql)->execute([$eval['payout'], $bet['user_id']]);
                }

                // Update bet status
                $updateBetSql = "
                    UPDATE wingo_bets 
                    SET status = ?, payout = ?, settled_at = CURRENT_TIMESTAMP 
                    WHERE id = ? AND status = 'pending'
                ";
                $this->pdo->prepare($updateBetSql)->execute([
                    $eval['is_won'] ? 'won' : 'lost',
                    $eval['payout'],
                    $bet['bet_id']
                ]);

                $this->pdo->commit();

                $settledCount++;
                if ($eval['is_won']) {
                    $wonCount++;
                    $totalPayout += $eval['payout'];
                } else {
                    $lostCount++;
                }

                $settledList[] = [
                    'bet_id' => $bet['bet_id'],
                    'user_id' => $bet['user_id'],
                    'game_code' => $bet['game_code'],
                    'issue_number' => $bet['issue_number'],
                    'draw_number' => $bet['draw_number'],
                    'draw_color' => $bet['draw_color'],
                    'status' => $eval['is_won'] ? 'won' : 'lost',
                    'payout' => $eval['payout'],
                    'reason' => $eval['reason']
                ];
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log("Failed settling bet ID {$bet['bet_id']}: " . $e->getMessage());
            }
        }

        return [
            'settled_count' => $settledCount,
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'total_payout' => round($totalPayout, 2),
            'settled_items' => $settledList,
            'settled_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Settle every game that has pending bets (used by the worker / /api/sync).
     */
    private function settleEveryGame(): array {
        $aggregate = [
            'settled_count' => 0,
            'won_count' => 0,
            'lost_count' => 0,
            'total_payout' => 0.0,
            'settled_items' => [],
            'settled_at' => date('Y-m-d H:i:s')
        ];

        try {
            $games = $this->pdo->query("SELECT DISTINCT game_code FROM wingo_bets WHERE status = 'pending'")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return $aggregate;
        }

        foreach ($games as $game) {
            $part = $this->settlePendingBets((string)$game);
            $aggregate['settled_count'] += $part['settled_count'];
            $aggregate['won_count'] += $part['won_count'];
            $aggregate['lost_count'] += $part['lost_count'];
            $aggregate['total_payout'] += $part['total_payout'];
            $aggregate['settled_items'] = array_merge($aggregate['settled_items'], $part['settled_items']);
        }

        $aggregate['total_payout'] = round($aggregate['total_payout'], 2);
        return $aggregate;
    }

    /**
     * WinGo Exact Settlement Logic Engine
     */
    public function evaluateBet(string $type, string $value, int $num, string $color, float $amount, float $odds): array {
        $type = strtolower($type);
        $value = strtolower($value);
        $isWon = false;
        $effectiveMultiplier = 0.0;
        $reason = '';

        $isBig = ($num >= 5);
        $isOdd = ($num % 2 === 1);

        switch ($type) {
            case 'number':
                if ((int)$value === $num) {
                    $isWon = true;
                    $effectiveMultiplier = 9.0;
                    $reason = "Exact number match: {$num}";
                } else {
                    $reason = "Drawn number was {$num}, expected {$value}";
                }
                break;

            case 'color':
                if ($value === 'green') {
                    if (in_array($num, [1, 3, 7, 9], true)) {
                        $isWon = true;
                        $effectiveMultiplier = 2.0;
                        $reason = "Pure Green match ({$num})";
                    } elseif ($num === 5) {
                        // Half win on 5 (Green + Violet)
                        $isWon = true;
                        $effectiveMultiplier = 1.5;
                        $reason = "Half win Green+Violet on 5 (1.5x)";
                    } else {
                        $reason = "Number was {$num} ({$color})";
                    }
                } elseif ($value === 'red') {
                    if (in_array($num, [2, 4, 6, 8], true)) {
                        $isWon = true;
                        $effectiveMultiplier = 2.0;
                        $reason = "Pure Red match ({$num})";
                    } elseif ($num === 0) {
                        // Half win on 0 (Red + Violet)
                        $isWon = true;
                        $effectiveMultiplier = 1.5;
                        $reason = "Half win Red+Violet on 0 (1.5x)";
                    } else {
                        $reason = "Number was {$num} ({$color})";
                    }
                } elseif ($value === 'violet') {
                    if (in_array($num, [0, 5], true)) {
                        $isWon = true;
                        $effectiveMultiplier = 4.5;
                        $reason = "Violet match ({$num})";
                    } else {
                        $reason = "Number was {$num} (Not Violet)";
                    }
                }
                break;

            case 'big_small':
                if ($value === 'big' && $isBig) {
                    $isWon = true;
                    $effectiveMultiplier = 2.0;
                    $reason = "Big match: {$num} >= 5";
                } elseif ($value === 'small' && !$isBig) {
                    $isWon = true;
                    $effectiveMultiplier = 2.0;
                    $reason = "Small match: {$num} < 5";
                } else {
                    $reason = "Number was {$num} (" . ($isBig ? 'Big' : 'Small') . ")";
                }
                break;

            case 'odd_even':
                if ($value === 'odd' && $isOdd) {
                    $isWon = true;
                    $effectiveMultiplier = 2.0;
                    $reason = "Odd match: {$num}";
                } elseif ($value === 'even' && !$isOdd) {
                    $isWon = true;
                    $effectiveMultiplier = 2.0;
                    $reason = "Even match: {$num}";
                } else {
                    $reason = "Number was {$num} (" . ($isOdd ? 'Odd' : 'Even') . ")";
                }
                break;
        }

        $payout = 0.0;
        if ($isWon) {
            // Apply platform fee deduction if any
            $feeFactor = 1.0 - PLATFORM_FEE_RATE;
            $payout = round($amount * $effectiveMultiplier * $feeFactor, 2);
        }

        return [
            'is_won' => $isWon,
            'multiplier' => $effectiveMultiplier,
            'payout' => $payout,
            'reason' => $reason
        ];
    }

    /**
     * Get user bet history
     */
    public function getUserBets(int $userId, ?string $gameCode = null, int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        $sql = "
            SELECT b.*, r.number AS draw_number, r.color AS draw_color
            FROM wingo_bets b
            LEFT JOIN wingo_results r ON b.game_code = r.game_code AND b.issue_number = r.issue_number
            WHERE b.user_id = ?
        ";
        $params = [$userId];

        if ($gameCode !== null) {
            $sql .= " AND b.game_code = ?";
            $params[] = $gameCode;
        }

        $sql .= " ORDER BY b.id DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get user wallet balance
     */
    public function getWallet(int $userId): array {
        $sql = sprintf("SELECT %s FROM %s WHERE %s = ?", USER_BAL_COL, USER_TABLE, USER_ID_COL);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $balance = $stmt->fetchColumn();

        if ($balance === false) {
            // Create initial demo wallet
            $initSql = sprintf("INSERT INTO %s (%s, %s) VALUES (?, ?)", USER_TABLE, USER_ID_COL, USER_BAL_COL);
            $this->pdo->prepare($initSql)->execute([$userId, 10000.00]);
            $balance = 10000.00;
        }

        return [
            'user_id' => $userId,
            'balance' => (float)$balance,
            'currency' => 'INR'
        ];
    }

    /**
     * Add funds to wallet
     */
    public function deposit(int $userId, float $amount): float {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }

        // Make sure the wallet row exists first, otherwise the UPDATE below silently affects
        // zero rows and the recharge disappears.
        $this->getWallet($userId);

        $sql = sprintf(
            "UPDATE %s SET %s = %s + ? WHERE %s = ?",
            USER_TABLE,
            USER_BAL_COL,
            USER_BAL_COL,
            USER_ID_COL
        );
        $this->pdo->prepare($sql)->execute([$amount, $userId]);
        return $this->getWallet($userId)['balance'];
    }
}
