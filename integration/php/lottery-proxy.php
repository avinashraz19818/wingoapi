<?php
/**
 * Same-origin proxy for the lottery API.
 *
 * Drop this file in the ROOT of your site and add one rewrite rule (below).
 * Every /api/Lottery/* call the browser makes then stays on YOUR domain — the
 * front-end attaches its usual token automatically, there is no CORS at all,
 * and this file forwards the request (token included) to the lottery engine.
 *
 *   browser  →  https://your-site.com/api/Lottery/GetBalance   (same origin)
 *   this file →  https://api.example.com/api/Lottery/GetBalance
 *                 + Authorization: <the player's own token>
 *                 + X-Api-Key: <your partner key>
 *
 * .htaccess (Apache / cPanel) — put this ABOVE your other rules:
 *
 *   RewriteEngine On
 *   RewriteRule ^api/Lottery/(.*)$ lottery-proxy.php [QSA,L]
 *
 * Nginx:
 *   location /api/Lottery/ { try_files $uri /lottery-proxy.php$is_args$args; }
 */

declare(strict_types=1);

/* ------------------------------------------------------------- settings */
const LOTTERY_BASE = 'https://api.devlopedwithzayro.site';
const LOTTERY_KEY  = 'PUT_YOUR_DOMAIN_API_KEY_HERE';   // admin panel → Domains
const TIMEOUT      = 15;

/* --------------------------------------------------------- what to call */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// /api/Lottery/GetBalance  ->  GetBalance
$action = '';
if (preg_match('#/api/Lottery/([A-Za-z0-9_]+)#i', $path, $m)) {
    $action = $m[1];
} elseif (isset($_GET['action'])) {
    $action = preg_replace('/[^A-Za-z0-9_]/', '', (string) $_GET['action']) ?? '';
}

if ($action === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['code' => 1001, 'msg' => 'Missing lottery action', 'data' => null]);
    exit;
}

$query = $_GET;
unset($query['action']);
$target = rtrim(LOTTERY_BASE, '/') . '/api/Lottery?action=' . rawurlencode($action)
    . ($query !== [] ? '&' . http_build_query($query) : '');

/* ------------------------------------------------- forward the request */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body   = $method === 'GET' ? null : file_get_contents('php://input');

// The player's own token, exactly as the browser sent it to us.
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('apache_request_headers')) {
    foreach ((apache_request_headers() ?: []) as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $authorization = (string) $value;
            break;
        }
    }
}
// Some front-ends use a different header name — pass those along too.
foreach (['HTTP_TOKEN', 'HTTP_X_TOKEN', 'HTTP_X_ACCESS_TOKEN'] as $alt) {
    if ($authorization === '' && !empty($_SERVER[$alt])) {
        $authorization = 'Bearer ' . $_SERVER[$alt];
    }
}

$headers = [
    'Accept: application/json',
    'Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json'),
    'X-Api-Key: ' . LOTTERY_KEY,
    // Tells the engine which partner site this player belongs to.
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

/* ------------------------------------------------------- send it back */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'code' => 1500,
        'msg'  => 'Lottery service unreachable: ' . $error,
        'data' => null,
    ]);
    exit;
}

http_response_code($status ?: 200);
echo $response;
