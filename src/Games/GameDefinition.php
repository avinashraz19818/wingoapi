<?php

declare(strict_types=1);

namespace Lottery\Games;

/**
 * Immutable description of one playable game (family + interval).
 */
final class GameDefinition
{
    public string $code;          // WinGo_1M
    public string $family;        // WinGo
    public string $familyCode;    // 100
    public string $intervalKey;   // 1M
    public string $intervalCode;  // 01
    public int $seconds;          // 60
    public int $lockSeconds;      // 5
    public int $sort;
    public int $state;
    public string $name;          // WinGo 1 Minute

    public function __construct(
        string $code,
        string $family,
        string $familyCode,
        string $intervalKey,
        string $intervalCode,
        int $seconds,
        int $lockSeconds,
        int $sort,
        int $state,
        string $name
    ) {
        $this->code         = $code;
        $this->family       = $family;
        $this->familyCode   = $familyCode;
        $this->intervalKey  = $intervalKey;
        $this->intervalCode = $intervalCode;
        $this->seconds      = $seconds;
        $this->lockSeconds  = $lockSeconds;
        $this->sort         = $sort;
        $this->state        = $state;
        $this->name         = $name;
    }

    /** Rounds per calendar day. */
    public function dailyIssues(): int
    {
        return (int) floor(86400 / $this->seconds);
    }

    public function toArray(): array
    {
        return [
            'gameCode'        => $this->code,
            'lottery'         => $this->family,
            'name'            => $this->name,
            'interval'        => $this->intervalKey,
            'intervalSeconds' => $this->seconds,
            'lockSeconds'     => $this->lockSeconds,
            'familyCode'      => $this->familyCode,
            'intervalCode'    => $this->intervalCode,
            'dailyIssues'     => $this->dailyIssues(),
            'sort'            => $this->sort,
            'state'           => $this->state,
        ];
    }
}
