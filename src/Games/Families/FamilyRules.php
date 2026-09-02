<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * Everything that differs between lottery families lives behind this contract:
 * how a draw is produced, how a provider payload is read, which bets exist,
 * and how a bet is evaluated against a result.
 */
interface FamilyRules
{
    public function family(): string;

    /**
     * Produce a canonical result array from a deterministic random source.
     *
     * @param callable(int,int):int $rng rng($min, $max)
     */
    public function generate(callable $rng): array;

    /**
     * Build a canonical result from an admin override value
     * (e.g. "7" for WinGo, "1,3,6" for K3, "1,2,3,4,5" for D5).
     */
    public function fromOverride(string $value): array;

    /**
     * Read a provider payload row into a canonical result. Returns null when
     * the payload cannot be understood (caller then falls back locally).
     */
    public function fromProvider(array $row): ?array;

    /** Denormalised columns for the results table + list responses. */
    public function summary(array $result): array;

    /** Bet types with their base odds, for GetGameInfo. */
    public function betOptions(): array;

    /**
     * Validate bet content and split it into billable units.
     *
     * @return array<int,string> canonical selections (1 unit each)
     */
    public function parseSelections(string $betType, string $betContent): array;

    /**
     * Evaluate one selection against a result.
     *
     * @return array{won:bool,odds:float}
     */
    public function evaluateSelection(string $betType, string $selection, array $result): array;

    /** Base odds used for the "potential payout" preview at bet time. */
    public function baseOdds(string $betType, string $selection): float;

    /**
     * Positions used by trend statistics, e.g. ['number' => [0..9]].
     *
     * @return array<string,array<int,string>>
     */
    public function trendPositions(): array;

    /** Did a given trend option occur in this result? */
    public function trendMatches(array $result, string $position, string $option): bool;
}
