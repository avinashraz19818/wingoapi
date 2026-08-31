<?php
/**
 * Single entrypoint / router.
 *
 *   /api/Lottery      -> the SaaS Lottery API (see docs/API.md)
 *   /health           -> liveness probe
 *   /                 -> service banner
 *
 * Legacy WinGo-only routes from earlier versions are kept under /legacy/*.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Lottery\Api\Kernel;
use Lottery\App;
use Lottery\Support\Response;
use Lottery\Support\Security;

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Direct hits on the front controller behave like the site root.
if (preg_match('#(^|/)index\.php$#i', $uri)) {
    $uri = '';
}

$app = App::boot();

/* ------------------------------------------------------------- main API */
if ($uri === '' || preg_match('#^/(api/)?lottery$#i', $uri)) {
    if ($uri === '' && !isset($_GET['action'])) {
        Security::applyHeaders((array) $app->config('security'));
        if (Security::isPreflight()) {
            http_response_code(204);
            exit;
        }
        Response::send(Response::success([
            'service'   => $app->config('app.name'),
            'version'   => $app->config('app.version'),
            'endpoint'  => '/api/Lottery?action=GetGameList',
            'docs'      => '/docs/API.md',
            'timezone'  => $app->config('app.timezone'),
        ]));
        exit;
    }

    (new Kernel($app))->handle();
    exit;
}

/* ------------------------------------------------------------- probes */
if ($uri === '/health' || $uri === '/api/health') {
    $_GET['action'] = 'Health';
    (new Kernel($app))->handle();
    exit;
}

/* ----------------------------------------------- provider-compatible paths
 | /WinGo/WinGo_1M/GetHistoryIssuePage.json style URLs used by some clients.
 */
if (preg_match('#^/([A-Za-z0-9]+)/([A-Za-z0-9_]+)/GetHistoryIssuePage\.json$#i', $uri, $m)) {
    $_GET['action']   = 'GetHistoryIssuePage';
    $_GET['gameCode'] = $m[2];
    (new Kernel($app))->handle();
    exit;
}

Security::applyHeaders((array) $app->config('security'));
Response::send(
    Response::error("Endpoint not found: {$uri}", Response::ERR_NOT_FOUND, 'NOT_FOUND'),
    404
);
