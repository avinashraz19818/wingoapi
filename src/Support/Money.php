<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Currency helpers. All money is handled as 2-decimal rupee amounts and is
 * always rounded half-up at the boundary before it touches the database.
 */
final class Money
{
    public const SCALE = 2;

    public static function round(float $amount): float
    {
        return round($amount, self::SCALE, PHP_ROUND_HALF_UP);
    }

    /** Format for storage / JSON: "12345.67" */
    public static function format(float $amount): string
    {
        return number_format(self::round($amount), self::SCALE, '.', '');
    }

    public static function isPositive(float $amount): bool
    {
        return self::round($amount) > 0.0;
    }

    /** Net payout after platform tax (e.g. 2%). */
    public static function applyTax(float $gross, float $taxRate): array
    {
        $gross = self::round($gross);
        $tax   = self::round($gross * $taxRate);
        return ['gross' => $gross, 'tax' => $tax, 'net' => self::round($gross - $tax)];
    }
}
