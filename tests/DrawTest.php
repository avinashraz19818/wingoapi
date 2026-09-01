<?php

declare(strict_types=1);

use Lottery\Draw\HmacRandom;
use Lottery\Draw\LocalDrawGenerator;
use Lottery\Games\Families\RulesFactory;
use Lottery\Support\Clock;

TestRunner::group('Draws — local HMAC fallback & overrides');

$app       = makeTestApp();
$registry  = $app->registry();
$scheduler = $app->scheduler();
$draws     = $app->draws();

$wingo = $registry->get('WinGo_1M');
$k3    = $registry->get('K3_1M');
$d5    = $registry->get('D5_1M');
$trx   = $registry->get('TrxWinGo_1M');
$moto  = $registry->get('MotoRace_1M');

Clock::freeze(strtotime('2026-08-31 12:05:30'));
$closed = $scheduler->previous($wingo);

$generator = new LocalDrawGenerator('test-draw-secret', new RulesFactory());
$a = $generator->draw($wingo, $closed->issueNumber);
$b = $generator->draw($wingo, $closed->issueNumber);
TestRunner::equals('local draw is deterministic', $a['result']['number'], $b['result']['number']);
TestRunner::equals('same hash for same seed', $a['hash'], $b['hash']);
TestRunner::ok('hash is sha256 hex', (bool) preg_match('/^[0-9a-f]{64}$/', $a['hash']));

$other = $generator->draw($k3, $closed->issueNumber);
TestRunner::ok('different game yields its own shape', isset($other['result']['dice']) && count($other['result']['dice']) === 3);

// Uniformity smoke test: 2000 draws of a digit should hit every face.
$random = new HmacRandom('secret', 'uniformity');
$counts = array_fill(0, 10, 0);
for ($i = 0; $i < 2000; $i++) {
    $counts[$random->int(0, 9)]++;
}
TestRunner::ok('rng covers all digits', min($counts) > 100, 'min bucket ' . min($counts));

// Persisting a result
$result = $draws->ensureResult($wingo, $closed);
TestRunner::ok('result stored for closed issue', $result !== null);
TestRunner::equals('source is local (no provider reachable)', 'local', $result['source']);
TestRunner::equals('stored issue matches', $closed->issueNumber, $result['issue_number']);

$again = $draws->ensureResult($wingo, $closed);
TestRunner::equals('result is immutable on re-run', $result['primary_number'], $again['primary_number']);

$open = $scheduler->current($wingo);
TestRunner::ok('running issue is not drawn', $draws->ensureResult($wingo, $open) === null);

// Every family can be drawn locally
foreach ([$k3, $d5, $trx, $moto] as $game) {
    $issue = $scheduler->previous($game);
    $drawn = $draws->ensureResult($game, $issue);
    TestRunner::ok($game->code . ' draws locally', $drawn !== null && $drawn['result'] !== []);
}

TestRunner::group('Draws — admin overrides');

$app2      = makeTestApp();
$registry2 = $app2->registry();
$wingo2    = $registry2->get('WinGo_1M');
$closed2   = $app2->scheduler()->previous($wingo2);

$app2->overrides()->set($wingo2, $closed2->issueNumber, '7', 'oneshot', 'tester');
$forced = $app2->draws()->ensureResult($wingo2, $closed2);
TestRunner::equals('override forces the number', 7, (int) $forced['primary_number']);
TestRunner::equals('override marks the source', 'override', $forced['source']);
TestRunner::equals('override auto-cleared', 0, count($app2->overrides()->listPending($wingo2->code)));

// Legacy one-shot: applies to the next drawn issue then disappears
$app3    = makeTestApp();
$wingo3  = $app3->registry()->get('WinGo_1M');
$sched3  = $app3->scheduler();
$prev    = $sched3->previous($wingo3);
$app3->overrides()->set($wingo3, '', '2', 'legacy', 'legacy-panel');
TestRunner::equals('legacy override queued', 1, count($app3->overrides()->listPending()));

