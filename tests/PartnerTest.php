<?php

declare(strict_types=1);

use Lottery\Api\Kernel;
use Lottery\Auth\Jwt;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Response;

TestRunner::group('Partner sites — login & user mapping');

$app     = makeTestApp();
$kernel  = new Kernel($app);
$domains = $app->domains();

$site  = $domains->create('dhaniwin.club9.eu.cc', 'Dhani Win', [], 'partner site');
$other = $domains->create('another-site.com', 'Other', [], '');

$call = static function (string $action, array $params = [], string $method = 'POST', array $server = []) use ($kernel): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    foreach (['HTTP_X_API_KEY', 'HTTP_AUTHORIZATION', 'HTTP_ORIGIN'] as $k) {
        unset($_SERVER[$k]);
    }
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    try {
        return Response::success($kernel->dispatch(strtolower($action), $params + ['action' => $action]));
    } catch (ApiException $e) {
        return Response::error($e->getMessage(), $e->getCode(), $e->msgCode());
    }
};

$key      = ['HTTP_X_API_KEY' => $site['apiKey']];
$otherKey = ['HTTP_X_API_KEY' => $other['apiKey']];

Clock::freeze(strtotime('2026-09-01 12:00:10'));

TestRunner::equals('partner call without a key is refused', 'API_KEY_REQUIRED',
    $call('PartnerLogin', ['externalUserId' => '1001'])['msgCode']);
TestRunner::equals('unknown key is refused', 'DOMAIN_NOT_ALLOWED',
    $call('PartnerLogin', ['externalUserId' => '1001'], 'POST', ['HTTP_X_API_KEY' => str_repeat('a', 32)])['msgCode']);

$login = $call('PartnerLogin', ['externalUserId' => '1001', 'nickname' => 'Ravi'], 'POST', $key);
TestRunner::equals('partner login succeeds', 0, $login['code']);
TestRunner::ok('a player token is issued', strlen((string) $login['data']['token']) > 40);
TestRunner::equals('their id is echoed back', '1001', $login['data']['externalUserId']);
TestRunner::equals('game wallet starts empty', '0.00', $login['data']['balance']);
$userId = (int) $login['data']['userId'];

$again = $call('PartnerLogin', ['externalUserId' => '1001'], 'POST', $key);
TestRunner::equals('same external id maps to the same player', $userId, $again['data']['userId']);

$twin = $call('PartnerLogin', ['externalUserId' => '1001'], 'POST', $otherKey);
TestRunner::ok('the same id on another site is a different player', (int) $twin['data']['userId'] !== $userId);

TestRunner::equals('externalUserId is required', 'VALIDATION_ERROR',
    $call('PartnerLogin', [], 'POST', $key)['msgCode']);
TestRunner::equals('userId alias accepted', 0,
    $call('PartnerLogin', ['userId' => '2002'], 'POST', $key)['code']);

$token = (string) $login['data']['token'];

TestRunner::group('Partner sites — transfers');

$in = $call('PartnerTransfer', [
    'externalUserId' => '1001', 'amount' => '500', 'direction' => 'in', 'orderId' => 'TXN-1',
], 'POST', $key);
TestRunner::equals('transfer in credits the game wallet', '500.00', $in['data']['balance']);
TestRunner::ok('transfer applied', $in['data']['applied'] === true);

$replay = $call('PartnerTransfer', [
    'externalUserId' => '1001', 'amount' => '500', 'direction' => 'in', 'orderId' => 'TXN-1',
], 'POST', $key);
TestRunner::ok('replaying an orderId is a no-op', $replay['data']['duplicate'] === true);
TestRunner::equals('balance unchanged after the replay', '500.00',
    $call('PartnerBalance', ['externalUserId' => '1001'], 'GET', $key)['data']['balance']);

$out = $call('PartnerTransfer', [
    'externalUserId' => '1001', 'amount' => '200', 'direction' => 'out', 'orderId' => 'TXN-2',
], 'POST', $key);
TestRunner::equals('transfer out debits the game wallet', '300.00', $out['data']['balance']);

TestRunner::equals('over-withdrawal refused', 'INSUFFICIENT_BALANCE',
    $call('PartnerTransfer', ['externalUserId' => '1001', 'amount' => '99999', 'direction' => 'out', 'orderId' => 'TXN-3'], 'POST', $key)['msgCode']);
TestRunner::equals('orderId is mandatory', 'VALIDATION_ERROR',
    $call('PartnerTransfer', ['externalUserId' => '1001', 'amount' => '10', 'direction' => 'in'], 'POST', $key)['msgCode']);
