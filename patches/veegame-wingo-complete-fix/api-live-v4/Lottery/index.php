<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD']??'')==='OPTIONS') { http_response_code(204); exit; }
try {
    require_once dirname(__DIR__,2).'/saas_lottery/bootstrap_live_v4.php';
    $action=(string)($_GET['action']??'');$input=sl_input();
    // Do not let JSON overwrite the route/action. Mutations never accept GET.
    if ($action==='WinGoBet' && ($_SERVER['REQUEST_METHOD']??'')!=='POST') sl_fail(405,'Use POST',405,405);
    $user=sl_require_user();
    sl_install_schema();
    switch ($action) {
        case 'GetGameList': sl_ok(sl_game_list());
        case 'GetGameInfo': sl_ok(sl_game_info(sl_game_code($input)));
        case 'GetUserInfo': sl_ok(['userId'=>(int)$user['id'],'tenantId'=>1,'agentCode'=>'VEEGAME','sysCurrency'=>'INR','state'=>1,'tenantAccount'=>(string)$user['id'],'isOpenFollow'=>false,'skin'=>'blackGoldStyle','skinColor'=>'#d7ad55']);
        case 'GetBalance': sl_ok(['balance'=>sl_wallet_balance((int)$user['id'])]);
        case 'WinGoBet': sl_ok(sl_place_bet($user,$input));
        case 'GetRecordPage': sl_ok(sl_record_page((int)$user['id'],$input));
        case 'GetWinLossResult': sl_ok(sl_win_loss((int)$user['id'],$input));
        case 'GetHistoryIssuePage': sl_ok(sl_history_page(sl_game_code($input),$input));
        case 'GetTrendStatistics': sl_sync_results(sl_game_code($input)); sl_ok(sl_trend(sl_game_code($input)));
        case 'GetBetLimit': sl_ok(sl_bet_limits(sl_game_code($input)));
        case 'GetGameIntroduce': sl_ok(sl_game_introduce(sl_game_code($input)));
        case 'GetMigrationStatus': sl_ok(['state'=>app_setting('migration_state','active'),'legacyHistoryUrl'=>'/veegame-legacy-history.html']);
        case 'GetLegacyRecordPage': sl_ok(vee_legacy_page((int)$user['id'],$input));
        case 'GetDragonList': sl_ok(['list'=>[],'pageNo'=>1,'totalPage'=>0,'totalCount'=>0]);
        case 'GetFollowPlanList': sl_ok([]);
        case 'GetFollowRecord': sl_ok(null);
        case 'GetHistoryFollowRecordPageList': sl_ok(['list'=>[],'pageNo'=>1,'totalPage'=>0,'totalCount'=>0]);
        case 'GetFollowRule': case 'AddFollowRecord': case 'StopFollowRecord': sl_fail(405,'Follow strategy is not enabled',405,405);
        default: sl_fail(404,'Unsupported lottery action',404,404);
    }
} catch (Throwable $e) {
    error_log('[veegame-saas] '.get_class($e));
    http_response_code(503);
    echo json_encode(['code'=>503,'msg'=>'Lottery service unavailable; migration/schema may need review','data'=>null]);
}
