<?php

declare(strict_types=1);

namespace Lottery\Games;

use Lottery\Support\ApiException;
use Lottery\Support\Clock;

/**
 * Deterministic round scheduling. Rounds are aligned to local midnight so the
 * sequence inside the issue number always matches wall-clock time.
 */
class IssueScheduler
{
    public function issueAt(GameDefinition $game, ?int $timestamp = null): Issue
    {
        $timestamp = $timestamp ?? Clock::now();
        $dayStart  = strtotime(date('Y-m-d 00:00:00', $timestamp));
        $index     = (int) floor(($timestamp - $dayStart) / $game->seconds);

        return $this->issueByIndex($game, $dayStart, $index);
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

        $dayStart = strtotime($parts['date'] . ' 00:00:00');
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
            IssueNumber::build($game, date('Ymd', $start), $index + 1),
            $start,
            $end,
            $end - $game->lockSeconds,
            $index + 1
        );
    }
}
