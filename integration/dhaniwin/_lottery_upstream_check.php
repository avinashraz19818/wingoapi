<?php
/**
 * Bridge self-check.
 *
 * Upload next to the other api/ files and open it in a browser:
 *
 *   https://your-site.com/api/_lottery_upstream_check.php
 *
 * It reports, in order, exactly which step is missing. Delete the file once
 * everything reads OK.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';

$line = static function (string $label, $value, ?bool $ok = null): void {
    $mark = $ok === null ? ' ' : ($ok ? '+' : 'x');
    printf("[%s] %-26s %s\n", $mark, $label, is_scalar($value) ? (string) $value : json_encode($value));
};

echo "LOTTERY BRIDGE CHECK\n";
echo str_repeat('=', 52) . "\n\n";

/* 1. is the bridge file loaded? */
$loaded = function_exists('lottery_upstream_handle_endpoint');
$line('bridge file loaded', $loaded ? 'yes' : 'NO  <- upload api/_lottery_upstream.php', $loaded);

if (!$loaded) {
    echo "\nFix: upload api/_lottery_upstream.php, and make sure api/_bootstrap.php\n";
    echo "     has these lines right after declare(strict_types=1);\n\n";
    echo "     if (is_file(__DIR__ . '/_lottery_upstream.php')) {\n";
    echo "         require_once __DIR__ . '/_lottery_upstream.php';\n";
    echo "     }\n";
    exit;
}

/* 2. database + settings */
$pdo = api_pdo();
$line('database', $pdo ? 'connected' : 'NOT CONNECTED', (bool) $pdo);

$url = lottery_upstream_url();
$key = lottery_upstream_key();
$line('lottery_upstream_url', $url !== '' ? $url : 'EMPTY  <- add it in Admin > Settings', $url !== '');
$line('lottery_upstream_key', $key !== '' ? substr($key, 0, 6) . '…' . substr($key, -4) : 'EMPTY', $key !== '');
$line('lottery_upstream_wallet', lottery_upstream_wallet() ? 'on' : 'off');

if ($url === '') {
    echo "\nFix: Admin panel > Settings > add\n";
    echo "     lottery_upstream_url   = https://api.devlopedwithzayro.site/api/Compat\n";
    echo "     lottery_upstream_key   = <your domain api key>\n";
    exit;
}

/* 3. can we reach the engine? */
echo "\nCalling the engine…\n";
$answer = lottery_upstream_call('GetGameIssue', ['gameCode' => 'WinGo_30S']);

if ($answer === null) {
    $line('engine reachable', 'NO', false);
    echo "\nFix: the server could not call {$url}\n";
    echo "     - is outbound HTTPS allowed from this host?\n";
    echo "     - check the PHP error log for [lottery-upstream]\n";
    exit;
}

$line('engine reachable', 'yes', true);
$line('engine code', $answer['code'] ?? 'missing', (int) ($answer['code'] ?? -1) === 0);
$line('engine msg', $answer['msg'] ?? '');

$issue = $answer['data']['issueNumber'] ?? '';
$line('engine issue number', $issue !== '' ? $issue : 'missing', $issue !== '');

if ((int) ($answer['code'] ?? -1) !== 0) {
    echo "\nThe engine refused the call. Common causes:\n";
    echo " - this domain is not whitelisted there (panel > Domains)\n";
    echo " - the API key is wrong\n";
    exit;
}

/* 3b. can we identify a player? */
$probeToken = (string) ($_GET['token'] ?? '');
if ($probeToken !== '') {
    echo "\nResolving the supplied token…\n";

    $pdo = api_pdo();
    $row = null;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, nickname, wallet_balance, game_balance FROM api_users WHERE token = ? LIMIT 1");
            $stmt->execute([$probeToken]);
            $row = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
        }
    }

    $line('token belongs to', $row ? ('user ' . $row['user_id'] . ' (' . $row['nickname'] . ')') : 'NOBODY — token not in api_users', (bool) $row);

    if ($row) {
        $line('site wallet', $row['wallet_balance']);
        $line('site game balance', $row['game_balance']);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $probeToken;
        $engineBalance = lottery_upstream_call('GetBalance', []);
        $line(
            'engine game wallet',
            $engineBalance === null ? 'call failed' : ($engineBalance['data']['balance'] ?? 'n/a'),
            $engineBalance !== null
        );
        echo "\n  (this is what the game screen shows — move money with Transfer\n";
        echo "   or from the engine panel to change it)\n";
    }
} else {
    echo "\nTip: add ?token=<a real value from localStorage.ar_token> to this URL\n";
    echo "     to see which player it resolves to and what the game shows them.\n";
}

/* 4. what the site itself would answer */
$local = api_lottery_issue_data('WinGo_30S');
$line('local issue number', $local['issueNumber']);

echo "\n";
if ($issue !== '' && $issue !== $local['issueNumber']) {
    echo "RESULT: the bridge works. The site will now serve the engine's rounds\n";
    echo "        ({$issue}) instead of its own ({$local['issueNumber']}).\n";
} else {
    echo "RESULT: the engine answered, but with the same issue number as the local\n";
    echo "        generator — check that the engine is on the ar-lottery01 profile.\n";
}

echo "\nDelete this file when you are done.\n";
