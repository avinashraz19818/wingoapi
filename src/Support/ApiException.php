<?php

declare(strict_types=1);

namespace Lottery\Support;

use RuntimeException;
use Throwable;

/**
 * Domain error that maps 1:1 onto an API envelope.
 */
class ApiException extends RuntimeException
{
    private string $msgCode;
    private int $httpStatus;
    private ?array $context;

    public function __construct(
        string $message,
        int $code = Response::ERR_VALIDATION,
        string $msgCode = 'ERROR',
        int $httpStatus = 200,
        ?array $context = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->msgCode    = $msgCode;
        $this->httpStatus = $httpStatus;
        $this->context    = $context;
    }

    public function msgCode(): string
    {
        return $this->msgCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function context(): ?array
    {
        return $this->context;
    }

    public static function validation(string $message, ?array $context = null): self
    {
        return new self($message, Response::ERR_VALIDATION, 'VALIDATION_ERROR', 200, $context);
    }

    public static function auth(string $message = 'Authentication required'): self
    {
        return new self($message, Response::ERR_AUTH, 'AUTH_REQUIRED', 401);
    }

    public static function signature(string $message = 'Invalid signature'): self
    {
        return new self($message, Response::ERR_SIGNATURE, 'INVALID_SIGNATURE', 401);
    }

    public static function rateLimit(string $message = 'Too many requests'): self
    {
        return new self($message, Response::ERR_RATE_LIMIT, 'RATE_LIMITED', 429);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self($message, Response::ERR_NOT_FOUND, 'NOT_FOUND', 404);
    }

    public static function insufficientBalance(string $message = 'Insufficient balance'): self
    {
        return new self($message, Response::ERR_INSUFFICIENT, 'INSUFFICIENT_BALANCE', 200);
    }

    public static function closed(string $message = 'Betting is closed for this issue'): self
    {
        return new self($message, Response::ERR_CLOSED, 'BETTING_CLOSED', 200);
    }

    public static function conflict(string $message = 'Conflicting request'): self
    {
        return new self($message, Response::ERR_CONFLICT, 'CONFLICT', 409);
    }

    public static function server(string $message = 'Internal error', ?Throwable $previous = null): self
    {
        return new self($message, Response::ERR_SERVER, 'SERVER_ERROR', 500, null, $previous);
    }
}
