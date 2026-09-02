<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\App;
use Lottery\Betting\BetService;
use Lottery\Draw\ResultPresenter;
use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use Lottery\Support\Validator;

/**
 * Every /api/Lottery?action=... endpoint.
 *
 * Handlers return plain arrays; the kernel wraps them into the
 * {data, code, msg, msgCode, serviceTime} envelope.
 */
class LotteryController
{
    private App $app;
    /** @var array<string,mixed> merged query + body parameters */
    private array $input;

    /** Public actions that still must be POSTed (they create state). */
    public const PUBLIC_WRITE_ACTIONS = ['register', 'login', 'partnerlogin', 'partnertransfer'];

    /** Partner surface: authenticated by X-Api-Key, not by a player JWT. */
    public const PARTNER_ACTIONS = [
        'partnerlogin', 'partnertransfer', 'partnerbalance', 'partnerbets',
    ];

    /** Actions that mutate state: POST + JWT + (optional) request signature. */
    public const WRITE_ACTIONS = [
        'wingobet', 'gamebet', 'placebet',
        'changepassword', 'refreshtoken',
        'addfollowrecord', 'stopfollowrecord',
        'setresultoverride', 'cancelresultoverride',
        'backfillvipexperience', 'settleissue',
    ];

    /** Actions that require a valid Bearer token. */
    public const AUTH_ACTIONS = [
        'getuserinfo', 'changepassword', 'refreshtoken',
        'wingobet', 'gamebet', 'placebet', 'getrecordpage', 'getbalance',
        'getwinlossresult', 'addfollowrecord', 'stopfollowrecord',
        'getmyfollowrecords', 'getvipinfo', 'backfillvipexperience', 'getwalletledger',
    ];

    /** Actions gated behind the admin token. */
    public const ADMIN_ACTIONS = [
        'setresultoverride', 'cancelresultoverride', 'listresultoverrides', 'settleissue',
    ];

    public function __construct(App $app, array $input)
    {
        $this->app   = $app;
        $this->input = $input;
    }

    /* ===================================================================
     |  Partner sites (third-game integration)
     * ================================================================ */

    /**
     * POST ?action=PartnerLogin   (header X-Api-Key)
     * {externalUserId, nickname?, mobile?} -> JWT for that user
     */
    public function partnerLogin(array $partner): array
    {
        return $this->app->partners()->login(
            $partner,
            $this->externalUserId(),
            Validator::optionalString($this->input, 'nickname', '', 64),
            Validator::optionalString($this->input, 'mobile', '', 20)
        );
    }

    /**
     * POST ?action=PartnerTransfer  (header X-Api-Key)
     * {externalUserId, amount, direction: in|out, orderId}
     */
    public function partnerTransfer(array $partner): array
    {
        return $this->app->partners()->transfer(
            $partner,
            $this->externalUserId(),
            (float) Validator::requireString($this->input, 'amount', 20),
            Validator::requireString($this->input, 'direction', 8),
            Validator::requireString($this->input, 'orderId', 64)
        );
    }

    /** GET ?action=PartnerBalance&externalUserId=…  (header X-Api-Key) */
    public function partnerBalance(array $partner): array
    {
        return $this->app->partners()->balance($partner, $this->externalUserId());
    }

