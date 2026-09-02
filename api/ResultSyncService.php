<?php
/**
 * Result Sync Service & Issue Timing Engine
 * Coordinates external data fetches, persistence, and accurate period countdowns.
 */

declare(strict_types=1);

require_once __DIR__ . '/ExternalLotteryAPI.php';

class ResultSyncService {
    private PDO $pdo;
    private ExternalLotteryAPI $api;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->api = new ExternalLotteryAPI();
    }

    /**
     * Sync single game code results directly from external source
     */
    public function syncGame(string $gameCode): array {
        $stmt = $this->pdo->prepare("SELECT * FROM wingo_games WHERE game_code = ? AND status = 1");
        $stmt->execute([$gameCode]);
        $game = $stmt->fetch();

        $interval = $this->api->getGameInterval($gameCode);
        $apiUrl = $game['external_api_url'] ?? '';

        $results = [];
        if (!empty($apiUrl)) {
            $results = $this->api->fetchHistory($apiUrl, $gameCode);
        } else {
            $results = $this->api->fetchHistory('', $gameCode);
        }

        $saved = 0;
        $duplicates = 0;

        foreach ($results as $item) {
            $normalized = $this->api->normalizeResult($item, $gameCode);
            if ($this->saveResult($normalized)) {
                $saved++;
            } else {
                $duplicates++;
            }
        }

        // Update real-time issue timing
        $this->updateCurrentIssue($gameCode, $interval);

        return [
            'game_code' => $gameCode,
            'fetched' => count($results),
            'saved' => $saved,
            'skipped_duplicates' => $duplicates,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Sync all active games
     */
    public function syncAll(): array {
        $stmt = $this->pdo->query("SELECT game_code FROM wingo_games WHERE status = 1");
        $games = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($games)) {
            $games = ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M', 'WinGo_10M'];
        }

        $results = [];
        foreach ($games as $gameCode) {
            try {
                $results[$gameCode] = $this->syncGame($gameCode);
            } catch (Exception $e) {
                $results[$gameCode] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Insert single result into database
     */
    private function saveResult(array $data): bool {
        try {
            $sql = "INSERT IGNORE INTO wingo_results 
                    (game_code, issue_number, number, color, premium, sum, draw_time)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['game_code'],
                $data['issue_number'],
                $data['number'],
                $data['color'],
                $data['premium'],
                $data['sum'],
                $data['draw_time']
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Accurately calculate and update current and next issue periods
     */
    public function updateCurrentIssue(string $gameCode, ?int $interval = null): array {
        if ($interval === null) {
            $interval = $this->api->getGameInterval($gameCode);
        }

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;
        $nextStartTs = $currentEndTs;
        $nextEndTs = $nextStartTs + $interval;

        // 1-period buffer lag offset behind upstream:
        $lagStartTs = $currentStartTs - $interval;
        $currentIssue = $this->api->calculateIssueNumberForTime($gameCode, $lagStartTs);
        $nextIssue = $this->api->calculateIssueNumberForTime($gameCode, $currentStartTs);

        $currentStartStr = date('Y-m-d H:i:s', $currentStartTs);
        $currentEndStr = date('Y-m-d H:i:s', $currentEndTs);
        $nextStartStr = date('Y-m-d H:i:s', $nextStartTs);
        $nextEndStr = date('Y-m-d H:i:s', $nextEndTs);

        $sql = "INSERT INTO wingo_current_issue 
                (game_code, current_issue, current_start, current_end, next_issue, next_start, next_end)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                current_issue=VALUES(current_issue),
                current_start=VALUES(current_start),
                current_end=VALUES(current_end),
                next_issue=VALUES(next_issue),
                next_start=VALUES(next_start),
                next_end=VALUES(next_end),
                updated_at=CURRENT_TIMESTAMP";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $gameCode,
            $currentIssue,
            $currentStartStr,
            $currentEndStr,
            $nextIssue,
            $nextStartStr,
            $nextEndStr
        ]);

        return [
            'game_code' => $gameCode,
            'current_issue' => $currentIssue,
            'current_start' => $currentStartStr,
            'current_end' => $currentEndStr,
            'next_issue' => $nextIssue,
            'next_start' => $nextStartStr,
            'next_end' => $nextEndStr,
            'interval' => $interval
        ];
    }

    /**
     * Get real-time status and issue timing for frontend countdown
     */
    public function getCurrentIssue(string $gameCode): array {
        $interval = $this->api->getGameInterval($gameCode);
        $lockSeconds = 5;

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;
        $nextStartTs = $currentEndTs;
        $nextEndTs = $nextStartTs + $interval;

        // 1-period buffer lag offset behind upstream:
        $lagStartTs = $currentStartTs - $interval;
        $currentIssue = $this->api->calculateIssueNumberForTime($gameCode, $lagStartTs);
        $nextIssue = $this->api->calculateIssueNumberForTime($gameCode, $currentStartTs);

        $secondsLeft = max(0, $currentEndTs - $now);
        $isLocked = ($secondsLeft <= $lockSeconds);

        return [
            'game_code' => $gameCode,
            'game_name' => $gameCode,
            'interval' => $interval,
            'lock_seconds' => $lockSeconds,
            'issue_number' => $currentIssue,
            'start_time' => date('Y-m-d H:i:s', $currentStartTs),
            'end_time' => date('Y-m-d H:i:s', $currentEndTs),
            'next_issue_number' => $nextIssue,
            'next_start_time' => date('Y-m-d H:i:s', $nextStartTs),
            'next_end_time' => date('Y-m-d H:i:s', $nextEndTs),
            'seconds_left' => $secondsLeft,
            'is_locked' => $isLocked,
            'server_time' => date('Y-m-d H:i:s', $now),
            'server_timestamp' => $now
        ];
    }

    /**
     * Ensure a specific past issue has a result in database
     */
    public function ensurePastResult(string $gameCode, string $issueNumber): array {
        $stmt = $this->pdo->prepare("SELECT issue_number, number, color, premium, sum, draw_time, fetched_at FROM wingo_results WHERE game_code = ? AND issue_number = ? LIMIT 1");
        $stmt->execute([$gameCode, $issueNumber]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        // Generate deterministic seed outcome for this exact issue
        $hash = md5($gameCode . '_' . $issueNumber);
        $num = hexdec(substr($hash, 0, 4)) % 10;
        $color = $this->api->calculateColorFromNumber($num);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("INSERT IGNORE INTO wingo_results (game_code, issue_number, number, color, premium, sum, draw_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$gameCode, $issueNumber, $num, $color, (string)$num, $num, $now]);

        return [
            'issue_number' => $issueNumber,
            'number'       => $num,
            'color'        => $color,
            'premium'      => (string)$num,
            'sum'          => $num,
            'draw_time'    => $now,
            'fetched_at'   => $now
        ];
    }

    /**
     * Get historical draw results:
     * Returns completed draw results. When includeActive is true (last 5s reveal), includes active issue for auto animation trigger.
     */
    public function getHistory(string $gameCode, int $limit = 50, ?string $activeIssue = null): array {
        $limit = max(1, min(200, $limit));

        if (!empty($activeIssue)) {
            $stmt = $this->pdo->prepare("
                SELECT issue_number, number, color, premium, sum, draw_time, fetched_at
                FROM wingo_results 
                WHERE game_code = ? AND issue_number < ?
                ORDER BY issue_number DESC 
                LIMIT ?
            ");
            $stmt->bindValue(1, $gameCode, PDO::PARAM_STR);
            $stmt->bindValue(2, $activeIssue, PDO::PARAM_STR);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
            if (!empty($history)) {
                return $history;
            }

            // If empty, generate past history strictly before active issue
            $interval = $this->api->getGameInterval($gameCode);
            $now = time();
            $currentStartTs = $now - ($now % $interval);
            for ($i = 1; $i <= $limit; $i++) {
                $pastTs = $currentStartTs - ($i * $interval);
                $pastIssue = $this->api->calculateIssueNumberForTime($gameCode, $pastTs);
                $this->ensurePastResult($gameCode, $pastIssue);
            }

            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $this->pdo->prepare("
            SELECT issue_number, number, color, premium, sum, draw_time, fetched_at
            FROM wingo_results 
            WHERE game_code = ? 
            ORDER BY issue_number DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $gameCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $history = $stmt->fetchAll();

        return $history ?: [];
    }
}
