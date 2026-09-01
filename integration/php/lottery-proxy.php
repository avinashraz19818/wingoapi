<?php
/**
 * Same-origin bridge between a game front-end and the lottery engine.
 *
 * Put this file in the site root and add three rewrites (see README-proxy.md).
 * It handles the three URL shapes these front-ends use:
 *
 *   /api/Lottery/<Action>                    -> engine action  (balance, bets, …)
 *   /<Family>/<GameCode>/<File>.json         -> engine result feed
 *   /webapi/(kv|v)/issue/<GameCode>          -> engine issue schedule
 *
 * Because everything stays on the site's own domain there is no CORS, and the
 * player's own token is forwarded so the engine can identify them.
 */

declare(strict_types=1);

/* ------------------------------------------------------------- settings */
const LOTTERY_BASE = 'https://api.devlopedwithzayro.site';
const LOTTERY_KEY  = 'PUT_YOUR_DOMAIN_API_KEY_HERE';   // admin panel → Domains
const TIMEOUT      = 15;

/* ------------------------------------------------------- work out the call */
$path  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$query = $_GET;
unset($query['action'], $query['path'], $query['gameCode']);

$target  = null;
$unwrap  = false;   // strip our envelope and return `data` directly

// 1. /webapi/kv/issue/WinGo_30S  (countdown + current issue)
if (preg_match('#/webapi/(?:kv|v)/issue/([A-Za-z0-9_]+)#i', $path, $m)) {
    $target = LOTTERY_BASE . '/api/Feed?action=Schedule&gameCode=' . rawurlencode($m[1]);
    $unwrap = true;

// 2. /WinGo/WinGo_30S/GetHistoryIssuePage.json  (results list)
} elseif (preg_match('#/(WinGo|TrxWinGo|K3|D5|MotoRace)/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)\.json#i', $path, $m)) {
    $target = LOTTERY_BASE . '/api/Feed?action=' . rawurlencode($m[3]) . '&gameCode=' . rawurlencode($m[2]);

// 3. /api/Lottery/GetBalance  (everything else)
} elseif (preg_match('#/api/Lottery/([A-Za-z0-9_]+)#i', $path, $m)) {
    $target = LOTTERY_BASE . '/api/Lottery?action=' . rawurlencode($m[1]);
} elseif (isset($_GET['action'])) {
    $action = preg_replace('/[^A-Za-z0-9_]/', '', (string) $_GET['action']) ?? '';
    $target = $action === '' ? null : LOTTERY_BASE . '/api/Lottery?action=' . rawurlencode($action);
}

if ($target === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['code' => 1001, 'msg' => 'Unrecognised lottery path: ' . $path, 'data' => null]);
    exit;
}

if ($query !== []) {
    $target .= '&' . http_build_query($query);
}

/* --------------------------------------------------- forward the request */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body   = $method === 'GET' ? null : file_get_contents('php://input');

// The player's own token, however the front-end sent it.
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('apache_request_headers')) {
    foreach ((apache_request_headers() ?: []) as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorization = (string) $value;
            break;
        }
    }
}
foreach (['HTTP_TOKEN', 'HTTP_X_TOKEN', 'HTTP_X_ACCESS_TOKEN'] as $alt) {
    if ($authorization === '' && !empty($_SERVER[$alt])) {
        $authorization = 'Bearer ' . $_SERVER[$alt];
    }
}
// Last resort: the site's own cookie/localStorage token passed as ?token=
if ($authorization === '' && !empty($_GET['token'])) {
    $authorization = 'Bearer ' . $_GET['token'];
}

$headers = [
    'Accept: application/json',
    'Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json'),
    'X-Api-Key: ' . LOTTERY_KEY,
    'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? ''),
];
if ($authorization !== '') {
    $headers[] = 'Authorization: ' . $authorization;
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
]);
if ($body !== null && $body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

/* ------------------------------------------------------------ answer */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($response === false) {
    http_response_code(502);
    echo json_encode(['code' => 1500, 'msg' => 'Lottery service unreachable: ' . $error, 'data' => null]);
    exit;
}

if ($unwrap) {
    $decoded = json_decode((string) $response, true);
    if (is_array($decoded) && isset($decoded['data'])) {
        echo json_encode($decoded['data'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

http_response_code($status ?: 200);
echo $response;
