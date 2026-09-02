<?php

declare(strict_types=1);

namespace Lottery\Games;

use Lottery\Support\ApiException;

/**
 * 17-digit issue number: YYYYMMDD + familyCode(3) + intervalCode(2) + sequence(4)
 *
 *   20260831 100 01 0001  ->  WinGo_1M, first round of 31 Aug 2026
 *
 * The sequence is the 1-based index of the round inside the local calendar day,
 * so it is fully reproducible from a timestamp and never needs a counter table.
 */
final class IssueNumber
{
    public const LENGTH = 17;

    public static function build(GameDefinition $game, string $dateYmd, int $sequence): string
    {
        if (!preg_match('/^\d{8}$/', $dateYmd)) {
            throw ApiException::validation('Issue date must be YYYYMMDD');
        }
        if ($sequence < 1 || $sequence > 9999) {
            throw ApiException::validation('Issue sequence out of range (1-9999)');
        }

        return $dateYmd
            . $game->familyCode
            . $game->intervalCode
            . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{date:string,familyCode:string,intervalCode:string,sequence:int}
     */
    public static function parse(string $issueNumber): array
    {
        if (!preg_match('/^(\d{8})(\d{3})(\d{2})(\d{4})$/', $issueNumber, $m)) {
            throw ApiException::validation('Invalid issue number (expected 17 digits)');
        }

        return [
            'date'         => $m[1],
            'familyCode'   => $m[2],
            'intervalCode' => $m[3],
            'sequence'     => (int) $m[4],
        ];
    }

    public static function isValid(string $issueNumber): bool
    {
        return (bool) preg_match('/^\d{17}$/', $issueNumber);
    }

    /** True when the issue number belongs to the given game. */
    public static function belongsTo(string $issueNumber, GameDefinition $game): bool
    {
        try {
            $parts = self::parse($issueNumber);
        } catch (ApiException $e) {
            return false;
        }

        return $parts['familyCode'] === $game->familyCode
            && $parts['intervalCode'] === $game->intervalCode;
    }
}
