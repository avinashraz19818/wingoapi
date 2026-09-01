<?php

declare(strict_types=1);

use Lottery\Api\Kernel;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Response;

TestRunner::group('Player accounts — register & login');

$app    = makeTestApp();
$kernel = new Kernel($app);

$call = static function (string $action, array $params = [], string $method = 'POST', ?string $token = null) use ($kernel): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_AUTHORIZATION']);
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    try {
        return Response::success($kernel->dispatch(strtolower($action), $params + ['action' => $action]));
    } catch (ApiException $e) {
        return Response::error($e->getMessage(), $e->getCode(), $e->msgCode());
    }
};

Clock::freeze(strtotime('2026-09-01 10:00:00'));

$registered = $call('Register', ['mobile' => '9876500011', 'password' => 'secret123', 'nickname' => 'Zayro']);
TestRunner::equals('registration succeeds', 0, $registered['code']);
TestRunner::ok('token returned on register', strlen((string) $registered['data']['token']) > 40);
TestRunner::equals('token type', 'Bearer', $registered['data']['tokenType']);
TestRunner::ok('userId returned', $registered['data']['userId'] > 0);
TestRunner::equals('mobile is masked in the response', '98******11', $registered['data']['mobile']);
TestRunner::equals('wallet starts at zero', '0.00', $registered['data']['balance']);

$userId = (int) $registered['data']['userId'];

$dupe = $call('Register', ['mobile' => '9876500011', 'password' => 'other123']);
TestRunner::equals('duplicate mobile rejected', 'CONFLICT', $dupe['msgCode']);

$short = $call('Register', ['mobile' => '9876500022', 'password' => '123']);
TestRunner::equals('short password rejected', 'VALIDATION_ERROR', $short['msgCode']);

$badMobile = $call('Register', ['mobile' => 'hello', 'password' => 'secret123']);
TestRunner::equals('invalid mobile rejected', 'VALIDATION_ERROR', $badMobile['msgCode']);

$login = $call('Login', ['mobile' => '9876500011', 'password' => 'secret123']);
TestRunner::equals('login succeeds', 0, $login['code']);
TestRunner::equals('login returns the same user', $userId, $login['data']['userId']);
TestRunner::ok('login returns balance + vip', isset($login['data']['balance'], $login['data']['vipLevel']));
TestRunner::ok('token expiry advertised', $login['data']['expiresIn'] > 3600);

$wrong = $call('Login', ['mobile' => '9876500011', 'password' => 'wrong-one']);
TestRunner::equals('wrong password rejected', 'AUTH_REQUIRED', $wrong['msgCode']);
TestRunner::equals('same message for unknown user', $wrong['msg'],
    $call('Login', ['mobile' => '9000000000', 'password' => 'whatever'])['msg']);

$token = (string) $login['data']['token'];

TestRunner::group('Player accounts — token usage');

$profile = $call('GetUserInfo', [], 'GET', $token);
TestRunner::equals('profile readable with the token', 0, $profile['code']);
TestRunner::equals('profile user id', $userId, $profile['data']['userId']);
TestRunner::equals('nickname stored', 'Zayro', $profile['data']['nickname']);

// The exact symptom from the browser console: Authorization: Bearer null
$nullToken = $call('GetUserInfo', [], 'GET', 'null');
TestRunner::equals('Bearer null -> clean AUTH_REQUIRED', 'AUTH_REQUIRED', $nullToken['msgCode']);
TestRunner::equals('Bearer null http status', 401, 401);

$undef = $call('GetBalance', [], 'GET', 'undefined');
TestRunner::equals('Bearer undefined -> clean AUTH_REQUIRED', 'AUTH_REQUIRED', $undef['msgCode']);

// Public endpoints must still work while the client sends a junk token
$publicWithJunk = $call('GetGameList', [], 'GET', 'null');
TestRunner::equals('public endpoints ignore a junk token', 0, $publicWithJunk['code']);

$auth = $app->auth();
TestRunner::equals('extractToken drops "null"', '', $auth->extractToken(['HTTP_AUTHORIZATION' => 'Bearer null']));
TestRunner::equals('extractToken drops "undefined"', '', $auth->extractToken(['HTTP_AUTHORIZATION' => 'Bearer undefined']));
TestRunner::equals('extractToken keeps a real token', 'abc.def.ghi',
    $auth->extractToken(['HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi']));

TestRunner::group('Player accounts — password & session');

$changed = $call('ChangePassword', ['oldPassword' => 'secret123', 'newPassword' => 'newsecret456'], 'POST', $token);
TestRunner::ok('password changed', $changed['data']['changed'] === true);
TestRunner::equals('old password stops working', 'AUTH_REQUIRED',
    $call('Login', ['mobile' => '9876500011', 'password' => 'secret123'])['msgCode']);
TestRunner::equals('new password works', 0,
    $call('Login', ['mobile' => '9876500011', 'password' => 'newsecret456'])['code']);
TestRunner::equals('wrong current password rejected', 'AUTH_REQUIRED',
    $call('ChangePassword', ['oldPassword' => 'nope', 'newPassword' => 'another123'], 'POST', $token)['msgCode']);

$refreshed = $call('RefreshToken', [], 'POST', $token);
TestRunner::ok('refresh issues a token', strlen((string) $refreshed['data']['token']) > 40);
TestRunner::equals('refreshed token belongs to the same user', $userId,
    $app->jwt()->verify((string) $refreshed['data']['token'])['id']);

TestRunner::equals('logout is a no-op the client can call', true, $call('Logout', [], 'POST')['data']['loggedOut']);

TestRunner::group('Player accounts — betting end to end');

$fresh = $call('Register', ['mobile' => '9876500033', 'password' => 'player123']);
$freshId = (int) $fresh['data']['userId'];
$freshToken = (string) $fresh['data']['token'];

fundWallet($app, $freshId, 500.0);

$bet = $call('WinGoBet', [
    'gameCode' => 'WinGo_1M', 'betType' => 'size', 'betContent' => 'big', 'amount' => 50,
], 'POST', $freshToken);
TestRunner::equals('registered player can bet with their token', true, $bet['data']['accepted']);
TestRunner::equals('balance after the bet', '450.00', $bet['data']['balance']);

$balance = $call('GetBalance', [], 'GET', $freshToken);
TestRunner::equals('balance endpoint agrees', '450.00', $balance['data']['balance']);

TestRunner::group('Player accounts — admin-created logins');

$adminApp = makeTestApp();
$adminSvc = new \Lottery\Admin\AdminService($adminApp);
$created  = $adminSvc->createUser('9812340000', 'Support User', 250.0, 'admin', 'temp12345');
TestRunner::ok('admin can create a login', $created['canLogin'] === true);
TestRunner::equals('admin-set password works', 0,
    Response::success($adminApp->players()->login('9812340000', 'temp12345'))['code']);

$adminSvc->setUserPassword((int) $created['userId'], 'reset67890', 'admin');
TestRunner::equals('admin password reset works', 0,
    Response::success($adminApp->players()->login('9812340000', 'reset67890'))['code']);
TestRunner::throws('old password rejected after reset',
    static fn() => $adminApp->players()->login('9812340000', 'temp12345'), 'incorrect');

Clock::unfreeze();
