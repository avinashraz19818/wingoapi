<?php

declare(strict_types=1);

namespace Lottery\Api;

use Lottery\App;
use Lottery\Games\GameDefinition;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Validator;

/**
 * The public result feed — this is what you hand to your customers.
 *
 * URLs mirror the shape upstream providers use, so an existing client only has
 * to swap the host name:
 *
 *   /WinGo/WinGo_1M/GetHistoryIssuePage.json      last N drawn rounds
 *   /WinGo/WinGo_1M/GetNoaverageEmerdList.json    alias of the above
 *   /WinGo/WinGo_1M/GetGameIssue.json             current round + countdown
 *   /api/Feed?action=GameList                     everything we publish
 *
 * Access is limited to whitelisted domains (see Lottery\Tenant\DomainService).
 */
class FeedController
{
    private App $app;
    private array $input;

    public function __construct(App $app, array $input)
    {
        $this->app   = $app;
        $this->input = $input;
    }

    /** Games we publish, with their feed URLs. */
    public function gameList(string $baseUrl = ''): array
    {
        $now  = Clock::now();
        $list = [];

        foreach ($this->app->registry()->all() as $game) {
            $issue  = $this->app->scheduler()->current($game, $now);
            $list[] = [
                'gameCode'        => $game->code,
                'lottery'         => $game->family,
                'name'            => $game->name,
                'interval'        => $game->intervalKey,
                'intervalSeconds' => $game->seconds,
                'issuePrefix'     => $game->familyCode . $game->intervalCode,
                'currentIssue'    => $issue->issueNumber,
                'remaining'       => $issue->remainingSeconds($now),
                'endpoints'       => [
                    'history' => $baseUrl . '/' . $game->family . '/' . $game->code . '/GetHistoryIssuePage.json',
                    'issue'   => $baseUrl . '/' . $game->family . '/' . $game->code . '/GetGameIssue.json',
                ],
            ];
        }

        return ['serverTime' => Clock::dateTime($now), 'timezone' => $this->app->config('app.timezone'), 'list' => $list];
    }

    /**
     * Drawn rounds, newest first — the endpoint customers poll.
     */
    public function history(GameDefinition $game): array
    {
        $pageNo   = Validator::int($this->input, 'pageNo', 1, 1, 1000);
        $pageSize = Validator::int($this->input, 'pageSize', 10, 1, 100);

        // Make sure everything that has finished is drawn before we serve it.
        $this->app->settlement()->settleDue($game, min(20, $pageSize + 5));

        $rows  = $this->app->draws()->history($game, $pageSize, ($pageNo - 1) * $pageSize);
        $total = $this->app->draws()->countHistory($game);

        return [
            'gameCode'   => $game->code,
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalCount' => $total,
            'totalPage'  => (int) ceil($total / $pageSize),
            'list'       => array_map(fn(array $row): array => $this->row($game, $row), $rows),
        ];
    }

    /** Current round with a countdown, for client-side timers. */
    public function issue(GameDefinition $game): array
    {
        $now      = Clock::now();
        $current  = $this->app->scheduler()->current($game, $now);
        $previous = $this->app->scheduler()->previous($game, $now);
        $last     = $this->app->draws()->find($game, $previous->issueNumber);

        return [
            'gameCode'      => $game->code,
            'issueNumber'   => $current->issueNumber,
            'startTime'     => date('Y-m-d H:i:s', $current->startTs),
            'endTime'       => date('Y-m-d H:i:s', $current->endTs),
            'remaining'     => $current->remainingSeconds($now),
            'bettingOpen'   => $current->isOpenAt($now),
            'serverTime'    => Clock::dateTime($now),
            'lastIssue'     => $last === null ? null : $this->row($game, $last),
        ];
    }

    /** One specific round. */
    public function result(GameDefinition $game): array
    {
        $issueNumber = Validator::issueNumber(Validator::requireString($this->input, 'issueNumber', 17));
        $issue       = $this->app->scheduler()->fromIssueNumber($game, $issueNumber);

        $this->app->settlement()->settleIssue($game, $issue);
        $row = $this->app->draws()->find($game, $issueNumber);

        if ($row === null) {
            throw ApiException::notFound('That round has not been drawn yet');
        }

        return $this->row($game, $row);
    }

    /**
     * Feed row. Keeps the field names upstream clients already parse
     * (issueNumber / number / colour / premium) and adds normalised extras.
     */
    private function row(GameDefinition $game, array $stored): array
    {
        $result = $stored['result'] ?? [];

        $row = [
            'issueNumber' => (string) $stored['issue_number'],
            'gameCode'    => $game->code,
            'drawTime'    => (string) $stored['drawn_at'],
            'source'      => (string) $stored['source'],
        ];

        switch ($game->family) {
            case 'WinGo':
            case 'TrxWinGo':
                $number = (int) ($result['number'] ?? 0);
                $row += [
                    'number'  => $number,
                    'colour'  => (string) ($result['color'] ?? ''),
                    'color'   => (string) ($result['color'] ?? ''),
                    'premium' => (string) $number,
                    'size'    => (string) ($result['size'] ?? ''),
                    'parity'  => (string) ($result['parity'] ?? ''),
                ];
                if ($game->family === 'TrxWinGo') {
                    $row['blockHash']   = $result['blockHash'] ?? null;
                    $row['blockHeight'] = $result['blockHeight'] ?? null;
                }
                break;

            case 'K3':
                $dice = array_map('intval', (array) ($result['dice'] ?? []));
                $row += [
                    'dice'    => $dice,
                    'openCode'=> implode(',', $dice),
                    'number'  => (int) ($result['sum'] ?? 0),
                    'sum'     => (int) ($result['sum'] ?? 0),
                    'size'    => (string) ($result['size'] ?? ''),
                    'parity'  => (string) ($result['parity'] ?? ''),
                    'triple'  => (bool) ($result['triple'] ?? false),
                    'premium' => (string) ($result['sum'] ?? 0),
                ];
                break;

            case 'D5':
                $digits = array_map('intval', (array) ($result['digits'] ?? []));
                $row += [
                    'digits'   => $digits,
                    'openCode' => (string) ($result['code'] ?? ''),
                    'number'   => (string) ($result['code'] ?? ''),
                    'sum'      => (int) ($result['sum'] ?? 0),
                    'size'     => (string) ($result['size'] ?? ''),
                    'parity'   => (string) ($result['parity'] ?? ''),
                    'premium'  => (string) ($result['sum'] ?? 0),
                ];
                foreach (['a', 'b', 'c', 'd', 'e'] as $index => $key) {
                    $row[strtoupper($key)] = $digits[$index] ?? 0;
                }
                break;

            case 'MotoRace':
                $ranking = array_map('intval', (array) ($result['ranking'] ?? []));
                $row += [
                    'ranking'  => $ranking,
                    'openCode' => implode(',', $ranking),
                    'champion' => (int) ($result['champion'] ?? 0),
                    'number'   => (int) ($result['champion'] ?? 0),
                    'podium'   => array_map('intval', (array) ($result['podium'] ?? [])),
                    'size'     => (string) ($result['size'] ?? ''),
                    'parity'   => (string) ($result['parity'] ?? ''),
                    'premium'  => (string) ($result['champion'] ?? 0),
                ];
                break;

            default:
                $row['raw'] = $result;
        }

        return $row;
    }
}
