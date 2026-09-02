<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Tiny .env reader. Real environment variables always win over the file.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (strlen($value) > 1 && (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $raw = self::get($key, (string) $default);
        return is_numeric($raw) ? (float) $raw : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $raw = self::get($key, (string) $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }

    /** Testing helper. */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
