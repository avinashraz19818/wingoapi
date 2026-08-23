<?php
/**
 * 91Club / In999 / Daman Universal WebAPI Controller
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

// Helper to map In999 typeId to standard gameCode
function resolveGameCode(mixed $typeId, mixed $gameParam): string {
    if (!empty($gameParam)) {
        $g = (string)$gameParam;
        if (str_starts_with(strtolower($g), 'wingo_')) return $g;
        if ($g === '30s' || $g === '30S' || $g === '30') return 'WinGo_30S';
        if ($g === '1m' || $g === '1M') return 'WinGo_1M';
        if ($g === '3m' || $g === '3M') return 'WinGo_3M';
        if ($g === '5m' || $g === '5M') return 'WinGo_5M';
        if ($g === '10m' || $g === '10M') return 'WinGo_10M';
    }

    $t = is_numeric($typeId) ? (int)$typeId : 2;
    return match ($t) {
        1, 30, 0 => 'WinGo_30S',  // 1, 30, 0 = 30 Seconds
        2        => 'WinGo_1M',   // 2 = 1 Minute
        3        => 'WinGo_3M',   // 3 = 3 Minutes
        4        => 'WinGo_5M',   // 4 = 5 Minutes
        5, 10    => 'WinGo_10M',  // 5 = 10 Minutes
        default  => 'WinGo_1M'
    };
}

function parseBetTypeAndValue(mixed $selectType, mixed $betType, mixed $betValue): array {
    if (!empty($betType) && !empty($betValue)) {
        return [(string)$betType, (string)$betValue];
    }
    
    $st = is_numeric($selectType) ? (int)$selectType : strtolower(trim((string)$selectType));
    
    if (is_int($st)) {
        if ($st >= 0 && $st <= 9) return ['number', (string)$st];
        if ($st === 10) return ['color', 'green'];
        if ($st === 11) return ['color', 'violet'];
        if ($st === 12) return ['color', 'red'];
        if ($st === 13) return ['big_small', 'big'];
        if ($st === 14) return ['big_small', 'small'];
        if ($st === 15) return ['odd_even', 'odd'];
        if ($st === 16) return ['odd_even', 'even'];
    }

    if (in_array($st, ['green', 'red', 'violet'], true)) return ['color', $st];
    if (in_array($st, ['big', 'small'], true)) return ['big_small', $st];
    if (in_array($st, ['odd', 'even'], true)) return ['odd_even', $st];
    if (is_numeric($st) && (int)$st >= 0 && (int)$st <= 9) return ['number', (string)(int)$st];

    return ['color', 'green'];
}

$typeId = $payload['typeId'] ?? $payload['typeid'] ?? $_GET['typeid'] ?? $_GET['typeId'] ?? 2;
$gameCode = resolveGameCode($typeId, $payload['game_code'] ?? $payload['game'] ?? $_GET['game'] ?? null);
$pageSize = (int)($payload['pageSize'] ?? $payload['pagesize'] ?? $_GET['pageSize'] ?? $_GET['limit'] ?? 10);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$action = basename($uri);
$action = preg_replace('/\.(json|php)$/i', '', $action);

switch (strtolower($action)) {
    // 1. History Endpoint: POST /api/webapi/GetNoaverageEmerdList
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
                'number'       => (string)$num,
                'drawNumber'   => (string)$num,
                'colour'       => (string)$row['color'],
                'color'        => (string)$row['color'],
                'premium'      => (string)($row['premium'] ?? $num),
                'sum'          => (int)($row['sum'] ?? $num),
                'state'        => 1,
                'openTime'     => (string)$row['draw_time'],
                'drawTime'     => (string)$row['draw_time'],
                'typeId'       => (int)$typeId,
                'isBig'        => $isBig,
                'bs'           => $isBig ? 'big' : 'small'
            ];
        }

        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'msgCode' => 0,
            'serviceNowTime' => date('Y-m-d H:i:s'),
            'data' => [
                'list' => $list,
                'pageNo' => 1,
                'pageSize' => $pageSize,
                'totalPage' => 10,
                'totalCount' => 100
            ],
            'time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // 2. Timer & Period Endpoint: POST /api/webapi/GetGameIssue
    case 'getgameissue':
    case 'getissue':
    case 'issue':
        $issue = $syncService->getCurrentIssue($gameCode);

        // In999 JavaScript explicitly executes: .serviceTime.replace(/-/g, "/") and .startTime.replace(/-/g, "/")
        // Therefore, serviceTime and startTime MUST be date strings formatted as 'YYYY-MM-DD HH:MM:SS'!
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'msgCode' => 0,
            'serviceNowTime' => date('Y-m-d H:i:s'),
            'data' => [
                'issueNumber'       => (string)$issue['issue_number'],
                'issue_number'      => (string)$issue['issue_number'],
                'nextIssueNumber'   => (string)$issue['next_issue_number'],
                'startTime'         => (string)$issue['start_time'],
                'endTime'           => (string)$issue['end_time'],
                'openTime'          => (string)$issue['end_time'],
                'serviceTime'       => date('Y-m-d H:i:s'),
                'seconds'           => (int)$issue['seconds_left'],
                'secondsLeft'       => (int)$issue['seconds_left'],
                'interval'          => (int)$issue['interval'],
                'intervalM'         => (float)($issue['interval'] == 30 ? 0.5 : round($issue['interval'] / 60, 1)),
                'isLocked'          => (bool)$issue['is_locked'],
                'typeId'            => (int)$typeId,
                'serverTime'        => $issue['server_time'],
                'serviceNowTime'    => $issue['server_time'],
                'serverTimestamp'   => (int)$issue['server_timestamp']
            ],
            'time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // 3. Bet Place Endpoint: POST /api/webapi/WinGoBet
    case 'wingobet':
    case 'bet':
    case 'placebet':
    case 'betting':
        $userId = (int)($payload['user_id'] ?? $payload['userId'] ?? 1001);
        $amount = (float)($payload['amount'] ?? $payload['money'] ?? $payload['betAmount'] ?? 10.0);
        $selectType = $payload['selectType'] ?? $payload['select'] ?? $payload['bet_value'] ?? $payload['betValue'] ?? 'green';
        $betTypeParam = $payload['bet_type'] ?? $payload['betType'] ?? null;
        $betValueParam = $payload['bet_value'] ?? $payload['betValue'] ?? null;

        [$betType, $betValue] = parseBetTypeAndValue($selectType, $betTypeParam, $betValueParam);

        try {
            $receipt = $betService->placeBet($userId, $gameCode, $betType, $betValue, $amount);
            echo json_encode([
                'code' => 0,
                'msg' => 'Bet placed successfully',
                'msgCode' => 0,
                'data' => [
                    'betId' => $receipt['bet_id'],
                    'bet_id' => $receipt['bet_id'],
                    'issueNumber' => $receipt['issue_number'],
                    'amount' => $receipt['amount'],
                    'odds' => $receipt['odds'],
                    'balance' => $receipt['wallet_balance'],
                    'created_at' => $receipt['created_at']
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'code' => 1,
                'msg' => $e->getMessage(),
                'msgCode' => 1
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;

    // 4. User Bet History: POST /api/webapi/GetMyEmerdList
    case 'getmyemerdlist':
    case 'userbets':
    case 'mybets':
        $userId = (int)($payload['user_id'] ?? $payload['userId'] ?? $_GET['user_id'] ?? 1001);
        $bets = $betService->getUserBets($userId, $gameCode, $pageSize);
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'msgCode' => 0,
            'data' => [
                'list' => $bets,
                'pageNo' => 1,
                'pageSize' => $pageSize,
                'totalPage' => 1
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    default:
        // Default history fallback
        $history = $syncService->getHistory($gameCode, $pageSize);
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $history]
        ], JSON_UNESCAPED_UNICODE);
        exit;
}
