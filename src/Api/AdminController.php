<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\Admin\AdminService;
use Lottery\App;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use Lottery\Support\Validator;

/**
 * Every /api/Admin?action=... endpoint used by the web panel.
 */
class AdminController
{
    private App $app;
    private AdminService $admin;
    private array $input;
    private string $actor;

    /** Actions that change state (POST only). */
    public const WRITE_ACTIONS = [
        'login', 'setoverride', 'canceloverride', 'settle', 'adjustwallet',
        'createuser', 'setuserstatus', 'saveplan', 'deleteplan', 'stopfollow',
        'backfillvip', 'runworkerpass',
        'savedomain', 'deletedomain', 'rotatedomainkey', 'setdomainstatus',
    ];

    /** Actions callable without an admin session. */
    public const PUBLIC_ACTIONS = ['login', 'ping'];

    public function __construct(App $app, array $input, string $actor = 'admin')
    {
        $this->app   = $app;
        $this->admin = new AdminService($app);
        $this->input = $input;
        $this->actor = $actor;
    }

    /* --------------------------------------------------------- session */

    public function ping(): array
    {
        return [
            'service' => $this->app->config('app.name'),
            'version' => $this->app->config('app.version'),
            'panel'   => $this->app->adminAuth()->enabled(),
            'time'    => Clock::dateTime(),
        ];
    }

    public function login(string $ip): array
    {
        $user     = Validator::optionalString($this->input, 'user', 'admin', 64);
        $password = Validator::requireString($this->input, 'password', 191);

        $session = $this->app->adminAuth()->login($user, $password, $ip);
        $this->admin->audit($session['user'], 'auth.login', null, [], $ip);

        return $session + ['system' => $this->admin->systemInfo()];
    }

    /* ------------------------------------------------------- dashboard */

    public function dashboard(): array
    {
        return $this->admin->dashboard(Validator::int($this->input, 'days', 7, 1, 60));
    }

    public function system(): array
    {
        return $this->admin->systemInfo();
    }

    /* ----------------------------------------------------------- games */

    public function games(): array
    {
        return $this->admin->games();
    }

    public function exposure(): array
    {
        $game        = $this->game();
        $issueNumber = Validator::optionalString($this->input, 'issueNumber', '', 17);

        if ($issueNumber === '') {
            $issueNumber = $this->app->scheduler()->current($game)->issueNumber;
        } else {
            Validator::issueNumber($issueNumber);
        }

        return $this->admin->exposure($game, $issueNumber);
    }

    /* --------------------------------------------------------- results */

