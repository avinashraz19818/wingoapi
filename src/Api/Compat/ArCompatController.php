<?php

declare(strict_types=1);

namespace Lottery\Api\Compat;

use Lottery\App;
use Lottery\Betting\BetService;
use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Money;
use Lottery\Support\Validator;

/**
 * Serves an existing "AR style" front-end from this engine.
 *
 * The client keeps its UI, its login and its wallet screens; every lottery
 * call is answered here with the exact JSON shape it already understands, but
 * the numbers, the bets and the settlement all come from this platform.
 */
class ArCompatController
{
    private App $app;
    private array $input;
    /** @var array{id:int,mobile:string}|null */
    private ?array $user;
    /** @var array<int,string> games this site may show (empty = all) */
    private array $allowedGames;

    public function __construct(App $app, array $input, ?array $user = null, array $allowedGames = [])
    {
        $this->app          = $app;
        $this->input        = $input;
        $this->user         = $user;
        $this->allowedGames = array_map('strtolower', $allowedGames);
    }

    /** A site can be limited to a subset of games (admin panel -> Domains). */
    private function isAllowed(string $gameCode): bool
    {
        return $this->allowedGames === [] || in_array(strtolower($gameCode), $this->allowedGames, true);
    }

    /* ===================================================================
     |  Envelope (theirs, not ours)
     * ================================================================ */

    /** @param mixed $data */
    public static function ok($data = null, array $extra = []): array
    {
        return array_merge([
            'data'        => $data,
            'code'        => 0,
            'msg'         => 'Succeed',
            'msgCode'     => 0,
            'serviceTime' => Clock::nowMillis(),
            'serverTime'  => Clock::nowMillis(),
        ], $extra);
    }

    /** @param mixed $data */
    public static function fail(string $message, int $msgCode = 500, $data = null): array
    {
        return [
            'data'       => $data,
            'code'       => -1,
            'msg'        => $message,
            'msgCode'    => $msgCode,
            'serverTime' => Clock::nowMillis(),
        ];
    }

    /* ===================================================================
     |  Rounds
     * ================================================================ */

    /** The /webapi/kv/issue/<game> payload: current round + countdown. */
    public function issue(GameDefinition $game): array
    {
        $now     = Clock::now();
        $current = $this->app->scheduler()->current($game, $now);

        return self::ok($this->issueData($game, $now), ['serverTime' => Clock::nowMillis()]);
    }

    private function issueData(GameDefinition $game, int $now): array
    {
        $delayedTs = $now - $game->seconds;
        $current = $this->app->scheduler()->issueAt($game, $delayedTs);
        $next = $this->app->scheduler()->issueAt($game, $now);
        $interval = $game->seconds;
        $startTs = $now - ($now % $interval);
        $endTs = $startTs + $interval;
        $secondsLeft = max(0, $endTs - $now);
        $nowMs = $now * 1000;

        return [
            'startTime'       => $startTs * 1000,
            'endTime'         => $endTs * 1000,
            'openTime'        => $endTs * 1000,
            'issueNumber'     => $current->issueNumber,
            'issue_number'    => $current->issueNumber,
            'nextIssueNumber' => $next->issueNumber,
            'intervalMinute'  => $game->seconds / 60,
            'intervalM'       => $game->seconds / 60,
            'interval'        => $game->seconds,
            'gameCode'        => $game->code,
            'seconds'         => $secondsLeft,
            'secondsLeft'     => $secondsLeft,
            'countdown'       => $secondsLeft,
            'isLocked'        => ($secondsLeft <= 5),
            'serverTime'      => $nowMs,
            'serviceTime'     => $nowMs,
            'serverTimestamp' => $now,
            'serviceNowTime'  => date('Y-m-d H:i:s', $now),
            'diif'            => 0,
            'diff'            => 0,
            'current'         => [
                'issueNumber'  => $current->issueNumber,
                'issue_number' => $current->issueNumber,
                'startTime'    => $startTs * 1000,
                'endTime'      => $endTs * 1000,
                'serverTime'   => $nowMs,
                'seconds'      => $secondsLeft,
            ],
        ];
    }

    /* ===================================================================
     |  Catalogue
     * ================================================================ */

