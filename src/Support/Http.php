<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Minimal outbound HTTP client (cURL with a stream fallback) used to talk to
 * the draw provider. Never throws: callers get a structured result and decide
 * whether to fall back to the local generator.
 */
class Http
{
    private int $timeout;
    private string $userAgent;

    public function __construct(int $timeout = 5, string $userAgent = 'LotteryAPI/4.0')
    {
        $this->timeout   = max(1, $timeout);
        $this->userAgent = $userAgent;
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string,elapsed_ms:int}
     */
    public function getJson(string $url, array $headers = []): array
    {
        $started = microtime(true);
        $result  = function_exists('curl_init')
            ? $this->viaCurl($url, $headers)
            : $this->viaStream($url, $headers);
        $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /**
     * Fetch + decode. Returns null on any transport or decode failure.
     */
    public function fetchArray(string $url, array $headers = []): ?array
    {
        $res = $this->getJson($url, $headers);
        if (!$res['ok']) {
            Log::warning('draw provider request failed', ['url' => $url, 'error' => $res['error'], 'status' => $res['status']]);
            return null;
        }
        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            Log::warning('draw provider returned non-JSON payload', ['url' => $url]);
            return null;
        }
        return $decoded;
    }

    private function viaCurl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 3),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'ok'     => $error === null && $status >= 200 && $status < 300 && is_string($body),
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
            'error'  => $error,
        ];
    }

    private function viaStream(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'header'        => implode("\r\n", array_merge(
                    ['Accept: application/json', 'User-Agent: ' . $this->userAgent],
                    $headers
                )),
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body   = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [
            'ok'     => is_string($body) && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
            'error'  => is_string($body) ? null : 'stream request failed',
        ];
    }
}
