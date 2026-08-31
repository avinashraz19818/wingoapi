<?php

declare(strict_types=1);

namespace Lottery\Auth;

use Lottery\Support\ApiException;
use Lottery\Support\Clock;

/**
 * Compact HS256 JWT implementation (no external dependency).
 *
 * Payload contract: { "id": <userId>, "mobile": "<mobile>", "exp": <unix ts> }
 */
final class Jwt
{
    private string $secret;
    private int $ttl;
    private int $leeway;

    public function __construct(string $secret, int $ttl = 86400, int $leeway = 30)
    {
        $this->secret = $secret;
        $this->ttl    = $ttl;
        $this->leeway = $leeway;
    }

    public function issue(int $userId, string $mobile, ?int $ttl = null, array $extra = []): string
    {
        $issuedAt = Clock::now();
        $payload  = $extra + [
            'id'     => $userId,
            'mobile' => $mobile,
            'iat'    => $issuedAt,
            'exp'    => $issuedAt + ($ttl ?? $this->ttl),
        ];

        $header    = $this->base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body      = $this->base64UrlEncode((string) json_encode($payload));
        $signature = $this->sign($header . '.' . $body);

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * @return array{id:int,mobile:string,exp:int}&array<string,mixed>
     */
    public function verify(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw ApiException::auth('Malformed token');
        }
        [$header, $body, $signature] = $parts;

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? '') !== 'HS256') {
            // Reject "alg: none" and algorithm-confusion attempts outright.
            throw ApiException::auth('Unsupported token algorithm');
        }

        $expected = $this->sign($header . '.' . $body);
        if (!hash_equals($expected, $signature)) {
            throw ApiException::auth('Invalid token signature');
        }

        $payload = json_decode($this->base64UrlDecode($body), true);
        if (!is_array($payload)) {
            throw ApiException::auth('Malformed token payload');
        }

        $userId = (int) ($payload['id'] ?? $payload['userId'] ?? 0);
        if ($userId <= 0) {
            throw ApiException::auth('Token is missing the user id claim');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && Clock::now() > $exp + $this->leeway) {
            throw ApiException::auth('Token has expired');
        }

        return $payload + [
            'id'     => $userId,
            'mobile' => (string) ($payload['mobile'] ?? ''),
            'exp'    => $exp,
        ];
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        return (string) base64_decode($padded, true);
    }
}