    /**
     * GetGameList.
     *
     * These clients sort tabs by `sort` descending, and the original payload
     * numbered them per family (WinGo 44,43,42… K3 34,33…), so the shortest
     * round comes first. The same numbering is reproduced here, otherwise the
     * tabs come out reversed (10 Min first, 30sec last).
     */
    private const GAME_TYPES  = ['WinGo' => 100, 'K3' => 101, 'D5' => 102, 'TrxWinGo' => 103, 'MotoRace' => 105];
    private const GAME_LABELS = ['WinGo' => 'WinGo', 'K3' => 'K3', 'D5' => '5D', 'TrxWinGo' => 'TrxWinGo', 'MotoRace' => 'MotoRace'];
    private const GROUP_SORT  = ['WinGo' => 1, 'MotoRace' => 2, 'D5' => 4, 'K3' => 5, 'TrxWinGo' => 6];
    private const GAME_SORT_BASE = ['WinGo' => 40, 'K3' => 30, 'D5' => 20, 'TrxWinGo' => 10, 'MotoRace' => 6];

    public function gameList(): array
    {
        $groups = [];

        foreach ($this->app->registry()->grouped() as $family => $games) {
            $allowed = [];
            foreach ($games as $game) {
                if ($this->isAllowed($game->code)) {
                    $allowed[] = $game;
                }
            }
            if ($allowed === []) {
                continue;
            }

            $label = self::GAME_LABELS[$family] ?? $family;
            $base  = self::GAME_SORT_BASE[$family] ?? 10;
            $count = count($allowed);
            $list  = [];

            foreach ($allowed as $index => $game) {
                $minutes = $game->seconds / 60;
                $name    = $label . ' ' . ($minutes < 1
                    ? $game->seconds . 'sec'
                    : rtrim(rtrim(number_format($minutes, 1, '.', ''), '0'), '.') . ' Min');

                $list[] = [
                    'gameCode'          => $game->code,
                    'lotteryCode'       => $family,
                    'name'              => $name,
                    'gameName'          => $name,
                    'gameNameEn'        => $name,
                    'gameTypeName'      => $label,
                    'status'            => $game->state,
                    'state'             => $game->state,
                    'intervalMinute'    => $minutes,
                    // descending: the first (shortest) round gets the highest
                    'sort'              => $base + ($count - $index),
                    'isGameMaintenance' => false,
                    'isPlatMaintenance' => false,
                ];
            }

            $groups[] = [
                'gameType'     => self::GAME_TYPES[$family] ?? 100,
                'gameTypeName' => $label,
                'lotteryCode'  => $family,
                'gameCode'     => $family,
                'categoryCode' => $family,
                'categoryName' => $label,
                'name'         => $label,
                'sort'         => self::GROUP_SORT[$family] ?? 9,
                'gameList'     => $list,
            ];
        }

        usort($groups, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return self::ok($groups);
    }

    /** GetGameInfo — odds table plus the live round. */
    public function gameInfo(GameDefinition $game): array
    {
        $rates = [];
        $id    = 50;

        foreach ($this->app->rules()->forGame($game)->betOptions() as $option) {
            foreach ($this->ratesFor($game, $option) as $row) {
                $rates[] = array_merge(['playTypeId' => $id++, 'state' => 1], $row);
            }
        }

        return self::ok(array_merge([
            'state'         => 1,
            'betScopes'     => $this->app->config('betting.bet_scopes'),
            'betMultiples'  => $this->app->config('betting.multiples'),
            'webSocketUrl'  => '',
            'gameCode'      => $game->code,
            'lotteryCode'   => ArTranslator::lotteryCode($game),
            'rates'         => $rates,
        ], $this->issueData($game, Clock::now())));
    }

    /** @return array<int,array<string,mixed>> */
    private function ratesFor(GameDefinition $game, array $option): array
    {
        $rows = [];
        $odds = (float) $option['odds'];

        switch ($game->family) {
            case 'WinGo':
            case 'TrxWinGo':
                if ($option['betType'] === 'number') {
                    return [['playType' => 'Num', 'playBet' => '0-9', 'playRate' => $odds]];
                }
                if ($option['betType'] === 'color') {
                    foreach (['green', 'red', 'violet'] as $colour) {
                        $rows[] = [
                            'playType' => 'Color',
                            'playBet'  => $colour,
                            'playRate' => (float) ($option['oddsMap'][$colour] ?? $odds),
                        ];
                    }
                    return $rows;
                }
                if ($option['betType'] === 'size') {
                    return [
                        ['playType' => 'BigSmall', 'playBet' => 'big', 'playRate' => $odds],
                        ['playType' => 'BigSmall', 'playBet' => 'small', 'playRate' => $odds],
                    ];
                }
                return [
                    ['playType' => 'OddEven', 'playBet' => 'O', 'playRate' => $odds],
                    ['playType' => 'OddEven', 'playBet' => 'E', 'playRate' => $odds],
                ];

            case 'K3':
                switch ($option['betType']) {
                    case 'total':
                        foreach (($option['oddsMap'] ?? []) as $sum => $rate) {
                            $rows[] = ['playType' => 'SumNum', 'playBet' => (string) $sum, 'playRate' => (float) $rate];
                        }
                        return $rows;
                    case 'size':
                        return [
                            ['playType' => 'SumBigSmall', 'playBet' => 'H', 'playRate' => $odds],
                            ['playType' => 'SumBigSmall', 'playBet' => 'L', 'playRate' => $odds],
                        ];
                    case 'parity':
                        return [
                            ['playType' => 'SumOddEven', 'playBet' => 'O', 'playRate' => $odds],
                            ['playType' => 'SumOddEven', 'playBet' => 'E', 'playRate' => $odds],
                        ];
                    case 'triple_any':
                        return [['playType' => 'NumSame3All', 'playBet' => '3TT', 'playRate' => $odds]];
                    case 'triple_exact':
                        return [['playType' => 'NumSame3', 'playBet' => '3TD', 'playRate' => $odds]];
                    case 'pair':
                        return [['playType' => 'NumSame2', 'playBet' => '2TD', 'playRate' => $odds]];
                    case 'two_different':
                        return [['playType' => 'NumDiff2', 'playBet' => '2BT', 'playRate' => $odds]];
                    case 'three_different':
                        return [['playType' => 'NumDiff3', 'playBet' => '3BT', 'playRate' => $odds]];
                }
                return [];

            case 'D5':
                foreach (['First', 'Second', 'Third', 'Fourth', 'Fifth'] as $position) {
                    if ($option['betType'] === 'number') {
                        $rows[] = ['playType' => $position . 'Num', 'playBet' => '0-9', 'playRate' => $odds];
                    } elseif ($option['betType'] === 'size') {
                        $rows[] = ['playType' => $position . 'BigSmall', 'playBet' => 'H', 'playRate' => $odds];
                        $rows[] = ['playType' => $position . 'BigSmall', 'playBet' => 'L', 'playRate' => $odds];
                    } else {
                        $rows[] = ['playType' => $position . 'OddEven', 'playBet' => 'O', 'playRate' => $odds];
                        $rows[] = ['playType' => $position . 'OddEven', 'playBet' => 'E', 'playRate' => $odds];
                    }
                }
                if ($option['betType'] === 'size') {
                    $rows[] = ['playType' => 'SumBigSmall', 'playBet' => 'H', 'playRate' => $odds];
                    $rows[] = ['playType' => 'SumBigSmall', 'playBet' => 'L', 'playRate' => $odds];
                }
                if ($option['betType'] === 'parity') {
                    $rows[] = ['playType' => 'SumOddEven', 'playBet' => 'O', 'playRate' => $odds];
                    $rows[] = ['playType' => 'SumOddEven', 'playBet' => 'E', 'playRate' => $odds];
                }
                return $rows;

            case 'MotoRace':
                if ($option['betType'] === 'champion') {
                    return [['playType' => 'FirstNum', 'playBet' => '1-10', 'playRate' => $odds]];
                }
                if ($option['betType'] === 'podium') {
                    return [
                        ['playType' => 'SecondNum', 'playBet' => '1-10', 'playRate' => $odds],
                        ['playType' => 'ThirdNum', 'playBet' => '1-10', 'playRate' => $odds],
                    ];
                }
                if ($option['betType'] === 'size') {
                    return [
                        ['playType' => 'FirstBigSmall', 'playBet' => 'H', 'playRate' => $odds],
                        ['playType' => 'FirstBigSmall', 'playBet' => 'L', 'playRate' => $odds],
                    ];
                }
                return [
                    ['playType' => 'FirstOddEven', 'playBet' => 'O', 'playRate' => $odds],
                    ['playType' => 'FirstOddEven', 'playBet' => 'E', 'playRate' => $odds],
                ];
        }

        return [];
    }

    /* ===================================================================
     |  Results
     * ================================================================ */

    /** GetHistoryIssuePage / GetNoaverageEmerdList */
    public function history(GameDefinition $game): array
    {
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 1000);
        $pageSize = Validator::int($this->input, 'pageSize', 10, 1, 100);

        $this->app->settlement()->settleDue($game, min(20, $pageSize + 5));

        $activeIssue = $this->app->draws()->resolveMaxIssue(
            $game,
            (string) ($this->input['activeIssue'] ?? $this->input['active_issue'] ?? '')
        );
        $rows  = $this->app->draws()->history($game, $pageSize, ($pageNo - 1) * $pageSize, $activeIssue);
        $total = $this->app->draws()->countHistory($game, $activeIssue);

        $list = [];
        foreach ($rows as $row) {
            $mapped = ArTranslator::fromEngineResult($game->family, $row['result'] ?? []);
            $issue  = (string) $row['issue_number'];

            $list[] = [
                'issueNumber'  => $issue,
                'issueNo'      => $issue,
                'issue'        => $issue,
                'number'       => $mapped['number'],
                'numberValue'  => $mapped['number'],
                'resultNumber' => $mapped['number'],
                'color'        => $mapped['color'],
                'colour'       => $mapped['color'],
                'premium'      => $mapped['premium'],
                'result'       => $mapped['premium'],
                'openCode'     => $mapped['premium'],
                'sum'          => $mapped['sum'],
                'sumValue'     => $mapped['sum'],
                'source'       => (string) $row['source'],
                'openTime'     => strtotime((string) $row['drawn_at']) * 1000,
                'serviceTime'  => Clock::nowMillis(),
            ];
        }

        return self::ok([
            'list'       => $list,
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalPage'  => (int) ceil(max(1, $total) / $pageSize),
            'totalCount' => $total,
        ]);
    }