    /** GET ?action=PartnerBets&externalUserId=…  (header X-Api-Key) */
    public function partnerBets(array $partner): array
    {
        return $this->app->partners()->bets(
            $partner,
            $this->externalUserId(),
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    private function externalUserId(): string
    {
        foreach (['externalUserId', 'external_user_id', 'userId', 'uid', 'memberId', 'account'] as $key) {
            if (isset($this->input[$key]) && is_scalar($this->input[$key]) && trim((string) $this->input[$key]) !== '') {
                return trim((string) $this->input[$key]);
            }
        }

        throw ApiException::validation('Missing required parameter: externalUserId (your own user id)');
    }

    /* ===================================================================
     |  Player accounts
     * ================================================================ */

    /**
     * Field names differ between front-ends, so both endpoints accept the
     * usual aliases: mobile | phone | username | account | userName …
     * and password | pwd | passWord | loginPwd …
     */
    private const MOBILE_KEYS = [
        'mobile', 'phone', 'phoneNumber', 'phonenumber', 'mobileNumber',
        'username', 'userName', 'user_name', 'account', 'loginName', 'tel',
    ];
    private const PASSWORD_KEYS = [
        'password', 'pwd', 'passWord', 'pass', 'loginPwd', 'login_pwd', 'userPassword',
    ];

    /** POST ?action=Register  {mobile, password, nickname?} */
    public function register(): array
    {
        return $this->app->players()->register(
            $this->pick(self::MOBILE_KEYS, 'mobile', 20),
            $this->pick(self::PASSWORD_KEYS, 'password', 64),
            Validator::optionalString($this->input, 'nickname', '', 64)
        );
    }

    /** POST ?action=Login  {mobile, password} -> the token your front-end stores */
    public function login(): array
    {
        return $this->app->players()->login(
            $this->pick(self::MOBILE_KEYS, 'mobile', 20),
            $this->pick(self::PASSWORD_KEYS, 'password', 64)
        );
    }

    /** First non-empty value among a set of aliases. */
    private function pick(array $keys, string $label, int $max): string
    {
        foreach ($keys as $key) {
            if (isset($this->input[$key]) && is_scalar($this->input[$key]) && trim((string) $this->input[$key]) !== '') {
                return Validator::requireString($this->input, $key, $max);
            }
        }

        throw ApiException::validation(
            "Missing required parameter: {$label} (also accepted: " . implode(', ', array_slice($keys, 1, 4)) . ')'
        );
    }

    /** GET ?action=GetUserInfo */
    public function getUserInfo(array $user): array
    {
        return $this->app->players()->profile((int) $user['id']);
    }

    /** POST ?action=ChangePassword {oldPassword, newPassword} */
    public function changePassword(array $user): array
    {
        return $this->app->players()->changePassword(
            (int) $user['id'],
            Validator::optionalString($this->input, 'oldPassword', '', 64),
            Validator::requireString($this->input, 'newPassword', 64)
        );
    }

    /** POST ?action=RefreshToken — new JWT before the old one expires. */
    public function refreshToken(array $user): array
    {
        return $this->app->players()->refresh((int) $user['id'], (string) $user['mobile']);
    }

    /**
     * GET ?action=Whoami — public debug helper.
     *
     * Tells a front-end exactly what the server received: whether a token
     * arrived, where it was found and whether it is valid. Nothing sensitive
     * is echoed back.
     */
    public function whoami(): array
    {
        $auth  = $this->app->auth();
        $token = $auth->extractToken($_SERVER, $this->input);

        $sources = [];
        foreach ([
            'Authorization' => 'HTTP_AUTHORIZATION',
            'Token'         => 'HTTP_TOKEN',
            'X-Token'       => 'HTTP_X_TOKEN',
            'X-Access-Token'=> 'HTTP_X_ACCESS_TOKEN',
            'Auth'          => 'HTTP_AUTH',
        ] as $label => $key) {
            if (!empty($_SERVER[$key])) {
                $sources[] = $label;
            }
        }
        foreach (['token', 'access_token', 'ar_token'] as $key) {
            if (!empty($this->input[$key])) {
                $sources[] = 'query:' . $key;
            }
        }

        $result = [
            'tokenReceived' => $token !== '',
            'tokenSources'  => $sources,
            'tokenPreview'  => $token === '' ? null : substr($token, 0, 12) . '…',
            'valid'         => false,
            'userId'        => null,
            'mobile'        => null,
            'serverTime'    => Clock::dateTime(),
            'hint'          => '',
        ];

        if ($token === '') {
            $result['hint'] = 'No token found. POST action=Login first, then send Authorization: Bearer <token>.';
            return $result;
        }

        // Our own JWT?
        try {
            $claims              = $this->app->jwt()->verify($token);
            $result['valid']     = true;
            $result['tokenKind'] = 'platform-jwt';
            $result['userId']    = (int) $claims['id'];
            $result['mobile']    = (string) $claims['mobile'];
            $result['expiresAt'] = date('Y-m-d H:i:s', (int) $claims['exp']);
            $result['hint']      = 'Token is valid — authenticated endpoints will work.';

            return $result;
        } catch (ApiException $e) {
            $result['jwtError'] = $e->getMessage();
        }

        // Not ours — run the full chain (partner JWT, then token introspection
        // against the partner's own API) exactly like a real request would.
        $user = $this->app->auth()->optionalUser($_SERVER, $this->input);
        if ($user !== null) {
            $result['valid']     = true;
            $result['tokenKind'] = 'partner-token';
            $result['userId']    = (int) $user['id'];
            $result['mobile']    = (string) $user['mobile'];
            $result['hint']      = 'Recognised through your site — authenticated endpoints will work.';

            return $result;
        }

        $origin = \Lottery\Tenant\DomainService::hostOf(
            (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '')
        );
        $domain = $origin === '' ? null : $this->app->domains()->findByDomain($origin);

        if ($origin === '') {
            $result['hint'] = 'Not our token, and no Origin/Referer header was sent — '
                . 'add one so we know which partner site to ask.';
        } elseif ($domain === null) {
            $result['hint'] = "Not our token, and {$origin} is not whitelisted (admin panel -> Domains).";
        } elseif (empty($domain['validate_url'])) {
            $result['hint'] = "Not our token. Set a 'token check URL' for {$origin} so we can ask your site who owns it.";
        } else {
            $result['hint'] = 'Your site did not recognise this token (' . $domain['validate_url'] . ').';
        }
        $result['origin'] = $origin ?: null;

        return $result;
    }

    /* ===================================================================
     |  Catalogue
     * ================================================================ */

    /** GET ?action=GetGameList */
    public function getGameList(): array
    {
        $now      = Clock::now();
        $registry = $this->app->registry();
        $groups   = [];

        foreach ($registry->grouped() as $family => $games) {
            $intervals = [];
            foreach ($games as $game) {
                $issue       = $this->app->scheduler()->current($game, $now);
                $intervals[] = $game->toArray() + [
                    'currentIssue' => $issue->toArray($now),
                    'rates'        => $this->rates($game),
                ];
            }

            $groups[] = [
                'lottery'   => $family,
                'name'      => \Lottery\Games\GameRegistry::familyLabel($family),
                'sort'      => $games[0]->sort,
                'state'     => $games[0]->state,
                'intervals' => $intervals,
            ];
        }

        return [
            'serverTime' => Clock::dateTime($now),
            'timezone'   => $this->app->config('app.timezone'),
            'groups'     => $groups,
        ];
    }

    /** GET ?action=GetGameInfo&gameCode=WinGo_1M */
    public function getGameInfo(): array
    {
        $game  = $this->game();
        $now   = Clock::now();
        $rules = $this->app->rules()->forGame($game);

        return $game->toArray() + [
            'serverTime'   => Clock::dateTime($now),
            'currentIssue' => $this->app->scheduler()->current($game, $now)->toArray($now),
            'nextIssue'    => $this->app->scheduler()->next($game, $now)->toArray($now),
            'rates'        => $this->rates($game),
            'betTypes'     => $rules->betOptions(),
            'betScopes'    => $this->app->config('betting.bet_scopes'),
            'multiples'    => $this->app->config('betting.multiples'),
            'limits'       => [
                'minStake'      => Money::format((float) $this->app->config('betting.min_stake')),
                'maxStake'      => Money::format((float) $this->app->config('betting.max_stake')),
                'payoutTaxRate' => (float) $this->app->config('betting.payout_tax_rate'),
            ],
        ];
    }

    /** GET ?action=GetGameIssue&gameCode=WinGo_1M */
    public function getGameIssue(): array
    {
        $game = $this->game();
        $now  = Clock::now();

        return [
            'gameCode'   => $game->code,
            'serverTime' => Clock::dateTime($now),
            'current'    => $this->app->scheduler()->current($game, $now)->toArray($now),
            'next'       => $this->app->scheduler()->next($game, $now)->toArray($now),
        ];
    }

    /* ===================================================================
     |  Results & statistics
     * ================================================================ */

    /** GET ?action=GetHistoryIssuePage&gameCode=WinGo_1M&pageNo=1&pageSize=10 */
    public function getHistoryIssuePage(): array
    {
        $game     = $this->game();
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 10000);
        $pageSize = Validator::int($this->input, 'pageSize', 10, 1, 100);

        // Make sure everything that has finished is drawn before we page over it.
        $this->app->settlement()->settleDue($game, min(20, $pageSize + 5));

        $activeIssue = $this->app->draws()->resolveMaxIssue(
            $game,
            (string) ($this->input['activeIssue'] ?? $this->input['active_issue'] ?? '')
        );
        $rows  = $this->app->draws()->history($game, $pageSize, ($pageNo - 1) * $pageSize, $activeIssue);
        $total = $this->app->draws()->countHistory($game, $activeIssue);

        return [
            'gameCode'  => $game->code,
            'pageNo'    => $pageNo,
            'pageSize'  => $pageSize,
            'totalCount'=> $total,
            'totalPage' => (int) ceil($total / $pageSize),
            'list'      => ResultPresenter::presentMany($rows),
        ];
    }

    /** GET ?action=GetTrendStatistics&gameCode=WinGo_1M&window=100 */
    public function getTrendStatistics(): array
    {
        $game   = $this->game();
        $window = Validator::int($this->input, 'window', 100, 10, 500);

        $this->app->settlement()->settleDue($game, 5);

        // Capped at the publication window too — the missing/appear counters
        // would otherwise give away a result that is still being held back.
        $activeIssue = $this->app->draws()->resolveMaxIssue(
            $game,
            (string) ($this->input['activeIssue'] ?? $this->input['active_issue'] ?? '')
        );
        return $this->app->trends()->statistics($game, $window, $activeIssue);
    }

    /* ===================================================================
     |  Betting
     * ================================================================ */

    /** POST ?action=WinGoBet (also GameBet / PlaceBet for the other families) */
    public function winGoBet(array $user): array
    {
        $game       = $this->game();
        $betType    = Validator::requireString($this->input, 'betType', 32);
        $betContent = Validator::betContent(Validator::requireString($this->input, 'betContent', 191));
        $amount     = Validator::amount($this->input, 'amount', 0.01, (float) $this->app->config('betting.max_stake'));
        $multiplier = Validator::int($this->input, 'multiplier', 1, 1, 10000);

        $placement = $this->app->bets()->place((int) $user['id'], [
            'gameCode'        => $game->code,
            'betType'         => $betType,
            'betContent'      => $betContent,
            'amount'          => $amount,
            'multiplier'      => $multiplier,
            'issueNumber'     => Validator::optionalString($this->input, 'issueNumber', '', 17),
            'requestGroupKey' => Validator::optionalString($this->input, 'requestGroupKey', '', 191),
            'requestKey'      => Validator::optionalString($this->input, 'requestKey', '', 191),
            'source'          => 'manual',
        ]);

        return $placement;
    }

    /** GET ?action=GetRecordPage&gameCode=WinGo_1M&pageNo=1&pageSize=10 */
    public function getRecordPage(array $user): array
    {
        $gameCode = Validator::optionalString($this->input, 'gameCode', '', 32);
        $game     = $gameCode === '' ? null : $this->game();
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 10000);
        $pageSize = Validator::int($this->input, 'pageSize', 10, 1, 100);

        if ($game !== null) {
            $this->app->settlement()->settleDue($game, 5);
        }

        $history = $this->app->bets()->history(
            (int) $user['id'],
            $game === null ? null : $game->code,
            $pageNo,
            $pageSize
        );

        return [
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalCount' => $history['total'],
            'totalPage'  => (int) ceil($history['total'] / $pageSize),
            'list'       => $history['list'],
        ];
    }

