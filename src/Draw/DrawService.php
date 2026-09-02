<?php

declare(strict_types=1);

namespace Lottery\Draw;

use Lottery\Admin\OverrideService;
use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Games\Issue;
use Lottery\Games\IssueScheduler;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use PDOException;

/**
 * Owns the lifecycle of a round's result.
 *
 * Resolution order for a finished issue:
 *   1. result already stored            -> return it (results are immutable)
 *   2. pending admin override           -> apply, then clear the override
 *   3. external provider (5s timeout)   -> store with source=remote
 *   4. local HMAC-SHA256 generator      -> store with source=local
 *                                          (skipped when force_remote_draw=true)
 */
class DrawService
{
    private Connection $db;
    private RulesFactory $rules;
    private IssueScheduler $scheduler;
    private DrawFetcher $fetcher;
    private LocalDrawGenerator $local;
    private OverrideService $overrides;
    private bool $forceRemote;
    private int $fallbackDelay;
    private int $publicationLag;

    public function __construct(
        Connection $db,
        RulesFactory $rules,
        IssueScheduler $scheduler,
        DrawFetcher $fetcher,
        LocalDrawGenerator $local,
        OverrideService $overrides,
        bool $forceRemote,
        int $fallbackDelay = 25,
        int $publicationLag = 0
    ) {
        $this->fallbackDelay = max(0, $fallbackDelay);
        $this->db          = $db;
        $this->rules       = $rules;
        $this->scheduler   = $scheduler;
        $this->fetcher     = $fetcher;
        $this->local       = $local;
        $this->overrides   = $overrides;
        $this->forceRemote = $forceRemote;
        $this->publicationLag = $publicationLag;
    }

    /* -----------------------------------------------------------------------
     | Publication window
     |
     | A round is *drawn* and *settled* the moment it closes, but it does not
     | have to become *visible* at the same time. ISSUE_OFFSET holds results
     | back by whole periods so the feed trails the clock:
     |
     |   lag 0 -> while round N is live, the newest published result is N-1
     |   lag 1 -> while round N is live, the newest published result is N-2
     |
     | Every read path that a customer can reach goes through the helpers
     | below, so a client cannot peek at a held-back round by guessing an
     | issue number or by passing its own activeIssue.
     * -------------------------------------------------------------------- */

    /** Periods the published result trails the live round by. */
    public function publicationLag(): int
    {
        return $this->publicationLag;
    }

    /**
     * Exclusive upper bound for published results: only issue numbers strictly
     * below this one may be served.
     *
     *   lag 0 -> the live issue number      (newest published = the round that just closed)
     *   lag 1 -> the previous issue number  (newest published = one round older)
     */
    public function visibleBefore(GameDefinition $game, ?int $now = null): string
    {
        return $this->scheduler->shifted($game, $this->publicationLag, $now)->issueNumber;
    }

    /** The newest round that is allowed to be published right now. */
    public function newestVisible(GameDefinition $game, ?int $now = null): Issue
    {
        return $this->scheduler->shifted($game, $this->publicationLag + 1, $now);
    }

    /** Is this round old enough to be published? */
    public function isVisible(GameDefinition $game, string $issueNumber, ?int $now = null): bool
    {
        // Issue numbers are fixed-width 17-digit strings, so a string compare
        // is the numeric compare.
        return $issueNumber < $this->visibleBefore($game, $now);
    }

    /**
     * Upper bound to page a history query with.
     *
     * A caller-supplied activeIssue is honoured only as far back as the
     * publication window allows — it can never be used to look *forward* past
     * a held-back round.
     */
    public function resolveMaxIssue(GameDefinition $game, ?string $requested, ?int $now = null): string
    {
        $boundary = $this->visibleBefore($game, $now);
        $requested = trim((string) $requested);

        if ($requested === '' || strcmp($requested, $boundary) > 0) {
            return $boundary;
        }

        return $requested;
    }

    /**
     * Forget the provider's cached response.
     *
     * The fetcher memoises each endpoint for the lifetime of the process so a
     * single request never hits the provider twice. The worker, however, is a
     * daemon: without this it would keep serving the same ten rows it fetched
     * at boot and every later round would look "not published yet".
     */
    public function flushProviderCache(): void
    {
        $this->fetcher->flush();
    }

    /** Stored result row (decoded) or null. */
    public function find(GameDefinition $game, string $issueNumber): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM ' . Tables::RESULTS . ' WHERE game_code = ? AND issue_number = ?',
            [$game->code, $issueNumber]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Ensure the given (finished) issue has a result. Returns null when the
     * round is still running, or when force_remote_draw is on and the provider
     * has not published the result yet.
     */
    public function ensureResult(GameDefinition $game, Issue $issue, ?int $now = null): ?array
    {
        $now = $now ?? Clock::now();

        if ($now < $issue->endTs) {
            return null;
        }

        $existing = $this->find($game, $issue->issueNumber);
        if ($existing !== null) {
            return $existing;
        }

        $resolved = $this->resolve($game, $issue->issueNumber, $issue->endTs, $now);
        if ($resolved === null) {
            return null;
        }

        return $this->store($game, $issue, $resolved['result'], $resolved['source'], $resolved['hash']);
    }