    /** GetWinTheLotteryResult / GetResult */
    public function result(GameDefinition $game): array
    {
        $issueNumber = (string) ($this->input['issueNumber'] ?? $this->input['issue_number'] ?? $this->input['issue'] ?? '');
        if ($issueNumber === '') {
            throw ApiException::validation('Missing issueNumber');
        }

        $row = $this->app->draws()->find($game, $issueNumber);
        if ($row === null) {
            $this->app->settlement()->settleDue($game, 5);
            $row = $this->app->draws()->find($game, $issueNumber);
        }

        if ($row === null) {
            return self::fail('Result not found', 404);
        }

        // Held back by ISSUE_OFFSET: treat it exactly like a round that has not
        // been drawn yet, so the front-end keeps showing its waiting state.
        if (!$this->app->draws()->isVisible($game, $issueNumber)) {
            return self::fail('Result not found', 404);
        }

        $mapped = ArTranslator::fromEngineResult($game->family, $row['result'] ?? []);
        return self::ok([
            [
                'issueNumber'  => (string) $row['issue_number'],
                'issue_number' => (string) $row['issue_number'],
                'number'       => (string) $mapped['number'],
                'drawNumber'   => (string) $mapped['number'],
                'color'        => (string) $mapped['color'],
                'colour'       => (string) $mapped['color'],
                'premium'      => (string) $mapped['premium'],
                'sum'          => (int) $mapped['sum'],
                'openTime'     => strtotime((string) $row['drawn_at']) * 1000,
                'drawTime'     => (string) $row['drawn_at'],
                'state'        => -1,
                'winAmount'    => 0,
            ]
        ]);
    }

