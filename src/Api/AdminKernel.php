<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\App;
use Lottery\Support\ApiException;
use Lottery\Support\Log;
use Lottery\Support\Response;
use Lottery\Support\Security;
use Throwable;

/**
 * HTTP kernel for /api/Admin — the endpoint the web panel talks to.
 *
 * Pipeline: security headers -> CORS preflight -> rate limit -> bootstrap DB ->
 * POST check for write actions -> admin session (or X-Admin-Token) -> action.
 */
class AdminKernel
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        Security::applyHeaders((array) $this->app->config('security'));

        if (Security::isPreflight()) {
            http_response_code(204);
            return;
        }

        try {
            $input  = (new Kernel($this->app))->collectInput();
            $action = strtolower(trim((string) ($input['action'] ?? '')));
            $ip     = Security::clientIp((array) $this->app->config('security.trusted_proxies', []));

            $this->rateLimit($ip, $action);
            $this->app->bootstrapDatabase();

            Response::send(Response::success($this->dispatch($action, $input, $ip)));
        } catch (ApiException $e) {
            Response::send(
                Response::error($e->getMessage(), $e->getCode(), $e->msgCode(), $e->context()),
                $e->httpStatus()
            );
        } catch (Throwable $e) {
            Log::exception($e, ['stage' => 'admin-kernel']);
            $message = (bool) $this->app->config('app.debug') ? $e->getMessage() : 'Internal server error';
            Response::send(Response::error($message, Response::ERR_SERVER, 'SERVER_ERROR'), 500);
        }
    }

    /** @param array<string,mixed> $input */
    public function dispatch(string $action, array $input, string $ip = '0.0.0.0'): array
    {
        if ($action === '') {
            throw ApiException::validation('Missing required parameter: action');
        }
        if (!in_array($action, AdminController::actions(), true)) {
            throw ApiException::notFound("Unknown admin action: {$action}");
        }
        if (!$this->app->config('admin.enabled')) {
            throw ApiException::auth('Admin panel is disabled');
        }

        $isWrite = in_array($action, AdminController::WRITE_ACTIONS, true);
        if ($isWrite && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new ApiException('This action requires POST', Response::ERR_VALIDATION, 'METHOD_NOT_ALLOWED', 405);
        }

        $actor = 'admin';
        if (!in_array($action, AdminController::PUBLIC_ACTIONS, true)) {
            $actor = $this->app->adminAuth()->authorise($_SERVER)['actor'];
        }

        return (new AdminController($this->app, $input, $actor))->handle($action, $ip);
    }

    private function rateLimit(string $ip, string $action): void
    {
        // Logins get a much tighter budget than ordinary panel traffic.
        $limiter = $this->app->rateLimiter();
        $result  = $limiter->hit('admin:' . $ip);

        if ($action === 'login') {
            $login = $limiter->hit('admin-login:' . $ip);
            if ($login['remaining'] < max(0, $login['limit'] - 10)) {
                Log::warning('admin login throttled', ['ip' => $ip]);
                throw ApiException::rateLimit('Too many login attempts, try again in a minute');
            }
        }

        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);
        }
        if (!$result['allowed']) {
            throw ApiException::rateLimit('Rate limit exceeded');
        }
    }
}
