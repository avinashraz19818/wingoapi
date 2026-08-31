<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Injectable clock: production reads the wall clock, tests freeze it.
 */
final class Clock
{
    private static ?int $frozenAt = null;

    public static function now(): int
    {
        return self::$frozenAt ?? time();
    }

    public static function nowMillis(): int
    {
        return self::$frozenAt !== null
            ? self::$frozenAt * 1000
            : (int) round(microtime(true) * 1000);
    }

    public static function dateTime(?int $timestamp = null): string
    {
        return date('Y-m-d H:i:s', $timestamp ?? self::now());
    }

    public static function freeze(int $timestamp): void
    {
        self::$frozenAt = $timestamp;
    }

    public static function unfreeze(): void
    {
        self::$frozenAt = null;
    }
}
