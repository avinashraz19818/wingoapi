<?php
/**
 * Result Sync Service & Issue Timing Engine
 * Coordinates external data fetches, persistence, and accurate period countdowns.
 *
 * LATENCY MODEL (why results no longer arrive ~5s after the countdown ends)
 * -----------------------------------------------------------------------
 * 1. The open period number is derived from the WALL CLOCK, not from "whatever the last
 *    synced row happens to be". The countdown therefore rolls over exactly on time and the
 *    period shown to the player is the one the provider is actually drawing.
 * 2. History returns every CLOSED period (issue < open issue) - including the one that closed
 *    a moment ago. It no longer hides the newest row, which is what forced the client to wait
 *    for the *next* sync cycle before the result/bet popup could appear.
 * 3. ensureLiveResult(): when a period has just closed and its result is not in the DB yet,
 *    the first client request pulls it from the provider right away (single-flight + throttled)
 *    instead of waiting for the next cron tick.
 */

declare(strict_types=1);

require_once __DIR__ . '/ExternalLotteryAPI.php';

class ResultSyncService {
    private PDO $pdo;
    private ExternalLotteryAPI $api;
    private array $config;
    private string $driver;
    private array $gameCache = [];
    /** @var callable|null Cannot be typed `?callable` - PHP forbids callable as a property type. */
    private $newResultsHandler = null;

