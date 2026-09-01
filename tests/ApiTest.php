<?php

declare(strict_types=1);

use Lottery\Api\Kernel;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Response;
use Lottery\Support\Validator;

TestRunner::group('API — dispatch & envelope');

$app    = makeTestApp();
$kernel = new Kernel($app);

Clock::freeze(strtotime('2026-08-31 12:00:10'));

/** Call an action the way the kernel does, and wrap it in the envelope. */
$call = static function (string $action, array $params = [], string $method = 'GET', ?string $token = null) use ($kernel): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_AUTHORIZATION']);
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    try {
        return Response::success($kernel->dispatch($action, $params + ['action' => $action]));
    } catch (ApiException $e) {
        return Response::error($e->getMessage(), $e->getCode(), $e->msgCode());
    }
};

$envelope = $call('GetGameList');
TestRunner::ok('envelope has all five keys', array_keys($envelope) === ['data', 'code', 'msg', 'msgCode', 'serviceTime']);
TestRunner::equals('success code is 0', 0, $envelope['code']);
TestRunner::equals('success msgCode', 'SUCCESS', $envelope['msgCode']);
TestRunner::ok('serviceTime is epoch millis', $envelope['serviceTime'] > 1000000000000);

$groups = $envelope['data']['groups'];
TestRunner::equals('five game families listed', 5, count($groups));
TestRunner::equals('WinGo listed first', 'WinGo', $groups[0]['lottery']);
TestRunner::equals('WinGo has five intervals', 5, count($groups[0]['intervals']));
TestRunner::ok('intervals expose rates', isset($groups[0]['intervals'][0]['rates']['number']['odds']));
TestRunner::ok('intervals expose state', $groups[0]['intervals'][0]['state'] === 1);
TestRunner::ok('intervals expose the live issue', isset($groups[0]['intervals'][0]['currentIssue']['issueNumber']));

$info = $call('GetGameInfo', ['gameCode' => 'WinGo_1M'])['data'];
TestRunner::equals('game info code', 'WinGo_1M', $info['gameCode']);
TestRunner::equals('bet scopes exposed', [1, 10, 100, 1000], $info['betScopes']);
TestRunner::equals('multiples exposed', [1, 2, 3, 5, 10, 20, 50, 100], $info['multiples']);
TestRunner::equals('min stake', '1.00', $info['limits']['minStake']);
TestRunner::equals('max stake ₹10L', '1000000.00', $info['limits']['maxStake']);
TestRunner::equals('payout tax 2%', 0.02, $info['limits']['payoutTaxRate']);
TestRunner::equals('number odds', 9.0, $info['rates']['number']['odds']);
TestRunner::ok('bet types listed', count($info['betTypes']) === 4);

$k3Info = $call('GetGameInfo', ['gameCode' => 'K3_5M'])['data'];
TestRunner::ok('K3 exposes its odds map', isset($k3Info['rates']['total']['oddsMap']['3']));

$notFound = $call('GetGameInfo', ['gameCode' => 'Nope_1M']);
TestRunner::equals('unknown game -> NOT_FOUND', 'NOT_FOUND', $notFound['msgCode']);
TestRunner::equals('unknown game code value', Response::ERR_NOT_FOUND, $notFound['code']);

$badAction = $call('Explode');
TestRunner::equals('unknown action rejected', 'NOT_FOUND', $badAction['msgCode']);

$missing = $call('GetGameInfo');
TestRunner::equals('missing gameCode -> validation error', 'VALIDATION_ERROR', $missing['msgCode']);

TestRunner::group('API — auth & method enforcement');

$anon = $call('GetBalance');
TestRunner::equals('protected action needs a token', 'AUTH_REQUIRED', $anon['msgCode']);

$token = $app->jwt()->issue(8001, '9998887776');
$balance = $call('GetBalance', [], 'GET', $token);
TestRunner::equals('balance for a fresh user', '0.00', $balance['data']['balance']);
TestRunner::equals('balance includes VIP status', 0, $balance['data']['vip']['level']);

$getBet = $call('WinGoBet', ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 10], 'GET', $token);
TestRunner::equals('write action rejects GET', 'METHOD_NOT_ALLOWED', $getBet['msgCode']);

