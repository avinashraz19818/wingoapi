<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * K3 — three dice, each 1-6, sum 3-18.
 *
 * Bet types
 *   total          exact sum 3-18                      (odds table below)
 *   size           big = 11-18, small = 3-10           2x
 *   parity         odd / even sum                      2x
 *   triple_any     any three-of-a-kind                 34.56x
 *   triple_exact   specific three-of-a-kind "5"        207.36x
 *   pair           at least two dice show N            13.83x
 *   two_different  both listed faces appear "1,2"      6.91x   (content "1:2")
 *   three_different exactly these three faces "1:2:3"  34.56x
 */
class K3Rules extends AbstractFamilyRules
{
    public const ODDS_TOTAL = [
        3 => 207.36, 18 => 207.36,
        4 => 69.12,  17 => 69.12,
        5 => 34.56,  16 => 34.56,
        6 => 20.74,  15 => 20.74,
        7 => 13.83,  14 => 13.83,
        8 => 9.88,   13 => 9.88,
        9 => 8.30,   12 => 8.30,
        10 => 7.68,  11 => 7.68,
    ];

    public const ODDS_SIZE            = 2.0;
    public const ODDS_PARITY          = 2.0;
    public const ODDS_TRIPLE_ANY      = 34.56;
    public const ODDS_TRIPLE_EXACT    = 207.36;
    public const ODDS_PAIR            = 13.83;
    public const ODDS_TWO_DIFFERENT   = 6.91;
    public const ODDS_THREE_DIFFERENT = 34.56;

    public function family(): string
    {
        return 'K3';
    }

    public function generate(callable $rng): array
    {
        return $this->build([$rng(1, 6), $rng(1, 6), $rng(1, 6)]);
    }

    public function fromOverride(string $value): array
    {
        $dice = array_map('intval', preg_split('/[,:\-]/', trim($value)) ?: []);
        if (count($dice) !== 3) {
            $this->reject('override', $value);
        }
        foreach ($dice as $die) {
            if ($die < 1 || $die > 6) {
                $this->reject('override', $value);
            }
        }
        return $this->build($dice);
    }

