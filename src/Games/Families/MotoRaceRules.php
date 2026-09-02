<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * MotoRace — ten riders (1-10) finish in a random order.
 *
 *   champion  exact winner "7"          9.4x
 *   podium    finishes in the top 3     3.1x
 *   size      winner 6-10 / 1-5         2x
 *   parity    winner odd / even         2x
 */
class MotoRaceRules extends AbstractFamilyRules
{
    public const RIDERS         = 10;
    public const ODDS_CHAMPION  = 9.4;
    public const ODDS_PODIUM    = 3.1;
    public const ODDS_SIZE      = 2.0;
    public const ODDS_PARITY    = 2.0;

    public function family(): string
    {
        return 'MotoRace';
    }

    public function generate(callable $rng): array
    {
        // Deterministic Fisher-Yates driven by the injected RNG.
        $riders = range(1, self::RIDERS);
        for ($i = count($riders) - 1; $i > 0; $i--) {
            $j = $rng(0, $i);
            [$riders[$i], $riders[$j]] = [$riders[$j], $riders[$i]];
        }
        return $this->build($riders);
    }

    public function fromOverride(string $value): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,:\-]/', trim($value)) ?: [])));

        // A single number means "this rider wins", the rest is filled in order.
        if (count($parts) === 1) {
            $champion = (int) $parts[0];
            if ($champion < 1 || $champion > self::RIDERS) {
                $this->reject('override', $value);
            }
            $rest = array_values(array_diff(range(1, self::RIDERS), [$champion]));
            return $this->build(array_merge([$champion], $rest));
        }

        $ranking = array_map('intval', $parts);
        if (count($ranking) !== self::RIDERS || count(array_unique($ranking)) !== self::RIDERS) {
            $this->reject('override', $value);
        }
        return $this->build($ranking);
    }

    public function fromProvider(array $row): ?array
    {
        $raw = $this->pick($row, ['ranking', 'openCode', 'result', 'code', 'order']);
        if ($raw !== null) {
            $parts = array_values(array_filter(preg_split('/[^0-9]+/', $raw) ?: [], static fn($p) => $p !== ''));
            if (count($parts) === self::RIDERS) {
                $ranking = array_map('intval', $parts);
                if (count(array_unique($ranking)) === self::RIDERS) {
                    return $this->build($ranking);
                }
            }
        }

        $champion = $this->pick($row, ['champion', 'winner', 'number']);
        if ($champion !== null && (int) $champion >= 1 && (int) $champion <= self::RIDERS) {
            return $this->fromOverride((string) (int) $champion);
        }

        return null;
    }

    /** @param array<int,int> $ranking finishing order, index 0 = winner */
    public function build(array $ranking): array
    {
        $ranking  = array_values(array_map('intval', $ranking));
        $champion = $ranking[0] ?? 1;

        return [
            'family'   => $this->family(),
            'ranking'  => $ranking,
            'champion' => $champion,
            'podium'   => array_slice($ranking, 0, 3),
            'size'     => $champion >= 6 ? 'big' : 'small',
            'parity'   => $champion % 2 === 0 ? 'even' : 'odd',
        ];
    }

    public function summary(array $result): array
    {
        return [
            'primary_number' => (int) $result['champion'],
            'color'          => implode(',', $result['ranking']),
            'sum_value'      => (int) $result['champion'],
        ];
    }

    public function betOptions(): array
    {
        $riders = array_map('strval', range(1, self::RIDERS));

        return [
            ['betType' => 'champion', 'label' => 'Champion', 'options' => $riders, 'odds' => self::ODDS_CHAMPION],
            ['betType' => 'podium', 'label' => 'Top 3', 'options' => $riders, 'odds' => self::ODDS_PODIUM],
            ['betType' => 'size', 'label' => 'Big / Small', 'options' => ['big', 'small'], 'odds' => self::ODDS_SIZE],
            ['betType' => 'parity', 'label' => 'Odd / Even', 'options' => ['odd', 'even'], 'odds' => self::ODDS_PARITY],
        ];
    }

    public function parseSelections(string $betType, string $betContent): array
    {
        $parts = $this->splitContent($betContent, self::RIDERS);

        switch ($betType) {
            case 'champion':
            case 'podium':
                foreach ($parts as $part) {
                    if (!preg_match('/^\d{1,2}$/', $part) || (int) $part < 1 || (int) $part > self::RIDERS) {
                        $this->reject($betType, $part);
                    }
                }
                return array_map(static fn($p) => (string) (int) $p, $parts);

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
        switch ($betType) {
            case 'champion':
                return ['won' => (int) $selection === (int) $result['champion'], 'odds' => self::ODDS_CHAMPION];
            case 'podium':
                return ['won' => in_array((int) $selection, array_map('intval', $result['podium']), true), 'odds' => self::ODDS_PODIUM];
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
            'champion' => array_map('strval', range(1, self::RIDERS)),
            'size'     => ['big', 'small'],
            'parity'   => ['odd', 'even'],
        ];
    }

    public function trendMatches(array $result, string $position, string $option): bool
    {
        switch ($position) {
            case 'champion':
                return (string) $result['champion'] === $option;
            case 'size':
                return (string) $result['size'] === $option;
            case 'parity':
                return (string) $result['parity'] === $option;
        }
        return false;
    }
}