$adminNoToken = $call('SetResultOverride', ['gameCode' => 'WinGo_1M', 'value' => '5'], 'POST', $token);
TestRunner::equals('admin action needs the admin token', 'AUTH_REQUIRED', $adminNoToken['msgCode']);

TestRunner::group('API — full betting round');

fundWallet($app, 8001, 5000.0);

$bet = $call('WinGoBet', [
    'gameCode'        => 'WinGo_1M',
    'betType'         => 'color',
    'betContent'      => 'green',
    'amount'          => 100,
    'multiplier'      => 1,
    'requestGroupKey' => 'api-grp',
    'requestKey'      => 'api-req-1',
], 'POST', $token);

TestRunner::equals('bet accepted through the API', true, $bet['data']['accepted']);
TestRunner::equals('response exposes betId', true, $bet['data']['betId'] > 0);
TestRunner::equals('response exposes balance', '4900.00', $bet['data']['balance']);
TestRunner::equals('response exposes stake', '100.00', $bet['data']['stake']);
TestRunner::equals('response exposes vipExperienceAdded', '100.00', $bet['data']['vipExperienceAdded']);

$replay = $call('WinGoBet', [
    'gameCode'        => 'WinGo_1M',
    'betType'         => 'color',
    'betContent'      => 'green',
    'amount'          => 100,
    'multiplier'      => 1,
    'requestGroupKey' => 'api-grp',
    'requestKey'      => 'api-req-1',
], 'POST', $token);
TestRunner::equals('idempotent replay through the API', $bet['data']['betId'], $replay['data']['betId']);
TestRunner::equals('replay does not deduct again', '4900.00', $call('GetBalance', [], 'GET', $token)['data']['balance']);

$records = $call('GetRecordPage', ['gameCode' => 'WinGo_1M', 'pageNo' => 1, 'pageSize' => 10], 'GET', $token);
TestRunner::equals('record page total', 1, $records['data']['totalCount']);
TestRunner::equals('record page size echoed', 10, $records['data']['pageSize']);
TestRunner::equals('record row status', 'pending', $records['data']['list'][0]['status']);

// Settle the round and read history / win-loss
$issueNumber = $bet['data']['issueNumber'];
Clock::freeze(strtotime('2026-08-31 12:01:05'));

$history = $call('GetHistoryIssuePage', ['gameCode' => 'WinGo_1M', 'pageNo' => 1, 'pageSize' => 10]);
TestRunner::ok('history contains the finished round', $history['data']['totalCount'] >= 1);
TestRunner::equals('history newest issue', $issueNumber, $history['data']['list'][0]['issueNumber']);
TestRunner::ok('history row has a number', is_int($history['data']['list'][0]['number']));

$winLoss = $call('GetWinLossResult', ['gameCode' => 'WinGo_1M', 'issueNumber' => $issueNumber], 'GET', $token);
TestRunner::ok('win/loss resolved', in_array($winLoss['data']['status'], ['won', 'lost'], true));
TestRunner::equals('win/loss counts the bet', 1, $winLoss['data']['betCount']);
TestRunner::equals('win/loss stake', '100.00', $winLoss['data']['stake']);

$trend = $call('GetTrendStatistics', ['gameCode' => 'WinGo_1M']);
TestRunner::equals('trend window default', 100, $trend['data']['window']);
TestRunner::equals('trend positions for WinGo', 10, count($trend['data']['positions']['number']));
TestRunner::ok('trend rows carry all metrics', array_keys($trend['data']['positions']['number'][0]) === ['value', 'missing', 'openCount', 'maxContinuous', 'currentStreak']);

TestRunner::group('API — follow endpoints');

$plans = $call('GetFollowPlanList');
TestRunner::ok('plan list returned', count($plans['data']['list']) >= 5);

$follow = $call('AddFollowRecord', ['planCode' => 'wingo1m-bigsmall-big', 'amount' => 10, 'rounds' => 5], 'POST', $token);
TestRunner::equals('follow started', 'active', $follow['data']['status']);
TestRunner::equals('follow rounds recorded', 5, $follow['data']['totalRounds']);

