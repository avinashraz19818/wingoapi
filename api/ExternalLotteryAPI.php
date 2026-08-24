<?php
/**
 * External Lottery API Fetcher & Data Normalizer
 * Connects to external draw providers with retry logic, anti-block headers, and fallbacks.
 *
 * Timeouts are intentionally short: this class is called from live player requests (the
 * "live pull" that removes the post-countdown delay) as well as from the background worker.
 * A slow provider must never stall a countdown or a result popup.
 */

declare(strict_types=1);

class ExternalLotteryAPI {
    private float $timeout;
    private float $connectTimeout;

    /** Period length in seconds per game (used when the caller does not pass one). */
    private array $intervals = [
        'WinGo_30S' => 30,
        'WinGo_1M'  => 60,
        'WinGo_3M'  => 180,
        'WinGo_5M'  => 300,
        'WinGo_10M' => 600,
    ];

    public function __construct(?float $timeout = null, ?float $connectTimeout = null) {
        $this->timeout = $timeout ?? (defined('UPSTREAM_TIMEOUT') ? UPSTREAM_TIMEOUT : 3.0);
        $this->connectTimeout = $connectTimeout ?? (defined('UPSTREAM_CONNECT_TIMEOUT') ? UPSTREAM_CONNECT_TIMEOUT : 2.0);
    }

    public function getTimeout(): float {
        return $this->timeout;
    }

    /**
     * Fetch history from a single external URL.
     *
     * @param bool $allowFallback When true (worker mode) an unreachable provider is replaced by
     *                            the deterministic simulator. Live player requests must pass false
     *                            so that fabricated numbers can never settle a real bet.
     */
    public function fetchHistory(string $url, string $gameCode = 'WinGo_1M', bool $allowFallback = true, ?float $timeout = null): array {
        if (!function_exists('curl_init')) {
            return $allowFallback ? $this->generateFallbackHistory($gameCode, 20) : [];
        }

        $list = $this->httpGetJsonList($url, $timeout ?? $this->timeout);
        if ($list !== null) {
            return $list;
        }

        // Resilient Fallback Generator (only when explicitly allowed)
        return $allowFallback ? $this->generateFallbackHistory($gameCode, 20) : [];
    }

    /**
     * Fetch several games in parallel (curl_multi).
     * One full worker cycle then costs as much as the SLOWEST provider call instead of the sum
     * of all of them - this is what used to push results several seconds behind the countdown.
     *
     * @param array<string,string> $urlByGame game code => provider URL
     * @return array<string,array> game code => raw list ([] when unreachable)
     */
    public function fetchMany(array $urlByGame, bool $allowFallback = true): array {
        $out = [];
        foreach ($urlByGame as $game => $_) {
            $out[$game] = [];
        }

        if (empty($urlByGame) || !function_exists('curl_multi_init')) {
            foreach ($urlByGame as $game => $url) {
                $out[$game] = $this->fetchHistory($url, $game, $allowFallback);
            }
            return $out;
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($urlByGame as $game => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $this->curlOptions($this->timeout));
            curl_multi_add_handle($mh, $ch);
            $handles[$game] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.2);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $game => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $list = ($httpCode === 200 && !empty($response)) ? $this->extractList($response) : null;
            $out[$game] = $list ?? ($allowFallback ? $this->generateFallbackHistory($game, 20) : []);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $out;
    }

