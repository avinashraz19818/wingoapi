<?php

declare(strict_types=1);

namespace Lottery\Draw;

use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Games\IssueNumber;
use Lottery\Support\Clock;
use Lottery\Support\Http;
use Lottery\Support\Log;

/**
 * Talks to the upstream draw provider.
 *
 * A provider profile (see config.php) supplies one or more URL shapes, the
 * family name used in the path and any required headers, e.g.
 *
 *   generic       {base}/{game}/{interval}.json
 *   ar-lottery01  {base}/{family}/{code}/GetHistoryIssuePage.json
 *
 * Candidate URLs are tried in order until one returns rows we can index; the
 * winner is remembered for the rest of the process. Responses are tolerated in
 * several shapes — {list:[...]}, {data:{list:[...]}} or a bare array — and rows
 * are indexed both by their exact issue number and by a date+sequence key, so a
 * provider that numbers its rounds slightly differently still lines up.
 *
 * Every failure is soft: the caller decides whether to fall back to the local
 * generator.
 */
class DrawFetcher
{
    private Http $http;
    private RulesFactory $rules;
    private string $baseUrl;
    /** @var array<int,string> */
    private array $templates;
    /** @var array<string,string> our family => provider family */
    private array $familyNames;
    private bool $enabled;
    private int $cooldown;

    /** @var array<string,array<string,array>|null> in-process memo: url => rows */
    private array $memo = [];
    /** @var array<string,int> url => unix ts until which we skip the provider */
    private array $cooldownUntil = [];
    /** @var array<string,string> gameCode => URL that last worked */
    private array $resolved = [];

    public function __construct(
        Http $http,
        RulesFactory $rules,
        string $baseUrl,
        $template = '{base}/{game}/{interval}.json',
        bool $enabled = true,
        int $cooldown = 60,
        array $familyNames = []
    ) {
        $this->http        = $http;
        $this->rules       = $rules;
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->templates   = array_values(array_unique(array_filter((array) $template)));
        $this->familyNames = $familyNames;
        $this->cooldown    = max(0, $cooldown);
        $this->enabled     = $enabled && $this->baseUrl !== '' && !self::isPlaceholder($this->baseUrl);

        if ($this->templates === []) {
            $this->templates = ['{base}/{game}/{interval}.json'];
        }
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

    /** Provider-side family name, e.g. D5 -> 5D. */
    public function providerFamily(GameDefinition $game): string
    {
        return $this->familyNames[$game->family] ?? $game->family;
    }

    /**
     * Every candidate endpoint for a game, in priority order.
     *
     * @return array<int,string>
     */
    public function endpoints(GameDefinition $game): array
    {
        $family = $this->providerFamily($game);
        $code   = $family === $game->family
            ? $game->code
            : $family . '_' . $game->intervalKey;   // 5D_1M for D5_1M

        $urls = [];
        foreach ($this->templates as $template) {
            $urls[] = strtr($template, [
                '{base}'     => $this->baseUrl,
                '{game}'     => $family,
                '{family}'   => $family,
                '{interval}' => $code,
                '{code}'     => $code,
                '{intervalKey}' => $game->intervalKey,
            ]);
        }

        return array_values(array_unique($urls));
    }

    /** The endpoint currently in use (last one that worked, else the first). */
    public function endpoint(GameDefinition $game): string
    {
        return $this->resolved[$game->code] ?? $this->endpoints($game)[0];
    }

    /**
     * Canonical result for one issue, or null when the provider does not
     * (yet) have it.
     *
     * @return array{result:array,hash:?string,row:array}|null
     */
    public function fetchIssue(GameDefinition $game, string $issueNumber): ?array
    {
        $rows = $this->fetchRows($game);
        if ($rows === null || $rows === []) {
            return null;
        }

        $row = $rows[$issueNumber] ?? $rows[$this->sequenceKey($issueNumber)] ?? null;
        if ($row === null) {
            return null;
        }

        $result = $this->rules->forGame($game)->fromProvider($row);
        if ($result === null) {
            Log::warning('provider row not understood', ['game' => $game->code, 'issue' => $issueNumber]);
            return null;
        }

        $result['verify'] = [
            'algorithm' => 'provider',
            'source'    => $this->endpoint($game),
            'issue'     => (string) ($row['issueNumber'] ?? $row['issue'] ?? $issueNumber),
        ];

        return [
            'result' => $result,
            'hash'   => isset($row['hash']) && is_scalar($row['hash']) ? (string) $row['hash'] : null,
            'row'    => $row,
        ];
    }

    /**
     * @return array<string,array>|null issueNumber (and date+seq key) => raw row
     */
    public function fetchRows(GameDefinition $game): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $now = Clock::now();

        foreach ($this->endpoints($game) as $url) {
            if (array_key_exists($url, $this->memo)) {
                $cached = $this->memo[$url];
                if ($cached !== null && $cached !== []) {
                    $this->resolved[$game->code] = $url;
                    return $cached;
                }
                continue;
            }

            // Back off after a failure so an unreachable provider cannot flood
            // the log (or stall the worker) once per round, per game.
            if (($this->cooldownUntil[$url] ?? 0) > $now) {
                continue;
            }

            $payload = $this->http->fetchArray($url);
            if ($payload === null) {
                $this->cooldownUntil[$url] = $now + $this->cooldown;
                $this->memo[$url]          = null;
                continue;
            }
            unset($this->cooldownUntil[$url]);

            $indexed = $this->indexRows($this->extractRows($payload));
            $this->memo[$url] = $indexed;

            if ($indexed !== []) {
                $this->resolved[$game->code] = $url;
                return $indexed;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<string,array>
     */
    private function indexRows(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $issue = $row['issueNumber'] ?? $row['issue'] ?? $row['issue_number'] ?? $row['period'] ?? null;
            if (!is_scalar($issue) || (string) $issue === '') {
                continue;
            }
            $issue = (string) $issue;

            $indexed[$issue] = $row;

            // Secondary key: date + 4-digit sequence, so a different game prefix
            // on the provider side still resolves to the right round.
            $key = $this->sequenceKey($issue);
            if ($key !== '' && !isset($indexed[$key])) {
                $indexed[$key] = $row;
            }
        }

        return $indexed;
    }

    /** "20260831#1054" from a 17-digit issue number. */
    private function sequenceKey(string $issueNumber): string
    {
        if (!IssueNumber::isValid($issueNumber)) {
            return '';
        }

        return substr($issueNumber, 0, 8) . '#' . substr($issueNumber, -4);
    }

    /** @return array<int,mixed> */
    private function extractRows(array $payload): array
    {
        foreach ([['list'], ['data', 'list'], ['data'], ['result'], ['rows'], ['data', 'rows']] as $path) {
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
