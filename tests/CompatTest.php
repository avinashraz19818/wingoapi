<?php

declare(strict_types=1);

use Lottery\Api\Compat\ArTranslator;
use Lottery\Api\CompatKernel;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;

TestRunner::group('AR compatibility — bet content translation');

$app = makeTestApp();
$reg = $app->registry();

$wingo = $reg->get('WinGo_1M');
$k3    = $reg->get('K3_1M');
$d5    = $reg->get('D5_1M');
$moto  = $reg->get('MotoRace_1M');

$map = static fn($game, string $content): string => (function (array $r): string {
    return $r['betType'] . '=' . $r['betContent'];
})(ArTranslator::toEngineBet($game, $content));

TestRunner::equals('Num_5',            'number=5',       $map($wingo, 'Num_5'));
TestRunner::equals('Color_green',      'color=green',    $map($wingo, 'Color_green'));
TestRunner::equals('Color_violet',     'color=violet',   $map($wingo, 'Color_violet'));
TestRunner::equals('BigSmall_big',     'size=big',       $map($wingo, 'BigSmall_big'));
TestRunner::equals('BigSmall_H',       'size=big',       $map($wingo, 'BigSmall_H'));
TestRunner::equals('BigSmall_L',       'size=small',     $map($wingo, 'BigSmall_L'));
TestRunner::equals('OddEven_O',        'parity=odd',     $map($wingo, 'OddEven_O'));

TestRunner::equals('SumNum_10',        'total=10',              $map($k3, 'SumNum_10'));
TestRunner::equals('SumBigSmall_H',    'size=big',              $map($k3, 'SumBigSmall_H'));
TestRunner::equals('SumOddEven_E',     'parity=even',           $map($k3, 'SumOddEven_E'));
TestRunner::equals('NumSame3All_3TT',  'triple_any=any',        $map($k3, 'NumSame3All_3TT'));
TestRunner::equals('NumSame3_4',       'triple_exact=4',        $map($k3, 'NumSame3_4'));
TestRunner::equals('NumSame2_3',       'pair=3',                $map($k3, 'NumSame2_3'));
TestRunner::equals('NumDiff2_2_5',     'two_different=2:5',     $map($k3, 'NumDiff2_2_5'));
TestRunner::equals('NumDiff3_1_2_3',   'three_different=1:2:3', $map($k3, 'NumDiff3_1_2_3'));

TestRunner::equals('FirstNum_7',       'number=a:7',      $map($d5, 'FirstNum_7'));
TestRunner::equals('ThirdNum_0',       'number=c:0',      $map($d5, 'ThirdNum_0'));
TestRunner::equals('FifthBigSmall_L',  'size=e:small',    $map($d5, 'FifthBigSmall_L'));
TestRunner::equals('SecondOddEven_O',  'parity=b:odd',    $map($d5, 'SecondOddEven_O'));
TestRunner::equals('SumBigSmall_H(5D)','size=sum:big',    $map($d5, 'SumBigSmall_H'));
TestRunner::equals('SumOddEven_E(5D)', 'parity=sum:even', $map($d5, 'SumOddEven_E'));

TestRunner::equals('FirstNum_7 (moto)', 'champion=7',   $map($moto, 'FirstNum_7'));
TestRunner::equals('SecondNum_3 (moto)','podium=3',     $map($moto, 'SecondNum_3'));
TestRunner::equals('FirstBigSmall_H',   'size=big',     $map($moto, 'FirstBigSmall_H'));

TestRunner::throws('unknown play type refused', static fn() => ArTranslator::toEngineBet($wingo, 'Rocket_9'), 'Unsupported');
TestRunner::throws('empty content refused', static fn() => ArTranslator::toEngineBet($wingo, ''), 'Empty bet content');

TestRunner::group('AR compatibility — result translation');

$wingoResult = (new \Lottery\Games\Families\WinGoRules())->build(0);
$out = ArTranslator::fromEngineResult('WinGo', $wingoResult);
TestRunner::equals('WinGo premium', '0', $out['premium']);
TestRunner::equals('WinGo colour', 'red,violet', $out['color']);

$k3Result = (new \Lottery\Games\Families\K3Rules())->build([1, 3, 5]);
$out = ArTranslator::fromEngineResult('K3', $k3Result);
TestRunner::equals('K3 premium is the dice', '1,3,5', $out['premium']);
TestRunner::equals('K3 number is the sum', '9', $out['number']);

$d5Result = (new \Lottery\Games\Families\D5Rules())->build([9, 2, 0, 4, 6]);
$out = ArTranslator::fromEngineResult('D5', $d5Result);
TestRunner::equals('5D premium is the digit string', '92046', $out['premium']);
TestRunner::equals('5D number is the sum', '21', $out['number']);

