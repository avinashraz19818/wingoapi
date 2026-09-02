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
    /** @var array<int,string> headers sent with every request */
    private array $defaultHeaders;
    private bool $verifySsl;

    public function __construct(
        int $timeout = 5,
        string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        array $defaultHeaders = [],
        bool $verifySsl = true
    ) {
        $this->timeout        = max(1, $timeout);
        $this->userAgent      = $userAgent;
        $this->defaultHeaders = $defaultHeaders;
        $this->verifySsl      = $verifySsl;
    }

    /**
     * @return array{ok:bool,status:int,body:string,error:?string,elapsed_ms:int}
     */
    public function getJson(string $url, array $headers = []): array
    {
        $started = microtime(true);
        $headers = array_merge($this->defaultHeaders, $headers);
        $result  = function_exists('curl_init')
            ? $this->viaCurl($url, $headers)
            : $this->viaStream($url, $headers);
        $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /**
     * POST a JSON body and decode the answer. Null on any failure.
     */
    public function postArray(string $url, array $body, array $headers = []): ?array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = array_merge($this->defaultHeaders, $headers, ['Content-Type: application/json']);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min($this->timeout, 3),
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_errno($ch) !== 0 ? curl_error($ch) : null;
            curl_close($ch);

            if ($error !== null || $status < 200 || $status >= 300 || !is_string($raw)) {
                Log::warning('partner POST failed', ['url' => $url, 'status' => $status, 'error' => $error]);
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'header'        => implode("\r\n", $headers),
                'content'       => $payload,
            ],
            'ssl' => ['verify_peer' => $this->verifySsl, 'verify_peer_name' => $this->verifySsl],
        ]);
        $raw     = @file_get_contents($url, false, $context);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
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
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json, text/plain, */*'], $headers),
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
            'ssl' => ['verify_peer' => $this->verifySsl, 'verify_peer_name' => $this->verifySsl],
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
