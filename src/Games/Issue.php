<?php

declare(strict_types=1);

namespace Lottery\Games;

/**
 * One scheduled round of a game.
 */
final class Issue
{
    public string $gameCode;
    public string $issueNumber;
    public string $number;
    public int $startTs;
    public int $endTs;
    public int $lockTs;
    public int $sequence;

    public function __construct(
        string $gameCode,
        string $issueNumber,
        int $startTs,
        int $endTs,
        int $lockTs,
        int $sequence
    ) {
        $this->gameCode    = $gameCode;
        $this->issueNumber = $issueNumber;
        $this->number      = $issueNumber;
        $this->startTs     = $startTs;
        $this->endTs       = $endTs;
        $this->lockTs      = $lockTs;
        $this->sequence    = $sequence;
    }

    public function isOpenAt(int $now): bool
    {
        return $now >= $this->startTs && $now < $this->lockTs;
    }

    public function remainingSeconds(int $now): int
    {
        return max(0, $this->endTs - $now);
    }

    public function toArray(int $now): array
    {
        return [
            'gameCode'     => $this->gameCode,
            'issueNumber'  => $this->issueNumber,
            'sequence'     => $this->sequence,
            'startTime'    => date('Y-m-d H:i:s', $this->startTs),
            'endTime'      => date('Y-m-d H:i:s', $this->endTs),
            'lockTime'     => date('Y-m-d H:i:s', $this->lockTs),
            'remaining'    => $this->remainingSeconds($now),
            'bettingOpen'  => $this->isOpenAt($now),
        ];
    }
}