$motoResult = (new \Lottery\Games\Families\MotoRaceRules())->build([7, 2, 9, 1, 3, 4, 5, 6, 8, 10]);
$out = ArTranslator::fromEngineResult('MotoRace', $motoResult);
TestRunner::equals('MotoRace premium is the ranking', '7,2,9,1,3,4,5,6,8,10', $out['premium']);
TestRunner::equals('MotoRace number is the champion', '7', $out['number']);

TestRunner::group('AR compatibility — endpoints');

$compatApp = makeTestApp();
$kernel    = new CompatKernel($compatApp);
$game      = $compatApp->registry()->get('WinGo_1M');

Clock::freeze(strtotime('2026-09-01 12:00:10'));

$call = static function (string $action, array $params = [], ?string $token = null) use ($kernel): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    try {
        return $kernel->dispatch($action, $params + ['action' => $action]);
    } catch (ApiException $e) {
        return $kernel->errorPayload($e);
    }
};

$list = $call('GetGameList');
TestRunner::equals('their envelope: code', 0, $list['code']);
TestRunner::equals('their envelope: msg', 'Succeed', $list['msg']);
TestRunner::equals('their envelope: msgCode is an int', 0, $list['msgCode']);
TestRunner::ok('game list is grouped', isset($list['data'][0]['gameList']));
TestRunner::ok('groups carry a gameType', isset($list['data'][0]['gameType']));

$info = $call('GetGameInfo', ['gameCode' => 'WinGo_1M']);
TestRunner::ok('rates present', count($info['data']['rates']) > 0);
TestRunner::ok('rate row shape', isset($info['data']['rates'][0]['playType'], $info['data']['rates'][0]['playBet'], $info['data']['rates'][0]['playRate']));
TestRunner::ok('issue merged into game info', isset($info['data']['issueNumber'], $info['data']['countdown']));
TestRunner::equals('bet scopes exposed', [1, 10, 100, 1000], $info['data']['betScopes']);

$issue = $call('GetGameIssue', ['gameCode' => 'WinGo_1M']);
TestRunner::ok('issue payload shape', isset($issue['data']['issueNumber'], $issue['data']['startTime'], $issue['data']['endTime'], $issue['data']['countdown']));
TestRunner::equals('interval in minutes', 1, $issue['data']['intervalMinute']);
TestRunner::ok('times are epoch millis', $issue['data']['startTime'] > 1000000000000);

// Seed a couple of results so history/trend have data
$prev = $compatApp->scheduler()->previous($game);
$compatApp->overrides()->set($game, $prev->issueNumber, '7', 'oneshot', 'test');
$compatApp->draws()->ensureResult($game, $prev, Clock::now());

$history = $call('GetHistoryIssuePage', ['gameCode' => 'WinGo_1M', 'pageSize' => 5]);
TestRunner::ok('history list present', count($history['data']['list']) >= 1);
$row = $history['data']['list'][0];
TestRunner::ok('history row has their field names', isset($row['issueNumber'], $row['number'], $row['colour'], $row['premium'], $row['openCode']));
TestRunner::equals('history number', '7', $row['number']);
TestRunner::equals('history colour', 'green', $row['colour']);

$trend = $call('GetTrendStatistics', ['gameCode' => 'WinGo_1M']);
TestRunner::ok('trend has statistics per digit', isset($trend['data']['statistics']['7']['appear']));

$limits = $call('GetBetLimit', ['gameCode' => 'WinGo_1M']);
TestRunner::ok('bet limits listed', isset($limits['data'][0]['minAmount'], $limits['data'][0]['maxAmount']));

TestRunner::group('AR compatibility — betting through the client dialect');

$token = $compatApp->jwt()->issue(9001, '9990001111');
fundWallet($compatApp, 9001, 1000.0);

$balance = $call('GetBalance', ['gameCode' => 'WinGo_1M'], $token);
TestRunner::equals('balance is a plain number', 1000.0, $balance['data']['balance']);

$bet = $call('WinGoBet', [
    'gameCode'    => 'WinGo_1M',
    'amount'      => 10,
    'betMultiple' => 2,
    'betContent'  => 'Color_green',
], $token);
TestRunner::equals('bet accepted', 0, $bet['code']);
TestRunner::equals('order number returned', true, $bet['data']['orderNo'] !== '');
TestRunner::equals('stake charged (10 x 2)', 20.0, $bet['data']['betAmount']);
TestRunner::equals('balance after bet', 980.0, $bet['data']['balance']);
TestRunner::equals('state flag', 1, $bet['data']['state']);

$multi = $call('WinGoBet', [
    'gameCode'    => 'WinGo_1M',
    'amount'      => 5,
    'betMultiple' => 1,
    'betContent'  => json_encode(['Num_1', 'Num_2', 'Num_3']),
], $token);
TestRunner::equals('three selections charged', 15.0, $multi['data']['betAmount']);
TestRunner::equals('balance after multi bet', 965.0, $multi['data']['balance']);

