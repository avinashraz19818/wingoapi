<?php
/**
 * 91Club / In999 / Daman / BDG Universal WebAPI Controller
 * Handles standard /api/webapi/* endpoints used across all color trading scripts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/ResultSyncService.php';
require_once __DIR__ . '/BetService.php';

$pdo = DB::getConnection();
$syncService = new ResultSyncService($pdo);
$betService = new BetService($pdo);

$payload = getRequestPayload();

// Helper to map typeId to gameCode
function resolveGameCode(mixed $typeId, mixed $gameParam): string {
    if (!empty($gameParam)) {
        return (string)$gameParam;
    }

    $t = (int)$typeId;
    return match ($t) {
        30, 0 => 'WinGo_30S',
        1     => 'WinGo_1M',
        2, 3  => 'WinGo_3M',
        5     => 'WinGo_5M',
        10    => 'WinGo_10M',
        default => 'WinGo_1M'
    };
}

$typeId = $payload['typeId'] ?? $payload['typeid'] ?? $_GET['typeid'] ?? $_GET['typeId'] ?? 1;
$gameCode = resolveGameCode($typeId, $payload['game_code'] ?? $_GET['game'] ?? null);
$pageSize = (int)($payload['pageSize'] ?? $payload['pagesize'] ?? $_GET['pageSize'] ?? $_GET['limit'] ?? 10);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$action = basename($uri);

// Remove any trailing .json or .php
$action = preg_replace('/\.(json|php)$/i', '', $action);

switch (strtolower($action)) {
    // 1. Get Draw History / List
    case 'getnoaverageemerdlist':
    case 'gethistoryissuepage':
    case 'gethistory':
    case 'history':
        $history = $syncService->getHistory($gameCode, $pageSize);
        $list = [];
        foreach ($history as $row) {
            $num = (int)$row['number'];
            $isBig = ($num >= 5);
            $list[] = [
                'issueNumber'  => (string)$row['issue_number'],
                'issue_number' => (string)$row['issue_number'],
                'number'       => $num,
                'drawNumber'   => $num,
                'colour'       => $row['color'],
                'color'        => $row['color'],
                'premium'      => (string)($row['premium'] ?? $num),
                'sum'          => (int)($row['sum'] ?? $num),
                'state'        => 1,
                'openTime'     => $row['draw_time'],
                'drawTime'     => $row['draw_time'],
                'typeId'       => (int)$typeId,
                'isBig'        => $isBig,
                'bs'           => $isBig ? 'big' : 'small'
            ];
        }

        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'msgCode' => 0,
            'data' => [
                'list' => $list,
                'pageNo' => 1,
                'pageSize' => $pageSize,
                'totalPage' => 1
            ],
            'time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // 2. Get Current Game Issue & Countdown
    case 'getgameissue':
    case 'getissue':
    case 'issue':
        $issue = $syncService->getCurrentIssue($gameCode);
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'msgCode' => 0,
            'data' => [
                'issueNumber'       => (string)$issue['issue_number'],
                'issue_number'      => (string)$issue['issue_number'],
                'nextIssueNumber'   => (string)$issue['next_issue_number'],
                'startTime'         => $issue['start_time'],
                'endTime'           => $issue['end_time'],
                'seconds'           => (int)$issue['seconds_left'],
                'secondsLeft'       => (int)$issue['seconds_left'],
                'isLocked'          => (bool)$issue['is_locked'],
                'typeId'            => (int)$typeId,
                'serverTime'        => $issue['server_time']
            ],
            'time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // 3. User Bet
    case 'wingobet':
    case 'bet':
    case 'placebet':
        $userId = (int)($payload['user_id'] ?? $payload['userId'] ?? 1001);
        $betType = (string)($payload['bet_type'] ?? $payload['betType'] ?? 'color');
        $betValue = (string)($payload['bet_value'] ?? $payload['betValue'] ?? $payload['selectType'] ?? 'green');
        $amount = (float)($payload['amount'] ?? $payload['money'] ?? 10.0);

        try {
            $receipt = $betService->placeBet($userId, $gameCode, $betType, $betValue, $amount);
            echo json_encode(['code' => 0, 'msg' => 'Bet placed successfully', 'data' => $receipt]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['code' => 1, 'msg' => $e->getMessage()]);
        }
        exit;

    // 4. User Bet History
    case 'getmyemerdlist':
    case 'userbets':
        $userId = (int)($payload['user_id'] ?? $payload['userId'] ?? $_GET['user_id'] ?? 1001);
        $bets = $betService->getUserBets($userId, $gameCode, $pageSize);
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $bets]
        ]);
        exit;

    default:
        // Default to returning draw history
        $history = $syncService->getHistory($gameCode, $pageSize);
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $history]
        ]);
        exit;
}
