<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Uniform API envelope: {data, code, msg, msgCode, serviceTime}.
 *
 * code    0  = success, non-zero = failure (stable machine codes below)
 * msgCode short string constant for clients that switch on text codes
 */
final class Response
{
    public const OK                  = 0;
    public const ERR_VALIDATION      = 1001;
    public const ERR_AUTH            = 1002;
    public const ERR_SIGNATURE       = 1003;
    public const ERR_RATE_LIMIT      = 1004;
    public const ERR_NOT_FOUND       = 1005;
    public const ERR_INSUFFICIENT    = 1006;
    public const ERR_CLOSED          = 1007;
    public const ERR_CONFLICT        = 1008;
    public const ERR_SERVER          = 1500;

    /** @param mixed $data */
    public static function payload(
        $data = null,
        int $code = self::OK,
        string $msg = 'success',
        string $msgCode = 'SUCCESS'
    ): array {
        return [
            'data'        => $data,
            'code'        => $code,
            'msg'         => $msg,
            'msgCode'     => $msgCode,
            'serviceTime' => (int) round(microtime(true) * 1000),
        ];
    }

    /** @param mixed $data */
    public static function success($data = null, string $msg = 'success'): array
    {
        return self::payload($data, self::OK, $msg, 'SUCCESS');
    }

    public static function error(
        string $msg,
        int $code = self::ERR_VALIDATION,
        string $msgCode = 'ERROR',
        ?array $data = null
    ): array {
        return self::payload($data, $code, $msg, $msgCode);
    }

    /** Emit JSON and terminate the request. */
    public static function send(array $payload, int $httpStatus = 200): void
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
