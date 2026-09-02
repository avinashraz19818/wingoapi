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
            'getmybetrecord', 'getbetrecord',
        ], true) || str_ends_with($action, 'bet');

        $user = $this->app->auth()->optionalUser(null, $input);

        if ($needsUser && $user === null) {
            // Reads degrade to an empty/zero answer so the game screen still
            // renders; anything that moves money refuses properly.
            if (!str_ends_with($action, 'bet')) {
                Log::warning('compat: unidentified player, answering empty', ['action' => $action]);

                return $this->guestAnswer($action);
            }

            $this->app->auth()->requireUser(null, $input);   // throws with the real reason
        }

        $controller = new ArCompatController($this->app, $input, $user, $this->allowedGames($input));
        $game       = $this->game($input);

        switch ($action) {
            case 'getgamelist':          return $controller->gameList();
            case 'getgameinfo':          return $controller->gameInfo($this->requireGame($game));
            case 'gethistoryissuepage':
            case 'getnoaverageemerdlist':return $controller->history($this->requireGame($game));
            case 'gettrendstatistics':   return $controller->trend($this->requireGame($game));
            case 'getwinthelotteryresult':
            case 'getresultbyissue':
            case 'getresult':            return $controller->result($this->requireGame($game));
            case 'issue':
            case 'getgameissue':         return $controller->issue($this->requireGame($game));
            case 'getbalance':           return $controller->balance();
            case 'getuserinfo':          return $controller->userInfo();
            case 'getrecordpage':
            case 'getmybetrecord':
            case 'getbetrecord':         return $controller->records($game);
            case 'getwinlossresult':     return $controller->winLoss($this->requireGame($game));
            case 'getbetlimit':          return $controller->betLimit();
            case 'getgameintroduce':     return $controller->introduce();
            case 'getdragonlist':
            case 'getnoticelist':
            case 'getactivitylist':      return ArCompatController::ok([]);
            case 'getwingoliveurl2':     return ArCompatController::ok(['url' => '', 'isOpen' => false]);
            case 'getwingoliveurl':      return ArCompatController::ok(['url' => '', 'isOpen' => false]);
            case 'getfollowrule':        return ArCompatController::ok(['minAmount' => 1, 'maxAmount' => 100000, 'maxIssueCount' => 1000]);
        }

        if (str_ends_with($action, 'bet')) {
            return $controller->bet($this->requireGame($game));
        }
        if (str_contains($action, 'follow')) {
            return $controller->emptyPage();
        }

        // Anything else on the lottery base is answered with an empty, valid
        // envelope: an unknown extra call must never break the game screen.
        Log::warning('compat: unhandled action, answered empty', ['action' => $action]);

        return ArCompatController::ok(null);
    }

    /**
     * Games this site is allowed to show, from its whitelist entry.
     *
     * @return array<int,string>
     */
    private function allowedGames(array $input): array
    {
        $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? $input['key'] ?? $input['apiKey'] ?? '');
        if ($key === '') {
            return [];
        }

        $domain = $this->app->domains()->findByKey($key);
        if ($domain === null || trim((string) ($domain['games'] ?? '')) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $domain['games']))));
    }

    /** What a read-only call gets when we could not identify the player. */
    private function guestAnswer(string $action): array
    {
        if ($action === 'getbalance') {
            return ArCompatController::ok(['balance' => 0.0]);
        }
        if ($action === 'getuserinfo') {
            return ArCompatController::ok([
                'userId' => 0, 'nickName' => 'Guest', 'vipLevel' => 0,
                'walletBalance' => 0.0, 'balance' => 0.0, 'amount' => 0.0,
            ]);
        }

        return ArCompatController::ok([
            'list' => [], 'pageNo' => 1, 'pageSize' => 10, 'totalCount' => 0, 'totalPage' => 0,
        ]);
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
