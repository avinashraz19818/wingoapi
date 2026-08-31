<?php

declare(strict_types=1);

namespace Lottery\Draw;

use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Support\Clock;
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
    private bool $enabled;
    private int $cooldown;
    /** @var array<string,array<string,array>> in-request memo: url => rows */
    private array $memo = [];
    /** @var array<string,int> url => unix ts until which we skip the provider */
    private array $cooldownUntil = [];

    public function __construct(
        Http $http,
        RulesFactory $rules,
        string $baseUrl,
        string $template,
        bool $enabled = true,
        int $cooldown = 60
    ) {
        $this->http     = $http;
        $this->rules    = $rules;
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->template = $template;
        $this->cooldown = max(0, $cooldown);
        $this->enabled  = $enabled && $this->baseUrl !== '' && !self::isPlaceholder($this->baseUrl);
    }

    /**
     * Placeholder hosts from the sample config: treat them as "no provider
     * configured" instead of hammering them once per round.
     */
    public static function isPlaceholder(string $baseUrl): bool
    {
        foreach (['yourdomain.com', 'example.com', 'example.org'] as $needle) {
            if (stripos($baseUrl, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** False when no usable provider is configured (local draws only). */
    public function enabled(): bool
    {
        return $this->enabled;
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
        if (!$this->enabled) {
            return null;
        }

        $url = $this->endpoint($game);
        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }

        // Back off after a failure so an unreachable provider cannot flood the
        // log (or stall the worker) once per round, per game.
        $now = Clock::now();
        if (($this->cooldownUntil[$url] ?? 0) > $now) {
            return $this->memo[$url] = null;
        }

        $payload = $this->http->fetchArray($url);
        if ($payload === null) {
            $this->cooldownUntil[$url] = $now + $this->cooldown;
            return $this->memo[$url] = null;
        }
        unset($this->cooldownUntil[$url]);

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

    /** Drop the per-request response cache (the back-off window survives). */
    public function flush(): void
    {
        $this->memo = [];
    }

    /** Forget any provider back-off, e.g. after fixing the configuration. */
    public function resetBackoff(): void
    {
        $this->cooldownUntil = [];
    }
}
