<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\Api\Compat\ArCompatController;
use Lottery\App;
use Lottery\Support\ApiException;
use Lottery\Support\Log;
use Lottery\Support\Response;
use Lottery\Support\Security;
use Throwable;

/**
 * Front door for existing "AR style" front-ends.
 *
 * Same pipeline as the player API, but every response is rendered in the
 * dialect the client already speaks (see Compat\ArCompatController), and the
 * caller is resolved through the partner token path so the site's own login
 * keeps working untouched.
 */
class CompatKernel
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function handle(array $route = []): void
    {
        Security::applyHeaders((array) $this->app->config('security'));

        if (Security::isPreflight()) {
            http_response_code(204);
            return;
        }

        $input  = (new Kernel($this->app))->collectInput() + $route;
        $action = strtolower(trim((string) ($input['action'] ?? '')));

        try {
            $this->app->bootstrapDatabase();
            echo json_encode($this->dispatch($action, $input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (ApiException $e) {
            http_response_code($e->httpStatus() === 401 ? 200 : $e->httpStatus());
            echo json_encode($this->errorPayload($e), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            Log::exception($e, ['stage' => 'compat-kernel', 'action' => $action]);
            $message = (bool) $this->app->config('app.debug') ? $e->getMessage() : 'Service error';
            echo json_encode(ArCompatController::fail($message, 500), JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Their front-ends switch on msgCode, so map our failures onto the codes
     * they already handle (142 = insufficient balance, 401 = bad input,
     * 405 = closed, 315 = auth).
     */
    /** The failure body a client of this dialect expects. */
    public function errorPayload(ApiException $e): array
    {
        return ArCompatController::fail($e->getMessage(), $this->msgCodeFor($e));
    }

    private function msgCodeFor(ApiException $e): int
    {
        switch ($e->msgCode()) {
            case 'INSUFFICIENT_BALANCE': return 142;
            case 'BETTING_CLOSED':       return 405;
            case 'AUTH_REQUIRED':        return 315;
            case 'VALIDATION_ERROR':     return 401;
            case 'RATE_LIMITED':         return 429;
            default:                     return 500;
        }
    }

    /**
     * @param array<string,mixed> $input
     */
    public function dispatch(string $action, array $input): array
    {
        $action = strtolower(preg_replace('/[^a-z0-9]/i', '', $action) ?? '');
        if ($action === '') {
            throw ApiException::validation('Missing action');
        }

        // Anything that needs a player is resolved through the partner path:
        // the site's own token is introspected against its own API.
        $needsUser = in_array($action, [
            'getbalance', 'getrecordpage', 'getwinlossresult', 'getuserinfo',
        ], true) || str_ends_with($action, 'bet');

        $user = $needsUser
            ? $this->app->auth()->requireUser(null, $input)
            : $this->app->auth()->optionalUser(null, $input);

        $controller = new ArCompatController($this->app, $input, $user);
        $game       = $this->game($input);

        switch ($action) {
            case 'getgamelist':          return $controller->gameList();
            case 'getgameinfo':          return $controller->gameInfo($this->requireGame($game));
            case 'gethistoryissuepage':
            case 'getnoaverageemerdlist':return $controller->history($this->requireGame($game));
            case 'gettrendstatistics':   return $controller->trend($this->requireGame($game));
            case 'issue':
            case 'getgameissue':         return $controller->issue($this->requireGame($game));
            case 'getbalance':           return $controller->balance();
            case 'getrecordpage':        return $controller->records($game);
            case 'getwinlossresult':     return $controller->winLoss($this->requireGame($game));
            case 'getbetlimit':          return $controller->betLimit();
            case 'getgameintroduce':     return $controller->introduce();
            case 'getdragonlist':        return ArCompatController::ok([]);
            case 'getwingoliveurl':      return ArCompatController::ok(['url' => '', 'isOpen' => false]);
            case 'getfollowrule':        return ArCompatController::ok(['minAmount' => 1, 'maxAmount' => 100000, 'maxIssueCount' => 1000]);
        }

        if (str_ends_with($action, 'bet')) {
            return $controller->bet($this->requireGame($game));
        }
        if (str_contains($action, 'follow')) {
            return $controller->emptyPage();
        }

        throw ApiException::notFound("Unknown action: {$action}");
    }

    private function game(array $input): ?\Lottery\Games\GameDefinition
    {
        $code = trim((string) ($input['gameCode'] ?? $input['typeId'] ?? ''));
        if ($code === '') {
            return null;
        }

        return $this->app->registry()->find($code);
    }

    private function requireGame(?\Lottery\Games\GameDefinition $game): \Lottery\Games\GameDefinition
    {
        if ($game === null) {
            throw ApiException::validation('Missing or unknown gameCode');
        }

        return $game;
    }
}
