<?php
/**
 * Shared API Helper: Dynamic CORS, Headers, and Uniform JSON Responses
 */

declare(strict_types=1);

// Prevent header duplication & dynamically allow origin (supports https://in999.club9.eu.cc)
if (!headers_sent()) {
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization,Token,token,authorization,x-token,X-Token,Accept,Origin,x-auth-token');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');

    // Draw results / countdowns must never be served from a browser, CDN or nginx cache -
    // a cached response is exactly what makes history and bet popups appear seconds late.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Handle preflight OPTIONS requests immediately
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Send uniform JSON success response
 */
function jsonSuccess(mixed $data = null, string $message = 'Success', int $code = 0): void {
    echo json_encode([
        'code' => $code,
        'msg' => $message,
        'data' => $data,
        'time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send uniform JSON error response
 */
function jsonError(string $message = 'Error', int $code = 1, int $httpStatus = 400, mixed $extra = null): void {
    http_response_code($httpStatus);
    $response = [
        'code' => $code,
        'msg' => $message,
        'time' => date('Y-m-d H:i:s')
    ];
    if ($extra !== null) {
        $response['details'] = $extra;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse input from both $_POST and JSON body
 */
function getRequestPayload(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return array_merge($_POST, $json);
    }
    return $_POST;
}
