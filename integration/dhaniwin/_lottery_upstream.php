<?php
/**
 * Lottery upstream bridge.
 *
 * When `lottery_upstream_url` is set in the admin settings, every lottery
 * endpoint (rounds, results, odds, bets, records, balance and the wallet
 * transfer) is answered by that engine instead of the local generator.
 * Nothing else on the site changes: the same URLs, the same JSON shapes, the
 * same login.
 *
 * Admin -> Settings:
 *   lottery_upstream_url   https://api.devlopedwithzayro.site/api/Compat
 *   lottery_upstream_key   <the API key of this domain>
 *   lottery_upstream_wallet 1   (optional: game wallet lives upstream too)
 *
 * Clearing lottery_upstream_url instantly reverts to the local behaviour.
 */

declare(strict_types=1);

function lottery_upstream_url(): string
{
    return rtrim(trim((string) api_setting('lottery_upstream_url', '')), '/');
}

function lottery_upstream_key(): string
{
    return trim((string) api_setting('lottery_upstream_key', ''));
}

function lottery_upstream_enabled(): bool
{
    return lottery_upstream_url() !== '';
}

/** Should the game wallet be read from (and moved to) the upstream engine? */
function lottery_upstream_wallet(): bool
{
    return lottery_upstream_enabled() && api_setting_bool('lottery_upstream_wallet', true);
}

/** The player's own token, exactly as the browser sent it. */
function lottery_upstream_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders() ?: [];
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if ($header !== '' && preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    return (string) ($_REQUEST['token'] ?? '');
}

/**
 * Call the upstream engine and return its decoded answer (already in this
 * site's own JSON dialect), or null when it cannot be reached.
 */
function lottery_upstream_call(string $action, array $input = [], string $method = 'POST'): ?array
{
    $base = lottery_upstream_url();
    if ($base === '') {
        return null;
    }

    $url = $base . '?action=' . rawurlencode($action);
    $token = lottery_upstream_token();

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Api-Key: ' . lottery_upstream_key(),
        'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? ''),
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[lottery-upstream] ' . $action . ' failed: ' . $err);
        return null;
    }

    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Handle a `Lottery/...` (or wallet) endpoint upstream.
 *
 * @return array|null null = let the local implementation handle it
 */
function lottery_upstream_handle_endpoint(string $endpoint, array $input): ?array
{
    if (!lottery_upstream_enabled()) {
        return null;
    }

    $normalised = strtolower(str_replace('\\', '/', $endpoint));
    $action     = basename($normalised);

    // Wallet endpoints: only when the game wallet lives upstream.
    if (lottery_upstream_wallet()) {
        if ($normalised === 'thirdgame/getargamebalance') {
            $answer = lottery_upstream_call('GetBalance', $input);
            if ($answer !== null && (int) ($answer['code'] ?? -1) === 0) {
                return api_success((float) ($answer['data']['balance'] ?? 0));
            }
        }
        if ($normalised === 'thirdgame/getargameandplatwallets') {
            $answer = lottery_upstream_call('GetBalance', $input);
            if ($answer !== null && (int) ($answer['code'] ?? -1) === 0) {
                $user = api_primary_user();
                return api_success([
                    ['vendorCode' => 'ARGame', 'balance' => (float) ($answer['data']['balance'] ?? 0), 'currency' => 'INR'],
                    ['vendorCode' => 'PlatForm', 'balance' => (float) $user['wallet_balance'], 'currency' => 'INR'],
                ]);
            }
        }
        if ($normalised === 'thirdgame/transfer' || $normalised === 'thirdgame/recoversaasbalance') {
            return lottery_upstream_transfer($input, $normalised === 'thirdgame/recoversaasbalance');
        }
    }

    if (strpos($normalised, 'lottery/') !== 0) {
        return null;
    }

    // Everything under Lottery/ is served by the engine.
    $payload = $input;
    if (!isset($payload['gameCode']) && isset($input['params']['gameCode'])) {
        $payload['gameCode'] = $input['params']['gameCode'];
    }

    $answer = lottery_upstream_call($action, $payload);
    if ($answer === null) {
        error_log('[lottery-upstream] falling back to local for ' . $endpoint);
        return null;   // engine unreachable: keep the site alive locally
    }

    return $answer;
}

/**
 * Move money between this site's main wallet and the upstream game wallet.
 *
 * The site wallet stays the source of truth for deposits and withdrawals; the
 * engine holds only what the player moved into the game.
 */
