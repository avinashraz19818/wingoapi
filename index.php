<?php
/**
 * Single entrypoint / router.
 *
 *   /api/Lottery                         -> the SaaS Lottery API
 *   /api/Lottery?action=GetGameList      -> query-string action format
 *   /api/Lottery/GetGameList             -> path action format
 *   /api/Feed                            -> public result feed
 *   /{Family}/{Game}/GetHistoryIssuePage.json -> provider-compatible feed
 *   /api/Admin                           -> admin panel API
 *   /admin                               -> admin web panel
 *   /health                              -> liveness probe
 *   /                                   -> service banner
 *
 * Legacy WinGo-only routes from earlier versions are kept under /legacy/*.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Lottery\Api\AdminKernel;
use Lottery\Api\FeedKernel;
use Lottery\Api\Kernel;
use Lottery\App;
use Lottery\Support\Response;
use Lottery\Support\Security;

$uri = (string) parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

$uri = rtrim($uri, '/');

// Direct hits on the front controller behave like the site root.
if (preg_match('#(^|/)index\.php$#i', $uri)) {
    $uri = '';
}

$app = App::boot();

/* ------------------------------------------------------------- main API */

/*
 * Supports BOTH:
 *
 *   /api/Lottery?action=GetGameList
 *
 * and:
 *
 *   /api/Lottery/GetGameList
 *
 * The second format extracts the action from the URL path and sends it
 * through the same Lottery Kernel.
 */
if (
    $uri === '' ||
    preg_match('#^/(api/)?lottery$#i', $uri) ||
    preg_match('#^/api/lottery/[^/]+$#i', $uri)
) {

    /*
     * Root/service banner.
     *
     * Only show the banner when the actual request is the site root
     * and no action has been supplied.
     */
    if ($uri === '' && !isset($_GET['action'])) {
        Security::applyHeaders(
            (array) $app->config('security')
        );

        if (Security::isPreflight()) {
            http_response_code(204);
            exit;
        }

        Response::send(
            Response::success([
                'service'   => $app->config('app.name'),
                'version'   => $app->config('app.version'),

                /*
                 * Keep the documented/query-string endpoint as the
                 * primary endpoint shown by the service banner.
                 */
                'endpoint'  => '/api/Lottery?action=GetGameList',

                'docs'      => '/docs/API.md',
                'timezone'  => $app->config('app.timezone'),
            ])
        );

        exit;
    }

    /*
     * Path-style compatibility:
     *
     * /api/Lottery/GetGameList
     *
     * becomes internally equivalent to:
     *
     * /api/Lottery?action=GetGameList
     *
     * Query-string action always takes priority if it was supplied.
     */
    if (
        !isset($_GET['action']) ||
        trim((string) $_GET['action']) === ''
    ) {
        if (
            preg_match(
                '#^/api/lottery/([^/]+)/?$#i',
                $uri,
                $matches
            )
        ) {
            $_GET['action'] = urldecode(
                (string) $matches[1]
            );
        }
    }

    (new Kernel($app))->handle();
    exit;
}

/* ---------------------------------------------------- admin panel API */

if (preg_match('#^/api/admin$#i', $uri)) {
    (new AdminKernel($app))->handle();
    exit;
}

/*
 * Admin web panel.
 *
 * Static SPA shell; every data call is authenticated separately.
 */
if (preg_match('#^/(admin|panel|admin-panel)$#i', $uri)) {

    if (!$app->config('admin.enabled')) {
        Security::applyHeaders(
            (array) $app->config('security')
        );

        Response::send(
            Response::error(
                'Admin panel is disabled',
                Response::ERR_AUTH,
                'AUTH_REQUIRED'
            ),
            403
        );

        exit;
    }

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    header(
        'X-Frame-Options: DENY'
    );

    header(
        'Referrer-Policy: no-referrer'
    );

    readfile(
        __DIR__ . '/panel/index.html'
    );

    exit;
}

/* ------------------------------------------------------------- probes */

if (
    $uri === '/health' ||
    $uri === '/api/health'
) {
    $_GET['action'] = 'Health';

    (new Kernel($app))->handle();

    exit;
}

/* ------------------------------------------------------------ result feed
 |
 | Provider-compatible URLs served to whitelisted customer domains:
 |
 |   /WinGo/WinGo_1M/GetHistoryIssuePage.json
 |   /WinGo/WinGo_1M/GetNoaverageEmerdList.json
 |   /WinGo/WinGo_1M/GetGameIssue.json
 |
 */

if (preg_match('#^/api/feed$#i', $uri)) {
    (new FeedKernel($app))->handle();

    exit;
}

if (
    preg_match(
        '#^/([A-Za-z0-9]+)/([A-Za-z0-9_]+)/(GetHistoryIssuePage|GetNoaverageEmerdList|GetGameIssue|GetResult)\.json$#i',
        $uri,
        $m
    )
) {
    (new FeedKernel($app))->handle([
        'gameCode' => $m[2],
        'action'   => $m[3],
    ]);

    exit;
}

/* ---------------------------------------------------- results board */

// Public results board (all game sections with live results).

if (preg_match('#^/(results|board)$#i', $uri)) {

    if (!$app->config('feed.board_enabled')) {
        Security::applyHeaders(
            (array) $app->config('security')
        );

        Response::send(
            Response::error(
                'Results board is disabled',
                Response::ERR_NOT_FOUND,
                'NOT_FOUND'
            ),
            404
        );

        exit;
    }

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    readfile(
        __DIR__ . '/panel/results.html'
    );

    exit;
}

/* ------------------------------------------------------------- 404 */

Security::applyHeaders(
    (array) $app->config('security')
);

Response::send(
    Response::error(
        "Endpoint not found: {$uri}",
        Response::ERR_NOT_FOUND,
        'NOT_FOUND'
    ),
    404
);