    /** Resolve every finished-but-undrawn issue for a game (worker entrypoint). */
    public function catchUp(GameDefinition $game, int $maxIssues = 20, ?int $now = null): array
    {
        $now      = $now ?? Clock::now();
        $resolved = [];

        foreach (array_reverse($this->scheduler->recentClosed($game, $maxIssues, $now)) as $issue) {
            $result = $this->ensureResult($game, $issue, $now);
            if ($result !== null && ($result['fresh'] ?? false)) {
                $resolved[] = $result;
            }
        }

        return $resolved;
    }

    /**
     * @return array{result:array,source:string,hash:?string}|null
     */
    private function resolve(GameDefinition $game, string $issueNumber, int $endTs = 0, ?int $now = null): ?array
    {
        $now = $now ?? Clock::now();
        $override = $this->overrides->pendingFor($game, $issueNumber);
        if ($override !== null) {
            try {
                $result = $this->rules->forGame($game)->fromOverride((string) $override['override_value']);
                $this->overrides->consume($override, $issueNumber);

                return ['result' => $result, 'source' => 'override', 'hash' => null];
            } catch (ApiException $e) {
                Log::error('invalid override value, ignoring', [
                    'game' => $game->code, 'issue' => $issueNumber, 'error' => $e->getMessage(),
                ]);
            }
        }

        $remote = $this->fetcher->fetchIssue($game, $issueNumber);
        if ($remote !== null) {
            return ['result' => $remote['result'], 'source' => 'remote', 'hash' => $remote['hash']];
        }

        if ($this->forceRemote) {
            Log::warning('remote draw unavailable and force_remote_draw is enabled', [
                'game' => $game->code, 'issue' => $issueNumber,
            ]);
            return null;
        }

        $local = $this->local->draw($game, $issueNumber);

        return ['result' => $local['result'], 'source' => 'local', 'hash' => $local['hash']];
    }

    /** Persist a result idempotently (unique on game_code + issue_number). */
    public function store(GameDefinition $game, Issue $issue, array $result, string $source, ?string $hash): array
    {
        $summary = $this->rules->forGame($game)->summary($result);
        $now     = Clock::dateTime();

        try {
            $this->db->execute(
                $this->db->insertIgnore() . ' ' . Tables::RESULTS . '
                    (game_code, issue_number, family, result_json, primary_number, color, sum_value, source, draw_hash, drawn_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $game->code,
                    $issue->issueNumber,
                    $game->family,
                    json_encode($result, JSON_UNESCAPED_SLASHES),
                    $summary['primary_number'],
                    $summary['color'],
                    $summary['sum_value'],
                    $source,
                    $hash,
                    date('Y-m-d H:i:s', $issue->endTs),
                    $now,
                ]
            );
        } catch (PDOException $e) {
            if (!$this->db->isDuplicateKey($e)) {
                throw $e;
            }
        }

        $this->recordIssue($game, $issue, 'drawn');

        $stored = $this->find($game, $issue->issueNumber);
        if ($stored === null) {
            throw ApiException::server('Failed to persist draw result');
        }
        $stored['fresh'] = true;

        Log::info('draw resolved', [
            'game'   => $game->code,
            'issue'  => $issue->issueNumber,
            'source' => $source,
        ]);

        return $stored;
    }

    /** Keep the issue calendar in sync (used for auditing and admin UIs). */
    public function recordIssue(GameDefinition $game, Issue $issue, string $status = 'open'): void
    {
        try {
            $this->db->execute(
                $this->db->insertIgnore() . ' ' . Tables::ISSUES . '
                    (game_code, issue_number, start_time, end_time, lock_time, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $game->code,
                    $issue->issueNumber,
                    date('Y-m-d H:i:s', $issue->startTs),
                    date('Y-m-d H:i:s', $issue->endTs),
                    date('Y-m-d H:i:s', $issue->lockTs),
                    $status,
                    Clock::dateTime(),
                ]
            );
            if ($status !== 'open') {
                $this->db->execute(
                    'UPDATE ' . Tables::ISSUES . ' SET status = ? WHERE game_code = ? AND issue_number = ?',
                    [$status, $game->code, $issue->issueNumber]
                );
            }
        } catch (PDOException $e) {
            if (!$this->db->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * Recent results, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(GameDefinition $game, int $limit, int $offset = 0, ?string $maxIssue = null): array
    {
        $sql = 'SELECT * FROM ' . Tables::RESULTS . ' WHERE game_code = ?';
        $params = [$game->code];

        if ($maxIssue !== null && $maxIssue !== '') {
            $sql .= ' AND issue_number < ?';
            $params[] = $maxIssue;
        }

        $sql .= ' ORDER BY issue_number DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn(array $row): array => $this->hydrate($row), $rows);
    }

    public function countHistory(GameDefinition $game, ?string $maxIssue = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . Tables::RESULTS . ' WHERE game_code = ?';
        $params = [$game->code];

        if ($maxIssue !== null && $maxIssue !== '') {
            $sql .= ' AND issue_number < ?';
            $params[] = $maxIssue;
        }

        return (int) $this->db->fetchValue($sql, $params);
    }

    private function hydrate(array $row): array
    {
        $row['result'] = json_decode((string) $row['result_json'], true) ?: [];
        unset($row['result_json']);
        $row['fresh'] = false;

        return $row;
    }
}
