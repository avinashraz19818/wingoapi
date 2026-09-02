<?php

declare(strict_types=1);

namespace Lottery\Api\Compat;

use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;

/**
 * Translates between the "AR" front-end dialect and this engine.
 *
 * Those clients speak a different language for everything:
 *
 *   bet content   "Num_5", "Color_green", "BigSmall_H", "SumNum_10",
 *                 "FirstBigSmall_L", "NumSame2_3", "SecondNum_7"
 *   results       a single `premium` string ("7", "1,3,5", "92046", "3,7,1,…")
 *   envelope      {data, code:0, msg:"Succeed", msgCode:0, serviceTime}
 *
 * Everything here is pure mapping: no state, no side effects.
 */
final class ArTranslator
{
    /* ===================================================================
     |  Bet content:  "Type_Bet"  ->  our betType + betContent
     * ================================================================ */

    /**
     * @return array{betType:string,betContent:string}
     */
    public static function toEngineBet(GameDefinition $game, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            throw ApiException::validation('Empty bet content');
        }

        $parts = explode('_', $content);
        $type  = strtolower(trim((string) array_shift($parts)));
        $bet   = strtolower(trim(implode('_', $parts)));

        switch ($game->family) {
            case 'WinGo':
            case 'TrxWinGo':
                return self::wingoBet($type, $bet, $content);
            case 'K3':
                return self::k3Bet($type, $bet, $content);
            case 'D5':
                return self::d5Bet($type, $bet, $content);
            case 'MotoRace':
                return self::motoBet($type, $bet, $content);
        }