    /** GET ?action=GetBalance */
    public function getBalance(array $user): array
    {
        return $this->app->wallet()->snapshot((int) $user['id'])
            + ['vip' => $this->app->vip()->status((int) $user['id'])];
    }

    /** GET ?action=GetWalletLedger&pageNo=1&pageSize=20 */
    public function getWalletLedger(array $user): array
    {
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 10000);
        $pageSize = Validator::int($this->input, 'pageSize', 20, 1, 100);

        return [
            'pageNo'   => $pageNo,
            'pageSize' => $pageSize,
            'list'     => $this->app->wallet()->ledger((int) $user['id'], $pageSize, ($pageNo - 1) * $pageSize),
        ];
    }

    /** GET ?action=GetWinLossResult&gameCode=WinGo_1M&issueNumber=... */
    public function getWinLossResult(array $user): array
    {
        $game        = $this->game();
        $issueNumber = Validator::issueNumber(Validator::requireString($this->input, 'issueNumber', 17));
        $issue       = $this->app->scheduler()->fromIssueNumber($game, $issueNumber);

        $this->app->settlement()->settleIssue($game, $issue);

        return $this->app->settlement()->winLossForUser((int) $user['id'], $game, $issueNumber);
    }

    /* ===================================================================
     |  Follow / copy trading
     * ================================================================ */

    /** GET ?action=GetFollowPlanList[&gameCode=WinGo_1M] */
    public function getFollowPlanList(): array
    {
        $gameCode = Validator::optionalString($this->input, 'gameCode', '', 32);
        $gameCode = $gameCode === '' ? null : $this->game()->code;

        return ['list' => $this->app->follow()->plans($gameCode)];
    }

    /** POST ?action=AddFollowRecord */
    public function addFollowRecord(array $user): array
    {
        return $this->app->follow()->subscribe((int) $user['id'], [
            'planCode'   => Validator::requireString($this->input, 'planCode', 48),
            'amount'     => Validator::amount($this->input, 'amount', 0.01, (float) $this->app->config('betting.max_stake')),
            'multiplier' => Validator::int($this->input, 'multiplier', 1, 1, 1000),
            'rounds'     => Validator::int($this->input, 'rounds', 0, 0, 1000),
            'stopLoss'   => Validator::int($this->input, 'stopLoss', 0, 0, 100000000),
        ]);
    }

    /** POST ?action=StopFollowRecord */
    public function stopFollowRecord(array $user): array
    {
        return $this->app->follow()->stop((int) $user['id'], [
            'followId' => Validator::int($this->input, 'followId', 0, 0, PHP_INT_MAX),
            'planCode' => Validator::optionalString($this->input, 'planCode', '', 48),
        ]);
    }

    /** GET ?action=GetMyFollowRecords */
    public function getMyFollowRecords(array $user): array
    {
        return ['list' => $this->app->follow()->userSubscriptions((int) $user['id'])];
    }

    /* ===================================================================
     |  VIP
     * ================================================================ */

    /** GET ?action=GetVipInfo */
    public function getVipInfo(array $user): array
    {
        return $this->app->vip()->status((int) $user['id'])
            + ['levels' => $this->app->vip()->levelTable()];
    }

    /** POST ?action=BackfillVipExperience */
    public function backfillVipExperience(array $user): array
    {
        $result = $this->app->vip()->backfill((int) $user['id']);

        return $result + $this->app->vip()->status((int) $user['id']);
    }

    /* ===================================================================
     |  Admin
     * ================================================================ */

    /** POST ?action=SetResultOverride */
    public function setResultOverride(): array
    {
        $game = $this->game();

        return $this->app->overrides()->set(
            $game,
            Validator::optionalString($this->input, 'issueNumber', '', 17),
            Validator::requireString($this->input, 'value', 64),
            Validator::optionalString($this->input, 'mode', 'oneshot', 12),
            'admin',
            Validator::optionalString($this->input, 'note', '', 191)
        );
    }

    /** POST ?action=CancelResultOverride */
    public function cancelResultOverride(): array
    {
        $game = $this->game();

        return [
            'cancelled' => $this->app->overrides()->cancel(
                $game,
                Validator::optionalString($this->input, 'issueNumber', '', 17)
            ),
        ];
    }

    /** GET ?action=ListResultOverrides */
    public function listResultOverrides(): array
    {
        $gameCode = Validator::optionalString($this->input, 'gameCode', '', 32);

        return ['list' => $this->app->overrides()->listPending($gameCode === '' ? null : $this->game()->code)];
    }

    /** POST ?action=SettleIssue — manual settlement trigger for ops. */
    public function settleIssue(): array
    {
        $game        = $this->game();
        $issueNumber = Validator::optionalString($this->input, 'issueNumber', '', 17);

        if ($issueNumber === '') {
            return ['reports' => $this->app->settlement()->settleDue($game, 20)];
        }

        $issue = $this->app->scheduler()->fromIssueNumber($game, Validator::issueNumber($issueNumber));

        return $this->app->settlement()->settleIssue($game, $issue);
    }

    /** GET ?action=Health */
    public function health(): array
    {
        return [
            'status'        => 'ok',
            'version'       => $this->app->config('app.version'),
            'schemaVersion' => $this->app->migrator()->currentVersion(),
            'driver'        => $this->app->db()->driver(),
            'serverTime'    => Clock::dateTime(),
            'timezone'      => $this->app->config('app.timezone'),
            'games'         => count($this->app->registry()->all()),
        ];
    }

    /* ===================================================================
     |  Helpers
     * ================================================================ */

    private function game(): GameDefinition
    {
        $code = Validator::gameCode(Validator::requireString($this->input, 'gameCode', 32));

        return $this->app->registry()->get($code);
    }

    /** Flat odds table used by list/info responses. */
    private function rates(GameDefinition $game): array
    {
        $rates = [];
        foreach ($this->app->rules()->forGame($game)->betOptions() as $option) {
            $rates[$option['betType']] = [
                'odds'    => $option['odds'],
                'oddsMap' => $option['oddsMap'] ?? null,
            ];
        }

        return $rates;
    }

    public static function isKnownAction(string $action): bool
    {
        return in_array(strtolower($action), array_merge(
            self::WRITE_ACTIONS,
            self::AUTH_ACTIONS,
            self::ADMIN_ACTIONS,
            self::PUBLIC_WRITE_ACTIONS,
            self::PARTNER_ACTIONS,
            [
                'getgamelist', 'getgameinfo', 'getgameissue', 'gethistoryissuepage',
                'gettrendstatistics', 'getfollowplanlist', 'health', 'logout', 'whoami',
            ]
        ), true);
    }
}
