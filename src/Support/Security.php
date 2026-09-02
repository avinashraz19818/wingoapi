<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * CORS negotiation + hardened response headers.
 */
final class Security
{
    /** @param array{cors_origins:array<int,string>,trusted_proxies:array<int,string>} $config */
    public static function applyHeaders(array $config): void
    {
        if (headers_sent()) {
            return;
        }

        $allowed = $config['cors_origins'] ?? ['*'];
        $origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $any     = in_array('*', $allowed, true);

        header('Vary: Origin, Access-Control-Request-Headers');

        if ($origin !== '' && ($any || in_array($origin, $allowed, true))) {
            // Echo the exact origin rather than "*": browsers reject the
            // wildcard as soon as a request carries credentials, which is what
            // most game front-ends do.
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        } elseif ($any) {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');
        header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, X-Feed-Domain');

        // Reflect whatever headers the browser asked for; front-ends send all
        // sorts of custom ones (token, language, deviceId, traceId …) and a
        // missing entry here shows up as a bare "CORS error" in the console.
        $requested = (string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
        header('Access-Control-Allow-Headers: ' . ($requested !== '' ? $requested : implode(', ', [
            'Content-Type', 'Authorization', 'Accept', 'Origin', 'X-Requested-With',
            'Token', 'X-Token', 'X-Access-Token', 'Access-Token', 'Auth', 'X-Auth-Token',
            'X-Api-Key', 'X-Signature', 'X-Timestamp', 'X-Admin-Token',
            'Language', 'Lang', 'Device-Id', 'DeviceId', 'Trace-Id', 'TraceId', 'Version',
        ])));

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        header_remove('X-Powered-By');
    }

    public static function isPreflight(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS';
    }

    /** Client IP, honouring X-Forwarded-For only from trusted proxies. */
    public static function clientIp(array $trustedProxies = []): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($trustedProxies !== [] && in_array($remote, $trustedProxies, true)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