$poor = $call('WinGoBet', [
    'gameCode' => 'WinGo_1M', 'amount' => 999999, 'betMultiple' => 1, 'betContent' => 'Color_red',
], $token);
TestRunner::equals('insufficient balance uses their code', 142, $poor['msgCode']);
TestRunner::equals('failure code is -1', -1, $poor['code']);

$records = $call('GetRecordPage', ['gameCode' => 'WinGo_1M', 'pageSize' => 10], $token);
// One engine bet per selection: Color_green + three Num_* = 4 rows.
TestRunner::equals('records returned', 4, $records['data']['totalCount']);
TestRunner::ok('record row shape', isset($records['data']['list'][0]['orderNo'], $records['data']['list'][0]['betAmount'], $records['data']['list'][0]['state']));

$noAuth = $call('GetBalance', ['gameCode' => 'WinGo_1M']);
TestRunner::equals('balance without a token fails cleanly', -1, $noAuth['code']);

TestRunner::group('AR compatibility — settlement through the client dialect');

Clock::freeze(strtotime('2026-09-01 12:01:10'));
$settleGame  = $compatApp->registry()->get('WinGo_1M');
$settleIssue = $compatApp->scheduler()->previous($settleGame);

$winLoss = $call('GetWinLossResult', [
    'gameCode' => 'WinGo_1M', 'issueNumber' => $settleIssue->issueNumber,
], $token);
TestRunner::equals('win/loss answers', 0, $winLoss['code']);
TestRunner::ok('win/loss carries the result', isset($winLoss['data']['number'], $winLoss['data']['premium']));
TestRunner::ok('win/loss carries the balance', isset($winLoss['data']['balance']));
TestRunner::ok('bets were settled', in_array($winLoss['data']['state'], [0, 1], true));

Clock::unfreeze();

TestRunner::group('AR compatibility — per-site game list & extra actions');

$siteApp    = makeTestApp();
$siteKernel = new CompatKernel($siteApp);

// A site that only sells the four WinGo rounds its UI has room for.
$site = $siteApp->domains()->create('dhaniwin.club9.eu.cc', 'Dhani', [
    'WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M',
], '');

$callAs = static function (string $action, array $params = [], ?string $key = null, ?string $token = null) use ($siteKernel): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION']);
    if ($key !== null)   { $_SERVER['HTTP_X_API_KEY'] = $key; }
    if ($token !== null) { $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token; }
    try {
        return $siteKernel->dispatch($action, $params + ['action' => $action]);
    } catch (ApiException $e) {
        return $siteKernel->errorPayload($e);
    }
};

$full = $callAs('GetGameList');
$allGames = 0;
foreach ($full['data'] as $group) {
    $allGames += count($group['gameList']);
}
TestRunner::equals('without a key every game is listed', 21, $allGames);

$limited = $callAs('GetGameList', [], $site['apiKey']);
$codes = [];
foreach ($limited['data'] as $group) {
    foreach ($group['gameList'] as $row) {
        $codes[] = $row['gameCode'];
    }
}
sort($codes);
TestRunner::equals('the site only sees its own plan', ['WinGo_1M', 'WinGo_30S', 'WinGo_3M', 'WinGo_5M'], $codes);
TestRunner::equals('empty families are dropped', 1, count($limited['data']));
TestRunner::ok('WinGo_10M is hidden', !in_array('WinGo_10M', $codes, true));

TestRunner::group('AR compatibility — actions the client calls extra');

$token = $siteApp->jwt()->issue(9200, '9998887777');
fundWallet($siteApp, 9200, 250.0);

$info = $callAs('GetUserInfo', [], $site['apiKey'], $token);
TestRunner::equals('GetUserInfo answers', 0, $info['code']);
TestRunner::equals('GetUserInfo carries the balance', 250.0, $info['data']['balance']);
TestRunner::ok('GetUserInfo has the fields the header reads', isset($info['data']['userId'], $info['data']['nickName'], $info['data']['walletBalance']));

// An action nobody implemented must not blow up the screen.
$unknown = $callAs('GetSomethingNobodyImplemented', [], $site['apiKey']);
TestRunner::equals('unknown action still answers 0', 0, $unknown['code']);
TestRunner::equals('unknown action msg', 'Succeed', $unknown['msg']);

TestRunner::group('AR compatibility — MotoRace intervals');

$motoCodes = [];
foreach ($siteApp->registry()->all() as $g) {
    if ($g->family === 'MotoRace') {
        $motoCodes[] = $g->code;
    }
}
TestRunner::equals('MotoRace now has four rounds',
    ['MotoRace_1M', 'MotoRace_3M', 'MotoRace_5M', 'MotoRace_10M'], $motoCodes);