    public function results(): array
    {
        $gameCode = Validator::optionalString($this->input, 'gameCode', '', 32);

        return $this->admin->results(
            $gameCode === '' ? null : $this->game()->code,
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    public function overrides(): array
    {
        $gameCode = Validator::optionalString($this->input, 'gameCode', '', 32);

        return ['list' => $this->app->overrides()->listPending($gameCode === '' ? null : $this->game()->code)];
    }

    public function setOverride(): array
    {
        $game   = $this->game();
        $result = $this->app->overrides()->set(
            $game,
            Validator::optionalString($this->input, 'issueNumber', '', 17),
            Validator::requireString($this->input, 'value', 64),
            Validator::optionalString($this->input, 'mode', 'oneshot', 12),
            $this->actor,
            Validator::optionalString($this->input, 'note', '', 191)
        );

        $this->admin->audit($this->actor, 'result.override', $game->code, $result);

        return $result;
    }

    public function cancelOverride(): array
    {
        $game      = $this->game();
        $cancelled = $this->app->overrides()->cancel(
            $game,
            Validator::optionalString($this->input, 'issueNumber', '', 17)
        );

        $this->admin->audit($this->actor, 'result.override.cancel', $game->code, ['cancelled' => $cancelled]);

        return ['cancelled' => $cancelled];
    }

    public function settle(): array
    {
        $game        = $this->game();
        $issueNumber = Validator::optionalString($this->input, 'issueNumber', '', 17);

        if ($issueNumber === '') {
            $reports = $this->app->settlement()->settleDue($game, 20);
            $this->admin->audit($this->actor, 'settle.sweep', $game->code, ['issues' => count($reports)]);

            return ['reports' => $reports];
        }

        $issue  = $this->app->scheduler()->fromIssueNumber($game, Validator::issueNumber($issueNumber));
        $report = $this->app->settlement()->settleIssue($game, $issue);
        $this->admin->audit($this->actor, 'settle.issue', $game->code, $report);

        return $report;
    }

    /** Run one full worker pass (draw + settle + copy trades) for every game. */
    public function runWorkerPass(): array
    {
        $settled = 0;
        $placed  = 0;

        foreach ($this->app->registry()->all() as $game) {
            foreach ($this->app->settlement()->settleDue($game, 10) as $report) {
                $settled += (int) $report['bets'];
            }
            $placed += (int) $this->app->follow()->runForGame($game)['placed'];
        }

        $this->admin->audit($this->actor, 'worker.pass', null, ['settledBets' => $settled, 'followBets' => $placed]);

        return ['settledBets' => $settled, 'followBets' => $placed, 'ranAt' => Clock::dateTime()];
    }

    /* ------------------------------------------------------------ bets */

    public function bets(): array
    {
        return $this->admin->bets(
            [
                'gameCode'    => Validator::optionalString($this->input, 'gameCode', '', 32),
                'issueNumber' => Validator::optionalString($this->input, 'issueNumber', '', 17),
                'status'      => Validator::optionalString($this->input, 'status', '', 12),
                'source'      => Validator::optionalString($this->input, 'source', '', 16),
                'userId'      => Validator::int($this->input, 'userId', 0, 0, PHP_INT_MAX),
            ],
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    /* ----------------------------------------------------------- users */

    public function users(): array
    {
        return $this->admin->users(
            Validator::optionalString($this->input, 'search', '', 32),
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    public function user(): array
    {
        return $this->admin->userDetail(Validator::int($this->input, 'userId', 0, 1, PHP_INT_MAX));
    }

    public function createUser(): array
    {
        return $this->admin->createUser(
            Validator::requireString($this->input, 'mobile', 20),
            Validator::optionalString($this->input, 'nickname', '', 64),
            (float) Validator::optionalString($this->input, 'balance', '0', 20),
            $this->actor
        );
    }

    public function adjustWallet(): array
    {
        return $this->admin->adjustWallet(
            Validator::int($this->input, 'userId', 0, 1, PHP_INT_MAX),
            Validator::amount($this->input, 'amount', 0.01, 100000000.0),
            strtolower(Validator::requireString($this->input, 'direction', 8)),
            Validator::optionalString($this->input, 'remark', 'admin adjustment', 191),
            $this->actor
        );
    }

    public function setUserStatus(): array
    {
        return $this->admin->setUserStatus(
            Validator::int($this->input, 'userId', 0, 1, PHP_INT_MAX),
            Validator::int($this->input, 'status', 1, 0, 1),
            $this->actor
        );
    }

    public function ledger(): array
    {
        return $this->admin->ledger(
            Validator::int($this->input, 'userId', 0, 0, PHP_INT_MAX),
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    /* ----------------------------------------------------------- plans */

    public function plans(): array
    {
        return $this->admin->planList();
    }

    public function savePlan(): array
    {
        return $this->admin->savePlan($this->input, $this->actor);
    }

    public function deletePlan(): array
    {
        return $this->admin->deletePlan(Validator::requireString($this->input, 'planCode', 48), $this->actor);
    }

    public function follows(): array
    {
        return $this->admin->subscriptions(
            Validator::optionalString($this->input, 'status', '', 12),
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    public function stopFollow(): array
    {
        return $this->admin->stopSubscription(
            Validator::int($this->input, 'followId', 0, 1, PHP_INT_MAX),
            $this->actor
        );
    }

    /* ------------------------------------------------------------- vip */

    public function vip(): array
    {
        return $this->admin->vipOverview(Validator::int($this->input, 'limit', 20, 1, 100));
    }

    public function backfillVip(): array
    {
        $userId = Validator::int($this->input, 'userId', 0, 1, PHP_INT_MAX);
        $result = $this->app->vip()->backfill($userId);

        $this->admin->audit($this->actor, 'vip.backfill', (string) $userId, [
            'experience' => Money::format((float) $result['experience']),
        ]);

        return $result + $this->app->vip()->status($userId);
    }

    /* --------------------------------------------- feed domain whitelist */

    public function domains(): array
    {
        return $this->admin->domains(
            Validator::optionalString($this->input, 'search', '', 64),
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 20, 1, 100)
        );
    }

    public function saveDomain(): array
    {
        return $this->admin->saveDomain($this->input, $this->actor);
    }

    public function setDomainStatus(): array
    {
        return $this->admin->setDomainStatus(
            Validator::int($this->input, 'id', 0, 1, PHP_INT_MAX),
            Validator::int($this->input, 'status', 1, 0, 1),
            $this->actor
        );
    }

    public function rotateDomainKey(): array
    {
        return $this->admin->rotateDomainKey(Validator::int($this->input, 'id', 0, 1, PHP_INT_MAX), $this->actor);
    }

    public function deleteDomain(): array
    {
        return $this->admin->deleteDomain(Validator::int($this->input, 'id', 0, 1, PHP_INT_MAX), $this->actor);
    }

    public function domainUsage(): array
    {
        return $this->admin->domainUsage(
            Validator::int($this->input, 'id', 0, 1, PHP_INT_MAX),
            Validator::int($this->input, 'days', 14, 1, 90)
        );
    }

    public function feedInfo(): array
    {
        return $this->admin->feedInfo($this->baseUrl());
    }

    /* ----------------------------------------------------------- audit */

    public function auditLog(): array
    {
        return $this->admin->auditLog(
            Validator::int($this->input, 'pageNo', 1, 1, 10000),
            Validator::int($this->input, 'pageSize', 30, 1, 100)
        );
    }

    /* --------------------------------------------------------- helpers */

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? $this->app->config('app.domain'));

        return $scheme . '://' . $host;
    }

    private function game()
    {
        return $this->app->registry()->get(
            Validator::gameCode(Validator::requireString($this->input, 'gameCode', 32))
        );
    }

    /** @return array<int,string> */
    public static function actions(): array
    {
        return [
            'ping', 'login', 'dashboard', 'system', 'games', 'exposure', 'results',
            'overrides', 'setoverride', 'canceloverride', 'settle', 'runworkerpass',
            'bets', 'users', 'user', 'createuser', 'adjustwallet', 'setuserstatus',
            'ledger', 'plans', 'saveplan', 'deleteplan', 'follows', 'stopfollow',
            'vip', 'backfillvip', 'auditlog',
            'domains', 'savedomain', 'setdomainstatus', 'rotatedomainkey',
            'deletedomain', 'domainusage', 'feedinfo',
        ];
    }

    public function handle(string $action, string $ip): array
    {
        switch ($action) {
            case 'ping':           return $this->ping();
            case 'login':          return $this->login($ip);
            case 'dashboard':      return $this->dashboard();
            case 'system':         return $this->system();
            case 'games':          return $this->games();
            case 'exposure':       return $this->exposure();
            case 'results':        return $this->results();
            case 'overrides':      return $this->overrides();
            case 'setoverride':    return $this->setOverride();
            case 'canceloverride': return $this->cancelOverride();
            case 'settle':         return $this->settle();
            case 'runworkerpass':  return $this->runWorkerPass();
            case 'bets':           return $this->bets();
            case 'users':          return $this->users();
            case 'user':           return $this->user();
            case 'createuser':     return $this->createUser();
            case 'adjustwallet':   return $this->adjustWallet();
            case 'setuserstatus':  return $this->setUserStatus();
            case 'ledger':         return $this->ledger();
            case 'plans':          return $this->plans();
            case 'saveplan':       return $this->savePlan();
            case 'deleteplan':     return $this->deletePlan();
            case 'follows':        return $this->follows();
            case 'stopfollow':     return $this->stopFollow();
            case 'vip':            return $this->vip();
            case 'backfillvip':    return $this->backfillVip();
            case 'auditlog':       return $this->auditLog();
            case 'domains':          return $this->domains();
            case 'savedomain':       return $this->saveDomain();
            case 'setdomainstatus':  return $this->setDomainStatus();
            case 'rotatedomainkey':  return $this->rotateDomainKey();
            case 'deletedomain':     return $this->deleteDomain();
            case 'domainusage':      return $this->domainUsage();
            case 'feedinfo':         return $this->feedInfo();
        }

        throw ApiException::notFound("Unknown admin action: {$action}");
    }
}
