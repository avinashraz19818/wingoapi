<?php
require_once dirname(__DIR__, 2) . '/saas_lottery/bootstrap_live_v4.php';

$action = isset($_GET['action']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['action']) : '';
$input = sl_input();

function follow_plans(): array
{
    $avatar = 'https://img.ar-lottery.com/lotterysaas-imgs/5.png';
    $base = [
        [
            'id' => 6, 'name' => 'Micheal', 'headImgUrl' => $avatar,
            'playType' => 'BigSmall', 'playBet' => '', 'isSupportDoubleBet' => 1,
            'followUserCount' => 2930, 'actualMaxOrderReturnRate' => 97.5500,
            'actualTotalBetAmount' => 1606573614.55, 'actualTotalProfitAmount' => 96091441.40,
            'followPlayType' => 'SameDirection',
        ],
        [
            'id' => 1, 'name' => 'Micheal', 'headImgUrl' => $avatar,
            'playType' => 'BigSmall', 'playBet' => 'big', 'isSupportDoubleBet' => 1,
            'followUserCount' => 418, 'actualMaxOrderReturnRate' => 96.0000,
            'actualTotalBetAmount' => 212887361.54, 'actualTotalProfitAmount' => 8540857.63,
            'followPlayType' => 'FixedDirection',
        ],
        [
            'id' => 3, 'name' => 'Micheal', 'headImgUrl' => $avatar,
            'playType' => 'Color', 'playBet' => 'red', 'isSupportDoubleBet' => 1,
            'followUserCount' => 205, 'actualMaxOrderReturnRate' => 120.5000,
            'actualTotalBetAmount' => 12192722.67, 'actualTotalProfitAmount' => 1176728.59,
            'followPlayType' => 'FixedDirection',
        ],
        [
            'id' => 4, 'name' => 'Micheal', 'headImgUrl' => $avatar,
            'playType' => 'Color', 'playBet' => 'green', 'isSupportDoubleBet' => 1,
            'followUserCount' => 426, 'actualMaxOrderReturnRate' => 341.0000,
            'actualTotalBetAmount' => 28691823.86, 'actualTotalProfitAmount' => 2822535.03,
            'followPlayType' => 'FixedDirection',
        ],
        [
            'id' => 5, 'name' => 'Micheal', 'headImgUrl' => $avatar,
            'playType' => 'Color', 'playBet' => 'violet', 'isSupportDoubleBet' => 1,
            'followUserCount' => 178, 'actualMaxOrderReturnRate' => 341.0000,
            'actualTotalBetAmount' => 18337708.83, 'actualTotalProfitAmount' => 4611849.81,
            'followPlayType' => 'FixedDirection',
        ],
    ];

    foreach ($base as &$plan) {
        $plan += [
            'state' => 1,
            'betAmountAfterLose' => 0.0,
            'preIssueCount' => 0,
            'winIssueCount' => 0,
            'lossIssueCount' => 0,
            'totalWinLossAmount' => 0.0,
            'orderNo' => null,
            'orderType' => 0,
            'isOpenDoubleBet' => 0,
            'betAmount' => 0.0,
            'initMarginAmount' => 0.0,
            'currentMarginAmount' => 0.0,
            'stopProfitAmount' => 0.0,
            'stopLossAmount' => 0.0,
        ];
    }
    unset($plan);
    return $base;
}

function follow_plan_by_id(int $planId): ?array
{
    foreach (follow_plans() as $plan) {
        if ((int)$plan['id'] === $planId) {
            return $plan;
        }
    }
    return null;
}

function follow_ensure_schema(): void
{
    global $conn;
    try {
        if (app_table_exists('app_follow_records') && !app_column_exists('app_follow_records', 'plan_id')) {
            $conn->query("ALTER TABLE app_follow_records ADD COLUMN plan_id INT NULL AFTER game_code");
        }
        if (app_table_exists('app_follow_records') && !app_column_exists('app_follow_records', 'details_json')) {
            $conn->query("ALTER TABLE app_follow_records ADD COLUMN details_json LONGTEXT NULL AFTER amount");
        }
    } catch (Throwable $e) {
        error_log('[follow_schema] ' . $e->getMessage());
    }
}

