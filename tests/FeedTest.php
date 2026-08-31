<?php

declare(strict_types=1);

use Lottery\Api\FeedKernel;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Tenant\DomainService;

TestRunner::group('Feed — domain normalisation');

TestRunner::equals('scheme stripped', 'shop.com', DomainService::normalise('https://shop.com'));
TestRunner::equals('www stripped', 'shop.com', DomainService::normalise('WWW.Shop.com'));
TestRunner::equals('port stripped', 'shop.com', DomainService::normalise('shop.com:8443'));
TestRunner::equals('path stripped', 'shop.com', DomainService::normalise('https://shop.com/game/wingo?x=1'));
TestRunner::equals('subdomain preserved', 'api.shop.com', DomainService::normalise('https://api.shop.com/'));
TestRunner::equals('empty stays empty', '', DomainService::normalise(''));

TestRunner::group('Feed — whitelist rules');

$app     = makeTestApp();
$domains = $app->domains();

$client = $domains->create('client-site.com', 'Client A', [], 'test plan');
TestRunner::ok('domain registered', $client['id'] > 0);
TestRunner::equals('stored normalised', 'client-site.com', $client['domain']);
TestRunner::ok('api key generated', strlen($client['apiKey']) === 32);
TestRunner::equals('allowed by default', 1, $client['status']);
TestRunner::equals('no game restriction by default', [], $client['games']);

TestRunner::throws('duplicate domain rejected', static fn() => $domains->create('www.client-site.com', '', [], ''), 'already whitelisted');
TestRunner::throws('invalid domain rejected', static fn() => $domains->create('not a domain', '', [], ''), 'valid domain');

