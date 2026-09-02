<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

use Lottery\Support\ApiException;

abstract class AbstractFamilyRules implements FamilyRules
{
    /** @return array<int,string> */
    protected function splitContent(string $betContent, int $maxUnits = 20): array
    {
        $parts = array_values(array_unique(array_filter(array_map(
            static fn(string $p): string => strtolower(trim($p)),
            explode(',', $betContent)
        ), static fn(string $p): bool => $p !== '')));

        if ($parts === []) {
            throw ApiException::validation('Bet content cannot be empty');
        }
        if (count($parts) > $maxUnits) {
            throw ApiException::validation("A single bet may not exceed {$maxUnits} selections");
        }

        return $parts;
    }

    protected function reject(string $betType, string $selection): void
    {
        throw ApiException::validation("Invalid selection '{$selection}' for bet type '{$betType}'");
    }

    protected function unknownType(string $betType): void
    {
        throw ApiException::validation("Unsupported bet type '{$betType}' for {$this->family()}");
    }

    /** Read the first present key from a provider row. */
    protected function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            foreach ([$key, strtolower($key), ucfirst($key)] as $variant) {
                if (isset($row[$variant]) && is_scalar($row[$variant]) && (string) $row[$variant] !== '') {
                    return (string) $row[$variant];
                }
            }
        }
        return null;
    }

    public function baseOdds(string $betType, string $selection): float
    {
        foreach ($this->betOptions() as $option) {
            if ($option['betType'] === $betType) {
                if (isset($option['oddsMap'][$selection])) {
                    return (float) $option['oddsMap'][$selection];
                }
                return (float) $option['odds'];
            }
        }
        $this->unknownType($betType);
        return 0.0; // unreachable
    }
}