TestRunner::equals('direction is validated', 'VALIDATION_ERROR',
    $call('PartnerTransfer', ['externalUserId' => '1001', 'amount' => '10', 'direction' => 'sideways', 'orderId' => 'TXN-4'], 'POST', $key)['msgCode']);
TestRunner::equals('another site cannot touch that wallet', '0.00',
    $call('PartnerBalance', ['externalUserId' => '1001'], 'GET', $otherKey)['data']['balance']);

TestRunner::group('Partner sites — playing with the issued token');

$bet = $call('WinGoBet', [
    'gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 100,
], 'POST', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
TestRunner::equals('partner user can bet', true, $bet['data']['accepted']);
TestRunner::equals('balance after the bet', '200.00', $bet['data']['balance']);

$balance = $call('GetBalance', [], 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
TestRunner::equals('GetBalance works for a partner user', '200.00', $balance['data']['balance']);

$partnerBets = $call('PartnerBets', ['externalUserId' => '1001'], 'GET', $key);
TestRunner::equals('the site can read its user bets', 1, $partnerBets['data']['totalCount']);
TestRunner::equals('bet belongs to that player', $userId, $partnerBets['data']['userId']);

TestRunner::group('Partner sites — accepting the site\'s own tokens');

$secret = 'partner-shared-secret-123';
$app->db()->execute('UPDATE lot_domains SET player_secret = ? WHERE id = ?', [$secret, $site['id']]);

$theirToken = (new Jwt($secret, 3600))->issue(7788, 'their-user', null, ['externalId' => '7788', 'nickname' => 'Amit']);

$viaTheirToken = $call('GetBalance', [], 'GET', [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $theirToken,
    'HTTP_ORIGIN'        => 'https://dhaniwin.club9.eu.cc',
]);
TestRunner::equals('a token signed by the partner is accepted', 0, $viaTheirToken['code']);

$fromWrongOrigin = $call('GetBalance', [], 'GET', [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $theirToken,
    'HTTP_ORIGIN'        => 'https://copycat.com',
]);
TestRunner::equals('the same token from another origin is refused', 'AUTH_REQUIRED', $fromWrongOrigin['msgCode']);

$forged = (new Jwt('wrong-secret', 3600))->issue(7788, 'their-user', null, ['externalId' => '7788']);
TestRunner::equals('a forged partner token is refused', 'AUTH_REQUIRED', $call('GetBalance', [], 'GET', [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $forged,
    'HTTP_ORIGIN'        => 'https://dhaniwin.club9.eu.cc',
])['msgCode']);

// Their user now exists locally and can receive a transfer
$topUp = $call('PartnerTransfer', [
    'externalUserId' => '7788', 'amount' => '50', 'direction' => 'in', 'orderId' => 'TXN-7788',
], 'POST', $key);
TestRunner::equals('their mapped user can be funded', '50.00', $topUp['data']['balance']);

Clock::unfreeze();

TestRunner::group('Partner sites — verifying their own opaque tokens');

$introApp = makeTestApp();
$introKernel = new Kernel($introApp);
$introDomains = $introApp->domains();

$partnerSite = $introDomains->create('dhaniwin.club9.eu.cc', 'Dhani', [], '');
$introDomains->update($partnerSite['id'], [
    'validateUrl'    => 'https://dhaniwin.club9.eu.cc/api/User/GetUserInfo',
    'validateMethod' => 'POST',
    'validateTtl'    => 300,
]);

$stored = $introDomains->find($partnerSite['id']);
TestRunner::equals('token check URL saved', 'https://dhaniwin.club9.eu.cc/api/User/GetUserInfo', $stored['validate_url']);
TestRunner::equals('method saved', 'POST', $stored['validate_method']);

TestRunner::throws('a bad URL is refused', static fn() => $introDomains->update($partnerSite['id'], ['validateUrl' => 'not-a-url']), 'full URL');

// Their user endpoint, in the shape these platforms actually answer with.
final class PartnerApiStub extends \Lottery\Support\Http
{
    public int $calls = 0;
    public array $seenTokens = [];
    private array $users;
    public function __construct(array $users) { parent::__construct(5); $this->users = $users; }

    public function postArray(string $url, array $body, array $headers = []): ?array
    {
        $this->calls++;
        $token = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Authorization: Bearer ') === 0) {
                $token = substr($header, 22);
            }
        }
        $this->seenTokens[] = $token;

        if (!isset($this->users[$token])) {
            return ['code' => 1, 'msg' => 'token invalid', 'data' => null];
        }

        return ['code' => 0, 'msg' => 'success', 'data' => [
            'userId'   => $this->users[$token],
            'userName' => 'Player ' . $this->users[$token],
            'amount'   => '1500.00',
        ]];
    }
}