$legacyResult = $app3->draws()->ensureResult($wingo3, $prev);
TestRunner::equals('legacy override applied to next draw', 2, (int) $legacyResult['primary_number']);
TestRunner::equals('legacy override deleted after use', 0, count($app3->overrides()->listPending()));

$older = $sched3->issueAt($wingo3, $prev->startTs - 120);
$plain = $app3->draws()->ensureResult($wingo3, $older);
TestRunner::equals('following draw falls back to local', 'local', $plain['source']);

TestRunner::throws('invalid override value rejected', static function () use ($app3, $wingo3, $sched3) {
    $app3->overrides()->set($wingo3, $sched3->current($wingo3)->issueNumber, '19');
}, 'Invalid selection');

TestRunner::group('Draws — force_remote_draw');

$strict = makeTestApp(['force_remote_draw' => true]);
$sg     = $strict->registry()->get('WinGo_1M');
$si     = $strict->scheduler()->previous($sg);
TestRunner::ok('no local fallback when force_remote_draw=true', $strict->draws()->ensureResult($sg, $si) === null);

Clock::unfreeze();

TestRunner::group('Draws — external provider integration');

/** Stub transport returning a canned provider payload. */
final class StubHttp extends \Lottery\Support\Http
{
    public array $requested = [];
    private array $payloads;

    public function __construct(array $payloads)
    {
        parent::__construct(5);
        $this->payloads = $payloads;
    }

    public function fetchArray(string $url, array $headers = []): ?array
    {
        $this->requested[] = $url;
        return $this->payloads[$url] ?? null;
    }
}

Clock::freeze(strtotime('2026-08-31 12:05:30'));

$app4   = makeTestApp();
$wingo4 = $app4->registry()->get('WinGo_1M');
$issue4 = $app4->scheduler()->previous($wingo4);
$url    = 'https://draw.invalid/WinGo/WinGo_1M.json';

$stub = new StubHttp([
    $url => ['data' => ['list' => [
        ['issueNumber' => $issue4->issueNumber, 'number' => '6', 'colour' => 'red'],
        ['issueNumber' => '20260831100010001', 'number' => '1'],
    ]]],
]);

$fetcher = new \Lottery\Draw\DrawFetcher($stub, new RulesFactory(), 'https://draw.invalid', '{base}/{game}/{interval}.json');
TestRunner::equals('endpoint template resolved', $url, $fetcher->endpoint($wingo4));

$remote = $fetcher->fetchIssue($wingo4, $issue4->issueNumber);
TestRunner::ok('provider row parsed', $remote !== null && $remote['result']['number'] === 6);
TestRunner::equals('provider colour derived from the digit', 'red', $remote['result']['color']);
TestRunner::ok('unknown issue returns null', $fetcher->fetchIssue($wingo4, '20260831100019999') === null);
TestRunner::equals('provider hit only once (memoised)', 1, count($stub->requested));

$service = new \Lottery\Draw\DrawService(
    $app4->db(), new RulesFactory(), $app4->scheduler(), $fetcher,
    new LocalDrawGenerator('test-draw-secret', new RulesFactory()), $app4->overrides(), false
);
$stored = $service->ensureResult($wingo4, $issue4);
TestRunner::equals('remote result stored', 6, (int) $stored['primary_number']);
TestRunner::equals('source marked remote', 'remote', $stored['source']);