    public function fromProvider(array $row): ?array
    {
        $raw = $this->pick($row, ['dice', 'openCode', 'result', 'number', 'code']);
        if ($raw === null) {
            return null;
        }
        $parts = preg_split('/[^0-9]+/', trim($raw)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if (count($parts) === 1 && strlen($parts[0]) === 3) {
            $parts = str_split($parts[0]);
        }
        if (count($parts) !== 3) {
            return null;
        }

        $dice = array_map('intval', $parts);
        foreach ($dice as $die) {
            if ($die < 1 || $die > 6) {
                return null;
            }
        }

        return $this->build($dice);
    }

    /** @param array<int,int> $dice */
    public function build(array $dice): array
    {
        $dice = array_map(static fn($d) => max(1, min(6, (int) $d)), array_values($dice));
        sort($dice);
        $sum = array_sum($dice);

        $counts     = array_count_values($dice);
        $isTriple   = count($counts) === 1;
        $isPair     = count($counts) === 2;
        $pairFace   = 0;
        if ($isPair) {
            foreach ($counts as $face => $count) {
                if ($count === 2) {
                    $pairFace = (int) $face;
                }
            }
        }

        return [
            'family'   => $this->family(),
            'dice'     => $dice,
            'sum'      => $sum,
            'size'     => $sum >= 11 ? 'big' : 'small',
            'parity'   => $sum % 2 === 0 ? 'even' : 'odd',
            'triple'   => $isTriple,
            'pair'     => $isPair,
            'pairFace' => $pairFace,
        ];
    }

    public function summary(array $result): array
    {
        return [
            'primary_number' => (int) $result['sum'],
            'color'          => implode(',', $result['dice']),
            'sum_value'      => (int) $result['sum'],
        ];
    }

    public function betOptions(): array
    {
        return [
            [
                'betType' => 'total',
                'label'   => 'Total',
                'options' => array_map('strval', range(3, 18)),
                'odds'    => 7.68,
                'oddsMap' => array_combine(
                    array_map('strval', array_keys(self::ODDS_TOTAL)),
                    array_values(self::ODDS_TOTAL)
                ),
            ],
            ['betType' => 'size', 'label' => 'Big / Small', 'options' => ['big', 'small'], 'odds' => self::ODDS_SIZE],
            ['betType' => 'parity', 'label' => 'Odd / Even', 'options' => ['odd', 'even'], 'odds' => self::ODDS_PARITY],
            ['betType' => 'triple_any', 'label' => 'Any triple', 'options' => ['any'], 'odds' => self::ODDS_TRIPLE_ANY],
            ['betType' => 'triple_exact', 'label' => 'Exact triple', 'options' => array_map('strval', range(1, 6)), 'odds' => self::ODDS_TRIPLE_EXACT],
            ['betType' => 'pair', 'label' => 'Pair', 'options' => array_map('strval', range(1, 6)), 'odds' => self::ODDS_PAIR],
            ['betType' => 'two_different', 'label' => 'Two different', 'options' => ['1:2', '1:3', '...'], 'odds' => self::ODDS_TWO_DIFFERENT],
            ['betType' => 'three_different', 'label' => 'Three different', 'options' => ['1:2:3', '...'], 'odds' => self::ODDS_THREE_DIFFERENT],
        ];
    }

    public function parseSelections(string $betType, string $betContent): array
    {
        $parts = $this->splitContent($betContent, 16);

        switch ($betType) {
            case 'total':
                foreach ($parts as $part) {
                    if (!preg_match('/^\d{1,2}$/', $part) || (int) $part < 3 || (int) $part > 18) {
                        $this->reject($betType, $part);
                    }
                }
                return $parts;

            case 'size':
                foreach ($parts as $part) {
                    if (!in_array($part, ['big', 'small'], true)) {
                        $this->reject($betType, $part);
                    }
                }
                return $parts;

            case 'parity':
                foreach ($parts as $part) {
                    if (!in_array($part, ['odd', 'even'], true)) {
                        $this->reject($betType, $part);
                    }
                }
                return $parts;

            case 'triple_any':
                foreach ($parts as $part) {
                    if ($part !== 'any') {
                        $this->reject($betType, $part);
                    }
                }
                return ['any'];

            case 'triple_exact':
            case 'pair':
                foreach ($parts as $part) {
                    if (!preg_match('/^[1-6]$/', $part)) {
                        $this->reject($betType, $part);
                    }
                }
                return $parts;

            case 'two_different':
                foreach ($parts as $part) {
                    $faces = explode(':', $part);
                    if (count($faces) !== 2 || count(array_unique($faces)) !== 2) {
                        $this->reject($betType, $part);
                    }
                    foreach ($faces as $face) {
                        if (!preg_match('/^[1-6]$/', $face)) {
                            $this->reject($betType, $part);
                        }
                    }
                }
                return $parts;

            case 'three_different':
                foreach ($parts as $part) {
                    $faces = explode(':', $part);
                    if (count($faces) !== 3 || count(array_unique($faces)) !== 3) {
                        $this->reject($betType, $part);
                    }
                    foreach ($faces as $face) {
                        if (!preg_match('/^[1-6]$/', $face)) {
                            $this->reject($betType, $part);
                        }
                    }
                }
                return $parts;
        }

        $this->unknownType($betType);
        return [];
    }

    public function evaluateSelection(string $betType, string $selection, array $result): array
    {
        $dice = array_map('intval', (array) $result['dice']);
        $sum  = (int) $result['sum'];

        switch ($betType) {
            case 'total':
                $target = (int) $selection;
                return ['won' => $target === $sum, 'odds' => self::ODDS_TOTAL[$target] ?? 0.0];

            case 'size':
                return ['won' => $selection === $result['size'], 'odds' => self::ODDS_SIZE];

            case 'parity':
                return ['won' => $selection === $result['parity'], 'odds' => self::ODDS_PARITY];

            case 'triple_any':
                return ['won' => (bool) $result['triple'], 'odds' => self::ODDS_TRIPLE_ANY];

            case 'triple_exact':
                $face = (int) $selection;
                return [
                    'won'  => $result['triple'] && $dice[0] === $face,
                    'odds' => self::ODDS_TRIPLE_EXACT,
                ];

            case 'pair':
                $face   = (int) $selection;
                $counts = array_count_values($dice);
                return [
                    'won'  => ($counts[$face] ?? 0) >= 2,
                    'odds' => self::ODDS_PAIR,
                ];

            case 'two_different':
                $faces = array_map('intval', explode(':', $selection));
                $hit   = count(array_intersect($faces, $dice)) === count($faces);
                return ['won' => $hit, 'odds' => self::ODDS_TWO_DIFFERENT];

            case 'three_different':
                $faces = array_map('intval', explode(':', $selection));
                sort($faces);
                $sorted = $dice;
                sort($sorted);
                return ['won' => $faces === $sorted, 'odds' => self::ODDS_THREE_DIFFERENT];
        }

        $this->unknownType($betType);
        return ['won' => false, 'odds' => 0.0];
    }

    public function trendPositions(): array
    {
        return [
            'total'  => array_map('strval', range(3, 18)),
            'size'   => ['big', 'small'],
            'parity' => ['odd', 'even'],
        ];
    }

    public function trendMatches(array $result, string $position, string $option): bool
    {
        switch ($position) {
            case 'total':
                return (string) $result['sum'] === $option;
            case 'size':
                return (string) $result['size'] === $option;
            case 'parity':
                return (string) $result['parity'] === $option;
        }
        return false;
    }
}