    /** GetTrendStatistics */
    public function trend(GameDefinition $game): array
    {
        $history = $this->history($game);
        $stats   = [];

        foreach (range(0, 9) as $digit) {
            $stats[(string) $digit] = ['appear' => 0, 'missing' => 0, 'maxContinuous' => 0];
        }

        $activeIssue = $this->app->draws()->resolveMaxIssue(
            $game,
            (string) ($this->input['activeIssue'] ?? $this->input['active_issue'] ?? '')
        );
        $engine = $this->app->trends()->statistics($game, 100, $activeIssue);
        foreach ($engine['positions']['number'] ?? [] as $row) {
            $value = (string) $row['value'];
            if (isset($stats[$value])) {
                $stats[$value] = [
                    'appear'        => (int) $row['openCount'],
                    'missing'       => (int) $row['missing'],
                    'maxContinuous' => (int) $row['maxContinuous'],
                ];
            }
        }

        return self::ok(['list' => $history['data']['list'] ?? [], 'statistics' => $stats]);
    }

    /* ===================================================================
     |  Wallet & betting
     * ================================================================ */

    /** GetUserInfo — the lottery screen's own copy of the player header. */
    public function userInfo(): array
    {
        $userId = $this->userId();
        $wallet = $this->app->wallet()->snapshot($userId);
        $vip    = $this->app->vip()->status($userId);

        return self::ok([
            'userId'        => $userId,
            'nickName'      => 'Player' . $userId,
            'userPhoto'     => '5',
            'userType'      => 0,
            'vipLevel'      => (int) $vip['level'],
            'walletBalance' => (float) $wallet['balance'],
            'balance'       => (float) $wallet['balance'],
            'amount'        => (float) $wallet['balance'],
            'safeBoxAmount' => 0.0,
            'lastLoginTime' => Clock::nowMillis(),
        ]);
    }