        throw ApiException::validation("Unsupported game family: {$game->family}");
    }

    /** big/small aliases used by these clients: H/L, big/small, high/low */
    private static function size(string $bet): string
    {
        return in_array($bet, ['h', 'big', 'high', '1'], true) ? 'big' : 'small';
    }

    /** odd/even aliases: O/E, odd/even */
    private static function parity(string $bet): string
    {
        return in_array($bet, ['o', 'odd', '1'], true) ? 'odd' : 'even';
    }

    private static function digits(string $bet, int $min, int $max): array
    {
        preg_match_all('/\d+/', $bet, $m);
        $found = [];
        foreach ($m[0] as $raw) {
            $n = (int) $raw;
            if ($n >= $min && $n <= $max) {
                $found[] = $n;
            }
        }

        return array_values(array_unique($found));
    }

    private static function wingoBet(string $type, string $bet, string $raw): array
    {
        if ($type === 'num' || $type === 'number') {
            $numbers = self::digits($bet, 0, 9);
            if ($numbers === []) {
                throw ApiException::validation("Invalid bet content: {$raw}");
            }
            return ['betType' => 'number', 'betContent' => implode(',', $numbers)];
        }
        if ($type === 'color' || $type === 'colour') {
            if (!in_array($bet, ['green', 'red', 'violet'], true)) {
                throw ApiException::validation("Invalid colour: {$raw}");
            }
            return ['betType' => 'color', 'betContent' => $bet];
        }
        if ($type === 'bigsmall' || $type === 'size') {
            return ['betType' => 'size', 'betContent' => self::size($bet)];
        }
        if ($type === 'oddeven' || $type === 'parity') {
            return ['betType' => 'parity', 'betContent' => self::parity($bet)];
        }

        throw ApiException::validation("Unsupported play type: {$raw}");
    }

    private static function k3Bet(string $type, string $bet, string $raw): array
    {
        switch ($type) {
            case 'sumnum':
                return ['betType' => 'total', 'betContent' => (string) (int) $bet];
            case 'sumbigsmall':
                return ['betType' => 'size', 'betContent' => self::size($bet)];
            case 'sumoddeven':
                return ['betType' => 'parity', 'betContent' => self::parity($bet)];
            case 'numsame3all':
                return ['betType' => 'triple_any', 'betContent' => 'any'];
            case 'numsame3':
                $faces = self::digits($bet, 1, 6);
                return ['betType' => 'triple_exact', 'betContent' => (string) ($faces[0] ?? 1)];
            case 'numsame2':
            case 'numsame2mult':
                $faces = self::digits($bet, 1, 6);
                if ($faces === []) {
                    throw ApiException::validation("Invalid pair bet: {$raw}");
                }
                return ['betType' => 'pair', 'betContent' => implode(',', $faces)];
            case 'numdiff2':
                $faces = self::digits($bet, 1, 6);
                if (count($faces) < 2) {
                    throw ApiException::validation("Two different faces are required: {$raw}");
                }
                return ['betType' => 'two_different', 'betContent' => $faces[0] . ':' . $faces[1]];
            case 'numdiff3':
                $faces = self::digits($bet, 1, 6);
                if (count($faces) < 3) {
                    throw ApiException::validation("Three different faces are required: {$raw}");
                }
                return ['betType' => 'three_different', 'betContent' => $faces[0] . ':' . $faces[1] . ':' . $faces[2]];
        }

        throw ApiException::validation("Unsupported K3 play type: {$raw}");
    }

    private const D5_POSITIONS = [
        'first' => 'a', 'second' => 'b', 'third' => 'c', 'fourth' => 'd', 'fifth' => 'e',
        'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e',
    ];

    private static function d5Bet(string $type, string $bet, string $raw): array
    {
        if ($type === 'sumbigsmall') {
            return ['betType' => 'size', 'betContent' => 'sum:' . self::size($bet)];
        }
        if ($type === 'sumoddeven') {
            return ['betType' => 'parity', 'betContent' => 'sum:' . self::parity($bet)];
        }

        foreach (self::D5_POSITIONS as $prefix => $position) {
            if (strpos($type, $prefix) !== 0) {
                continue;
            }
            $suffix = substr($type, strlen($prefix));

            if (str_contains($suffix, 'num')) {
                $numbers = self::digits($bet, 0, 9);
                if ($numbers === []) {
                    throw ApiException::validation("Invalid digit bet: {$raw}");
                }
                $selections = array_map(static fn(int $n): string => $position . ':' . $n, $numbers);
                return ['betType' => 'number', 'betContent' => implode(',', $selections)];
            }
            if (str_contains($suffix, 'bigsmall')) {
                return ['betType' => 'size', 'betContent' => $position . ':' . self::size($bet)];
            }
            if (str_contains($suffix, 'oddeven')) {
                return ['betType' => 'parity', 'betContent' => $position . ':' . self::parity($bet)];
            }
        }

        throw ApiException::validation("Unsupported 5D play type: {$raw}");
    }

    private static function motoBet(string $type, string $bet, string $raw): array
    {
        // Only the champion (First…) is settled by this engine; Second/Third
        // rank bets are mapped onto the podium market.
        $isChampion = strpos($type, 'first') === 0 || strpos($type, 'num') === 0
            || strpos($type, 'bigsmall') === 0 || strpos($type, 'oddeven') === 0;

        if (str_contains($type, 'num')) {
            $riders = self::digits($bet, 1, 10);
            if ($riders === []) {
                throw ApiException::validation("Invalid rider: {$raw}");
            }
            return [
                'betType'    => $isChampion ? 'champion' : 'podium',
                'betContent' => implode(',', $riders),
            ];
        }
        if (str_contains($type, 'bigsmall')) {
            return ['betType' => 'size', 'betContent' => self::size($bet)];
        }
        if (str_contains($type, 'oddeven')) {
            return ['betType' => 'parity', 'betContent' => self::parity($bet)];
        }

        throw ApiException::validation("Unsupported MotoRace play type: {$raw}");
    }

    /* ===================================================================
     |  Results:  our canonical result  ->  their `premium` / number / colour
     * ================================================================ */

    /**
     * @return array{premium:string,number:string,color:string,sum:int}
     */
    public static function fromEngineResult(string $family, array $result): array
    {
        switch ($family) {
            case 'WinGo':
            case 'TrxWinGo':
                $number = (string) ($result['number'] ?? '');
                return [
                    'premium' => $number,
                    'number'  => $number,
                    'color'   => (string) ($result['color'] ?? ''),
                    'sum'     => (int) ($result['number'] ?? 0),
                ];

            case 'K3':
                $dice = array_map('intval', (array) ($result['dice'] ?? []));
                return [
                    'premium' => implode(',', $dice),
                    'number'  => (string) ($result['sum'] ?? ''),
                    'color'   => '',
                    'sum'     => (int) ($result['sum'] ?? 0),
                ];

            case 'D5':
                return [
                    'premium' => (string) ($result['code'] ?? ''),
                    'number'  => (string) ($result['sum'] ?? ''),
                    'color'   => '',
                    'sum'     => (int) ($result['sum'] ?? 0),
                ];

            case 'MotoRace':
                $ranking = array_map('intval', (array) ($result['ranking'] ?? []));
                return [
                    'premium' => implode(',', $ranking),
                    'number'  => (string) ($result['champion'] ?? ''),
                    'color'   => '',
                    'sum'     => (int) ($result['champion'] ?? 0),
                ];
        }

        return ['premium' => '', 'number' => '', 'color' => '', 'sum' => 0];
    }

    /** Their lotteryCode for one of our families. */
    public static function lotteryCode(GameDefinition $game): string
    {
        return $game->family === 'D5' ? 'D5' : $game->family;
    }
}