// An override still beats the provider.
$app5   = makeTestApp();
$wingo5 = $app5->registry()->get('WinGo_1M');
$issue5 = $app5->scheduler()->previous($wingo5);
$stub5  = new StubHttp([$url => ['list' => [['issueNumber' => $issue5->issueNumber, 'number' => '6']]]]);
$svc5   = new \Lottery\Draw\DrawService(
    $app5->db(), new RulesFactory(), $app5->scheduler(),
    new \Lottery\Draw\DrawFetcher($stub5, new RulesFactory(), 'https://draw.invalid', '{base}/{game}/{interval}.json'),
    new LocalDrawGenerator('s', new RulesFactory()), $app5->overrides(), false
);
$app5->overrides()->set($wingo5, $issue5->issueNumber, '9', 'oneshot', 'test');
TestRunner::equals('override outranks the provider', 9, (int) $svc5->ensureResult($wingo5, $issue5)['primary_number']);

// TRX derives the digit from the block hash when no number is supplied.
$trxGame = $app4->registry()->get('TrxWinGo_1M');
$trxIssue = $app4->scheduler()->previous($trxGame);
$trxUrl   = 'https://draw.invalid/TrxWinGo/TrxWinGo_1M.json';
$trxStub  = new StubHttp([$trxUrl => ['list' => [[
    'issueNumber' => $trxIssue->issueNumber,
    'blockHash'   => '0000000003f1a2b9c8d7e6f504030201a9b8c7d6e5f40312233445566778893',
    'blockHeight' => '65123456',
]]]]);
$trxFetcher = new \Lottery\Draw\DrawFetcher($trxStub, new RulesFactory(), 'https://draw.invalid', '{base}/{game}/{interval}.json');
$trxResult  = $trxFetcher->fetchIssue($trxGame, $trxIssue->issueNumber);
TestRunner::equals('TRX digit from last numeric char of the hash', 3, $trxResult['result']['number']);
TestRunner::equals('TRX keeps the block height', '65123456', $trxResult['result']['blockHeight']);

Clock::unfreeze();

TestRunner::group('Draws — provider back-off & placeholder host');

$placeholderApp = makeTestApp(['draw_base_url' => 'https://draw.yourdomain.com']);
TestRunner::ok('sample provider host disables remote fetching', $placeholderApp->fetcher()->enabled() === false);

$offApp = makeTestApp(['draw_enabled' => false, 'draw_base_url' => 'https://draw.example.net']);
TestRunner::ok('DRAW_ENABLED=false disables remote fetching', $offApp->fetcher()->enabled() === false);

$onApp = makeTestApp(['draw_base_url' => 'https://draw.example.net']);
TestRunner::ok('a real provider host stays enabled', $onApp->fetcher()->enabled() === true);

// The placeholder path still produces results locally.
Clock::freeze(strtotime('2026-08-31 12:05:30'));
$pg    = $placeholderApp->registry()->get('WinGo_1M');
$pIssue = $placeholderApp->scheduler()->previous($pg);
TestRunner::equals('local draw still resolves without a provider', 'local',
    $placeholderApp->draws()->ensureResult($pg, $pIssue)['source']);

/** Counts how many times the transport is actually hit. */
final class CountingHttp extends \Lottery\Support\Http
{
    public int $calls = 0;
    public function __construct() { parent::__construct(1); }
    public function fetchArray(string $url, array $headers = []): ?array { $this->calls++; return null; }
}

$counter = new CountingHttp();
$backoff = new \Lottery\Draw\DrawFetcher($counter, new RulesFactory(), 'https://draw.example.net', '{base}/{game}/{interval}.json', true, 60);
$gameA   = $onApp->registry()->get('WinGo_1M');

$backoff->fetchIssue($gameA, '20260831100010001');
$backoff->flush();                       // clear the per-request memo only
$backoff->fetchIssue($gameA, '20260831100010002');
TestRunner::equals('failing endpoint is not retried during the cooldown', 1, $counter->calls);

Clock::freeze(Clock::now() + 120);
$backoff->flush();
$backoff->fetchIssue($gameA, '20260831100010003');
TestRunner::equals('endpoint is retried after the cooldown expires', 2, $counter->calls);

Clock::unfreeze();

TestRunner::group('Draws — give the provider a head start');

