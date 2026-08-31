<?php

declare(strict_types=1);

namespace Lottery\Draw;

use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Support\Http;
use Lottery\Support\Log;

/**
 * Talks to the external draw provider.
 *
 *   {draw_base_url}/{game}/{interval}.json     (template is configurable)
 *   e.g. https://draw.yourdomain.com/WinGo/WinGo_1M.json
 *
 * Responses are tolerated in several shapes — {list:[...]}, {data:{list:[...]}},
 * or a bare array — and are indexed by issue number. All failures are soft: the
 * caller decides whether to fall back to the local generator.
 */
class DrawFetcher
{
    private Http $http;
    private string $baseUrl;
    private string $template;
    private RulesFactory $rules;
    /** @var array<string,array<string,array>> in-request memo: url => rows */
    private array $memo = [];

    public function __construct(Http $http, RulesFactory $rules, string $baseUrl, string $template)
    {
        $this->http     = $http;
        $this->rules    = $rules;
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->template = $template;
    }

    public function endpoint(GameDefinition $game): string
    {
        return strtr($this->template, [
            '{base}'     => $this->baseUrl,
            '{game}'     => $game->family,
            '{interval}' => $game->code,
            '{family}'   => $game->family,
            '{code}'     => $game->code,
        ]);
    }

    /**
     * Canonical result for one issue, or null when the provider does not
     * (yet) have it.
     *
     * @return array{result:array,hash:?string}|null
     */
    public function fetchIssue(GameDefinition $game, string $issueNumber): ?array
    {
        $rows = $this->fetchRows($game);
        if ($rows === null || !isset($rows[$issueNumber])) {
            return null;
        }

        $row    = $rows[$issueNumber];
        $result = $this->rules->forGame($game)->fromProvider($row);
        if ($result === null) {
            Log::warning('provider row not understood', ['game' => $game->code, 'issue' => $issueNumber]);
            return null;
        }

        $result['verify'] = ['algorithm' => 'provider', 'source' => $this->endpoint($game)];

        return [
            'result' => $result,
            'hash'   => isset($row['hash']) && is_scalar($row['hash']) ? (string) $row['hash'] : null,
        ];
    }

    /**
     * @return array<string,array>|null issueNumber => raw row
     */
    public function fetchRows(GameDefinition $game): ?array
    {
        $url = $this->endpoint($game);
        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }

        $payload = $this->http->fetchArray($url);
        if ($payload === null) {
            return $this->memo[$url] = null;
        }

        $rows    = $this->extractRows($payload);
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $issue = $row['issueNumber'] ?? $row['issue'] ?? $row['issue_number'] ?? $row['period'] ?? null;
            if (is_scalar($issue) && $issue !== '') {
                $indexed[(string) $issue] = $row;
            }
        }

        return $this->memo[$url] = $indexed;
    }

    /** @return array<int,mixed> */
    private function extractRows(array $payload): array
    {
        foreach ([['list'], ['data', 'list'], ['data'], ['result'], ['rows']] as $path) {
            $cursor = $payload;
            foreach ($path as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if (is_array($cursor) && $cursor !== [] && array_is_list($cursor)) {
                return $cursor;
            }
        }

        return array_is_list($payload) ? $payload : [];
    }

    /** Testing/ops hook: drop the in-request cache. */
    public function flush(): void
    {
        $this->memo = [];
    }
}
