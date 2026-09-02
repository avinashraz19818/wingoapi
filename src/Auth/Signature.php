<?php

declare(strict_types=1);

namespace Lottery\Auth;

use Lottery\Support\ApiException;
use Lottery\Support\Clock;

/**
 * Request signing for write endpoints.
 *
 *   1. take every request parameter except `signature`
 *   2. drop empty values, sort the keys ascending (ksort)
 *   3. build "k1=v1&k2=v2..." and append "&key=<signature_secret>"
 *   4. signature = strtoupper(md5(payload))
 *
 * A `timestamp` parameter (unix seconds) is required when signing is enforced
 * and must be within `signature_ttl` to blunt replay attacks.
 */
final class Signature
{
    private string $secret;
    private int $ttl;

    public function __construct(string $secret, int $ttl = 300)
    {
        $this->secret = $secret;
        $this->ttl    = $ttl;
    }

    public function calculate(array $params): string
    {
        unset($params['signature'], $params['sign']);

        $params = array_filter(
            $params,
            static fn($value): bool => is_scalar($value) && (string) $value !== ''
        );
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . (string) $value;
        }
        $pairs[] = 'key=' . $this->secret;

        return strtoupper(md5(implode('&', $pairs)));
    }

    public function verify(array $params): void
    {
        $provided = (string) ($params['signature'] ?? $params['sign'] ?? '');
        if ($provided === '') {
            throw ApiException::signature('Missing signature');
        }

        $timestamp = (int) ($params['timestamp'] ?? 0);
        if ($timestamp <= 0) {
            throw ApiException::signature('Missing timestamp');
        }
        if (abs(Clock::now() - $timestamp) > $this->ttl) {
            throw ApiException::signature('Signature timestamp outside the allowed window');
        }

        $expected = $this->calculate($params);
        if (!hash_equals($expected, strtoupper($provided))) {
            throw ApiException::signature('Signature mismatch');
        }
    }
}