// A provider that only publishes a round 30s after it closes.
final class SlowProviderStub extends \Lottery\Support\Http
{
    public array $rows = [];
    public int $publishAt = 0;
    public function __construct() { parent::__construct(5); }
    public function fetchArray(string $url, array $headers = []): ?array
    {
        if (Clock::now() < $this->publishAt) {
            return ['list' => []];
        }
        return ['list' => $this->rows];
    }
}

Clock::freeze(strtotime('2026-09-01 12:00:30'));

$delayApp   = makeTestApp(['draw_base_url' => 'https://draw.example.net']);
$delayGame  = $delayApp->registry()->get('WinGo_1M');
$delayIssue = $delayApp->scheduler()->previous($delayGame);   // ended at 12:00:00

$slow            = new SlowProviderStub();
$slow->publishAt = strtotime('2026-09-01 12:00:20');
$slow->rows      = [['issueNumber' => $delayIssue->issueNumber, 'number' => '6']];

$slowFetcher = new \Lottery\Draw\DrawFetcher(
    $slow, new RulesFactory(), 'https://draw.example.net',
    ['{base}/{game}/{interval}.json'], true, 0, []
);
$slowDraws = new \Lottery\Draw\DrawService(
    $delayApp->db(), new RulesFactory(), $delayApp->scheduler(), $slowFetcher,
    new LocalDrawGenerator('s', new RulesFactory()), $delayApp->overrides(), false, 25
);

// 5 seconds after the round closed: the provider has nothing yet.
Clock::freeze(strtotime('2026-09-01 12:00:05'));
$slowFetcher->flush();
TestRunner::ok('no local draw while the provider may still publish',
    $slowDraws->ensureResult($delayGame, $delayIssue) === null);

// 25 seconds in, the provider has published — we mirror it.
Clock::freeze(strtotime('2026-09-01 12:00:25'));
$slowFetcher->flush();
$mirrored = $slowDraws->ensureResult($delayGame, $delayIssue);
TestRunner::ok('the provider result is used once available', $mirrored !== null);
TestRunner::equals('mirrored source', 'remote', $mirrored['source']);
TestRunner::equals('mirrored number', 6, (int) $mirrored['primary_number']);

// A provider that never publishes: local takes over after the grace period.
$deadApp   = makeTestApp(['draw_base_url' => 'https://draw.example.net']);
$deadGame  = $deadApp->registry()->get('WinGo_1M');
$deadIssue = $deadApp->scheduler()->previous($deadGame);

$dead        = new SlowProviderStub();
$dead->publishAt = strtotime('2026-09-01 23:59:59');   // never, in practice
$deadFetcher = new \Lottery\Draw\DrawFetcher(
    $dead, new RulesFactory(), 'https://draw.example.net',
    ['{base}/{game}/{interval}.json'], true, 0, []
);
$deadDraws = new \Lottery\Draw\DrawService(
    $deadApp->db(), new RulesFactory(), $deadApp->scheduler(), $deadFetcher,
    new LocalDrawGenerator('s', new RulesFactory()), $deadApp->overrides(), false, 25
);

Clock::freeze(strtotime('2026-09-01 12:00:10'));
$deadFetcher->flush();
TestRunner::ok('still waiting inside the grace period',
    $deadDraws->ensureResult($deadGame, $deadIssue) === null);

Clock::freeze(strtotime('2026-09-01 12:00:40'));
$deadFetcher->flush();
$fallback = $deadDraws->ensureResult($deadGame, $deadIssue);
TestRunner::ok('local generator takes over afterwards', $fallback !== null);
TestRunner::equals('fallback source', 'local', $fallback['source']);