function lottery_upstream_transfer(array $input, bool $recover = false): array
{
    $pdo  = api_pdo();
    $user = api_primary_user();

    $balanceAnswer = lottery_upstream_call('GetBalance', $input);
    $gameBalance   = (float) ($balanceAnswer['data']['balance'] ?? 0);
    $wallet        = (float) $user['wallet_balance'];

    $amount    = (float) api_param($input, 'amount', api_param($input, 'transferAmount', 0));
    $direction = strtolower((string) api_param($input, 'direction', api_param($input, 'transferType', '')));

    $toGame = !$recover;
    if (in_array($direction, ['recover', 'togamewallet', '2', 'out'], true)) {
        $toGame = false;
    }
    if (in_array($direction, ['togame', 'ingame', '1', 'in'], true)) {
        $toGame = true;
    }

    if ($amount <= 0) {
        $amount = $toGame ? $wallet : $gameBalance;
    }
    if ($amount <= 0) {
        return api_success(['walletBalance' => $wallet, 'gameBalance' => $gameBalance]);
    }
    if ($toGame && $wallet < $amount) {
        return api_error('Insufficient balance', 142, -1, ['walletBalance' => $wallet, 'gameBalance' => $gameBalance]);
    }
    if (!$toGame && $gameBalance < $amount) {
        return api_error('Insufficient balance', 142, -1, ['walletBalance' => $wallet, 'gameBalance' => $gameBalance]);
    }

    $orderId = ($toGame ? 'IN-' : 'OUT-') . $user['id'] . '-' . round(microtime(true) * 1000);

    // Money leaves one side before it lands on the other, so a failure can
    // never create funds out of nothing.
    if ($toGame && $pdo) {
        $stmt = $pdo->prepare("UPDATE api_users SET wallet_balance = wallet_balance - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND wallet_balance >= ?");
        $stmt->execute([$amount, $user['id'], $amount]);
        if ($stmt->rowCount() < 1) {
            return api_error('Insufficient balance', 142, -1, ['walletBalance' => $wallet, 'gameBalance' => $gameBalance]);
        }
    }

    $answer = lottery_upstream_transfer_call($user, $amount, $toGame ? 'in' : 'out', $orderId);

    if ($answer === null || (int) ($answer['code'] ?? -1) !== 0) {
        if ($toGame && $pdo) {
            // Give it back: the engine did not take the money.
            $stmt = $pdo->prepare("UPDATE api_users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $user['id']]);
        }
        return api_error('Transfer failed, please try again', 403, -1, [
            'walletBalance' => $wallet,
            'gameBalance'   => $gameBalance,
        ]);
    }

    if (!$toGame && $pdo) {
        $stmt = $pdo->prepare("UPDATE api_users SET wallet_balance = wallet_balance + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$amount, $user['id']]);
    }

    $fresh    = api_primary_user();
    $newGame  = (float) ($answer['data']['balance'] ?? $gameBalance);
    api_audit('thirdgame_transfer_upstream', $toGame ? 'wallet_to_game' : 'game_to_wallet', [
        'amount' => $amount, 'orderId' => $orderId,
    ]);

    return api_success([
        'walletBalance' => (float) $fresh['wallet_balance'],
        'gameBalance'   => $newGame,
        'amount'        => $amount,
    ]);
}

/** PartnerTransfer on the engine (server-to-server, keyed by our API key). */
function lottery_upstream_transfer_call(array $user, float $amount, string $direction, string $orderId): ?array
{
    $base = lottery_upstream_url();
    if ($base === '') {
        return null;
    }

    // PartnerTransfer lives on the player API, next to the compat endpoint.
    $url = preg_replace('#/api/Compat$#i', '/api/Lottery', $base) . '?action=PartnerTransfer';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Api-Key: ' . lottery_upstream_key(),
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'externalUserId' => (string) $user['user_id'],
            'amount'         => $amount,
            'direction'      => $direction,
            'orderId'        => $orderId,
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : null;
}

/**
 * Draw routes (/webapi/kv/issue/X and /WinGo/WinGo_1M/*.json).
 *
 * @return array|null
 */
function lottery_upstream_draw(string $gameCode, string $action = 'GetGameIssue', array $input = []): ?array
{
    if (!lottery_upstream_enabled() || $gameCode === '') {
        return null;
    }

    $answer = lottery_upstream_call($action, array_merge($input, ['gameCode' => $gameCode]));

    return $answer;
}
