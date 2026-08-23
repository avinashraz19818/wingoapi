<?php
/**
 * Shared API Helper: Headers, CORS, and Uniform JSON Responses
 */

declare(strict_types=1);

// Enable CORS for frontend integration
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
