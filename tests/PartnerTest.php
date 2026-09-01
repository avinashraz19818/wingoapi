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
