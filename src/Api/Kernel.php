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
 * HTTP kernel for /api/Lottery.
 *
 * Pipeline: security headers -> CORS preflight -> rate limit -> bootstrap DB ->
 * method check -> signature -> auth -> action -> envelope.
 *
 * Supports both endpoint formats:
 *   /api/Lottery?action=GetGameList
 *   /api/Lottery/GetGameList
 */
class Kernel
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(): void
    {
        $config = $this->app->config('security');
        Security::applyHeaders($config);

        if (Security::isPreflight()) {
            http_response_code(204);
            return;
        }

        try {
            $input = $this->collectInput();

            // Normal endpoint format:
            // /api/Lottery?action=GetGameList
            $action = (string) ($input['action'] ?? '');

            /*
             * Legacy/path endpoint compatibility:
             * /api/Lottery/GetGameList
             *
             * If no ?action=... is supplied, extract the action from
             * the request path and pass it through the same dispatcher.
             */
            if (trim($action) === '') {
                $path = (string) (parse_url(
                    $_SERVER['REQUEST_URI'] ?? '',
                    PHP_URL_PATH
                ) ?? '');

                if (preg_match(
                    '#/api/Lottery/([^/]+)/?$#i',
                    $path,
                    $matches
                )) {
                    $action = urldecode((string) $matches[1]);
                    $input['action'] = $action;
                }
            }

            $this->enforceRateLimit($action);
            $this->app->bootstrapDatabase();

            $payload = $this->dispatch($action, $input);
            Response::send(Response::success($payload));
        } catch (ApiException $e) {
            Response::send(
                Response::error(
                    $e->getMessage(),
                    $e->getCode(),
                    $e->msgCode(),
                    $e->context()
                ),
                $e->httpStatus()
            );
        } catch (Throwable $e) {
            Log::exception($e, ['stage' => 'kernel']);

            $debug = (bool) $this->app->config('app.debug');

            $message = $debug
                ? $e->getMessage()
                : 'Internal server error';

            Response::send(
                Response::error(
                    $message,
                    Response::ERR_SERVER,
                    'SERVER_ERROR'
                ),
                500
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function collectInput(): array
    {
        $input = $_GET;

        $raw = file_get_contents('php://input');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }

        if ($_POST !== []) {
            $input = array_merge($input, $_POST);
        }

        return $input;
    }

    /** @param array<string,mixed> $input */
    public function dispatch(string $action, array $input): array
    {
        $normalised = strtolower(trim($action));

        if ($normalised === '') {
            throw ApiException::validation(
                'Missing required parameter: action'
            );
        }

        if (!LotteryController::isKnownAction($normalised)) {
            throw ApiException::notFound(
                "Unknown action: {$action}"
            );
        }

        $controller = new LotteryController(
            $this->app,
            $input
        );

        $isWrite = in_array(
            $normalised,
            LotteryController::WRITE_ACTIONS,
            true
        ) || in_array(
            $normalised,
            LotteryController::PUBLIC_WRITE_ACTIONS,
            true
        );

        if (
            $isWrite &&
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        ) {
            throw new ApiException(
                'This action requires POST',
                Response::ERR_VALIDATION,
                'METHOD_NOT_ALLOWED',
                405
            );
        }

        if (
            in_array(
                $normalised,
                LotteryController::ADMIN_ACTIONS,
                true
            )
        ) {
            $this->requireAdmin();
        }

        if (
            $isWrite &&
            (bool) $this->app->config('auth.require_signature')
        ) {
            $this->app->signature()->verify($input);
        }

        // Partner surface is authenticated by the site's API key.
        if (in_array($normalised, LotteryController::PARTNER_ACTIONS, true)) {
            $partner = $this->app->partners()->requirePartner($_SERVER, $input);

            switch ($normalised) {
                case 'partnerlogin':    return $controller->partnerLogin($partner);
                case 'partnertransfer': return $controller->partnerTransfer($partner);
                case 'partnerbalance':  return $controller->partnerBalance($partner);
                case 'partnerbets':     return $controller->partnerBets($partner);
            }
        }

        $user = null;

        if (
            in_array(
                $normalised,
                LotteryController::AUTH_ACTIONS,
                true
            )
        ) {
            $user = $this->app->auth()->requireUser(null, $input);
        }

        switch ($normalised) {
            /* player accounts */

            case 'register':
                return $controller->register();

            case 'login':
                return $controller->login();

            case 'logout':
                return ['loggedOut' => true];

            case 'whoami':
                return $controller->whoami();

            case 'getuserinfo':
                return $controller->getUserInfo($user);

            case 'changepassword':
                return $controller->changePassword($user);

            case 'refreshtoken':
                return $controller->refreshToken($user);

            /* public */

            case 'getgamelist':
                return $controller->getGameList();

            case 'getgameinfo':
                return $controller->getGameInfo();

            case 'getgameissue':
                return $controller->getGameIssue();

            case 'gethistoryissuepage':
            case 'getnoaverageemerdlist':
                return $controller->getHistoryIssuePage();

            case 'gettrendstatistics':
                return $controller->getTrendStatistics();

            case 'getfollowplanlist':
                return $controller->getFollowPlanList();

            case 'health':
                return $controller->health();

            /* authenticated */

            case 'wingobet':
            case 'gamebet':
            case 'placebet':
                return $controller->winGoBet($user);

            case 'getrecordpage':
                return $controller->getRecordPage($user);

            case 'getbalance':
                return $controller->getBalance($user);

            case 'getwalletledger':
                return $controller->getWalletLedger($user);

            case 'getwinlossresult':
                return $controller->getWinLossResult($user);

            case 'addfollowrecord':
                return $controller->addFollowRecord($user);

            case 'stopfollowrecord':
                return $controller->stopFollowRecord($user);

            case 'getmyfollowrecords':
                return $controller->getMyFollowRecords($user);

            case 'getvipinfo':
                return $controller->getVipInfo($user);

            case 'backfillvipexperience':
                return $controller->backfillVipExperience($user);

            /* admin */

            case 'setresultoverride':
                return $controller->setResultOverride();

            case 'cancelresultoverride':
                return $controller->cancelResultOverride();

            case 'listresultoverrides':
                return $controller->listResultOverrides();

            case 'settleissue':
                return $controller->settleIssue();
        }

        throw ApiException::notFound(
            "Unhandled action: {$action}"
        );
    }

    private function requireAdmin(): void
    {
        $expected = (string) $this->app->config(
            'security.admin_token'
        );

        if ($expected === '') {
            throw ApiException::auth(
                'Admin endpoints are disabled (ADMIN_TOKEN not configured)'
            );
        }

        $provided = (string) (
            $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''
        );

        if (
            $provided === '' ||
            !hash_equals($expected, $provided)
        ) {
            throw ApiException::auth(
                'Invalid admin token'
            );
        }
    }

    private function enforceRateLimit(string $action): void
    {
        $ip = Security::clientIp(
            (array) $this->app->config(
                'security.trusted_proxies',
                []
            )
        );

        $result = $this->app->rateLimiter()->hit($ip);

        if (!headers_sent()) {
            header(
                'X-RateLimit-Limit: ' .
                $result['limit']
            );

            header(
                'X-RateLimit-Remaining: ' .
                $result['remaining']
            );

            header(
                'X-RateLimit-Reset: ' .
                $result['reset']
            );
        }

        if (!$result['allowed']) {
            Log::warning(
                'rate limit exceeded',
                [
                    'ip' => $ip,
                    'action' => $action
                ]
            );

            throw ApiException::rateLimit(
                'Rate limit of ' .
                $result['limit'] .
                ' requests/minute exceeded'
            );
        }
    }
}