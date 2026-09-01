<?php

declare(strict_types=1);

use Lottery\Api\AdminKernel;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Response;

TestRunner::group('Admin panel — authentication');

$app = makeTestApp(['admin' => ['password' => 'panel-secret', 'user' => 'admin', 'enabled' => true]]);
$kernel = new AdminKernel($app);

$callRaw = static function (string $action, array $params = [], string $method = 'POST', ?string $token = null) use ($kernel): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_ADMIN_TOKEN']);
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    try {
        return Response::success($kernel->dispatch(strtolower($action), $params + ['action' => $action], '127.0.0.1'));
    } catch (ApiException $e) {
        return Response::error($e->getMessage(), $e->getCode(), $e->msgCode());
    }
};

$ping = $callRaw('ping', [], 'GET');
TestRunner::equals('ping is public', 0, $ping['code']);
TestRunner::ok('ping reports the panel is enabled', $ping['data']['panel'] === true);

$bad = $callRaw('login', ['user' => 'admin', 'password' => 'wrong']);
TestRunner::equals('wrong password rejected', 'AUTH_REQUIRED', $bad['msgCode']);

$badUser = $callRaw('login', ['user' => 'root', 'password' => 'panel-secret']);
TestRunner::equals('wrong user rejected', 'AUTH_REQUIRED', $badUser['msgCode']);

$login = $callRaw('login', ['user' => 'admin', 'password' => 'panel-secret']);
TestRunner::equals('login succeeds', 0, $login['code']);
TestRunner::ok('session token issued', is_string($login['data']['token']) && $login['data']['token'] !== '');
TestRunner::ok('login returns system info', isset($login['data']['system']['schemaVersion']));

$token = $login['data']['token'];

TestRunner::equals('protected action needs a session', 'AUTH_REQUIRED', $callRaw('dashboard', [], 'GET')['msgCode']);
TestRunner::equals('dashboard works with the session', 0, $callRaw('dashboard', [], 'GET', $token)['code']);

// A player JWT must never unlock the admin API.
$playerToken = $app->jwt()->issue(9101, '9990001111');
TestRunner::equals('player JWT rejected by admin API', 'AUTH_REQUIRED', $callRaw('dashboard', [], 'GET', $playerToken)['msgCode']);

// The static machine token still works.
$_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
$_SERVER['REQUEST_METHOD']     = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION']);
TestRunner::ok('X-Admin-Token still authorises', $kernel->dispatch('system', ['action' => 'system'], '127.0.0.1')['schemaVersion'] > 0);
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);

TestRunner::equals('write action rejects GET', 'METHOD_NOT_ALLOWED', $callRaw('adjustwallet', ['userId' => 1, 'amount' => 1, 'direction' => 'credit'], 'GET', $token)['msgCode']);
TestRunner::equals('unknown admin action rejected', 'NOT_FOUND', $callRaw('dropdatabase', [], 'GET', $token)['msgCode']);

$disabled = new AdminKernel(makeTestApp(['admin' => ['password' => '', 'enabled' => false]]));
try {
    $disabled->dispatch('login', ['action' => 'login', 'password' => 'x'], '127.0.0.1');
    TestRunner::ok('disabled panel refuses login', false, 'no exception');
} catch (ApiException $e) {
    TestRunner::ok('disabled panel refuses login', true);
}

TestRunner::group('Admin panel — users & wallets');

$app2   = makeTestApp(['admin' => ['password' => 'panel-secret']]);
$k2     = new AdminKernel($app2);
$call   = static function (string $action, array $params = [], string $method = 'POST') use ($k2, $app2): array {
    $_SERVER['REQUEST_METHOD']     = $method;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $GLOBALS['adminToken2'];
    return $k2->dispatch(strtolower($action), $params + ['action' => $action], '10.0.0.1');
};
$GLOBALS['adminToken2'] = $app2->adminAuth()->login('admin', 'panel-secret')['token'];

Clock::freeze(strtotime('2026-08-31 12:00:10'));

$created = $call('createuser', ['mobile' => '9812345678', 'nickname' => 'Tester', 'balance' => '500']);
TestRunner::ok('user created', $created['userId'] > 0);
TestRunner::ok('a player JWT is returned for the new user', str_contains($created['token'], '.'));
TestRunner::equals('opening balance credited', '500.00', $app2->wallet()->snapshot($created['userId'])['balance']);

$userId = (int) $created['userId'];

TestRunner::throws('duplicate mobile rejected', static fn() => $call('createuser', ['mobile' => '9812345678']), 'already exists');
TestRunner::throws('invalid mobile rejected', static fn() => $call('createuser', ['mobile' => 'not-a-number']), 'Invalid mobile');

