<?php

declare(strict_types=1);

/**
 * Lottery API client — drop this file into your own site.
 *
 * It talks to the lottery engine as a *partner site*: your platform keeps the
 * users and the main wallet, the engine runs the games.
 *
 *   $lottery = new LotteryClient('https://api.devlopedwithzayro.site', 'YOUR_API_KEY');
 *
 *   $token = $lottery->login($userId, $userName);   // when the user opens a game
 *   $lottery->transferIn($userId, 500, 'DEP-991');  // move money into the game
 *   $lottery->transferOut($userId, 200, 'WDR-992'); // move it back
 *   $balance = $lottery->balance($userId);
 *
 * No Composer, no dependencies — plain PHP 7.4+.
 */
class LotteryClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 10)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /* ------------------------------------------------------------------ */
    /*  1. Log a user in — call this when they open the lottery            */
    /* ------------------------------------------------------------------ */

    /**
     * @param  string|int $userId   YOUR user id (as stored on your site)
     * @return array{token:string,userId:int,balance:string}
     */
    public function login($userId, string $nickname = ''): array
    {
        $data = $this->post('PartnerLogin', [
            'externalUserId' => (string) $userId,
            'nickname'       => $nickname,
        ]);

        return [
            'token'   => $data['token'],
            'userId'  => (int) $data['userId'],
            'balance' => $data['balance'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  2. Move money in and out of the game wallet                        */
    /* ------------------------------------------------------------------ */

    /**
     * Deposit into the game wallet.
     *
     * $orderId MUST be unique per transfer (use your own transaction id).
     * Re-sending the same orderId never moves the money twice, so it is safe
     * to retry after a timeout.
     */
    public function transferIn($userId, float $amount, string $orderId): array
    {
        return $this->post('PartnerTransfer', [
            'externalUserId' => (string) $userId,
            'amount'         => $amount,
            'direction'      => 'in',
            'orderId'        => $orderId,
        ]);
    }

    /** Withdraw from the game wallet back to your platform. */
    public function transferOut($userId, float $amount, string $orderId): array
    {
        return $this->post('PartnerTransfer', [
            'externalUserId' => (string) $userId,
            'amount'         => $amount,
            'direction'      => 'out',
            'orderId'        => $orderId,
        ]);
    }

    /** Everything in the game wallet, so you can show it or sweep it back. */
    public function balance($userId): array
    {
        return $this->get('PartnerBalance', ['externalUserId' => (string) $userId]);
    }

    /** That user's bets in the lottery. */
    public function bets($userId, int $pageNo = 1, int $pageSize = 20): array
    {
        return $this->get('PartnerBets', [
            'externalUserId' => (string) $userId,
            'pageNo'         => $pageNo,
            'pageSize'       => $pageSize,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  3. Public data (no key needed, but handy to proxy)                 */
    /* ------------------------------------------------------------------ */

    public function gameList(): array
    {
        return $this->get('GetGameList');
    }

    public function history(string $gameCode, int $pageSize = 10): array
    {
        return $this->get('GetHistoryIssuePage', ['gameCode' => $gameCode, 'pageSize' => $pageSize]);
    }

    /* ------------------------------------------------------------------ */
    /*  transport                                                          */
    /* ------------------------------------------------------------------ */

    private function get(string $action, array $params = []): array
    {
        $url = $this->baseUrl . '/api/Lottery?action=' . rawurlencode($action);
        if ($params !== []) {
            $url .= '&' . http_build_query($params);
        }

        return $this->send($url, null);
    }

    private function post(string $action, array $body): array
    {
        return $this->send(
            $this->baseUrl . '/api/Lottery?action=' . rawurlencode($action),
            json_encode($body, JSON_UNESCAPED_SLASHES)
        );
    }

    private function send(string $url, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Lottery API unreachable: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Lottery API returned invalid JSON (HTTP ' . $status . ')');
        }
        if (($decoded['code'] ?? -1) !== 0) {
            throw new RuntimeException(
                'Lottery API error [' . ($decoded['msgCode'] ?? 'ERROR') . ']: ' . ($decoded['msg'] ?? 'unknown')
            );
        }

        return $decoded['data'] ?? [];
    }
}