function follow_decode_details(array $row): array
{
    $details = [];
    if (!empty($row['details_json'])) {
        $decoded = json_decode((string)$row['details_json'], true);
        if (is_array($decoded)) {
            $details = $decoded;
        }
    }
    return $details;
}

function follow_record_payload(array $row): array
{
    $details = follow_decode_details($row);
    $planId = isset($row['plan_id']) ? (int)$row['plan_id'] : (int)($details['followPlanId'] ?? 0);
    $plan = follow_plan_by_id($planId);

    // Compatibility for records created by the previous build, which did not
    // persist plan_id. Match the unique playBet stored in strategy_code.
    if (!$plan) {
        $code = strtolower((string)($row['strategy_code'] ?? ''));
        foreach (follow_plans() as $candidate) {
            if (strtolower((string)$candidate['playBet']) === $code) {
                $plan = $candidate;
                break;
            }
        }
    }
    if (!$plan) {
        $plan = follow_plans()[0];
    }

    $amount = (float)($details['betAmount'] ?? $row['amount'] ?? 0);
    $isDouble = (int)($details['isOpenDoubleBet'] ?? 0) === 1 ? 1 : 0;
    $multiple = max(1.0, min(15.0, (float)($details['doubleBetMultiple'] ?? 1)));
    $status = (int)($row['status'] ?? 0);

    return array_merge($plan, [
        'state' => $status === 1 ? 1 : 0,
        'orderNo' => (string)$row['id'],
        'orderType' => (int)($details['orderType'] ?? 1),
        'preIssueCount' => max(1, min(1000, (int)($details['preIssueCount'] ?? 10))),
        'winIssueCount' => (int)($details['winIssueCount'] ?? 0),
        'lossIssueCount' => (int)($details['lossIssueCount'] ?? 0),
        'totalWinLossAmount' => (float)($details['totalWinLossAmount'] ?? 0),
        'betAmount' => $amount,
        'initMarginAmount' => (float)($details['initMarginAmount'] ?? 0),
        'currentMarginAmount' => (float)($details['currentMarginAmount'] ?? ($details['initMarginAmount'] ?? 0)),
        'stopProfitAmount' => (float)($details['stopProfitAmount'] ?? 0),
        'stopLossAmount' => (float)($details['stopLossAmount'] ?? 0),
        'isOpenDoubleBet' => $isDouble,
        'doubleBetMultiple' => $multiple,
        'betAmountAfterWin' => $amount,
        'betAmountAfterLose' => $isDouble ? round($amount * $multiple, 2) : $amount,
        'enableMartingale' => $isDouble,
        'startTime' => $row['created_at'] ?? null,
        'endTime' => $row['stopped_at'] ?? null,
        'stopReason' => $status === 1 ? '' : 'ManualStop',
    ]);
}

function follow_select_columns(): string
{
    $cols = 'id,user_id,game_code,strategy_code,strategy_name,amount,status,created_at,stopped_at';
    $cols .= app_column_exists('app_follow_records', 'plan_id') ? ',plan_id' : ',0 AS plan_id';
    $cols .= app_column_exists('app_follow_records', 'details_json') ? ',details_json' : ',NULL AS details_json';
    return $cols;
}