TestRunner::equals('user id found in a nested envelope', '99887',
    \Lottery\Tenant\PartnerService::findUserId(['code' => 0, 'data' => ['userId' => 99887, 'nickName' => 'x']]));
TestRunner::equals('uid is understood too', '5150',
    \Lottery\Tenant\PartnerService::findUserId(['data' => ['userInfo' => ['uid' => '5150']]]));
TestRunner::ok('an empty envelope yields nothing',
    \Lottery\Tenant\PartnerService::findUserId(['code' => 0, 'data' => []]) === null);

Clock::freeze(strtotime('2026-09-01 12:00:00'));

$siteToken = 'local_6bb2051b34d06e9995ea0e5b2f8b140eaee7510';
$stub      = new PartnerApiStub([$siteToken => 4242]);

$partners = new \Lottery\Tenant\PartnerService(
    $introApp->db(), $introDomains, $introApp->jwt(), $introApp->wallet(), $introApp->vip(), $stub
);

$resolved = $partners->resolveIntrospectedToken($siteToken, 'dhaniwin.club9.eu.cc');
TestRunner::ok('their token identifies the player', $resolved !== null && $resolved['id'] > 0);
// One probe (is this endpoint safe?) plus the real lookup.
TestRunner::equals('their API was asked twice: probe + lookup', 2, $stub->calls);
TestRunner::ok('the real token was forwarded', in_array($siteToken, $stub->seenTokens, true));

$again = $partners->resolveIntrospectedToken($siteToken, 'dhaniwin.club9.eu.cc');
TestRunner::equals('the same player comes back', $resolved['id'], $again['id']);
TestRunner::equals('the answer is cached, their API is not called again', 2, $stub->calls);

Clock::freeze(Clock::now() + 400);   // past validate_ttl
$partners->resolveIntrospectedToken($siteToken, 'dhaniwin.club9.eu.cc');
TestRunner::equals('after the cache expires their API is asked again', 3, $stub->calls);
Clock::freeze(strtotime('2026-09-01 12:00:00'));

TestRunner::ok('an unknown token is refused',
    $partners->resolveIntrospectedToken('local_garbage', 'dhaniwin.club9.eu.cc') === null);
TestRunner::ok('the token is useless from another origin',
    $partners->resolveIntrospectedToken($siteToken, 'copycat.com') === null);

// The mapped player behaves like any other: fund and bet
$mapped = $resolved['id'];
$introApp->wallet()->credit($mapped, 300, 'intro:1', 'transfer_in', null, 'test');
TestRunner::equals('their user can be funded', 300.0, $introApp->wallet()->balance($mapped));

$transfer = $partners->transfer($introDomains->find($partnerSite['id']), '4242', 200, 'in', 'ORD-1');
TestRunner::equals('transfers reach the same player', '500.00', $transfer['balance']);

Clock::unfreeze();

TestRunner::group('Partner sites — Whoami understands partner tokens');

$whoApp = makeTestApp();
$whoKernel = new Kernel($whoApp);
$whoDomain = $whoApp->domains()->create('dhaniwin.club9.eu.cc', 'Dhani', [], '');
$whoApp->domains()->update($whoDomain['id'], [
    'validateUrl'    => 'https://dhaniwin.club9.eu.cc/api/User/GetUserInfo',
    'validateMethod' => 'POST',
]);

/** Answers in the exact shape the live site returned. */
final class LiveShapeStub extends \Lottery\Support\Http
{
    public function __construct() { parent::__construct(5); }
    public function postArray(string $url, array $body, array $headers = []): ?array
    {
        foreach ($headers as $h) {
            if ($h === 'Authorization: Bearer local_6bb2051b34d06e9995ea0e5b2f8b140eaee7510') {
                return ['data' => [
                    'userId'   => 132257,
                    'nickName' => 'MemberNNGKLPHA',
                    'vipLevel' => 0,
                    'walletBalance' => 0,
                ]];
            }
        }
        return ['data' => null, 'code' => 1];
    }
}

$liveStub = new LiveShapeStub();
$livePartners = new \Lottery\Tenant\PartnerService(
    $whoApp->db(), $whoApp->domains(), $whoApp->jwt(), $whoApp->wallet(), $whoApp->vip(), $liveStub
);

$live = $livePartners->resolveIntrospectedToken('local_6bb2051b34d06e9995ea0e5b2f8b140eaee7510', 'dhaniwin.club9.eu.cc');
TestRunner::ok('the real response shape resolves a player', $live !== null && $live['id'] > 0);
TestRunner::equals('mapped to their user id', 'p' . $whoDomain['id'] . '_132257', $live['mobile']);

$noUser = $livePartners->resolveIntrospectedToken('local_expired_one', 'dhaniwin.club9.eu.cc');
TestRunner::ok('an unknown token stays unauthenticated', $noUser === null);