$credit = $call('adjustwallet', ['userId' => $userId, 'amount' => '250.50', 'direction' => 'credit', 'remark' => 'bonus']);
TestRunner::equals('manual credit applied', '750.50', $credit['balance']);

$debit = $call('adjustwallet', ['userId' => $userId, 'amount' => '50', 'direction' => 'debit', 'remark' => 'correction']);
TestRunner::equals('manual debit applied', '700.50', $debit['balance']);

TestRunner::throws('over-debit rejected', static fn() => $call('adjustwallet', ['userId' => $userId, 'amount' => '99999', 'direction' => 'debit']), 'Insufficient balance');
TestRunner::throws('bad direction rejected', static fn() => $call('adjustwallet', ['userId' => $userId, 'amount' => '10', 'direction' => 'steal']), 'credit or debit');
TestRunner::throws('adjusting an unknown user fails', static fn() => $call('adjustwallet', ['userId' => 987654, 'amount' => '10', 'direction' => 'credit']), 'User not found');

$blocked = $call('setuserstatus', ['userId' => $userId, 'status' => 0]);
TestRunner::equals('user blocked', 0, $blocked['status']);
TestRunner::throws('blocked user cannot authenticate', static function () use ($app2, $created) {
    $app2->auth()->requireUser(['HTTP_AUTHORIZATION' => 'Bearer ' . $created['token']]);
}, 'disabled');
$call('setuserstatus', ['userId' => $userId, 'status' => 1]);

$list = $call('users', ['search' => '9812345678'], 'GET');
TestRunner::equals('user search finds the row', 1, $list['totalCount']);
TestRunner::equals('user row exposes the balance', '700.50', $list['list'][0]['balance']);

$detail = $call('user', ['userId' => $userId], 'GET');
TestRunner::equals('user detail returns the wallet', '700.50', $detail['user']['balance']);
TestRunner::ok('user detail includes ledger + vip', isset($detail['ledger'], $detail['vip'], $detail['follows']));

$ledger = $call('ledger', ['userId' => $userId], 'GET');
TestRunner::equals('ledger rows for the user', 3, $ledger['totalCount']);

TestRunner::group('Admin panel — rounds, risk & overrides');

