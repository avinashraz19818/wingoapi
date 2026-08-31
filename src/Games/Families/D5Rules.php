<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * D5 (a.k.a. 5D) — five digits A B C D E, each 0-9, plus their sum (0-45).
 *
 * Bet content is always "<position>:<option>" where position is A-E or SUM:
 *   number  "A:7"        9x
 *   size    "A:big"      2x   (digit >= 5; for SUM big is >= 23)
 *   parity  "C:odd"      2x
 */
class D5Rules extends AbstractFamilyRules
{
    public const POSITIONS   = ['a', 'b', 'c', 'd', 'e'];
    public const ODDS_NUMBER = 9.0;
    public const ODDS_SIZE   = 2.0;
    public const ODDS_PARITY = 2.0;

    public function family(): string
    {
        return 'D5';
    }

    public function generate(callable $rng): array
    {
        return $this->build([$rng(0, 9), $rng(0, 9), $rng(0, 9), $rng(0, 9), $rng(0, 9)]);
    }

    public function fromOverride(string $value): array
    {
        $raw    = preg_replace('/[^0-9]/', '', trim($value)) ?? '';
        if (strlen($raw) !== 5) {
            $this->reject('override', $value);
        }
        return $this->build(array_map('intval', str_split($raw)));
    }

    public function fromProvider(array $row): ?array
    {
        $raw = $this->pick($row, ['digits', 'openCode', 'result', 'number', 'code']);
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
        if (strlen($digits) !== 5) {
            return null;
        }
        return $this->build(array_map('intval', str_split($digits)));
    }

    /** @param array<int,int> $digits */
    public function build(array $digits): array
    {
        $digits = array_map(static fn($d) => max(0, min(9, (int) $d)), array_values($digits));
        $digits = array_slice(array_pad($digits, 5, 0), 0, 5);
        $sum    = array_sum($digits);

        $positions = [];
        foreach (self::POSITIONS as $index => $key) {
            $digit = $digits[$index];
            $positions[$key] = [
                'digit'  => $digit,
                'size'   => $digit >= 5 ? 'big' : 'small',
                'parity' => $digit % 2 === 0 ? 'even' : 'odd',
            ];
        }
        $positions['sum'] = [
            'digit'  => $sum,
            'size'   => $sum >= 23 ? 'big' : 'small',
            'parity' => $sum % 2 === 0 ? 'even' : 'odd',
        ];

        return [
            'family'    => $this->family(),
            'digits'    => $digits,
            'code'      => implode('', $digits),
            'sum'       => $sum,
            'positions' => $positions,
            'size'      => $positions['sum']['size'],
            'parity'    => $positions['sum']['parity'],
        ];
    }

    public function summary(array $result): array
    {
        return [
            'primary_number' => (int) $result['sum'],
            'color'          => (string) $result['code'],
            'sum_value'      => (int) $result['sum'],
        ];
    }

    public function betOptions(): array
    {
        $positions = array_merge(array_map('strtoupper', self::POSITIONS), ['SUM']);

        return [
            [
                'betType'   => 'number',
                'label'     => 'Digit',
                'positions' => $positions,
                'options'   => ['A:0', 'A:1', '...', 'E:9'],
                'odds'      => self::ODDS_NUMBER,
                'note'      => 'SUM position does not accept the number bet type',
            ],
            [
                'betType'   => 'size',
                'label'     => 'Big / Small',
                'positions' => $positions,
                'options'   => ['A:big', 'A:small', 'SUM:big', 'SUM:small'],
                'odds'      => self::ODDS_SIZE,
            ],
            [
                'betType'   => 'parity',
                'label'     => 'Odd / Even',
                'positions' => $positions,
                'options'   => ['A:odd', 'A:even', 'SUM:odd', 'SUM:even'],
                'odds'      => self::ODDS_PARITY,
            ],
        ];
    }

    public function parseSelections(string $betType, string $betContent): array
    {
        if (!in_array($betType, ['number', 'size', 'parity'], true)) {
            $this->unknownType($betType);
        }

        $parts      = $this->splitContent($betContent, 20);
        $selections = [];

        foreach ($parts as $part) {
            $pair = explode(':', $part);
            if (count($pair) !== 2) {
                $this->reject($betType, $part);
            }
            [$position, $option] = $pair;

            if (!in_array($position, array_merge(self::POSITIONS, ['sum']), true)) {
                $this->reject($betType, $part);
            }

            if ($betType === 'number') {
                if ($position === 'sum' || !preg_match('/^[0-9]$/', $option)) {
                    $this->reject($betType, $part);
                }
            } elseif ($betType === 'size') {
                if (!in_array($option, ['big', 'small'], true)) {
                    $this->reject($betType, $part);
                }
            } else {
                if (!in_array($option, ['odd', 'even'], true)) {
                    $this->reject($betType, $part);
                }
            }

            $selections[] = $position . ':' . $option;
        }

        return array_values(array_unique($selections));
    }

    public function evaluateSelection(string $betType, string $selection, array $result): array
    {
        [$position, $option] = explode(':', $selection, 2);
        $data = $result['positions'][$position] ?? null;
        if ($data === null) {
            return ['won' => false, 'odds' => 0.0];
        }

        switch ($betType) {
            case 'number':
                return ['won' => (int) $option === (int) $data['digit'], 'odds' => self::ODDS_NUMBER];
            case 'size':
                return ['won' => $option === $data['size'], 'odds' => self::ODDS_SIZE];
            case 'parity':
                return ['won' => $option === $data['parity'], 'odds' => self::ODDS_PARITY];
        }

        $this->unknownType($betType);
        return ['won' => false, 'odds' => 0.0];
    }

    public function trendPositions(): array
    {
        $positions = [];
        foreach (self::POSITIONS as $key) {
            $positions[$key] = array_map('strval', range(0, 9));
        }
        $positions['sum_size']   = ['big', 'small'];
        $positions['sum_parity'] = ['odd', 'even'];

        return $positions;
    }

    public function trendMatches(array $result, string $position, string $option): bool
    {
        if ($position === 'sum_size') {
            return $result['positions']['sum']['size'] === $option;
        }
        if ($position === 'sum_parity') {
            return $result['positions']['sum']['parity'] === $option;
        }
        if (isset($result['positions'][$position])) {
            return (string) $result['positions'][$position]['digit'] === $option;
        }
        return false;
    }
}