    public function balance(): array
    {
        return self::ok(['balance' => (float) $this->app->wallet()->balance($this->userId())]);
    }

    /** WinGoBet / K3Bet / D5Bet / MotoRaceBet / TrxWinGoBet */
    public function bet(GameDefinition $game): array
    {
        $userId   = $this->userId();
        $amount   = (float) ($this->input['amount'] ?? $this->input['betAmount'] ?? 0);
        $multiple = (int) ($this->input['betMultiple'] ?? $this->input['multiple'] ?? 1);
        $contents = $this->betContents();

        if ($contents === []) {
            return self::fail('Invalid bet content', 401);
        }
        if ($amount <= 0) {
            return self::fail('Invalid bet amount', 401);
        }

        $issue    = $this->app->scheduler()->current($game, Clock::now());
        $orderNo  = '';
        $accepted = 0;

        foreach ($contents as $index => $content) {
            $mapped = ArTranslator::toEngineBet($game, $content);

            $placement = $this->app->bets()->place($userId, [
                'gameCode'        => $game->code,
                'betType'         => $mapped['betType'],
                'betContent'      => $mapped['betContent'],
                'amount'          => $amount,
                'multiplier'      => max(1, $multiple),
                'issueNumber'     => $issue->issueNumber,
                'requestGroupKey' => substr(hash('sha256', $userId . '|' . $issue->issueNumber . '|' . implode('|', $contents)), 0, 32),
                'requestKey'      => substr(hash('sha256', $content . '|' . $amount . '|' . $multiple . '|' . $index), 0, 64),
                'source'          => 'manual',
            ]);

            $orderNo = $orderNo === '' ? (string) $placement['betNo'] : $orderNo;
            $accepted++;
        }

        $stake = Money::round($amount * max(1, $multiple) * $accepted);

        return self::ok([
            'orderNo'     => $orderNo,
            'balance'     => (float) $this->app->wallet()->balance($userId),
            'issueNumber' => $issue->issueNumber,
            'gameCode'    => $game->code,
            'amount'      => $amount,
            'betMultiple' => $multiple,
            'betAmount'   => $stake,
            'state'       => 1,
        ]);
    }

    /** @return array<int,string> */
    private function betContents(): array
    {
        $raw = $this->input['betContent'] ?? $this->input['content'] ?? '';

        if (is_array($raw)) {
            $items = [];
            foreach ($raw as $value) {
                if (is_array($value)) {
                    $items[] = (string) ($value['betContent'] ?? '');
                } elseif (is_scalar($value)) {
                    $items[] = trim((string) $value);
                }
            }
            return array_values(array_filter($items, static fn(string $v): bool => $v !== ''));
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        if ($text[0] === '[' || $text[0] === '{') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $items = [];
                foreach ($decoded as $value) {
                    if (is_array($value)) {
                        $items[] = (string) ($value['betContent'] ?? '');
                    } elseif (is_scalar($value)) {
                        $items[] = trim((string) $value);
                    }
                }
                return array_values(array_filter($items, static fn(string $v): bool => $v !== ''));
            }
        }

