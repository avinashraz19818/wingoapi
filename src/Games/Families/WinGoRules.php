<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * WinGo — a single digit 0-9.
 *
 *   colour : 0 = red+violet, 5 = green+violet, even = red, odd = green
 *   size   : 0-4 Small, 5-9 Big
 *   parity : digit % 2
 *
 * Payouts (before the 2% payout tax):
 *   number 9x | red/green 2x (1.5x when the digit is 0 or 5) | violet 4.5x
 *   big/small 2x | odd/even 2x
 */
class WinGoRules extends AbstractFamilyRules
{
    public const ODDS_NUMBER      = 9.0;
    public const ODDS_COLOR       = 2.0;
    public const ODDS_COLOR_MIXED = 1.5;
    public const ODDS_VIOLET      = 4.5;
    public const ODDS_SIZE        = 2.0;
    public const ODDS_PARITY      = 2.0;

    public function family(): string
    {
        return 'WinGo';
    }

    public function generate(callable $rng): array
    {
        return $this->build($rng(0, 9));
    }

    public function fromOverride(string $value): array
    {
        $value = trim($value);
        if (!preg_match('/^[0-9]$/', $value)) {
            $this->reject('override', $value);
        }
        return $this->build((int) $value);
    }

    public function fromProvider(array $row): ?array
    {
        $number = $this->pick($row, ['number', 'result', 'openCode', 'code', 'digit']);
        if ($number === null || !preg_match('/^[0-9]$/', trim($number))) {
            return null;
        }
        return $this->build((int) trim($number));
    }

    /** Canonical result payload for a digit. */
    public function build(int $number): array
    {
        $number = max(0, min(9, $number));

        $colors = [];
        if ($number === 0) {
            $colors = ['red', 'violet'];
        } elseif ($number === 5) {
            $colors = ['green', 'violet'];
        } elseif ($number % 2 === 0) {
            $colors = ['red'];
        } else {
            $colors = ['green'];
        }

        return [
            'family' => $this->family(),
            'number' => $number,
            'colors' => $colors,
            'color'  => implode(',', $colors),
            'size'   => $number >= 5 ? 'big' : 'small',
            'parity' => $number % 2 === 0 ? 'even' : 'odd',
        ];
    }

    public function summary(array $result): array
    {
        return [
            'primary_number' => (int) $result['number'],
            'color'          => (string) $result['color'],
            'sum_value'      => (int) $result['number'],
        ];
    }

    public function betOptions(): array
    {
        return [
            [
                'betType' => 'number',
                'label'   => 'Number',
                'options' => ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
                'odds'    => self::ODDS_NUMBER,
            ],
            [
                'betType' => 'color',
                'label'   => 'Colour',
                'options' => ['green', 'red', 'violet'],
                'odds'    => self::ODDS_COLOR,
                'oddsMap' => [
                    'green'  => self::ODDS_COLOR,
                    'red'    => self::ODDS_COLOR,
                    'violet' => self::ODDS_VIOLET,
                ],
                'note'    => 'green/red pay 1.5x when the drawn digit is 0 or 5',
            ],
            [
                'betType' => 'size',
                'label'   => 'Big / Small',
                'options' => ['big', 'small'],
                'odds'    => self::ODDS_SIZE,
            ],
            [
                'betType' => 'parity',
                'label'   => 'Odd / Even',
                'options' => ['odd', 'even'],
                'odds'    => self::ODDS_PARITY,
            ],
        ];
    }

    public function parseSelections(string $betType, string $betContent): array
    {
        $parts = $this->splitContent($betContent, 10);

        switch ($betType) {
            case 'number':
                foreach ($parts as $part) {
                    if (!preg_match('/^[0-9]$/', $part)) {
                        $this->reject($betType, $part);
                    }
                }
                return $parts;

            case 'color':
                foreach ($parts as $part) {
                    if (!in_array($part, ['green', 'red', 'violet'], true)) {
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
        }

        $this->unknownType($betType);
        return [];
    }

    public function evaluateSelection(string $betType, string $selection, array $result): array
    {
        $number = (int) $result['number'];
        $colors = (array) $result['colors'];
        $mixed  = in_array('violet', $colors, true); // digit 0 or 5

        switch ($betType) {
            case 'number':
                return [
                    'won'  => (int) $selection === $number,
                    'odds' => self::ODDS_NUMBER,
                ];

            case 'color':
                if ($selection === 'violet') {
                    return ['won' => $mixed, 'odds' => self::ODDS_VIOLET];
                }
                $hit = in_array($selection, $colors, true);
                return [
                    'won'  => $hit,
                    'odds' => $hit && $mixed ? self::ODDS_COLOR_MIXED : self::ODDS_COLOR,
                ];

            case 'size':
                return ['won' => $selection === $result['size'], 'odds' => self::ODDS_SIZE];

            case 'parity':
                return ['won' => $selection === $result['parity'], 'odds' => self::ODDS_PARITY];
        }

        $this->unknownType($betType);
        return ['won' => false, 'odds' => 0.0];
    }

    public function trendPositions(): array
    {
        return [
            'number' => ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            'size'   => ['big', 'small'],
            'parity' => ['odd', 'even'],
            'color'  => ['green', 'red', 'violet'],
        ];
    }

    public function trendMatches(array $result, string $position, string $option): bool
    {
        switch ($position) {
            case 'number':
                return (string) $result['number'] === $option;
            case 'size':
                return (string) $result['size'] === $option;
            case 'parity':
                return (string) $result['parity'] === $option;
            case 'color':
                return in_array($option, (array) $result['colors'], true);
        }
        return false;
    }
}
