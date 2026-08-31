<?php

declare(strict_types=1);

namespace Lottery\Support;

use Throwable;

/**
 * Dependency-free line logger (JSON per line, safe for logrotate / journald).
 */
final class Log
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private static string $path = '';
    private static string $level = 'info';

    public static function configure(string $path, string $level = 'info'): void
    {
        self::$path  = $path;
        self::$level = isset(self::LEVELS[$level]) ? $level : 'info';
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(Throwable $e, array $context = []): void
    {
        self::write('error', $e->getMessage(), $context + [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$path === '') {
            return;
        }
        if (self::LEVELS[$level] < self::LEVELS[self::$level]) {
            return;
        }
        $dir = dirname(self::$path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = json_encode([
            'ts'      => date('c'),
            'level'   => $level,
            'msg'     => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents(self::$path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
