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
TestRunner::equals('their API was asked once', 1, $stub->calls);
TestRunner::equals('the token was forwarded', $siteToken, $stub->seenTokens[0]);

$again = $partners->resolveIntrospectedToken($siteToken, 'dhaniwin.club9.eu.cc');
TestRunner::equals('the same player comes back', $resolved['id'], $again['id']);
TestRunner::equals('the answer is cached, their API is not called again', 1, $stub->calls);

Clock::freeze(Clock::now() + 400);   // past validate_ttl
$partners->resolveIntrospectedToken($siteToken, 'dhaniwin.club9.eu.cc');
TestRunner::equals('after the cache expires their API is asked again', 2, $stub->calls);
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