try {
    sl_install_schema();
    follow_ensure_schema();
    $user = sl_require_user();

    switch ($action) {
        case 'GetGameList':
            sl_ok(sl_game_list());
            break;
        case 'GetGameInfo':
            sl_ok(sl_game_info(sl_game_code($input)));
            break;
        case 'GetUserInfo':
            sl_ok(array(
                'userId'=>(int)$user['id'],'tenantId'=>1,'agentCode'=>'LOCAL',
                'sysCurrency'=>'INR','state'=>1,'tenantAccount'=>(string)$user['id'],
                'isOpenFollow'=>false,'skin'=>'blackGoldStyle','skinColor'=>'#d7ad55'
            ));
            break;
        case 'GetBalance':
            sl_ok(array('balance'=>sl_wallet_balance((int)$user['id'])));
            break;
        case 'WinGoBet':
        case 'TrxWinGoBet':
        case 'K3Bet':
        case 'D5Bet':
        case 'MotoRaceBet':
            sl_ok(sl_place_bet($user, $input), 402);
            break;
        case 'GetHistoryIssuePage':
            $gameCode = sl_game_code($input);
            sl_ok(sl_history_page($gameCode, $input));
            break;
        case 'GetRecordPage':
            sl_ok(sl_record_page((int)$user['id'], $input));
            break;
        case 'GetWinLossResult':
            sl_ok(sl_win_loss((int)$user['id'], $input));
            break;
        case 'GetTrendStatistics':
            sl_ok(sl_trend(sl_game_code($input)));
            break;
        case 'GetBetLimit':
            sl_ok(sl_bet_limits(sl_game_code($input)));
            break;
        case 'GetGameIntroduce':
            sl_ok(sl_game_introduce(sl_game_code($input)));
            break;
        case 'GetDragonList':
            sl_ok(array('list'=>array(),'pageNo'=>1,'totalPage'=>0,'totalCount'=>0));
            break;
        case 'GetFollowPlanList':
            $plans = follow_plans();
            $uid = (int)$user['id'];
            $game = sl_game_code($input);
            $sql = 'SELECT ' . follow_select_columns() . ' FROM app_follow_records WHERE user_id=? AND game_code=? AND status=1 ORDER BY id DESC LIMIT 1';
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('is', $uid, $game);
                $stmt->execute();
                $active = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($active) {
                    $activePayload = follow_record_payload($active);
                    foreach ($plans as &$plan) {
                        if ((int)$plan['id'] === (int)$activePayload['id']) {
                            $plan = array_merge($plan, $activePayload);
                            break;
                        }
                    }
                    unset($plan);
                }
            }
            // The frontend calls data.find(...), so data must be the array itself.
            sl_ok($plans);
            break;
        case 'GetFollowRule':
            sl_ok(array('minAmount'=>1.0,'maxAmount'=>50000.0,'maxIssueCount'=>1000,'supportDoubleBet'=>true,'doubleBetMultiple'=>2,'stopProfitAmount'=>0.0,'stopLossAmount'=>0.0));
            break;
        case 'GetFollowRecord':
            $uid = (int)$user['id'];
            $order = (int)($input['orderNo'] ?? $input['id'] ?? 0);
            if ($order > 0) {
                $sql = 'SELECT ' . follow_select_columns() . ' FROM app_follow_records WHERE id=? AND user_id=? LIMIT 1';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $order, $uid);
            } else {
                $game = sl_game_code($input);
                $sql = 'SELECT ' . follow_select_columns() . ' FROM app_follow_records WHERE user_id=? AND game_code=? AND status=1 ORDER BY id DESC LIMIT 1';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('is', $uid, $game);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            sl_ok($row ? follow_record_payload($row) : null);
            break;
        case 'AddFollowRecord':
            $planId = (int)($input['followPlanId'] ?? 0);
            $plan = follow_plan_by_id($planId);
            if (!$plan) {
                sl_fail(7, 'Invalid follow plan', 6, 200);
            }
            $amount = max(1.0, min(50000.0, (float)($input['betAmount'] ?? $input['defineAmount'] ?? $input['amount'] ?? 1)));
            $uid = (int)$user['id'];
            $game = sl_game_code($input);
            $code = (string)$plan['playBet'];
            $name = (string)$plan['name'];
            $details = [
                'followPlanId' => $planId,
                'betAmount' => $amount,
                'preIssueCount' => max(1, min(1000, (int)($input['preIssueCount'] ?? 10))),
                'initMarginAmount' => max(0.0, (float)($input['initMarginAmount'] ?? 0)),
                'currentMarginAmount' => max(0.0, (float)($input['initMarginAmount'] ?? 0)),
                'stopProfitAmount' => max(0.0, (float)($input['stopProfitAmount'] ?? 0)),
                'stopLossAmount' => max(0.0, (float)($input['stopLossAmount'] ?? 0)),
                'isOpenDoubleBet' => (int)($input['isOpenDoubleBet'] ?? 0) === 1 ? 1 : 0,
                'doubleBetMultiple' => max(1.0, min(15.0, (float)($input['doubleBetMultiple'] ?? 1))),
                'orderType' => (int)($input['orderType'] ?? 1) === 0 ? 0 : 1,
                'winIssueCount' => 0,
                'lossIssueCount' => 0,
                'totalWinLossAmount' => 0.0,
            ];
            $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Only one active follow order per game/user. Closing the old one first
            // prevents two cards from claiming the same currentStrategy state.
            $stmt = $conn->prepare('UPDATE app_follow_records SET status=0,stopped_at=NOW() WHERE user_id=? AND game_code=? AND status=1');
            $stmt->bind_param('is', $uid, $game);
            $stmt->execute();
            $stmt->close();

            if (app_column_exists('app_follow_records', 'plan_id') && app_column_exists('app_follow_records', 'details_json')) {
                $stmt = $conn->prepare('INSERT INTO app_follow_records(user_id,game_code,plan_id,strategy_code,strategy_name,amount,details_json,status,created_at) VALUES (?,?,?,?,?,?,?,1,NOW())');
                $stmt->bind_param('isissds', $uid, $game, $planId, $code, $name, $amount, $detailsJson);
            } else {
                $stmt = $conn->prepare('INSERT INTO app_follow_records(user_id,game_code,strategy_code,strategy_name,amount,status,created_at) VALUES (?,?,?,?,?,1,NOW())');
                $stmt->bind_param('isssd', $uid, $game, $code, $name, $amount);
            }
            $stmt->execute();
            $id = (int)$conn->insert_id;
            $stmt->close();

            $sql = 'SELECT ' . follow_select_columns() . ' FROM app_follow_records WHERE id=? AND user_id=? LIMIT 1';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $id, $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            sl_ok($row ? follow_record_payload($row) : array('orderNo'=>(string)$id));
            break;
        case 'StopFollowRecord':
            $uid = (int)$user['id'];
            $order = (int)($input['orderNo'] ?? $input['id'] ?? 0);
            if ($order < 1) {
                sl_fail(7, 'Invalid order number', 6, 200);
            }
            $stmt = $conn->prepare('UPDATE app_follow_records SET status=0,stopped_at=NOW() WHERE id=? AND user_id=? AND status=1');
            $stmt->bind_param('ii', $order, $uid);
            $stmt->execute();
            $stmt->close();
            sl_ok(null);
            break;
        case 'GetHistoryFollowRecordPageList':
            $uid = (int)$user['id'];
            $game = sl_game_code($input);
            $page = max(1, (int)($input['pageNo'] ?? 1));
            $size = min(50, max(1, (int)($input['pageSize'] ?? 10)));
            $offset = ($page - 1) * $size;
            $stmt = $conn->prepare('SELECT COUNT(*) FROM app_follow_records WHERE user_id=? AND game_code=? AND status=0');
            $stmt->bind_param('is', $uid, $game);
            $stmt->execute();
            $count = 0;
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            $list = [];
            $sql = 'SELECT ' . follow_select_columns() . ' FROM app_follow_records WHERE user_id=? AND game_code=? AND status=0 ORDER BY id DESC LIMIT ? OFFSET ?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isii', $uid, $game, $size, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $list[] = follow_record_payload($row);
            }
            $stmt->close();
            sl_ok(array('list'=>$list,'pageNo'=>$page,'totalPage'=>(int)ceil($count/$size),'totalCount'=>(int)$count));
            break;
        case 'VideoWinGoBet':
            sl_fail(405, 'This lottery family is not enabled', 405, 200);
            break;
        default:
            sl_fail(404, 'Unknown lottery action', 404, 404);
    }
} catch (Throwable $e) {
    error_log('[daman-saas-lottery-live-v4] ' . $e->getMessage());
    sl_fail(500, 'Lottery service is temporarily unavailable', 500, 500);
}
