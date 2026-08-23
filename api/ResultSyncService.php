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

        if (!$game) {
            throw new Exception("Active game not found for code: {$gameCode}");
        }

        $apiUrl = $game['external_api_url'];
        $results = $this->api->fetchHistory($apiUrl, $gameCode);
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
        $this->updateCurrentIssue($gameCode, (int)$game['interval_seconds']);

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
            $stmt = $this->pdo->prepare("SELECT interval_seconds FROM wingo_games WHERE game_code = ?");
            $stmt->execute([$gameCode]);
            $interval = (int)($stmt->fetchColumn() ?: 60);
        }

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;
        $nextStartTs = $currentEndTs;
        $nextEndTs = $nextStartTs + $interval;

        // Current active issue is the top drawn issue
        $currentIssue = $this->deriveActiveIssueNumber($gameCode, $currentStartTs);
        $nextIssue = $this->deriveNextIssueNumber($currentIssue);

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
        $stmt = $this->pdo->prepare("SELECT * FROM wingo_games WHERE game_code = ?");
        $stmt->execute([$gameCode]);
        $game = $stmt->fetch();

        if (!$game) {
            throw new Exception("Invalid game code: {$gameCode}");
        }

        $interval = (int)$game['interval_seconds'];
        $lockSeconds = (int)($game['lock_seconds'] ?? 5);

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;
        $nextStartTs = $currentEndTs;
        $nextEndTs = $nextStartTs + $interval;

        // Current active betting issue
        $currentIssue = $this->deriveActiveIssueNumber($gameCode, $currentStartTs);
        $nextIssue = $this->deriveNextIssueNumber($currentIssue);

        $secondsLeft = max(0, $currentEndTs - $now);
        $isLocked = ($secondsLeft <= $lockSeconds);

        return [
            'game_code' => $gameCode,
            'game_name' => $game['name'],
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
     * Active issue is the latest drawn issue from external provider
     */
    private function deriveActiveIssueNumber(string $gameCode, int $currentStartTs): string {
        $stmt = $this->pdo->prepare("
            SELECT issue_number 
            FROM wingo_results 
            WHERE game_code = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$gameCode]);
        $latest = $stmt->fetch();

        if ($latest && !empty($latest['issue_number'])) {
            return (string)$latest['issue_number'];
        }

        return $this->api->calculateIssueNumberForTime($gameCode, $currentStartTs);
    }

    /**
     * Derive next issue number (Active issue + 1)
     */
    private function deriveNextIssueNumber(string $currentIssue): string {
        if (strlen($currentIssue) >= 10) {
            $prefix = substr($currentIssue, 0, -4);
            $seq = (int)substr($currentIssue, -4);
            return $prefix . sprintf('%04d', $seq + 1);
        }
        return (string)((int)$currentIssue + 1);
    }

    /**
     * Get historical draw results:
     * Offsets by 1 to hide the current active/in-progress issue from history
     * so history only shows completed/previous periods.
     */
    public function getHistory(string $gameCode, int $limit = 50): array {
        $limit = max(1, min(200, $limit));

        // Query with OFFSET 1 to display finished past draws
        $stmt = $this->pdo->prepare("
            SELECT issue_number, number, color, premium, sum, draw_time, fetched_at
            FROM wingo_results 
            WHERE game_code = ? 
            ORDER BY id DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $gameCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $history = $stmt->fetchAll();

        // If less than 2 records in DB, return standard list
        if (empty($history)) {
            $stmt = $this->pdo->prepare("
                SELECT issue_number, number, color, premium, sum, draw_time, fetched_at
                FROM wingo_results 
                WHERE game_code = ? 
                ORDER BY id DESC 
                LIMIT ?
            ");
            $stmt->bindValue(1, $gameCode, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
        }

        return $history;
    }
}