// Whoami now explains what is missing instead of "Malformed token"
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer local_something';
unset($_SERVER['HTTP_ORIGIN']);
$diag = (new \Lottery\Api\LotteryController($whoApp, ['action' => 'Whoami']))->whoami();
TestRunner::ok('Whoami reports the token arrived', $diag['tokenReceived'] === true);
TestRunner::ok('Whoami no longer says "Malformed token"', !str_contains($diag['hint'], 'Malformed'));
TestRunner::ok('Whoami asks for an Origin header', str_contains($diag['hint'], 'Origin'));

$_SERVER['HTTP_ORIGIN'] = 'https://not-whitelisted.com';
$diag2 = (new \Lottery\Api\LotteryController($whoApp, ['action' => 'Whoami']))->whoami();
TestRunner::ok('Whoami names an unlisted domain', str_contains($diag2['hint'], 'not whitelisted'));
unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_AUTHORIZATION']);

TestRunner::group('Partner sites — every player must resolve to themselves');

$idApp     = makeTestApp();
$idDomains = $idApp->domains();
$idSite    = $idDomains->create('dhaniwin.club9.eu.cc', 'Dhani', [], '');

/**
 * The shape that caused the bug: the site answers with its *first* user
 * whenever the token is unknown, so every visitor looked like the same player.
 */
final class LenientSiteStub extends \Lottery\Support\Http
{
    public function __construct() { parent::__construct(5); }
    public function postArray(string $url, array $body, array $headers = []): ?array
    {
        return ['data' => ['userId' => 132257, 'nickName' => 'FirstUser']];
    }
}

$idDomains->update($idSite['id'], [
    'validateUrl'    => 'https://dhaniwin.club9.eu.cc/api/User/GetUserInfo',
    'validateMethod' => 'POST',
]);

$lenient = new \Lottery\Tenant\PartnerService(
    $idApp->db(), $idDomains, $idApp->jwt(), $idApp->wallet(), $idApp->vip(), new LenientSiteStub()
);

TestRunner::ok('a non-discriminating endpoint is refused',
    $lenient->resolveIntrospectedToken('local_player_one', 'dhaniwin.club9.eu.cc') === null);
TestRunner::ok('…for every token, not just one',
    $lenient->resolveIntrospectedToken('local_player_two', 'dhaniwin.club9.eu.cc') === null);

// The accurate path: the site resolved the player itself and says who it is.
$server = ['HTTP_X_API_KEY' => $idSite['apiKey']];

$one = $idApp->partners()->resolveTrustedHeaderUser($server + ['HTTP_X_PLAYER_ID' => '132257'], []);
$two = $idApp->partners()->resolveTrustedHeaderUser($server + ['HTTP_X_PLAYER_ID' => '999888'], []);

TestRunner::ok('player one resolves', $one !== null);
TestRunner::ok('player two resolves', $two !== null);
TestRunner::ok('and they are different players', $one['id'] !== $two['id']);
TestRunner::equals('ids are namespaced per site', 'p' . $idSite['id'] . '_132257', $one['mobile']);

$again = $idApp->partners()->resolveTrustedHeaderUser($server + ['HTTP_X_PLAYER_ID' => '132257'], []);
TestRunner::equals('the same id always maps to the same player', $one['id'], $again['id']);

TestRunner::ok('no API key means no trust',
    $idApp->partners()->resolveTrustedHeaderUser(['HTTP_X_PLAYER_ID' => '132257'], []) === null);
TestRunner::ok('a wrong API key means no trust',
    $idApp->partners()->resolveTrustedHeaderUser(['HTTP_X_API_KEY' => str_repeat('b', 32), 'HTTP_X_PLAYER_ID' => '1'], []) === null);
TestRunner::ok('no player id means no trust',
    $idApp->partners()->resolveTrustedHeaderUser($server, []) === null);

// Balances stay separate — the symptom that started this.
$idApp->wallet()->credit($one['id'], 1500, 'sep:1', 'transfer_in', null, 'test');
TestRunner::nearly('player one has their own balance', 1500.0, $idApp->wallet()->balance($one['id']));
TestRunner::nearly('player two starts at zero', 0.0, $idApp->wallet()->balance($two['id']));

// End to end through the API.
$idKernel = new Kernel($idApp);
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_X_API_KEY']  = $idSite['apiKey'];
unset($_SERVER['HTTP_AUTHORIZATION']);

$_SERVER['HTTP_X_PLAYER_ID'] = '132257';
$balanceOne = $idKernel->dispatch('getbalance', ['action' => 'GetBalance']);
TestRunner::equals('player one sees their balance', '1500.00', $balanceOne['balance']);

unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_X_PLAYER_ID']);
