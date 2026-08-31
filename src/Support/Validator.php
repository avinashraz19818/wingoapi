<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Input validation helpers. Everything user supplied passes through here
 * before reaching SQL (which is always parameterised) or business logic.
 */
final class Validator
{
    public static function requireString(array $input, string $key, int $max = 191): string
    {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
        if ($value === '') {
            throw ApiException::validation("Missing required parameter: {$key}");
        }
        if (mb_strlen($value) > $max) {
            throw ApiException::validation("Parameter {$key} exceeds {$max} characters");
        }
        return $value;
    }

    public static function optionalString(array $input, string $key, string $default = '', int $max = 191): string
    {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
        if ($value === '') {
            return $default;
        }
        return mb_substr($value, 0, $max);
    }

    public static function int(array $input, string $key, int $default, int $min, int $max): int
    {
        if (!isset($input[$key]) || $input[$key] === '' || !is_scalar($input[$key])) {
            return $default;
        }
        if (!is_numeric($input[$key])) {
            throw ApiException::validation("Parameter {$key} must be numeric");
        }
        $value = (int) $input[$key];
        if ($value < $min || $value > $max) {
            throw ApiException::validation("Parameter {$key} must be between {$min} and {$max}");
        }
        return $value;
    }

    public static function amount(array $input, string $key, float $min, float $max): float
    {
        if (!isset($input[$key]) || !is_scalar($input[$key]) || !is_numeric($input[$key])) {
            throw ApiException::validation("Parameter {$key} must be a number");
        }
        $value = Money::round((float) $input[$key]);
        if ($value < $min) {
            throw ApiException::validation("Minimum {$key} is " . Money::format($min));
        }
        if ($value > $max) {
            throw ApiException::validation("Maximum {$key} is " . Money::format($max));
        }
        return $value;
    }

    /** Issue numbers are strictly 17 digits. */
    public static function issueNumber(string $value): string
    {
        if (!preg_match('/^\d{17}$/', $value)) {
            throw ApiException::validation('Invalid issue number format (expected 17 digits)');
        }
        return $value;
    }

    /** Game codes are Family_Interval, letters/digits/underscore only. */
    public static function gameCode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9]+_[A-Za-z0-9]+$/', $value)) {
            throw ApiException::validation('Invalid gameCode format');
        }
        return $value;
    }

    /** Bet content: digits, letters, comma, colon, dash, underscore. */
    public static function betContent(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_,:\-]{1,191}$/', $value)) {
            throw ApiException::validation('Invalid bet content');
        }
        return $value;
    }

    public static function mobile(string $value): string
    {
        if (!preg_match('/^[0-9+\-]{6,20}$/', $value)) {
            throw ApiException::validation('Invalid mobile number');
        }
        return $value;
    }
}
