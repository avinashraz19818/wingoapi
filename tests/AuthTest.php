<?php

declare(strict_types=1);

use Lottery\Auth\Jwt;
use Lottery\Auth\Signature;
use Lottery\Support\Clock;

TestRunner::group('Auth — JWT');

$app = makeTestApp();
$jwt = $app->jwt();

Clock::freeze(strtotime('2026-08-31 12:00:00'));
$token  = $jwt->issue(7001, '9876543210');
$claims = $jwt->verify($token);

TestRunner::equals('token carries the id claim', 7001, $claims['id']);
TestRunner::equals('token carries the mobile claim', '9876543210', $claims['mobile']);
TestRunner::equals('exp is now + ttl', Clock::now() + 86400, $claims['exp']);
TestRunner::equals('three JWT segments', 3, count(explode('.', $token)));

TestRunner::throws('tampered payload rejected', static function () use ($jwt, $token) {
    [$h, $p, $s] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode('{"id":9999,"mobile":"x","exp":99999999999}'), '+/', '-_'), '=');
    $jwt->verify($h . '.' . $forged . '.' . $s);
}, 'Invalid token signature');

TestRunner::throws('alg=none rejected', static function () use ($jwt) {
    $h = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode('{"id":1,"exp":99999999999}'), '+/', '-_'), '=');
    $jwt->verify($h . '.' . $p . '.');
}, 'Unsupported token algorithm');

TestRunner::throws('foreign secret rejected', static function () use ($token) {
    (new Jwt('other-secret'))->verify($token);
}, 'Invalid token signature');

TestRunner::throws('malformed token rejected', static fn() => $jwt->verify('abc.def'), 'Malformed token');

$shortLived = $jwt->issue(7002, '1', 10);
Clock::freeze(Clock::now() + 120);
TestRunner::throws('expired token rejected', static fn() => $jwt->verify($shortLived), 'expired');
Clock::freeze(strtotime('2026-08-31 12:00:00'));

TestRunner::group('Auth — request signature');

$signature = new Signature('test-signature-secret', 300);
$params = [
    'action'     => 'WinGoBet',
    'gameCode'   => 'WinGo_1M',
    'betType'    => 'color',
    'betContent' => 'green',
    'amount'     => '100',
    'timestamp'  => (string) Clock::now(),
];

$signed = $signature->calculate($params);
TestRunner::equals('signature is 32 hex chars uppercase', 32, strlen($signed));
TestRunner::ok('signature is uppercase md5', (bool) preg_match('/^[0-9A-F]{32}$/', $signed));

// Order of the parameters must not matter (sorted before hashing).
$shuffled = array_reverse($params, true);
TestRunner::equals('parameter order is irrelevant', $signed, $signature->calculate($shuffled));

// Empty values are excluded from the payload.
TestRunner::equals('empty values ignored', $signed, $signature->calculate($params + ['note' => '']));

$signature->verify($params + ['signature' => $signed]);
TestRunner::ok('valid signature verifies', true);

TestRunner::throws('wrong signature rejected', static function () use ($signature, $params) {
    $signature->verify($params + ['signature' => str_repeat('A', 32)]);
}, 'Signature mismatch');

TestRunner::throws('missing signature rejected', static function () use ($signature, $params) {
    $signature->verify($params);
}, 'Missing signature');

TestRunner::throws('stale timestamp rejected', static function () use ($signature) {
    $stale = ['action' => 'WinGoBet', 'timestamp' => (string) (Clock::now() - 4000)];
    $signature->verify($stale + ['signature' => $signature->calculate($stale)]);
}, 'outside the allowed window');

TestRunner::group('Auth — bearer extraction');

$auth = $app->auth();
TestRunner::equals('extracts bearer token', 'abc.def.ghi', $auth->extractToken(['HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi']));
TestRunner::equals('case-insensitive scheme', 'xyz', $auth->extractToken(['HTTP_AUTHORIZATION' => 'bearer xyz']));
TestRunner::equals('no header returns empty', '', $auth->extractToken([]));

$user = $auth->requireUser(['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
TestRunner::equals('user resolved from token', 7001, $user['id']);
TestRunner::nearly('wallet provisioned for new user', 0.0, $app->wallet()->balance(7001));

Clock::unfreeze();