    /**
     * Single blocking GET that returns the decoded draw list, or null when unusable.
     */
    private function httpGetJsonList(string $url, float $timeout): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions($timeout));

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            return $this->extractList((string)$response);
        }
        return null;
    }

    private function curlOptions(float $timeout): array {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int)round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int)round($this->connectTimeout * 1000),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept: application/json, text/plain, */*',
                'Referer: https://ar-lottery01.com/',
            ],
            CURLOPT_ENCODING => '',
        ];
    }

    /**
     * Accept every known provider envelope shape.
     */
    private function extractList(string $response): ?array {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['data']['list']) && is_array($data['data']['list'])) {
            return $data['data']['list'];
        }
        if (isset($data['list']) && is_array($data['list'])) {
            return $data['list'];
        }
        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['issueNumber'])) {
            return [$data['data']];
        }
        return null;
    }

    /**
     * Normalize incoming draw record exactly from ar-lottery.
     * Returns null when the record carries no usable issue number.
     */
    public function normalizeResult(array $item, string $gameCode): ?array {
        $issueNumber = (string)($item['issueNumber'] ?? $item['issue_number'] ?? $item['issue'] ?? '');
        $issueNumber = trim($issueNumber);
        if ($issueNumber === '') {
            return null;
        }

        $number = (int)($item['number'] ?? 0);
        $color = !empty($item['color']) ? (string)$item['color'] : $this->calculateColorFromNumber($number);

        $rawTime = $item['drawTime'] ?? $item['draw_time'] ?? $item['endTime'] ?? $item['openTime'] ?? null;
        if (is_numeric($rawTime)) {
            // Providers sometimes send a millisecond epoch
            $ts = (int)$rawTime;
            $drawTime = date('Y-m-d H:i:s', $ts > 9999999999 ? intdiv($ts, 1000) : $ts);
        } elseif (!empty($rawTime)) {
            $parsed = strtotime((string)$rawTime);
            $drawTime = $parsed ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
        } else {
            $drawTime = date('Y-m-d H:i:s');
        }

        return [
            'game_code' => $gameCode,
            'issue_number' => $issueNumber,
            'number' => $number,
            'color' => $color,
            'premium' => (string)($item['premium'] ?? (string)$number),
            'sum' => isset($item['sum']) ? (int)$item['sum'] : $number,
            'draw_time' => $drawTime,
        ];
    }

    /**
     * Determine official WinGo color from 0-9 number
     */
    public function calculateColorFromNumber(int $num): string {
        if ($num === 0) return 'red,violet';
        if ($num === 5) return 'green,violet';
        if (in_array($num, [1, 3, 7, 9], true)) return 'green';
        if (in_array($num, [2, 4, 6, 8], true)) return 'red';
        return 'green';
    }

    public function getInterval(string $gameCode): int {
        return $this->intervals[$gameCode] ?? 60;
    }

    /**
     * Seconds elapsed since local midnight for the configured timezone.
     */
    public function secondsSinceLocalMidnight(int $timestamp): int {
        $offset = (int)date('Z', $timestamp);
        return ((($timestamp % 86400) + $offset) % 86400 + 86400) % 86400;
    }

    /**
     * Compute the provider's own issue number for the period that CONTAINS $timestamp.
     *
     * Real provider format (verified against stored draws): YYYYMMDD + 5-digit period index,
     * e.g. WinGo_1M period 12:11:00-12:12:00 IST  ->  2026082300732
     *      WinGo_30S period 12:11:30-12:12:00 IST ->  2026082301464
     * The index is floor(secondsSinceMidnight / interval) + 1, so the first period of the day
     * is 00001. Getting this wrong is fatal: a client would poll for an issue number the
     * provider never publishes and its result would never arrive.
     */
    public function calculateIssueNumberForTime(string $gameCode, int $timestamp, ?int $interval = null): string {
        $interval = ($interval && $interval > 0) ? $interval : $this->getInterval($gameCode);
        $periodIndex = intdiv($this->secondsSinceLocalMidnight($timestamp), $interval) + 1;

        return date('Ymd', $timestamp) . sprintf('%05d', $periodIndex);
    }

    /**
     * Period index (1-based, since local midnight) for a timestamp.
     */
    public function periodIndexForTime(int $timestamp, int $interval): int {
        $interval = $interval > 0 ? $interval : 60;
        return intdiv($this->secondsSinceLocalMidnight($timestamp), $interval) + 1;
    }

    /**
     * Fallback generator (only used when UPSTREAM_FALLBACK=1)
     */
    public function generateFallbackHistory(string $gameCode, int $count = 20): array {
        $interval = $this->getInterval($gameCode);
        $now = time();
        $list = [];

        for ($i = 0; $i < $count; $i++) {
            $drawTimestamp = $now - (($i + 1) * $interval);
            $issueNumber = $this->calculateIssueNumberForTime($gameCode, $drawTimestamp, $interval);
            $hash = md5($gameCode . '_' . $issueNumber);
            $num = hexdec(substr($hash, 0, 4)) % 10;

            $list[] = [
                'issueNumber' => $issueNumber,
                'number' => $num,
                'color' => $this->calculateColorFromNumber((int)$num),
                'premium' => (string)$num,
                'sum' => $num,
                'drawTime' => date('Y-m-d H:i:s', $drawTimestamp)
            ];
        }

        return $list;
    }
}
