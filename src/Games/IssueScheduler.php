<?php

declare(strict_types=1);

namespace Lottery\Games;

use DateTimeImmutable;
use DateTimeZone;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;

/**
 * Deterministic round scheduling. Rounds are aligned to local midnight so the
 * sequence inside the issue number always matches wall-clock time.
 */
class IssueScheduler
{
    /**
     * Timezone whose midnight restarts the daily sequence. Empty = the app
     * timezone (Asia/Kolkata). Upstream providers often number their rounds
     * from 00:00 UTC, and matching that keeps our issue numbers identical.
     */
    private ?DateTimeZone $issueZone;

    public function __construct(string $issueTimezone = '')
    {
        $this->issueZone = $issueTimezone === '' ? null : new DateTimeZone($issueTimezone);
    }

    public function issueAt(GameDefinition $game, ?int $timestamp = null): Issue
    {
        $timestamp = $timestamp ?? Clock::now();
        $dayStart  = $this->dayStart($timestamp);
        $index     = (int) floor(($timestamp - $dayStart) / $game->seconds);

        return $this->issueByIndex($game, $dayStart, $index);
    }

    /** Unix timestamp of the midnight that opens this issue day. */
    private function dayStart(int $timestamp): int
    {
        if ($this->issueZone === null) {
            return (int) strtotime(date('Y-m-d 00:00:00', $timestamp));
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->issueZone)
            ->setTime(0, 0, 0)
            ->getTimestamp();
    }

    /** Date stamp (YYYYMMDD) used inside the issue number. */
    private function dateStamp(int $timestamp): string
    {
        if ($this->issueZone === null) {
            return date('Ymd', $timestamp);
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->issueZone)
            ->format('Ymd');
    }

    public function current(GameDefinition $game, ?int $timestamp = null): Issue
    {
        return $this->issueAt($game, $timestamp);
    }

    public function next(GameDefinition $game, ?int $timestamp = null): Issue
    {
        $current = $this->issueAt($game, $timestamp);
        return $this->issueAt($game, $current->endTs);
    }

    public function previous(GameDefinition $game, ?int $timestamp = null): Issue
    {
        $current = $this->issueAt($game, $timestamp);
        return $this->issueAt($game, $current->startTs - 1);
    }

    /**
     * Rounds that have already finished, newest first.
     *
     * @return array<int,Issue>
     */
    public function recentClosed(GameDefinition $game, int $count, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? Clock::now();
        $cursor    = $this->issueAt($game, $timestamp)->startTs - 1;
        $issues    = [];

        for ($i = 0; $i < $count; $i++) {
            if ($cursor < 0) {
                break;
            }
            $issue    = $this->issueAt($game, $cursor);
            $issues[] = $issue;
            $cursor   = $issue->startTs - 1;
        }

        return $issues;
    }

    /** Rebuild an Issue from its number (used to validate client input). */
    public function fromIssueNumber(GameDefinition $game, string $issueNumber): Issue
    {
        $parts = IssueNumber::parse($issueNumber);
        if (!IssueNumber::belongsTo($issueNumber, $game)) {
            throw ApiException::validation('Issue number does not belong to ' . $game->code);
        }

        $dayStart = $this->issueZone === null
            ? strtotime($parts['date'] . ' 00:00:00')
            : (new DateTimeImmutable($parts['date'] . ' 00:00:00', $this->issueZone))->getTimestamp();

        if ($dayStart === false) {
            throw ApiException::validation('Invalid issue date');
        }

        return $this->issueByIndex($game, $dayStart, $parts['sequence'] - 1);
    }

    private function issueByIndex(GameDefinition $game, int $dayStart, int $index): Issue
    {
        $start = $dayStart + ($index * $game->seconds);
        $end   = $start + $game->seconds;

        return new Issue(
            $game->code,
            IssueNumber::build($game, $this->dateStamp($start), $index + 1),
            $start,
            $end,
            $end - $game->lockSeconds,
            $index + 1
        );
    }
}