        return [$text];
    }

    /** GetRecordPage — the player's own bets. */
    public function records(?GameDefinition $game): array
    {
        $userId   = $this->userId();
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 1000);
        $pageSize = Validator::int($this->input, 'pageSize', 10, 1, 100);

        if ($game !== null) {
            $this->app->settlement()->settleDue($game, 5);
        }

        $history = $this->app->bets()->history($userId, $game?->code, $pageNo, $pageSize);

        $list = [];
        foreach ($history['list'] as $row) {
            $status = (string) $row['status'];
            $stake  = (float) $row['stake'];
            $payout = (float) $row['payout'];

            $list[] = [
                'orderNo'      => $row['betNo'],
                'issueNumber'  => $row['issueNumber'],
                'gameCode'     => $row['gameCode'],
                'lotteryCode'  => explode('_', (string) $row['gameCode'])[0],
                'playType'     => $row['betType'],
                'playBet'      => $row['betContent'],
                'betContent'   => $row['betType'] . '_' . $row['betContent'],
                'betContentList' => [$row['betType'] . '_' . $row['betContent']],
                'amount'       => (float) $row['amount'],
                'betMultiple'  => (int) $row['multiplier'],
                'betAmount'    => $stake,
                'stakeAmount'  => $stake,
                'winAmount'    => $payout,
                'profitAmount' => $payout - $stake,
                'winLoseAmount'=> $status === 'pending' ? 0.0 : ($payout > 0 ? $payout - $stake : -$stake),
                'status'       => $status,
                'state'        => $status === 'pending' ? 2 : ($status === 'won' ? 1 : 0),
                'createTime'   => strtotime((string) $row['createdAt']) * 1000,
                'settleTime'   => $row['settledAt'] ? strtotime((string) $row['settledAt']) * 1000 : 0,
            ];
        }

        return self::ok([
            'list'       => $list,
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalCount' => $history['total'],
            'totalPage'  => (int) ceil(max(1, $history['total']) / $pageSize),
        ]);
    }

    /** GetWinLossResult — the popup shown right after a round settles. */
    public function winLoss(GameDefinition $game): array
    {
        $issueNumber = trim((string) ($this->input['issueNumber'] ?? ''));
        if ($issueNumber === '') {
            $issueNumber = $this->app->scheduler()->previous($game)->issueNumber;
        }

        $issue = $this->app->scheduler()->fromIssueNumber($game, $issueNumber);
        $this->app->settlement()->settleIssue($game, $issue);

        $report = $this->app->settlement()->winLossForUser($this->userId(), $game, $issueNumber);
        $result = $report['result'] ?? null;
        $mapped = $result === null
            ? ['premium' => '', 'number' => '', 'color' => '', 'sum' => 0]
            : ArTranslator::fromEngineResult($game->family, $this->app->draws()->find($game, $issueNumber)['result'] ?? []);

        $stake  = (float) $report['stake'];
        $payout = (float) $report['payout'];

        return self::ok([
            'issueNumber'   => $issueNumber,
            'gameCode'      => $game->code,
            'hasBet'        => $report['betCount'] > 0,
            'state'         => $report['status'] === 'won' ? 1 : ($report['status'] === 'lost' ? 0 : 2),
            'status'        => $report['status'],
            'betAmount'     => $stake,
            'winAmount'     => $payout,
            'winLoseAmount' => $payout - $stake,
            'number'        => $mapped['number'],
            'premium'       => $mapped['premium'],
            'color'         => $mapped['color'],
            'balance'       => (float) $this->app->wallet()->balance($this->userId()),
        ]);
    }

    /* ===================================================================
     |  Static odds & content
     * ================================================================ */

    public function betLimit(): array
    {
        $min = (float) $this->app->config('betting.min_stake');
        $max = (float) $this->app->config('betting.max_stake');

        $rows = [];
        foreach (['Num', 'Color', 'BigSmall', 'OddEven', 'SumNum', 'SumBigSmall', 'SumOddEven'] as $type) {
            $rows[] = [
                'playType'            => $type,
                'minAmount'           => $min,
                'maxAmount'           => $max,
                'maxPayoutAmount'     => $max,
                'isSupportDoubleBet'  => 1,
            ];
        }

        return self::ok($rows);
    }

    public function introduce(): array
    {
        return self::ok([
            'title'   => 'Rules',
            'content' => 'Choose your numbers before the countdown ends. Stakes are taken from the game '
                . 'balance and winnings are credited automatically once the round is drawn.',
        ]);
    }

    public function emptyPage(): array
    {
        return self::ok([
            'list'       => [],
            'pageNo'     => 1,
            'pageSize'   => 10,
            'totalCount' => 0,
            'totalPage'  => 0,
        ]);
    }

    private function userId(): int
    {
        if ($this->user === null) {
            throw ApiException::auth('Login required');
        }

        return (int) $this->user['id'];
    }
}