$mine = $call('GetMyFollowRecords', [], 'GET', $token);
TestRunner::equals('follow record listed', 1, count($mine['data']['list']));

$stop = $call('StopFollowRecord', ['followId' => $follow['data']['followId']], 'POST', $token);
TestRunner::equals('follow stopped', 'stopped', $stop['data']['status']);

TestRunner::group('API — admin override endpoint');

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
$override = $call('SetResultOverride', [
    'gameCode' => 'WinGo_1M', 'value' => '8', 'mode' => 'oneshot',
], 'POST', $token);
TestRunner::equals('override queued via API', 'pending', $override['data']['status']);
TestRunner::equals('legacy placeholder issue used', '00000000000000000', $override['data']['issueNumber']);

$list = $call('ListResultOverrides', [], 'GET', $token);
TestRunner::equals('override listed', 1, count($list['data']['list']));

$cancel = $call('CancelResultOverride', ['gameCode' => 'WinGo_1M'], 'POST', $token);
TestRunner::equals('override cancelled', true, $cancel['data']['cancelled']);

$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'wrong';
$badAdmin = $call('SetResultOverride', ['gameCode' => 'WinGo_1M', 'value' => '8'], 'POST', $token);
TestRunner::equals('wrong admin token rejected', 'AUTH_REQUIRED', $badAdmin['msgCode']);
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);

TestRunner::group('API — signature enforcement');

$signedApp    = makeTestApp(['auth' => ['require_signature' => true]]);
$signedKernel = new Kernel($signedApp);
$signedToken  = $signedApp->jwt()->issue(8002, '1112223334');
fundWallet($signedApp, 8002, 1000.0);

$_SERVER['REQUEST_METHOD']     = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $signedToken;

$params = [
    'action'     => 'WinGoBet',
    'gameCode'   => 'WinGo_1M',
    'betType'    => 'size',
    'betContent' => 'big',
    'amount'     => '10',
    'timestamp'  => (string) Clock::now(),
];

try {
    $signedKernel->dispatch('WinGoBet', $params);
    TestRunner::ok('unsigned request rejected', false, 'no exception');
} catch (ApiException $e) {
    TestRunner::equals('unsigned request rejected', 'INVALID_SIGNATURE', $e->msgCode());
}

$params['signature'] = $signedApp->signature()->calculate($params);
$signedResult = $signedKernel->dispatch('WinGoBet', $params);
TestRunner::equals('signed request accepted', true, $signedResult['accepted']);

TestRunner::group('API — input validation guards');

TestRunner::throws('issue number must be 17 digits', static fn() => Validator::issueNumber('123'), 'Invalid issue number');
TestRunner::throws('game code shape enforced', static fn() => Validator::gameCode('WinGo 1M;DROP TABLE'), 'Invalid gameCode');
TestRunner::throws('bet content rejects sql payloads', static fn() => Validator::betContent("1' OR '1'='1"), 'Invalid bet content');
TestRunner::throws('page size upper bound', static fn() => Validator::int(['pageSize' => 5000], 'pageSize', 10, 1, 100), 'between 1 and 100');
TestRunner::equals('page size default applied', 10, Validator::int([], 'pageSize', 10, 1, 100));
TestRunner::throws('amount must be numeric', static fn() => Validator::amount(['amount' => 'abc'], 'amount', 1, 10), 'must be a number');

TestRunner::group('API — rate limiting');

$limiter = $app->rateLimiter();
$key     = 'test-ip-' . bin2hex(random_bytes(4));
$allowed = 0;
for ($i = 0; $i < 125; $i++) {
    if ($limiter->hit($key)['allowed']) {
        $allowed++;
    }
}
TestRunner::equals('120 requests per minute allowed', 120, $allowed);
TestRunner::ok('further requests blocked', !$limiter->hit($key)['allowed']);
TestRunner::ok('a different client is unaffected', $limiter->hit($key . '-other')['allowed']);

TestRunner::group('API — health & schema');

$health = $call('Health')['data'];
TestRunner::equals('health status', 'ok', $health['status']);
TestRunner::equals('schema at latest version', $app->migrator()->latestVersion(), $health['schemaVersion']);
TestRunner::equals('all games registered', 21, $health['games']);

Clock::unfreeze();
