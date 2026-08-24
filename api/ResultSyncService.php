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

    /**
     * How many seconds before its own minute ends the provider hands over the next result.
     * Clamped so it can never eat the whole period.
     */
    public function resultLeadSeconds(int $interval): int
    {
        $lead = (int)($this->config['period']['result_lead_seconds'] ?? 2);
        if ($interval <= 1) {
            return 0;
        }
        return max(0, min($lead, $interval - 1));
    }

    /**
     * Seconds our period boundaries sit past the plain wall-clock minute.
     *
     * The provider publishes $lead seconds early, so we start counting $lead seconds early
     * too: our countdown for a period begins the instant the provider publishes it and ends
     * the instant it publishes the next one. For WinGo_1M with a 2s lead that is a 58s phase,
     * i.e. our rollover lands at :58 - two seconds ahead of the plain minute.
     */
    public function phaseOffsetSeconds(int $interval): int
    {
        if ($interval <= 0) {
            return 0;
        }
        return ($interval - $this->resultLeadSeconds($interval)) % $interval;
    }

    /**
     * Start of the window that contains $now, honouring the phase offset.
     */
    public function windowStart(int $interval, int $now): int
    {
        if ($interval <= 0) {
            return $now;
        }
        $phase = $this->phaseOffsetSeconds($interval);
        return intdiv($now - $phase, $interval) * $interval + $phase;
    }

    /**
     * DB-clock bounds of the window containing $now: [start, start + interval + 1).
     * Used to answer "did the provider publish anything during this period?".
     *
     * @return array{0:string,1:string}
     */
    private function freshnessWindow(int $interval, int $now): array {
        $start = $this->windowStart($interval, $now);
        return [$this->dbTime($start), $this->dbTime($start + $interval + 1)];
    }

    /**
     * The provider's newest stored draw: the period currently open for betting.
     */
    private function newestRow(string $gameCode): ?array {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, issue_number, fetched_at FROM wingo_results
                 WHERE game_code = ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$gameCode]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
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
    // DB clock alignment
    // ------------------------------------------------------------------

    /**
     * Seconds the database clock is ahead of PHP's clock.
     *
     * wingo_results.fetched_at is written by the DB (CURRENT_TIMESTAMP) in the DB session
     * timezone, while PHP formats times in Asia/Kolkata. On this VPS those differ by hours,
     * so any comparison between the two has to be skewed first.
     */
    private ?int $dbSkew = null;

    private function dbSkewSeconds(): int {
        if ($this->dbSkew === null) {
            $this->dbSkew = 0;
            try {
                $dbNow = $this->pdo->query("SELECT CURRENT_TIMESTAMP")->fetchColumn();
                $dbTs = $dbNow ? strtotime((string)$dbNow) : false;
                if ($dbTs !== false) {
                    $this->dbSkew = $dbTs - time();
                }
            } catch (Throwable $e) {
                $this->dbSkew = 0;
            }
        }
        return $this->dbSkew;
    }

    /**
     * A unix timestamp expressed in the database's own clock, for comparing against fetched_at.
     */
    public function dbTime(int $unixTs): string {
        return date('Y-m-d H:i:s', $unixTs + $this->dbSkewSeconds());
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

        // Providers return the list NEWEST first. We insert oldest-first so that the highest
        // auto-increment id is always the newest draw - "latest" is then a simple ORDER BY id DESC
        // and stays correct even when the provider's own counter jumps (it does).
        $results = array_reverse(array_values($results));

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
     * Make sure the draw for the window that has just closed is in the database NOW.
     *
     * Numbering-agnostic: we do not need to know which issue number the provider will use, we
     * only check whether ANY draw arrived during the previous window. Single-flight (flock) +
     * throttled, so 1000 concurrent players cause exactly one upstream call.
     *
     * @return array{needed:bool, fetched:bool, saved:int, fresh:bool}
     */
    public function ensureLiveResult(string $gameCode, bool $force = false): array {
        $interval = $this->getIntervalSeconds($gameCode);
        $now = time();
        $periodStart = $this->windowStart($interval, $now);

        // One single definition of "the provider has published during this window", used by
        // every branch below: the period on screen belongs to this window, so the next
        // rollover needs no network.
        [$freshFrom, $freshTo] = $this->freshnessWindow($interval, $now);

        $fresh = $this->hasDrawInWindow($gameCode, $freshFrom, $freshTo);
        if ($fresh) {
            return ['needed' => false, 'fetched' => false, 'saved' => 0, 'fresh' => true];
        }

        $cfg = $this->liveConfig();
        $secondsIntoPeriod = $now - $periodStart;

        if (empty($cfg['enabled'])) {
            // Operator turned on-demand pulls off - cron/worker owns the refresh.
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => false];
        }
        if (!$force && $secondsIntoPeriod > (float)$cfg['window']) {
            // Not our job right now - the background worker owns the refresh.
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => false];
        }

        $game = $this->getGame($gameCode);
        $url = (string)($game['external_api_url'] ?? '');
        if ($url === '' || !function_exists('curl_init')) {
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => false];
        }

        // The throttle ALWAYS applies (even for an explicit result lookup), so a client polling
        // once per second can never turn into one upstream call per second.
        $state = $this->stateFile($gameCode);
        if (!$this->throttleAllows($state, (float)$cfg['min_gap'])) {
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => $this->waitForFresh($gameCode, $freshFrom, $freshTo, (float)$cfg['max_wait'])];
        }

        // Single flight across every PHP-FPM worker: only one request talks to the provider,
        // the others wait briefly and then serve what it wrote.
        $lock = @fopen($state . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => $this->waitForFresh($gameCode, $freshFrom, $freshTo, (float)$cfg['max_wait'])];
        }

        try {
            // Double-check: a sibling request may have written the row while we waited.
            if ($this->hasDrawInWindow($gameCode, $freshFrom, $freshTo)) {
                $this->touchState($state);
                return ['needed' => true, 'fetched' => false, 'saved' => 0, 'fresh' => true];
            }

            $saved = 0;
            $list = $this->api->fetchHistory($url, $gameCode, (bool)($cfg['allow_fallback'] ?? false), (float)$cfg['timeout']);
            $this->touchState($state);
            if (!empty($list)) {
                $res = $this->persistResults($gameCode, $list);
                $saved = (int)$res['saved'];
            }

            return [
                'needed' => true,
                'fetched' => !empty($list),
                'saved' => $saved,
                'fresh' => $this->hasDrawInWindow($gameCode, $freshFrom, $freshTo),
            ];
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
    private function waitForFresh(string $gameCode, string $from, string $to, float $maxWait): bool {
        $deadline = microtime(true) + $maxWait;
        while (microtime(true) < $deadline) {
            if ($this->hasDrawInWindow($gameCode, $from, $to)) {
                return true;
            }
            usleep(60000); // 60ms
        }
        return $this->hasDrawInWindow($gameCode, $from, $to);
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

        $now = time();
        $currentStartTs = $this->windowStart($interval, $now);
        $currentEndTs = $currentStartTs + $interval;

        // Same source of truth as getCurrentIssue(): the provider's newest draw.
        $currentIssue = $this->deriveActiveIssueNumber($gameCode, $currentStartTs, $interval);
        $nextIssue = $this->deriveNextIssueNumber($currentIssue);

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
        $leadSeconds = $this->resultLeadSeconds($interval);

        $now = time();
        $currentStartTs = $this->windowStart($interval, $now);
        $currentEndTs = $currentStartTs + $interval;

        // THE MODEL
        // -------
        // The provider publishes the result of a period ~$leadSeconds BEFORE its own minute
        // ends (it hands over 841 while 840 is still counting down). Our window is shifted by
        // the same amount, so the moment the provider publishes, our countdown for that period
        // starts - and it ends exactly when the next one is published. Nothing is ever waiting
        // on the network at the boundary.
        //
        //   provider's last result  ->  the period shown for betting (issue_number)
        //   everything older        ->  history (one period behind the provider)
        //
        // The number always comes from the provider's own feed, never from our clock: the
        // provider's counter does not track our time (it has been seen jumping backwards).
        $openRow = $this->newestRow($gameCode);

        if ($openRow !== null) {
            $currentIssue = (string)$openRow['issue_number'];
            $openRowId    = (int)$openRow['id'];
            // Pending means the provider has not published anything during this window yet, so
            // the period on screen is the previous one running over.
            $resultPending = ((string)$openRow['fetched_at']) < $this->dbTime($currentStartTs);
        } else {
            // No stored draws at all (fresh install): fall back to the clock-derived number.
            $currentIssue  = $this->api->calculateIssueNumberForTime($gameCode, $currentStartTs, $interval);
            $openRowId     = null;
            $resultPending = true;
        }
        $nextIssue = $this->deriveNextIssueNumber($currentIssue);

        if ($autoPull && $resultPending) {
            $pull = $this->ensureLiveResult($gameCode);
            $row  = $this->newestRow($gameCode);
            if ($row !== null) {
                $currentIssue  = (string)$row['issue_number'];
                $openRowId     = (int)$row['id'];
                $resultPending = ((string)$row['fetched_at']) < $this->dbTime($currentStartTs);
                $nextIssue     = $this->deriveNextIssueNumber($currentIssue);
            } else {
                $resultPending = empty($pull['fresh']);
            }
        }

        $secondsLeft = max(0, $currentEndTs - $now);
        $isLocked    = ($secondsLeft <= $lockSeconds);

        // History stops one short of the period on screen: the result of the period being bet
        // on stays hidden until it rolls over, and the period that just closed becomes the top
        // row at that same instant.
        $lastIssue = $openRowId !== null ? $this->issueBeforeId($gameCode, $openRowId) : null;

        return [
            'game_code' => $gameCode,
            'game_name' => $game['name'],
            'interval' => $interval,
            'lock_seconds' => $lockSeconds,
            'result_lead_seconds' => $leadSeconds,
            'issue_number' => $currentIssue,
            'start_time' => date('Y-m-d H:i:s', $currentStartTs),
            'end_time' => date('Y-m-d H:i:s', $currentEndTs),
            'next_issue_number' => $nextIssue,
            'next_start_time' => date('Y-m-d H:i:s', $currentEndTs),
            'next_end_time' => date('Y-m-d H:i:s', $currentEndTs + $interval),
            'last_issue_number' => $lastIssue,
            'open_row_id' => $openRowId,
            'history_before_id' => $openRowId,
            'result_pending' => $resultPending,
            'result_available' => !$resultPending,
            'seconds_left' => $secondsLeft,
            'is_locked' => $isLocked,
            'server_time' => date('Y-m-d H:i:s', $now),
            'server_timestamp' => $now
        ];
    }

    /**
     * Id of the row that is currently on screen (the period open for betting).
     * Draws with a lower id are visible / settleable; that row and everything newer are not.
     */
    public function openRowId(string $gameCode): ?int {
        $row = $this->newestRow($gameCode);
        return $row === null ? null : (int)$row['id'];
    }

    /**
     * Issue number of the draw immediately older than $id (the current top of history).
     */
    private function issueBeforeId(string $gameCode, int $id): ?string {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT issue_number FROM wingo_results WHERE game_code = ? AND id < ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$gameCode, $id]);
            $issue = $stmt->fetchColumn();
            return $issue === false ? null : (string)$issue;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function hasDrawInWindow(string $gameCode, string $from, string $to): bool {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM wingo_results WHERE game_code = ? AND fetched_at >= ? AND fetched_at < ? LIMIT 1"
            );
            $stmt->execute([$gameCode, $from, $to]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function latestIssueBefore(string $gameCode, string $before): ?string {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT issue_number FROM wingo_results WHERE game_code = ? AND fetched_at < ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$gameCode, $before]);
            $issue = $stmt->fetchColumn();
            return $issue === false ? null : (string)$issue;
        } catch (Throwable $e) {
            return null;
        }
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
     * Active issue = the newest draw the provider has given us (arrival order).
     * Falls back to the clock-derived number only on a brand-new install with no draws yet.
     */
    private function deriveActiveIssueNumber(string $gameCode, int $currentStartTs, int $interval): string {
        $latest = $this->latestStoredIssue($gameCode);
        if ($latest !== null && $latest !== '') {
            return $latest;
        }
        return $this->api->calculateIssueNumberForTime($gameCode, $currentStartTs, $interval);
    }

    /**
     * Newest stored draw by ARRIVAL order. The provider's own counter can jump backwards, so
     * issue_number ordering is not a reliable "latest".
     */
    public function latestStoredIssue(string $gameCode): ?string {
        $stmt = $this->pdo->prepare(
            "SELECT issue_number FROM wingo_results WHERE game_code = ? ORDER BY id DESC LIMIT 1"
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
     * Visibility rule: everything older than the period currently on screen is visible. Because
     * we run one period behind the provider, the next result is already stored - so the moment
     * the countdown hits 00 the new row is at the top of history with no network round-trip.
     *
     * @param int|null $beforeId exclude this row and everything newer (the period on screen)
     */
    /**
     * Result history, newest first - one period behind the provider.
     *
     * $beforeId is the row currently on screen (getCurrentIssue()['history_before_id']): only
     * rows older than it are returned, so the result of the period being bet on is never
     * readable early. The period that just closed becomes the top row the instant the provider
     * publishes the next one, which is also the instant our countdown ends.
     */
    public function getHistory(string $gameCode, int $limit = 50, ?int $beforeId = null): array {
        $limit = max(1, min(200, $limit));
        $beforeId = $beforeId ?? $this->openRowId($gameCode);

        if ($beforeId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT issue_number, number, color, premium, sum, draw_time, fetched_at
                FROM wingo_results
                WHERE game_code = ? AND id < ?
                ORDER BY id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $gameCode, PDO::PARAM_STR);
            $stmt->bindValue(2, $beforeId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll();
            if (!empty($history)) {
                return $history;
            }
        }

        // Nothing visible yet (fresh install, or the provider is lagging): show the newest
        // stored draws so the client UI is never empty.
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

        return $stmt->fetchAll();
    }
}
