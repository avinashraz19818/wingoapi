<?php

declare(strict_types=1);

namespace Lottery\Stats;

use Lottery\Draw\DrawService;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;

/**
 * Trend statistics over the last N rounds (default 100).
 *
 * For every position (WinGo: number/size/parity/colour, K3: total/size/parity,
 * D5: each digit position, MotoRace: champion/size/parity) and every option we
 * report:
 *   missing        rounds since the option last appeared (0 = latest round)
 *   openCount      how many times it appeared inside the window
 *   maxContinuous  longest run of consecutive rounds it appeared in
 *   currentStreak  ongoing run counted from the newest round
 */
class TrendService
{
    public const DEFAULT_WINDOW = 100;

    private DrawService $draws;
    private RulesFactory $rules;

    public function __construct(DrawService $draws, RulesFactory $rules)
    {
        $this->draws = $draws;
        $this->rules = $rules;
    }

    public function statistics(GameDefinition $game, int $window = self::DEFAULT_WINDOW, ?string $maxIssue = null): array
    {
        $window  = max(1, min(500, $window));
        $rows    = $this->draws->history($game, $window, 0, $maxIssue);   // newest first
        $results = array_map(static fn(array $row): array => $row['result'], $rows);
        $rules   = $this->rules->forGame($game);
        $rounds  = count($results);

        $positions = [];
        foreach ($rules->trendPositions() as $position => $options) {
            $stats = [];
            foreach ($options as $option) {
                $stats[] = $this->statsFor($rules, $results, $position, (string) $option);
            }
            $positions[$position] = $stats;
        }

        return [
            'gameCode'    => $game->code,
            'window'      => $window,
            'rounds'      => $rounds,
            'latestIssue' => $rows[0]['issue_number'] ?? null,
            'positions'   => $positions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $results newest first
     */
    private function statsFor($rules, array $results, string $position, string $option): array
    {
        $missing       = 0;
        $missingFound  = false;
        $openCount     = 0;
        $maxContinuous = 0;
        $run           = 0;
        $currentStreak = 0;
        $index         = 0;

        foreach ($results as $result) {
            $hit = $rules->trendMatches($result, $position, $option);

            if ($hit) {
                $openCount++;
                $run++;
                $maxContinuous = max($maxContinuous, $run);
                if (!$missingFound) {
                    $missing      = $index;
                    $missingFound = true;
                }
                if ($index === $run - 1) {
                    $currentStreak = $run;
                }
            } else {
                $run = 0;
            }
            $index++;
        }

        if (!$missingFound) {
            $missing = count($results);
        }

        return [
            'value'         => $option,
            'missing'       => $missing,
            'openCount'     => $openCount,
            'maxContinuous' => $maxContinuous,
            'currentStreak' => $currentStreak,
        ];
    }
}
