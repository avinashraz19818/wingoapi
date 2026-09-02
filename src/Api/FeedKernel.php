<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\App;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Response;
use Lottery\Support\Security;
use Lottery\Support\Validator;
use Lottery\Tenant\DomainService;
use Throwable;

/**
 * HTTP kernel for the public result feed.
 *
 * Pipeline: hardened headers -> domain whitelist (CORS is granted only to the
 * calling domain once it is whitelisted) -> rate limit -> action.
 *
 * A blocked caller gets a clean 403 DOMAIN_NOT_ALLOWED and no data at all.
 */
class FeedKernel
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $route extra params captured from the path
     */
    public function handle(array $route = []): void
    {
        $input  = (new Kernel($this->app))->collectInput() + $route;
        $action = strtolower((string) ($input['action'] ?? 'history'));

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $access = null;

        try {
            $gameCode = isset($input['gameCode'])
                ? $this->app->registry()->normaliseCode((string) $input['gameCode'])
                : '';

            $access = $this->app->domains()->authorise($_SERVER, $input, $gameCode);
            $this->sendHeaders($origin, $access);

            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
                http_response_code(204);
                return;
            }

            if (!$access['allowed']) {
                throw DomainService::reject($access['reason']);
            }

            $this->rateLimit($access);

            $this->app->bootstrapDatabase();
            Response::send(Response::success($this->dispatch($action, $input)));
        } catch (ApiException $e) {
            $this->sendHeaders($origin, $access);
            Response::send(
                Response::error($e->getMessage(), $e->getCode(), $e->msgCode(), $e->context()),
                $e->httpStatus()
            );
        } catch (Throwable $e) {
            Log::exception($e, ['stage' => 'feed-kernel']);
            $this->sendHeaders($origin, $access);
            $message = (bool) $this->app->config('app.debug') ? $e->getMessage() : 'Internal server error';
            Response::send(Response::error($message, Response::ERR_SERVER, 'SERVER_ERROR'), 500);
        }
    }

    /**
     * @param array<string,mixed> $input
     */
    public function dispatch(string $action, array $input): array
    {
        $controller = new FeedController($this->app, $input);

        if (in_array($action, ['gamelist', 'games'], true)) {
            return $controller->gameList($this->baseUrl());
        }

        $game = $this->app->registry()->get(
            Validator::gameCode(Validator::requireString($input, 'gameCode', 32))
        );

        switch ($action) {
            case 'history':
            case 'gethistoryissuepage':
            case 'getnoaverageemerdlist':
                return $controller->history($game);

            case 'issue':
            case 'getgameissue':
                return $controller->issue($game);

            case 'schedule':
            case 'getissueschedule':
                return $controller->schedule($game);

            case 'result':
            case 'getresult':
                return $controller->result($game);
        }

        throw ApiException::notFound("Unknown feed action: {$action}");
    }

    /**
     * CORS is the whitelist in action: the calling origin is echoed back only
     * when it is registered (or it is our own site).
     */
    private function sendHeaders(string $origin, ?array $access): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Vary: Origin, Access-Control-Request-Headers');
        header('Cache-Control: public, max-age=2');
        header_remove('X-Powered-By');

        if ($origin !== '' && $access !== null && $access['allowed']) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 86400');

            $requested = (string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
            header('Access-Control-Allow-Headers: ' . ($requested !== ''
                ? $requested
                : 'Content-Type, Authorization, X-Api-Key, Accept, Language, X-Requested-With'));
        }

        if ($access !== null && $access['domain'] !== null) {
            header('X-Feed-Domain: ' . $access['domain']['domain']);
        }
    }

    /** Per-domain budget, falling back to the global limit. */
    private function rateLimit(array $access): void
    {
        $domain = $access['domain'];
        $key    = $domain !== null ? 'feed-domain:' . $domain['id'] : 'feed-ip:' . Security::clientIp(
            (array) $this->app->config('security.trusted_proxies', [])
        );

        $limit   = $domain !== null && (int) $domain['rate_limit'] > 0
            ? (int) $domain['rate_limit']
            : (int) $this->app->config('feed.rate_limit', 600);

        $limiter = $this->app->feedRateLimiter($limit);
        $result  = $limiter->hit($key);

        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);
        }

        if (!$result['allowed']) {
            throw ApiException::rateLimit('Feed rate limit of ' . $limit . ' requests/minute exceeded');
        }
    }

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? $this->app->config('app.domain'));

        return $scheme . '://' . $host;
    }

    /** Timestamp helper for tests. */
    public function now(): int
    {
        return Clock::now();
    }
}