$app2->bets()->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'number', 'betContent' => '7', 'amount' => 100.0]);
$app2->bets()->place($userId, ['gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 50.0]);

$games = $call('games', [], 'GET');
TestRunner::equals('all games listed', 21, count($games['list']));
$wingoRow = null;
foreach ($games['list'] as $row) {
    if ($row['gameCode'] === 'WinGo_1M') { $wingoRow = $row; }
}
TestRunner::equals('live bets counted for the open round', 2, $wingoRow['liveBets']);
TestRunner::equals('live stake counted', '150.00', $wingoRow['liveStake']);

$exposure = $call('exposure', ['gameCode' => 'WinGo_1M'], 'GET');
TestRunner::equals('exposure covers 10 WinGo outcomes', 10, count($exposure['outcomes']));
TestRunner::equals('exposure stake matches', '150.00', $exposure['stake']);

$byOutcome = [];
foreach ($exposure['outcomes'] as $row) {
    $byOutcome[$row['outcome']] = $row;
}
// digit 7: number 7 pays 900 gross (882 net) + big pays 100 gross (98 net) = 980 net
TestRunner::equals('payout simulated for digit 7', '980.00', $byOutcome['7']['payout']);
// digit 0: only "small" would win — nothing here — so the house keeps everything
TestRunner::equals('payout simulated for digit 0', '0.00', $byOutcome['0']['payout']);
TestRunner::equals('profit simulated for digit 0', '150.00', $byOutcome['0']['profit']);
TestRunner::ok('outcomes sorted best-profit first', (float) $exposure['outcomes'][0]['profit'] >= (float) $exposure['outcomes'][9]['profit']);

$k3Exposure = $call('exposure', ['gameCode' => 'K3_1M'], 'GET');
TestRunner::equals('K3 simulates all 56 dice combinations', 56, count($k3Exposure['outcomes']));
$d5Exposure = $call('exposure', ['gameCode' => 'D5_1M'], 'GET');
TestRunner::ok('D5 exposure explains why there is no simulation', $d5Exposure['note'] !== null);

$override = $call('setoverride', ['gameCode' => 'WinGo_1M', 'value' => '0', 'issueNumber' => $exposure['issueNumber']]);
TestRunner::equals('override queued from the panel', 'pending', $override['status']);
TestRunner::equals('override listed', 1, count($call('overrides', [], 'GET')['list']));

Clock::freeze(strtotime('2026-08-31 12:01:05'));
$settle = $call('settle', ['gameCode' => 'WinGo_1M', 'issueNumber' => $exposure['issueNumber']]);
TestRunner::ok('panel settlement ran', $settle['settled'] === true);
TestRunner::equals('both bets settled', 2, $settle['bets']);
TestRunner::equals('forced result loses both bets', 0, $settle['won']);
TestRunner::equals('balance unchanged by losing round', '550.50', $app2->wallet()->snapshot($userId)['balance']);

$results = $call('results', ['gameCode' => 'WinGo_1M'], 'GET');
TestRunner::equals('result recorded with panel stats', '150.00', $results['list'][0]['stake']);
TestRunner::equals('result source is override', 'override', $results['list'][0]['source']);

$bets = $call('bets', ['gameCode' => 'WinGo_1M', 'status' => 'lost'], 'GET');
TestRunner::equals('bet filter by status', 2, $bets['totalCount']);
TestRunner::equals('bet totals aggregated', '150.00', $bets['totals']['stake']);
TestRunner::equals('bet filter by user', 2, $call('bets', ['userId' => $userId], 'GET')['totalCount']);
TestRunner::equals('bet filter by issue', 2, $call('bets', ['issueNumber' => $exposure['issueNumber']], 'GET')['totalCount']);

TestRunner::group('Admin panel — plans, dashboard & audit');

$plan = $call('saveplan', [
    'planCode' => 'panel-test-plan', 'name' => 'Panel Test', 'gameCode' => 'WinGo_1M',
    'betType' => 'color', 'betContent' => 'red', 'minAmount' => '2', 'sort' => 99, 'state' => 1,
]);
TestRunner::equals('plan created from the panel', 'panel-test-plan', $plan['plan_code']);

$updated = $call('saveplan', [
    'planCode' => 'panel-test-plan', 'name' => 'Panel Test v2', 'gameCode' => 'WinGo_1M',
    'betType' => 'color', 'betContent' => 'green', 'minAmount' => '5', 'state' => 0,
]);
TestRunner::equals('plan updated in place', 'Panel Test v2', $updated['name']);
TestRunner::equals('plan content updated', 'green', $updated['bet_content']);
TestRunner::equals('plan can be disabled', 0, (int) $updated['state']);

TestRunner::throws('invalid plan content rejected', static fn() => $call('saveplan', [
    'planCode' => 'bad-plan', 'gameCode' => 'WinGo_1M', 'betType' => 'color', 'betContent' => 'rainbow',
]), 'Invalid selection');
TestRunner::throws('invalid plan code rejected', static fn() => $call('saveplan', [
    'planCode' => 'X', 'gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big',
]), 'planCode must be');

$deleted = $call('deleteplan', ['planCode' => 'panel-test-plan']);
TestRunner::ok('unused plan deleted', $deleted['deleted'] === true);

// A plan with active subscribers is disabled instead of deleted.
Clock::freeze(strtotime('2026-08-31 12:02:10'));
$app2->follow()->subscribe($userId, ['planCode' => 'wingo1m-bigsmall-big', 'amount' => 5.0]);
$kept = $call('deleteplan', ['planCode' => 'wingo1m-bigsmall-big']);
TestRunner::ok('plan with subscribers is disabled, not deleted', $kept['deleted'] === false && $kept['disabled'] === true);

$follows = $call('follows', ['status' => 'active'], 'GET');
TestRunner::equals('subscription listed', 1, $follows['totalCount']);
$stopped = $call('stopfollow', ['followId' => $follows['list'][0]['followId']]);
TestRunner::equals('subscription stopped from the panel', 'stopped', $stopped['status']);

$dash = $call('dashboard', [], 'GET');
TestRunner::equals('dashboard counts today\'s bets', 2, $dash['today']['bets']);
TestRunner::equals('dashboard stake', '150.00', $dash['today']['stake']);
TestRunner::equals('dashboard GGR', '150.00', $dash['today']['ggr']);
TestRunner::ok('dashboard has a per-game breakdown', count($dash['perGame']) >= 1);
TestRunner::ok('dashboard exposes system health', isset($dash['system']['workerHealthy']));
TestRunner::ok('dashboard lists recent results', count($dash['recentResults']) >= 1);

$vip = $call('vip', [], 'GET');
TestRunner::equals('vip level table exposed', 6, count($vip['levels']));
TestRunner::ok('vip top list populated', count($vip['top']) >= 1);

$worker = $call('runworkerpass', []);
TestRunner::ok('worker pass runs from the panel', isset($worker['ranAt']));

$audit = $call('auditlog', [], 'GET');
TestRunner::ok('admin actions are audited', $audit['totalCount'] >= 8, 'rows=' . $audit['totalCount']);
$actions = array_column($audit['list'], 'action');
TestRunner::ok('wallet adjustments audited', in_array('wallet.credit', $actions, true));
TestRunner::ok('overrides audited', in_array('result.override', $actions, true));
TestRunner::ok('plan changes audited', in_array('plan.save', $actions, true));

Clock::unfreeze();