// Games the provider does not serve are drawn locally straight away.
$motoApp = makeTestApp([
    'draw_base_url'           => 'https://draw.example.net',
    'draw_supported_families' => ['WinGo'],
    'draw_fallback_delay'     => 25,
]);
Clock::freeze(strtotime('2026-09-01 12:00:05'));
$moto      = $motoApp->registry()->get('MotoRace_1M');
$motoIssue = $motoApp->scheduler()->previous($moto);
$motoDrawn = $motoApp->draws()->ensureResult($moto, $motoIssue);
TestRunner::ok('an unsupported game does not wait', $motoDrawn !== null);
TestRunner::equals('and is drawn locally', 'local', $motoDrawn['source']);

Clock::unfreeze();

TestRunner::group('Draws — a long-running worker keeps polling');

/** Publishes one new round each time the clock moves on. */
final class RollingProviderStub extends \Lottery\Support\Http
{
    public int $calls = 0;
    /** @var array<string,string> issue => number */
    public array $published = [];
    public function __construct() { parent::__construct(5); }
    public function fetchArray(string $url, array $headers = []): ?array
    {
        $this->calls++;
        $rows = [];
        foreach ($this->published as $issue => $number) {
            $rows[] = ['issueNumber' => $issue, 'number' => $number];
        }
        return ['list' => $rows];
    }
}

Clock::freeze(strtotime('2026-09-01 12:00:40'));

$dayApp  = makeTestApp(['draw_base_url' => 'https://draw.example.net']);
$dayGame = $dayApp->registry()->get('WinGo_1M');
$rolling = new RollingProviderStub();

$rollingFetcher = new \Lottery\Draw\DrawFetcher(
    $rolling, new RulesFactory(), 'https://draw.example.net',
    ['{base}/{game}/{interval}.json'], true, 0, []
);
$rollingDraws = new \Lottery\Draw\DrawService(
    $dayApp->db(), new RulesFactory(), $dayApp->scheduler(), $rollingFetcher,
    new LocalDrawGenerator('s', new RulesFactory()), $dayApp->overrides(), false, 25
);

// Round A closed at 12:00:00 and the provider has it.
$issueA = $dayApp->scheduler()->issueAt($dayGame, strtotime('2026-09-01 11:59:30'));
$rolling->published[$issueA->issueNumber] = '4';
$a = $rollingDraws->ensureResult($dayGame, $issueA);
TestRunner::equals('first round mirrored', 'remote', $a['source']);

// A minute later the provider publishes round B. Still inside the grace
// period, so nothing is written yet — but the cached page cannot see it.
Clock::freeze(strtotime('2026-09-01 12:01:10'));
$issueB = $dayApp->scheduler()->issueAt($dayGame, strtotime('2026-09-01 12:00:30'));
$rolling->published[$issueB->issueNumber] = '9';

$callsBefore = $rolling->calls;
$stale       = $rollingDraws->ensureResult($dayGame, $issueB);
TestRunner::ok('a cached page cannot see the new round', $stale === null);
TestRunner::equals('and the provider was not even asked', $callsBefore, $rolling->calls);

$rollingDraws->flushProviderCache();
$b = $rollingDraws->ensureResult($dayGame, $issueB);
TestRunner::ok('after flushing, the provider is asked again', $rolling->calls > $callsBefore);
TestRunner::ok('and the new round is mirrored', $b !== null && $b['source'] === 'remote',
    'source=' . ($b['source'] ?? 'null'));
TestRunner::equals('with the provider number', 9, (int) $b['primary_number']);

// The grace period never eats a whole short round.
$shortApp   = makeTestApp(['draw_base_url' => 'https://draw.example.net', 'draw_fallback_delay' => 25]);
$shortGame  = $shortApp->registry()->get('WinGo_30S');   // 30s rounds
$shortIssue = $shortApp->scheduler()->previous($shortGame);
Clock::freeze($shortIssue->endTs + 14);                  // 14s after it closed
$short = $shortApp->draws()->ensureResult($shortGame, $shortIssue);
TestRunner::ok('a 30s round is settled within ~12s of closing', $short !== null);

Clock::unfreeze();