    public function __construct(PDO $pdo, ?ExternalLotteryAPI $api = null) {
        $this->pdo = $pdo;
        $this->api = $api ?? new ExternalLotteryAPI();
        $this->config = require __DIR__ . '/../config.php';
        $this->driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getApi(): ExternalLotteryAPI {
        return $this->api;
    }

    /**
     * Register a callback invoked when fresh results were written during a request.
     * Endpoints use it to settle bets immediately, so the win/lose popup is not late.
     */
    public function onNewResults(?callable $handler): void {
        $this->newResultsHandler = $handler;
    }

    private function liveConfig(): array {
        return $this->config['live_pull'] ?? [
            'enabled' => true, 'min_gap' => 0.8, 'window' => 10.0, 'max_wait' => 2.5,
            'timeout' => 3.0, 'connect_timeout' => 2.0, 'allow_fallback' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Game metadata
    // ------------------------------------------------------------------

    public function getGame(string $gameCode): ?array {
        if (array_key_exists($gameCode, $this->gameCache)) {
            return $this->gameCache[$gameCode];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM wingo_games WHERE game_code = ? LIMIT 1");
        $stmt->execute([$gameCode]);
        $game = $stmt->fetch();
        $this->gameCache[$gameCode] = $game ?: null;
        return $this->gameCache[$gameCode];
    }

    public function getIntervalSeconds(string $gameCode): int {
        $game = $this->getGame($gameCode);
        $interval = (int)($game['interval_seconds'] ?? 0);
        return $interval > 0 ? $interval : $this->api->getInterval($gameCode);
    }

    // ------------------------------------------------------------------
    // Sync
    // ------------------------------------------------------------------

    /**
     * Sync single game code results directly from external source
     */
    public function syncGame(string $gameCode, bool $allowFallback = false): array {
        $game = $this->getGame($gameCode);
        if (!$game || (int)($game['status'] ?? 1) !== 1) {
            throw new Exception("Active game not found for code: {$gameCode}");
        }

        $apiUrl = (string)$game['external_api_url'];
        $results = $this->api->fetchHistory($apiUrl, $gameCode, $allowFallback);

        return $this->persistResults($gameCode, $results);
    }

    /**
     * Write a raw provider list into wingo_results and refresh the period cache.
     */
    public function persistResults(string $gameCode, array $results): array {
        $saved = 0;
        $duplicates = 0;
        $invalid = 0;

        foreach ($results as $item) {
            if (!is_array($item)) {
                $invalid++;
                continue;
            }
            $normalized = $this->api->normalizeResult($item, $gameCode);
            if ($normalized === null) {
                $invalid++;
                continue;
            }
            if ($this->saveResult($normalized)) {
                $saved++;
            } else {
                $duplicates++;
            }
        }

        // Update real-time issue timing
        $this->updateCurrentIssue($gameCode);

        if ($saved > 0 && $this->newResultsHandler !== null) {
            try {
                ($this->newResultsHandler)($gameCode, $saved);
            } catch (Throwable $e) {
                error_log("ResultSyncService: new-results handler failed: " . $e->getMessage());
            }
        }

        return [
            'game_code' => $gameCode,
            'fetched' => count($results),
            'saved' => $saved,
            'skipped_duplicates' => $duplicates,
            'invalid' => $invalid,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Sync all active games in parallel - one cycle costs the slowest call, not the sum.
     */
    public function syncAll(bool $allowFallback = false): array {
        $stmt = $this->pdo->query("SELECT game_code, external_api_url FROM wingo_games WHERE status = 1");
        $games = $stmt->fetchAll();

        $urlByGame = [];
        foreach ($games as $row) {
            $urlByGame[(string)$row['game_code']] = (string)$row['external_api_url'];
        }

        if (empty($urlByGame)) {
            return [];
        }

        $fetched = $this->api->fetchMany($urlByGame, $allowFallback);
        $results = [];
        foreach ($urlByGame as $gameCode => $url) {
            $list = $fetched[$gameCode] ?? [];
            if (empty($list)) {
                $results[$gameCode] = ['game_code' => $gameCode, 'error' => 'upstream_unreachable', 'saved' => 0];
                continue;
            }
            try {
                $results[$gameCode] = $this->persistResults($gameCode, $list);
            } catch (Throwable $e) {
                $results[$gameCode] = ['game_code' => $gameCode, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Insert single result into database (portable upsert / ignore-duplicate)
     */
    private function saveResult(array $data): bool {
        $verb = $this->driver === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
        $sql = "{$verb} wingo_results
                (game_code, issue_number, number, color, premium, sum, draw_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        try {
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
            error_log("ResultSyncService::saveResult failed: " . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Zero-delay live pull
    // ------------------------------------------------------------------

    /**
     * Make sure the result of the period that has just closed is in the database NOW.
     *
     * Single-flight (file lock) + throttled, so 1000 concurrent players cause exactly one
     * upstream call, and the others either wait briefly for it or answer from the DB.
     *
     * @return array{needed:bool, fetched:bool, saved:int, row:array|null}
     */
    public function ensureLiveResult(string $gameCode, bool $force = false): array {
        $interval = $this->getIntervalSeconds($gameCode);
        $offset = (int)($this->config['issue_offset'] ?? 0);
        $now = time();
        $periodStart = $now - ($now % $interval);
        // Must match getCurrentIssue()['last_issue_number'] exactly, offset included.
        $closedIssue = $this->issueForTime($gameCode, $periodStart - $interval, $interval, $offset);

        $row = $this->getResult($gameCode, $closedIssue);
        if ($row !== null) {
            return ['needed' => false, 'fetched' => false, 'saved' => 0, 'row' => $row];
        }

        $cfg = $this->liveConfig();
        $secondsIntoPeriod = $now - $periodStart;

        if (empty($cfg['enabled'])) {
            // Operator turned on-demand pulls off - cron/worker owns the refresh.
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => null];
        }
        if (!$force && $secondsIntoPeriod > (float)$cfg['window']) {
            // Not our job right now - the background worker owns the refresh.
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => null];
        }

        $game = $this->getGame($gameCode);
        $url = (string)($game['external_api_url'] ?? '');
        if ($url === '' || !function_exists('curl_init')) {
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => null];
        }

        // The throttle ALWAYS applies (even for an explicit result lookup), so a client polling
        // once per second can never turn into one upstream call per second.
        $state = $this->stateFile($gameCode);
        if (!$this->throttleAllows($state, (float)$cfg['min_gap'])) {
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => $this->getResult($gameCode, $closedIssue)];
        }

        // Single flight across every PHP-FPM worker: only one request talks to the provider,
        // the others wait briefly and then serve the row it wrote.
        $lock = @fopen($state . '.lock', 'c');
        if ($lock === false) {
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => $this->waitForRow($gameCode, $closedIssue, (float)$cfg['max_wait'])];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => $this->waitForRow($gameCode, $closedIssue, (float)$cfg['max_wait'])];
        }

        try {
            // Double-check: a sibling request may have written the row while we waited.
            $row = $this->getResult($gameCode, $closedIssue);
            if ($row !== null) {
                $this->touchState($state);
                return ['needed' => true, 'fetched' => false, 'saved' => 0, 'row' => $row];
            }

            $saved = 0;
            $list = $this->api->fetchHistory($url, $gameCode, (bool)($cfg['allow_fallback'] ?? false), (float)$cfg['timeout']);
            $this->touchState($state);
            if (!empty($list)) {
                $res = $this->persistResults($gameCode, $list);
                $saved = (int)$res['saved'];
            }

            return ['needed' => true, 'fetched' => !empty($list), 'saved' => $saved, 'row' => $this->getResult($gameCode, $closedIssue)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function getResult(string $gameCode, string $issueNumber): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT issue_number, number, color, premium, sum, draw_time
             FROM wingo_results WHERE game_code = ? AND issue_number = ? LIMIT 1"
        );
        $stmt->execute([$gameCode, $issueNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Poll the DB briefly while a sibling request performs the upstream pull.
     */
    private function waitForRow(string $gameCode, string $issueNumber, float $maxWait): ?array {
        $deadline = microtime(true) + $maxWait;
        $row = $this->getResult($gameCode, $issueNumber);
        while ($row === null && microtime(true) < $deadline) {
            usleep(60000); // 60ms
            $row = $this->getResult($gameCode, $issueNumber);
        }
        return $row;
    }

    private function stateFile(string $gameCode): string {
        $dir = sys_get_temp_dir();
        if (!is_writable($dir)) {
            $dir = dirname(__DIR__) . '/data';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        return $dir . '/wingo_live_' . preg_replace('/[^A-Za-z0-9_]/', '', $gameCode) . '.state';
    }

    private function throttleAllows(string $stateFile, float $minGap): bool {
        if (!file_exists($stateFile)) {
            return true;
        }
        return (microtime(true) - (float)filemtime($stateFile)) >= $minGap;
    }

    private function touchState(string $stateFile): void {
        @touch($stateFile);
        @clearstatcache(true, $stateFile);
    }

    // ------------------------------------------------------------------
    // Period timing
    // ------------------------------------------------------------------

    /**
     * Accurately calculate and update current and next issue periods
     */
    public function updateCurrentIssue(string $gameCode, ?int $interval = null): array {
        $interval = ($interval && $interval > 0) ? $interval : $this->getIntervalSeconds($gameCode);
        $offset = (int)($this->config['issue_offset'] ?? 0);

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;

        $currentIssue = $this->issueForTime($gameCode, $currentStartTs, $interval, $offset);
        $nextIssue = $this->issueForTime($gameCode, $currentEndTs, $interval, $offset);

        $currentStartStr = date('Y-m-d H:i:s', $currentStartTs);
        $currentEndStr = date('Y-m-d H:i:s', $currentEndTs);
        $nextStartStr = date('Y-m-d H:i:s', $currentEndTs);
        $nextEndStr = date('Y-m-d H:i:s', $currentEndTs + $interval);

        if ($this->driver === 'mysql') {
            $sql = "INSERT INTO wingo_current_issue
                    (game_code, current_issue, current_start, current_end, next_issue, next_start, next_end, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                    current_issue=VALUES(current_issue),
                    current_start=VALUES(current_start),
                    current_end=VALUES(current_end),
                    next_issue=VALUES(next_issue),
                    next_start=VALUES(next_start),
                    next_end=VALUES(next_end),
                    updated_at=CURRENT_TIMESTAMP";
        } else {
            $sql = "INSERT INTO wingo_current_issue
                    (game_code, current_issue, current_start, current_end, next_issue, next_start, next_end, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(game_code) DO UPDATE SET
                    current_issue=excluded.current_issue,
                    current_start=excluded.current_start,
                    current_end=excluded.current_end,
                    next_issue=excluded.next_issue,
                    next_start=excluded.next_start,
                    next_end=excluded.next_end,
                    updated_at=CURRENT_TIMESTAMP";
        }

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
     * Get real-time status and issue timing for frontend countdown.
     *
     * @param bool $autoPull pull the just-closed result on demand (zero-delay delivery)
     */
    public function getCurrentIssue(string $gameCode, bool $autoPull = true): array {
        $game = $this->getGame($gameCode);
        if (!$game) {
            throw new Exception("Invalid game code: {$gameCode}");
        }

        $interval = (int)($game['interval_seconds'] ?? 0);
        if ($interval <= 0) {
            $interval = $this->api->getInterval($gameCode); // never divide by zero on a bad row
        }
        $lockSeconds = isset($game['lock_seconds']) ? (int)$game['lock_seconds'] : 5;
        $offset = (int)($this->config['issue_offset'] ?? 0);

        $now = time();
        $currentStartTs = $now - ($now % $interval);
        $currentEndTs = $currentStartTs + $interval;

        // The open period comes from the clock, so it never waits for a sync cycle.
        $currentIssue = $this->issueForTime($gameCode, $currentStartTs, $interval, $offset);
        $nextIssue = $this->issueForTime($gameCode, $currentEndTs, $interval, $offset);
        $lastIssue = $this->issueForTime($gameCode, $currentStartTs - $interval, $interval, $offset);

        $secondsLeft = max(0, $currentEndTs - $now);
        $isLocked = ($secondsLeft <= $lockSeconds);

        $resultPending = $this->getResult($gameCode, $lastIssue) === null;
        if ($autoPull && $resultPending) {
            $pull = $this->ensureLiveResult($gameCode);
            $resultPending = ($pull['row'] === null);
        }

        return [
            'game_code' => $gameCode,
            'game_name' => $game['name'],
            'interval' => $interval,
            'lock_seconds' => $lockSeconds,
            'issue_number' => $currentIssue,
            'start_time' => date('Y-m-d H:i:s', $currentStartTs),
            'end_time' => date('Y-m-d H:i:s', $currentEndTs),
            'next_issue_number' => $nextIssue,
            'next_start_time' => date('Y-m-d H:i:s', $currentEndTs),
            'next_end_time' => date('Y-m-d H:i:s', $currentEndTs + $interval),
            'last_issue_number' => $lastIssue,
            'result_pending' => $resultPending,
            'result_available' => !$resultPending,
            'seconds_left' => $secondsLeft,
            'is_locked' => $isLocked,
            'server_time' => date('Y-m-d H:i:s', $now),
            'server_timestamp' => $now
        ];
    }

    /**
     * Issue number of the period containing $timestamp, with the configured offset applied.
     */
    public function issueForTime(string $gameCode, int $timestamp, int $interval, int $offset = 0): string {
        if ($offset === 0) {
            return $this->api->calculateIssueNumberForTime($gameCode, $timestamp, $interval);
        }
        $periodIndex = $this->api->periodIndexForTime($timestamp, $interval) + $offset;
        if ($periodIndex < 1) {
            // rolled back past local midnight -> previous day's last period
            $interval = $interval > 0 ? $interval : 60;
            $periodsPerDay = intdiv(86400, $interval);
            $periodIndex += $periodsPerDay;
            $timestamp -= 86400;
        }
        return date('Ymd', $timestamp) . sprintf('%05d', $periodIndex);
    }

    /**
     * Active issue is the period that is open for betting right now.
     * Kept for backwards compatibility with anything calling it directly.
     */
    private function deriveActiveIssueNumber(string $gameCode, int $currentStartTs): string {
        return $this->issueForTime(
            $gameCode,
            $currentStartTs,
            $this->getIntervalSeconds($gameCode),
            (int)($this->config['issue_offset'] ?? 0)
        );
    }

    /**
     * Newest stored result issue for a game (provider ordering, not insert ordering).
     */
    public function latestStoredIssue(string $gameCode): ?string {
        $stmt = $this->pdo->prepare(
            "SELECT issue_number FROM wingo_results WHERE game_code = ? ORDER BY issue_number DESC LIMIT 1"
        );
        $stmt->execute([$gameCode]);
        $issue = $stmt->fetchColumn();
        return $issue === false ? null : (string)$issue;
    }

    /**
     * Derive next issue number (Increment sequence by 1)
     */
    public function deriveNextIssueNumber(string $currentIssue): string {
        if (strlen($currentIssue) >= 10) {
            $prefix = substr($currentIssue, 0, -4);
            $seq = (int)substr($currentIssue, -4);
            return $prefix . sprintf('%04d', $seq + 1);
        }
        return (string)((int)$currentIssue + 1);
    }

    /**
     * Get historical draw results.
     *
     * Returns every CLOSED period (issue_number < the period currently open for betting), which
     * includes the period that closed a second ago. The still-open period is hidden so its
     * result can never leak before the countdown ends.
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
        }

        // Nothing closed yet (fresh install): show the newest stored draws so the UI is not empty.
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

        return $stmt->fetchAll();
    }
}