$byOrigin = $domains->authorise(['HTTP_ORIGIN' => 'https://client-site.com', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('whitelisted origin allowed', $byOrigin['allowed'] === true);
TestRunner::equals('resolution method', 'origin', $byOrigin['via']);

$byWww = $domains->authorise(['HTTP_ORIGIN' => 'https://www.client-site.com', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('www variant allowed', $byWww['allowed'] === true);

$byReferer = $domains->authorise(['HTTP_REFERER' => 'https://client-site.com/games/wingo', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('referer works when origin is absent', $byReferer['allowed'] === true);

$stranger = $domains->authorise(['HTTP_ORIGIN' => 'https://copycat.com', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('unlisted domain blocked', $stranger['allowed'] === false);
TestRunner::ok('block reason names the domain', str_contains($stranger['reason'], 'copycat.com'));

$byKey = $domains->authorise(['HTTP_HOST' => 'api.mysaas.com', 'HTTP_X_API_KEY' => $client['apiKey']], []);
TestRunner::ok('server-to-server api key allowed', $byKey['allowed'] === true);
TestRunner::equals('key resolution method', 'api-key', $byKey['via']);

$badKey = $domains->authorise(['HTTP_HOST' => 'api.mysaas.com', 'HTTP_X_API_KEY' => str_repeat('f', 32)], []);
TestRunner::ok('unknown api key blocked', $badKey['allowed'] === false);

$keyFromWrongSite = $domains->authorise([
    'HTTP_HOST' => 'api.mysaas.com', 'HTTP_ORIGIN' => 'https://copycat.com', 'HTTP_X_API_KEY' => $client['apiKey'],
], []);
TestRunner::ok('leaked key cannot be used from another site', $keyFromWrongSite['allowed'] === false);

$self = $domains->authorise(['HTTP_HOST' => 'api.mysaas.com', 'HTTP_ORIGIN' => 'https://api.mysaas.com'], []);
TestRunner::ok('our own site is always allowed', $self['allowed'] === true);
TestRunner::equals('self resolution method', 'self', $self['via']);

$noHeaders = $domains->authorise(['HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('same-host call without headers allowed (board/panel)', $noHeaders['allowed'] === true);

/* -------------------------------------------------- status & expiry */
$domains->setStatus($client['id'], 0);
$blocked = $domains->authorise(['HTTP_ORIGIN' => 'https://client-site.com', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('disabled domain blocked', $blocked['allowed'] === false);
TestRunner::equals('disabled reason', 'Domain is disabled', $blocked['reason']);
$domains->setStatus($client['id'], 1);

Clock::freeze(strtotime('2026-08-31 12:00:00'));
$expiring = $domains->create('expired-client.com', 'Expired', [], '', '2026-08-30 00:00:00');
$expiredCheck = $domains->authorise(['HTTP_ORIGIN' => 'https://expired-client.com', 'HTTP_HOST' => 'api.mysaas.com'], []);
TestRunner::ok('expired subscription blocked', $expiredCheck['allowed'] === false);
TestRunner::equals('expiry reason', 'Subscription expired', $expiredCheck['reason']);

/* ------------------------------------------------- per-game plans */
$limited = $domains->create('wingo-only.com', 'WinGo plan', ['WinGo_1M', 'WinGo_3M'], '');
$okGame  = $domains->authorise(['HTTP_ORIGIN' => 'https://wingo-only.com', 'HTTP_HOST' => 'api.mysaas.com'], [], 'WinGo_1M');
TestRunner::ok('game inside the plan allowed', $okGame['allowed'] === true);
$badGame = $domains->authorise(['HTTP_ORIGIN' => 'https://wingo-only.com', 'HTTP_HOST' => 'api.mysaas.com'], [], 'K3_1M');
TestRunner::ok('game outside the plan blocked', $badGame['allowed'] === false);
TestRunner::ok('plan reason mentions the game', str_contains($badGame['reason'], 'K3_1M'));

/* ------------------------------------------------------- key rotation */
$oldKey  = $client['apiKey'];
$rotated = $domains->rotateKey($client['id']);
TestRunner::ok('rotation changes the key', $rotated['apiKey'] !== $oldKey);
TestRunner::ok('old key stops working',
    $domains->authorise(['HTTP_HOST' => 'api.mysaas.com', 'HTTP_X_API_KEY' => $oldKey], [])['allowed'] === false);
TestRunner::ok('new key works',
    $domains->authorise(['HTTP_HOST' => 'api.mysaas.com', 'HTTP_X_API_KEY' => $rotated['apiKey']], [])['allowed'] === true);

/* ------------------------------------------------------------ usage */
$usageRow = $domains->find($client['id']);
TestRunner::ok('successful reads are counted', (int) $usageRow['requests_total'] >= 3, 'requests=' . $usageRow['requests_total']);
TestRunner::ok('blocked attempts are counted', (int) $usageRow['blocked_total'] >= 1);
TestRunner::ok('daily usage recorded', count($domains->usage($client['id'])) >= 1);

$domains->delete($expiring['id']);
TestRunner::ok('deleted domain loses access',
    $domains->authorise(['HTTP_ORIGIN' => 'https://expired-client.com', 'HTTP_HOST' => 'api.mysaas.com'], [])['allowed'] === false);

TestRunner::group('Feed — endpoints');

$feedApp = makeTestApp();
$kernel  = new FeedKernel($feedApp);
$wingo   = $feedApp->registry()->get('WinGo_1M');

Clock::freeze(strtotime('2026-08-31 12:00:30'));

// Seed three drawn rounds with known digits.
foreach ([['4', 120], ['7', 60], ['0', 0]] as $index => [$digit, $ago]) {
    $issue = $feedApp->scheduler()->issueAt($wingo, Clock::now() - 60 - $ago);
    $feedApp->overrides()->set($wingo, $issue->issueNumber, $digit, 'oneshot', 'test');
    $feedApp->draws()->ensureResult($wingo, $issue, Clock::now());
}

$history = $kernel->dispatch('history', ['gameCode' => 'WinGo_1M', 'pageSize' => 5]);
TestRunner::ok('history returns rows', count($history['list']) >= 3);
TestRunner::equals('newest first', '0', (string) $history['list'][0]['number']);

$row = $history['list'][0];
TestRunner::ok('row keeps upstream field names', isset($row['issueNumber'], $row['number'], $row['colour'], $row['premium']));
TestRunner::equals('colour derived for 0', 'red,violet', $row['colour']);
TestRunner::equals('size exposed', 'small', $row['size']);
TestRunner::equals('17 digit issue number', 17, strlen((string) $row['issueNumber']));

$issue = $kernel->dispatch('issue', ['gameCode' => 'WinGo_1M']);
TestRunner::ok('issue endpoint returns a countdown', $issue['remaining'] > 0 && $issue['remaining'] <= 60);
TestRunner::ok('issue endpoint includes the previous draw', $issue['lastIssue'] !== null);

$games = $kernel->dispatch('gamelist', []);
TestRunner::equals('game list covers every game', 18, count($games['list']));
TestRunner::ok('game list exposes ready-made URLs', str_contains($games['list'][0]['endpoints']['history'], 'GetHistoryIssuePage.json'));

$k3 = $feedApp->registry()->get('K3_1M');
$k3Issue = $feedApp->scheduler()->previous($k3);
$feedApp->overrides()->set($k3, $k3Issue->issueNumber, '2,4,6', 'oneshot', 'test');
$feedApp->draws()->ensureResult($k3, $k3Issue, Clock::now());
$k3Row = $kernel->dispatch('history', ['gameCode' => 'K3_1M', 'pageSize' => 1])['list'][0];
TestRunner::equals('K3 dice exposed', [2, 4, 6], $k3Row['dice']);
TestRunner::equals('K3 openCode', '2,4,6', $k3Row['openCode']);
TestRunner::equals('K3 sum', 12, $k3Row['sum']);

TestRunner::throws('unknown feed action rejected',
    static fn() => $kernel->dispatch('deletestuff', ['gameCode' => 'WinGo_1M']), 'Unknown feed action');
TestRunner::throws('feed requires a game code',
    static fn() => $kernel->dispatch('history', []), 'Missing required parameter');

TestRunner::group('Feed — provider profile (ar-lottery01)');

$profile = require dirname(__DIR__) . '/config.php';
TestRunner::ok('generic profile is the default', $profile['draw_profile'] === 'generic');

putenv('DRAW_PROFILE=ar-lottery01');
$arApp = makeTestApp(['draw_profile' => 'ar-lottery01']);
putenv('DRAW_PROFILE');

$arConfig = [
    'draw_base_url'      => 'https://draw.ar-lottery01.com',
    'draw_url_templates' => ['{base}/{family}/{code}/GetHistoryIssuePage.json'],
    'draw_family_names'  => ['D5' => '5D'],
    'issue_prefixes'     => [
        'WinGo_30S' => '10003', 'WinGo_1M' => '10001', 'WinGo_3M' => '10002',
        'WinGo_5M'  => '10004', 'WinGo_10M' => '10005',
    ],
];
$arApp2 = makeTestApp($arConfig);

$reg = $arApp2->registry();
TestRunner::equals('WinGo_1M keeps prefix 10001', '10001',
    $reg->get('WinGo_1M')->familyCode . $reg->get('WinGo_1M')->intervalCode);
TestRunner::equals('WinGo_3M adopts upstream prefix 10002', '10002',
    $reg->get('WinGo_3M')->familyCode . $reg->get('WinGo_3M')->intervalCode);
TestRunner::equals('WinGo_30S adopts upstream prefix 10003', '10003',
    $reg->get('WinGo_30S')->familyCode . $reg->get('WinGo_30S')->intervalCode);
TestRunner::equals('WinGo_5M adopts upstream prefix 10004', '10004',
    $reg->get('WinGo_5M')->familyCode . $reg->get('WinGo_5M')->intervalCode);

Clock::freeze(strtotime('2026-08-31 00:01:00'));
TestRunner::equals('issue number matches the upstream format', '20260831100020001',
    $arApp2->scheduler()->current($reg->get('WinGo_3M'))->issueNumber);

$fetcher = $arApp2->fetcher();
TestRunner::ok('upstream fetching enabled for a real host', $fetcher->enabled() === true);
TestRunner::equals('upstream URL shape',
    'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json',
    $fetcher->endpoint($reg->get('WinGo_1M')));
TestRunner::equals('5D family name mapped',
    'https://draw.ar-lottery01.com/5D/5D_1M/GetHistoryIssuePage.json',
    $fetcher->endpoint($reg->get('D5_1M')));

/** Serves a canned upstream payload in the ar-lottery01 shape. */
final class UpstreamStub extends \Lottery\Support\Http
{
    private array $map;
    public array $seen = [];
    public function __construct(array $map) { parent::__construct(5); $this->map = $map; }
    public function fetchArray(string $url, array $headers = []): ?array
    {
        $this->seen[] = $url;
        return $this->map[$url] ?? null;
    }
}

Clock::freeze(strtotime('2026-08-31 12:05:30'));
$mirrorApp = makeTestApp($arConfig);
$mGame     = $mirrorApp->registry()->get('WinGo_1M');
$mIssue    = $mirrorApp->scheduler()->previous($mGame);

$stub = new UpstreamStub([
    'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json' => [
        'code' => 0,
        'data' => ['list' => [
            ['issueNumber' => $mIssue->issueNumber, 'number' => '8', 'colour' => 'red', 'premium' => '8'],
        ]],
    ],
]);

$mirrorFetcher = new \Lottery\Draw\DrawFetcher(
    $stub,
    new \Lottery\Games\Families\RulesFactory(),
    'https://draw.ar-lottery01.com',
    ['{base}/{family}/{code}/GetHistoryIssuePage.json'],
    true,
    60,
    ['D5' => '5D']
);

$mirrored = $mirrorFetcher->fetchIssue($mGame, $mIssue->issueNumber);
TestRunner::ok('upstream row parsed', $mirrored !== null && $mirrored['result']['number'] === 8);
TestRunner::equals('colour recomputed from the digit', 'red', $mirrored['result']['color']);

// A provider that numbers the same round with a different prefix still matches.
$shifted = new UpstreamStub([
    'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json' => ['list' => [
        ['issueNumber' => substr($mIssue->issueNumber, 0, 8) . '99999' . substr($mIssue->issueNumber, -4), 'number' => '6'],
    ]],
]);
$shiftedFetcher = new \Lottery\Draw\DrawFetcher(
    $shifted, new \Lottery\Games\Families\RulesFactory(),
    'https://draw.ar-lottery01.com', ['{base}/{family}/{code}/GetHistoryIssuePage.json'], true, 60, []
);
$bySequence = $shiftedFetcher->fetchIssue($mGame, $mIssue->issueNumber);
TestRunner::ok('date+sequence fallback matches a different prefix', $bySequence !== null && $bySequence['result']['number'] === 6);

// Second URL shape is tried when the first one yields nothing.
$fallback = new UpstreamStub([
    'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetNoaverageEmerdList.json' => ['list' => [
        ['issueNumber' => $mIssue->issueNumber, 'number' => '2'],
    ]],
]);
$multiFetcher = new \Lottery\Draw\DrawFetcher(
    $fallback, new \Lottery\Games\Families\RulesFactory(), 'https://draw.ar-lottery01.com',
    ['{base}/{family}/{code}/GetHistoryIssuePage.json', '{base}/{family}/{code}/GetNoaverageEmerdList.json'],
    true, 60, []
);
$viaSecond = $multiFetcher->fetchIssue($mGame, $mIssue->issueNumber);
TestRunner::ok('falls through to the next URL shape', $viaSecond !== null && $viaSecond['result']['number'] === 2);
TestRunner::equals('both shapes were attempted', 2, count($fallback->seen));

Clock::unfreeze();
