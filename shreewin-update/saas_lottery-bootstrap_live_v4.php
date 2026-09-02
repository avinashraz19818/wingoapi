<?php
/**
 * Shared plain-PHP lottery engine used by WinGo, TrxWinGo, K3, 5D and Moto Racing.
 *
 * Public draw timing/results are exposed through /draw while authenticated
 * wallet and betting operations are exposed through /api/Lottery/*. Both
 * surfaces use this file so the countdown, accepted issue and settlement
 * result cannot drift apart.
 */
date_default_timezone_set('Asia/Kolkata');

$SL_CONFIG = require __DIR__ . '/config_live_v4.php';
require_once dirname(__DIR__) . '/developer-maruf/conn.php';
require_once dirname(__DIR__) . '/developer-maruf/functions2.php';
require_once dirname(__DIR__) . '/developer-maruf/app_core_live_v4.php';
require_once dirname(__DIR__) . '/developer-maruf/vip_core.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    throw new RuntimeException('Database connection is not available');
}
$conn->set_charset('utf8mb4');

if (!defined('SL_NO_API_HEADERS')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Allow: GET, POST, OPTIONS');
        http_response_code(204);
        exit;
    }
}

function sl_now_ms()
{
    return (int) floor(microtime(true) * 1000);
}

function sl_input()
{
    $data = $_GET;
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

function sl_send($payload, $httpStatus)
{
    http_response_code((int) $httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sl_ok($data, $successCode = 0)
{
    sl_send(array(
        'data' => $data,
        'code' => 0,
        'msg' => 'Succeed',
        'msgCode' => (int) $successCode,
        'serviceTime' => sl_now_ms()
    ), 200);
}

function sl_fail($code, $message, $msgCode, $httpStatus = 200)
{
    sl_send(array(
        'data' => null,
        'code' => (int) $code,
        'msg' => (string) $message,
        'msgCode' => (int) $msgCode,
        'serviceTime' => sl_now_ms()
    ), $httpStatus);
}

function sl_bearer_token()
{
    $header = '';
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string) $_SERVER[$key];
            break;
        }
    }
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $header = (string) $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $header = (string) $headers['authorization'];
        }
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($header), $match) ? trim($match[1]) : '';
}

function sl_require_user()
{
    global $conn;
    $token = sl_bearer_token();
    if ($token === '') {
        sl_fail(401, 'Login required', 401, 401);
    }

    $verified = @json_decode(is_jwt_valid($token), true);
    $userId = is_array($verified) && isset($verified['payload']['id']) ? (int) $verified['payload']['id'] : 0;
    if (!is_array($verified) || ($verified['status'] ?? '') !== 'Success' || $userId < 1) {
        sl_fail(401, 'Session is invalid', 401, 401);
    }

    $stmt = $conn->prepare('SELECT id,mobile,codechorkamukala FROM shonu_subjects WHERE id=? AND akshinak=? LIMIT 1');
    if (!$stmt) {
        sl_fail(503, 'User service is unavailable', 503, 503);
    }
    $stmt->bind_param('is', $userId, $token);
    $stmt->execute();
    $id = $mobile = $code = null;
    $stmt->bind_result($id, $mobile, $code);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        sl_fail(401, 'Session is not active', 401, 401);
    }
    return array('id' => (int) $id, 'mobile' => (string) $mobile, 'codechorkamukala' => (string) $code);
}

function sl_schema_requirements()
{
    return array(
        'saas_lottery_bets' => array(
            'id', 'user_id', 'game_code', 'issue_number', 'bet_content', 'amount',
            'bet_multiple', 'bet_units', 'stake', 'request_group_key', 'request_key',
            'status', 'result_premium', 'payout', 'tax_fee', 'created_at',
            'settled_at', 'vip_exp_applied'
        ),
        'saas_lottery_requests' => array(
            'request_group_key', 'user_id', 'game_code', 'issue_number', 'created_at'
        ),
        'saas_lottery_results' => array(
            'id', 'game_code', 'issue_number', 'premium', 'number', 'color',
            'result_sum', 'provider_seen_at', 'created_at'
        ),
        'saas_wallet_ledger' => array(
            'id', 'user_id', 'entry_key', 'entry_type', 'amount', 'balance_before',
            'balance_after', 'created_at'
        ),
        'saas_lottery_settings' => array('setting_key', 'setting_value', 'updated_at'),
        'saas_lottery_overrides' => array(
            'game_code', 'issue_number', 'premium', 'created_by', 'created_at'
        )
    );
}

function sl_install_schema()
{
    global $conn, $SL_CONFIG;
    app_install_schema($conn);
    static $installed = false;
    if ($installed) {
        return;
    }

    $engineVersion = '20260826-daman-v10.2';
    try {
        if (app_schema_columns_exist(sl_schema_requirements(), $conn)) {
            $versionResult = $conn->query("SELECT setting_value FROM saas_lottery_settings WHERE setting_key='engine_schema_version' LIMIT 1");
            $versionRow = $versionResult ? $versionResult->fetch_assoc() : null;
            if ($versionRow && (string)$versionRow['setting_value'] === $engineVersion) {
                $installed = true;
                return;
            }
        }
    } catch (Throwable $ignored) {
        // Continue into the self-install/upgrade pass below.
    }

    $queries = array(
        "CREATE TABLE IF NOT EXISTS saas_lottery_bets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT NOT NULL,game_code VARCHAR(32) NOT NULL,issue_number VARCHAR(40) NOT NULL,bet_content VARCHAR(190) NOT NULL,amount DECIMAL(18,4) NOT NULL,bet_multiple INT NOT NULL,bet_units INT NOT NULL DEFAULT 1,stake DECIMAL(18,4) NOT NULL,request_group_key CHAR(64) NOT NULL,request_key CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',result_premium VARCHAR(64) NULL,payout DECIMAL(18,4) NOT NULL DEFAULT 0,tax_fee DECIMAL(18,4) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,settled_at DATETIME NULL,vip_exp_applied TINYINT(1) NOT NULL DEFAULT 0,PRIMARY KEY(id),UNIQUE KEY uq_saas_lottery_request(request_key),KEY idx_saas_lottery_group(request_group_key),KEY idx_saas_lottery_user_created(user_id,created_at),KEY idx_saas_lottery_issue_status(game_code,issue_number,status),KEY idx_saas_vip_exp(vip_exp_applied,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS saas_lottery_requests (request_group_key CHAR(64) NOT NULL,user_id BIGINT NOT NULL,game_code VARCHAR(32) NOT NULL,issue_number VARCHAR(40) NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(request_group_key),KEY idx_saas_request_user_created(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS saas_lottery_results (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,game_code VARCHAR(32) NOT NULL,issue_number VARCHAR(40) NOT NULL,premium VARCHAR(64) NOT NULL,number VARCHAR(32) NOT NULL DEFAULT '',color VARCHAR(32) NOT NULL DEFAULT '',result_sum INT NOT NULL DEFAULT 0,provider_seen_at DATETIME NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_saas_lottery_result(game_code,issue_number),KEY idx_saas_lottery_result_created(game_code,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS saas_wallet_ledger (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT NOT NULL,entry_key VARCHAR(96) NOT NULL,entry_type VARCHAR(32) NOT NULL,amount DECIMAL(18,4) NOT NULL,balance_before DECIMAL(18,4) NOT NULL,balance_after DECIMAL(18,4) NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_saas_wallet_entry(entry_key),KEY idx_saas_wallet_user_created(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS saas_lottery_settings (setting_key VARCHAR(80) NOT NULL,setting_value TEXT NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS saas_lottery_overrides (game_code VARCHAR(32) NOT NULL,issue_number VARCHAR(40) NOT NULL,premium VARCHAR(64) NOT NULL,created_by VARCHAR(100) NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(game_code,issue_number),KEY idx_saas_override_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Unable to create lottery tables: ' . $conn->error);
        }
    }

    // Upgrade installations that received the earlier lightweight SaaS tables.
    // All migrations are additive and idempotent so cPanel installs self-heal on
    // the first API request without requiring a separate SQL import.
    $betColumns = array(
        'bet_content' => "ALTER TABLE saas_lottery_bets ADD COLUMN bet_content VARCHAR(190) NOT NULL DEFAULT '' AFTER issue_number",
        'bet_multiple' => "ALTER TABLE saas_lottery_bets ADD COLUMN bet_multiple INT NOT NULL DEFAULT 1 AFTER amount",
        'bet_units' => "ALTER TABLE saas_lottery_bets ADD COLUMN bet_units INT NOT NULL DEFAULT 1 AFTER bet_multiple",
        'stake' => "ALTER TABLE saas_lottery_bets ADD COLUMN stake DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER bet_units",
        'request_group_key' => "ALTER TABLE saas_lottery_bets ADD COLUMN request_group_key CHAR(64) NULL AFTER stake",
        'request_key' => "ALTER TABLE saas_lottery_bets ADD COLUMN request_key CHAR(64) NULL AFTER request_group_key",
        'status' => "ALTER TABLE saas_lottery_bets ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER request_key",
        'result_premium' => "ALTER TABLE saas_lottery_bets ADD COLUMN result_premium VARCHAR(64) NULL AFTER status",
        'payout' => "ALTER TABLE saas_lottery_bets ADD COLUMN payout DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER result_premium",
        'tax_fee' => "ALTER TABLE saas_lottery_bets ADD COLUMN tax_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER payout",
        'created_at' => "ALTER TABLE saas_lottery_bets ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER tax_fee",
        'settled_at' => "ALTER TABLE saas_lottery_bets ADD COLUMN settled_at DATETIME NULL AFTER created_at",
        'vip_exp_applied' => "ALTER TABLE saas_lottery_bets ADD COLUMN vip_exp_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER settled_at"
    );
    foreach ($betColumns as $column => $sql) {
        if (!app_column_exists('saas_lottery_bets', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS bet schema: ' . $conn->error);
        }
    }
    // The older package used a numeric status column; the live engine uses names.
    $conn->query("ALTER TABLE saas_lottery_bets MODIFY COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending'");
    if (app_column_exists('saas_lottery_bets', 'selection')) {
        $conn->query("UPDATE saas_lottery_bets SET bet_content=CAST(selection AS CHAR) WHERE bet_content='' AND selection IS NOT NULL");
    }
    $conn->query("UPDATE saas_lottery_bets SET stake=amount WHERE stake=0 AND amount>0");
    $conn->query("UPDATE saas_lottery_bets SET status=CASE status WHEN '0' THEN 'pending' WHEN '1' THEN 'won' WHEN '2' THEN 'lost' ELSE status END");
    $conn->query("UPDATE saas_lottery_bets SET tax_fee=ROUND(stake*0.02,4) WHERE stake>0 AND tax_fee=0");
    $conn->query("UPDATE saas_lottery_bets SET request_group_key=SHA2(CONCAT('legacy-group|',id,'|',user_id,'|',game_code,'|',issue_number),256) WHERE request_group_key IS NULL OR request_group_key=''");
    $conn->query("UPDATE saas_lottery_bets SET request_key=SHA2(CONCAT('legacy-bet|',id,'|',user_id,'|',game_code,'|',issue_number),256) WHERE request_key IS NULL OR request_key=''");
    $conn->query("ALTER TABLE saas_lottery_bets MODIFY COLUMN request_group_key CHAR(64) NOT NULL, MODIFY COLUMN request_key CHAR(64) NOT NULL");

    $ledgerColumns = array(
        'entry_key' => "ALTER TABLE saas_wallet_ledger ADD COLUMN entry_key VARCHAR(96) NULL AFTER user_id",
        'entry_type' => "ALTER TABLE saas_wallet_ledger ADD COLUMN entry_type VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER entry_key",
        'balance_before' => "ALTER TABLE saas_wallet_ledger ADD COLUMN balance_before DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER amount",
        'balance_after' => "ALTER TABLE saas_wallet_ledger ADD COLUMN balance_after DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER balance_before",
        'created_at' => "ALTER TABLE saas_wallet_ledger ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER balance_after"
    );
    foreach ($ledgerColumns as $column => $sql) {
        if (!app_column_exists('saas_wallet_ledger', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS wallet schema: ' . $conn->error);
        }
    }
    $conn->query("UPDATE saas_wallet_ledger SET entry_key=SHA2(CONCAT('legacy-ledger|',id,'|',user_id,'|',created_at),256) WHERE entry_key IS NULL OR entry_key=''");
    $conn->query("ALTER TABLE saas_wallet_ledger MODIFY COLUMN entry_key VARCHAR(96) NOT NULL");

    $resultColumns = array(
        'number' => "ALTER TABLE saas_lottery_results ADD COLUMN number VARCHAR(32) NOT NULL DEFAULT '' AFTER premium",
        'color' => "ALTER TABLE saas_lottery_results ADD COLUMN color VARCHAR(32) NOT NULL DEFAULT '' AFTER number",
        'result_sum' => "ALTER TABLE saas_lottery_results ADD COLUMN result_sum INT NOT NULL DEFAULT 0 AFTER color",
        'provider_seen_at' => "ALTER TABLE saas_lottery_results ADD COLUMN provider_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER result_sum",
        'created_at' => "ALTER TABLE saas_lottery_results ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER provider_seen_at"
    );
    foreach ($resultColumns as $column => $sql) {
        if (!app_column_exists('saas_lottery_results', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS result schema: ' . $conn->error);
        }
    }

    $requestColumns = array(
        'created_at' => "ALTER TABLE saas_lottery_requests ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER issue_number"
    );
    foreach ($requestColumns as $column => $sql) {
        if (!app_column_exists('saas_lottery_requests', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS request schema: ' . $conn->error);
        }
    }

    $settingColumns = array(
        'updated_at' => "ALTER TABLE saas_lottery_settings ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER setting_value"
    );
    foreach ($settingColumns as $column => $sql) {
        if (!app_column_exists('saas_lottery_settings', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS setting schema: ' . $conn->error);
        }
    }

    $overrideColumns = array(
        'created_by' => "ALTER TABLE saas_lottery_overrides ADD COLUMN created_by VARCHAR(100) NOT NULL DEFAULT 'system' AFTER premium",
        'created_at' => "ALTER TABLE saas_lottery_overrides ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by"
    );
    foreach ($overrideColumns as $column => $sql) {
        if (!app_column_exists('saas_lottery_overrides', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Unable to upgrade SaaS override schema: ' . $conn->error);
        }
    }

    if (!app_schema_columns_exist(sl_schema_requirements(), $conn)) {
        throw new RuntimeException('Lottery database schema is incomplete after automatic repair');
    }

    // Add indexes after legacy rows have deterministic keys. Duplicate-key errors
    // simply mean an equivalent index already exists.
    try { $conn->query("ALTER TABLE saas_lottery_bets ADD UNIQUE KEY uq_saas_lottery_request(request_key)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_bets ADD KEY idx_saas_lottery_group(request_group_key)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_bets ADD KEY idx_saas_lottery_issue_status(game_code,issue_number,status)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_wallet_ledger ADD UNIQUE KEY uq_saas_wallet_entry(entry_key)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_wallet_ledger ADD KEY idx_saas_wallet_user_created(user_id,created_at)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_results ADD UNIQUE KEY uq_saas_lottery_result(game_code,issue_number)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_results ADD KEY idx_saas_lottery_result_created(game_code,created_at)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_requests ADD KEY idx_saas_request_user_created(user_id,created_at)"); } catch (Throwable $ignored) {}
    try { $conn->query("ALTER TABLE saas_lottery_overrides ADD KEY idx_saas_override_created(created_at)"); } catch (Throwable $ignored) {}

    app_vip_ensure_saas_tracking($conn, false);

    $versionResult = $conn->query("SELECT setting_value FROM saas_lottery_settings WHERE setting_key='engine_schema_version' LIMIT 1");
    $versionRow = $versionResult ? $versionResult->fetch_assoc() : null;
    $installedVersion = $versionRow ? (string) $versionRow['setting_value'] : '';
    if ($installedVersion !== $engineVersion) {
        foreach (array_keys($SL_CONFIG['games']) as $gameCode) {
            $key = 'game_' . $gameCode . '_enabled';
            $stmt = $conn->prepare("INSERT IGNORE INTO saas_lottery_settings(setting_key,setting_value,updated_at) VALUES (?,'1',NOW())");
            if ($stmt) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $stmt->close();
            }
        }

        $secret = bin2hex(random_bytes(32));
        $secretStmt = $conn->prepare("INSERT IGNORE INTO saas_lottery_settings(setting_key,setting_value,updated_at) VALUES ('local_result_secret',?,NOW())");
        if ($secretStmt) {
            $secretStmt->bind_param('s', $secret);
            $secretStmt->execute();
            $secretStmt->close();
        }

        // One-way compatibility migration from the earlier WinGo-only tables.
        // The version marker prevents these scans from running on every poll.
        if (app_table_exists('saas_wingo_results')) {
            $conn->query("INSERT IGNORE INTO saas_lottery_results(id,game_code,issue_number,premium,number,color,result_sum,provider_seen_at,created_at) SELECT id,game_code,issue_number,CAST(number AS CHAR),CAST(number AS CHAR),color,0,provider_seen_at,created_at FROM saas_wingo_results");
        }
        if (app_table_exists('saas_wingo_bets')) {
            $conn->query("INSERT IGNORE INTO saas_lottery_bets(id,user_id,game_code,issue_number,bet_content,amount,bet_multiple,bet_units,stake,request_group_key,request_key,status,result_premium,payout,created_at,settled_at) SELECT id,user_id,game_code,issue_number,bet_content,amount,bet_multiple,1,stake,request_key,request_key,status,IF(result_number IS NULL,NULL,CAST(result_number AS CHAR)),payout,created_at,settled_at FROM saas_wingo_bets");
        }
        $conn->query("INSERT IGNORE INTO saas_lottery_requests(request_group_key,user_id,game_code,issue_number,created_at) SELECT request_group_key,MIN(user_id),MIN(game_code),MIN(issue_number),MIN(created_at) FROM saas_lottery_bets GROUP BY request_group_key");
        app_vip_backfill_saas_bets($conn);
        $versionStmt = $conn->prepare("INSERT INTO saas_lottery_settings(setting_key,setting_value,updated_at) VALUES ('engine_schema_version',?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
        if ($versionStmt) {
            $versionStmt->bind_param('s', $engineVersion);
            $versionStmt->execute();
            $versionStmt->close();
        }
    }
    $installed = true;
}

function sl_game_code($input)
{
    global $SL_CONFIG;
    $gameCode = isset($input['gameCode']) ? (string) $input['gameCode'] : 'WinGo_30S';
    if (!isset($SL_CONFIG['games'][$gameCode])) {
        sl_fail(7, 'Unsupported game code', 7, 200);
    }
    return $gameCode;
}

function sl_game_config($gameCode)
{
    global $SL_CONFIG;
    return isset($SL_CONFIG['games'][$gameCode]) ? $SL_CONFIG['games'][$gameCode] : null;
}

function sl_game_family($gameCode)
{
    $config = sl_game_config($gameCode);
    return $config ? (string) $config['lottery'] : '';
}

function sl_game_enabled($gameCode)
{
    global $conn;
    $key = 'game_' . $gameCode . '_enabled';
    if (!app_setting_bool($key, true)) {
        return false;
    }
    $control = app_game_control($gameCode);
    if (isset($control['enabled']) && (int) $control['enabled'] !== 1) {
        return false;
    }
    $stmt = $conn->prepare('SELECT setting_value FROM saas_lottery_settings WHERE setting_key=? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $value = null;
    $stmt->bind_result($value);
    $found = $stmt->fetch();
    $stmt->close();
    return !$found || (string) $value === '1';
}

function sl_game_list()
{
    global $SL_CONFIG;
    $groups = array(
        'WinGo' => array('gameType' => 100, 'gameTypeName' => 'WinGo', 'sort' => 1, 'gameList' => array()),
        'TrxWinGo' => array('gameType' => 103, 'gameTypeName' => 'TrxWinGo', 'sort' => 6, 'gameList' => array()),
        'K3' => array('gameType' => 101, 'gameTypeName' => 'K3', 'sort' => 5, 'gameList' => array()),
        'D5' => array('gameType' => 102, 'gameTypeName' => '5D', 'sort' => 4, 'gameList' => array()),
        'MotoRace' => array('gameType' => 105, 'gameTypeName' => 'MotoRace', 'sort' => 2, 'gameList' => array())
    );
    foreach ($SL_CONFIG['games'] as $gameCode => $game) {
        $family = (string) $game['lottery'];
        if (!isset($groups[$family])) {
            continue;
        }
        $groups[$family]['gameList'][] = array(
            'gameCode' => $gameCode,
            'gameName' => (string) $game['name'],
            'sort' => (int) $game['sort'],
            'state' => sl_game_enabled($gameCode) ? 1 : 2,
            'intervalMinute' => (float) $game['interval']
        );
    }
    return array_values($groups);
}

function sl_game_rates($gameCode)
{
    $family = sl_game_family($gameCode);
    if ($family === 'WinGo' || $family === 'TrxWinGo') {
        return array(
            array('playTypeId'=>54,'playType'=>'Color','playBet'=>'violet','state'=>1,'playRate'=>4.5),
            array('playTypeId'=>52,'playType'=>'Color','playBet'=>'red','state'=>1,'playRate'=>1.5),
            array('playTypeId'=>53,'playType'=>'Color','playBet'=>'violet','state'=>1,'playRate'=>4.5),
            array('playTypeId'=>50,'playType'=>'Color','playBet'=>'green','state'=>1,'playRate'=>1.5),
            array('playTypeId'=>49,'playType'=>'Color','playBet'=>'green','state'=>1,'playRate'=>2.0),
            array('playTypeId'=>51,'playType'=>'Color','playBet'=>'red','state'=>1,'playRate'=>2.0),
            array('playTypeId'=>55,'playType'=>'Num','playBet'=>'0-9','state'=>1,'playRate'=>9.0),
            array('playTypeId'=>56,'playType'=>'BigSmall','playBet'=>'big','state'=>1,'playRate'=>2.0),
            array('playTypeId'=>57,'playType'=>'BigSmall','playBet'=>'small','state'=>1,'playRate'=>2.0)
        );
    }
    if ($family === 'K3') {
        $sumRates = array(3=>207.36,4=>69.12,5=>34.56,6=>20.74,7=>13.83,8=>9.88,9=>8.30,10=>7.68,11=>7.68,12=>8.30,13=>9.88,14=>13.83,15=>20.74,16=>34.56,17=>69.12,18=>207.36);
        $rates = array();
        foreach ($sumRates as $number => $rate) {
            $rates[] = array('playTypeId'=>55+$number,'playType'=>'SumNum','playBet'=>(string)$number,'state'=>1,'playRate'=>$rate);
        }
        $rates[] = array('playTypeId'=>74,'playType'=>'SumBigSmall','playBet'=>'HL','state'=>1,'playRate'=>2.0);
        $rates[] = array('playTypeId'=>75,'playType'=>'SumOddEven','playBet'=>'OE','state'=>1,'playRate'=>2.0);
        $rates[] = array('playTypeId'=>76,'playType'=>'NumDiff2','playBet'=>'2BT','state'=>1,'playRate'=>6.91);
        $rates[] = array('playTypeId'=>77,'playType'=>'NumSame2','playBet'=>'2TD','state'=>1,'playRate'=>13.83);
        $rates[] = array('playTypeId'=>78,'playType'=>'NumSame2Mult','playBet'=>'2TF','state'=>1,'playRate'=>69.12);
        $rates[] = array('playTypeId'=>79,'playType'=>'NumSame3','playBet'=>'3TD','state'=>1,'playRate'=>207.36);
        $rates[] = array('playTypeId'=>80,'playType'=>'NumSame3All','playBet'=>'3TT','state'=>1,'playRate'=>34.56);
        $rates[] = array('playTypeId'=>81,'playType'=>'NumDiff3','playBet'=>'3BT','state'=>1,'playRate'=>34.56);
        $rates[] = array('playTypeId'=>82,'playType'=>'NumNear3All','playBet'=>'3LT','state'=>1,'playRate'=>8.64);
        return $rates;
    }
    if ($family === 'D5') {
        $positions = array('First','Second','Third','Fourth','Fifth');
        $rates = array();
        $id = 83;
        foreach ($positions as $position) {
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'Num','playBet'=>'0-9','state'=>1,'playRate'=>9.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'BigSmall','playBet'=>'H','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'BigSmall','playBet'=>'L','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'OddEven','playBet'=>'O','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'OddEven','playBet'=>'E','state'=>1,'playRate'=>2.0);
        }
        $rates[] = array('playTypeId'=>108,'playType'=>'SumBigSmall','playBet'=>'H','state'=>1,'playRate'=>2.0);
        $rates[] = array('playTypeId'=>109,'playType'=>'SumBigSmall','playBet'=>'L','state'=>1,'playRate'=>2.0);
        $rates[] = array('playTypeId'=>110,'playType'=>'SumOddEven','playBet'=>'O','state'=>1,'playRate'=>2.0);
        $rates[] = array('playTypeId'=>111,'playType'=>'SumOddEven','playBet'=>'E','state'=>1,'playRate'=>2.0);
        return $rates;
    }
    if ($family === 'MotoRace') {
        $rates = array();
        $id = 130;
        foreach (array('First','Second','Third') as $position) {
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'Num','playBet'=>'1-10','state'=>1,'playRate'=>9.8);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'OddEven','playBet'=>'Odd','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'OddEven','playBet'=>'Even','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'BigSmall','playBet'=>'Big','state'=>1,'playRate'=>2.0);
            $rates[] = array('playTypeId'=>$id++,'playType'=>$position.'BigSmall','playBet'=>'Small','state'=>1,'playRate'=>2.0);
        }
        return $rates;
    }
    return array();
}

function sl_game_info($gameCode)
{
    $family = sl_game_family($gameCode);
    return array(
        'state' => sl_game_enabled($gameCode) ? 1 : 2,
        'betScopes' => $family === 'MotoRace' ? array(1,10,50,100) : array(1,10,100,1000),
        'betMultiples' => array(1,2,3,4,5,10,20,50,100),
        'webSocketUrl' => '',
        'rates' => sl_game_rates($gameCode)
    );
}

function sl_bet_limits($gameCode)
{
    $seen = array();
    $limits = array();
    foreach (sl_game_rates($gameCode) as $rate) {
        $type = (string) $rate['playType'];
        $content = (string) $rate['playBet'];
        $key = $type . '|' . $content;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $limits[] = array(
                'playType'=>$type,
                'betContent'=>$content,
                'maxPayoutAmount'=>100000,
                // Retain these aliases for older clients using the same API.
                'minimum'=>1,
                'maximum'=>100000
            );
        }
    }
    return $limits;
}

function sl_game_introduce($gameCode)
{
    $family = sl_game_family($gameCode);
    if ($family === 'K3') {
        return '<p>Select a dice total, pair, triple or combination before the countdown closes. Three dice (1-6) form each result.</p>';
    }
    if ($family === 'D5') {
        return '<p>Select a digit, Big/Small or Odd/Even for any of the five positions, or select a property of the five-digit sum.</p>';
    }
    if ($family === 'MotoRace') {
        return '<p>Select the number or Big/Small/Odd/Even property for the first, second or third finishing position.</p>';
    }
    return '<p>Choose a number, color, Big or Small before the betting countdown closes.</p>';
}

function sl_fetch_json($url, $timeout = null)
{
    global $SL_CONFIG;
    $timeout = $timeout === null ? (int) $SL_CONFIG['request_timeout_seconds'] : (int) $timeout;
    $timeout = max(2, min(20, $timeout));
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-IN,en;q=0.9',
                'Referer: https://www.lottery7uuu.com/'
            ),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36'
        ));
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status !== 200) {
            $body = false;
        }
    } else {
        $context = stream_context_create(array('http'=>array(
            'timeout'=>$timeout,
            'follow_location'=>1,
            'max_redirects'=>2,
            'header'=>"Accept: application/json, text/plain, */*\r\nAccept-Language: en-IN,en;q=0.9\r\nReferer: https://www.lottery7uuu.com/\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/150.0.0.0 Safari/537.36\r\n"
        )));
        $body = @file_get_contents($url, false, $context);
    }
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function sl_external_url($gameCode, $history)
{
    global $SL_CONFIG;
    $game = sl_game_config($gameCode);
    $control = app_game_control($gameCode);
    $configured = !empty($SL_CONFIG['force_remote_draw'])
        ? ''
        : trim((string) ($control['api_url'] ?? ''));
    $lottery = (string) $game['lottery'];
    if ($configured !== '' && strpos($configured, '{') !== false) {
        return strtr($configured, array(
            '{gameCode}' => rawurlencode($gameCode),
            '{lottery}' => rawurlencode($lottery),
            '{history}' => $history ? 'GetHistoryIssuePage' : 'current'
        ));
    }
    $base = rtrim($configured !== '' ? $configured : (string) $SL_CONFIG['draw_base_url'], '/');
    $path = '/' . rawurlencode($lottery) . '/' . rawurlencode($gameCode);
    return $history ? $base . $path . '/GetHistoryIssuePage.json' : $base . $path . '.json';
}

function sl_interval_seconds($gameCode)
{
    $game = sl_game_config($gameCode);
    return max(30, (int) round(((float) $game['interval']) * 60));
}

function sl_issue_number_for_start($gameCode, $start)
{
    // Preserve the 17-digit issue format expected by the bundled client:
    // YYYYMMDD + family type + interval type + daily sequence.
    $familyCodes = array('WinGo'=>100,'TrxWinGo'=>103,'K3'=>101,'D5'=>102,'MotoRace'=>105);
    $intervalCodes = array(30=>5,60=>1,180=>2,300=>3,600=>4);
    $family = sl_game_family($gameCode);
    $interval = sl_interval_seconds($gameCode);
    $dayStart = strtotime(gmdate('Y-m-d', $start) . ' 00:00:00 UTC');
    $sequence = (int) floor(($start - $dayStart) / $interval) + 1;
    $intervalCode = isset($intervalCodes[$interval]) ? $intervalCodes[$interval] : 9;
    return gmdate('Ymd', $start) . sprintf('%03d%02d%04d', $familyCodes[$family] ?? 999, $intervalCode, $sequence);
}

function sl_local_current($gameCode)
{
    $interval = sl_interval_seconds($gameCode);
    $now = time();
    $dayStart = strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
    $start = $dayStart + ((int) floor(($now - $dayStart) / $interval) * $interval);
    $make = function ($roundStart) use ($gameCode, $interval) {
        return array(
            'issueNumber' => sl_issue_number_for_start($gameCode, $roundStart),
            'startTime' => $roundStart * 1000,
            'endTime' => ($roundStart + $interval) * 1000
        );
    };
    return array(
        'gameCode' => $gameCode,
        'intervalMinute' => $interval / 60,
        'state' => sl_game_enabled($gameCode) ? 1 : 2,
        'previous' => $make($start - $interval),
        'current' => $make($start),
        'next' => $make($start + $interval)
    );
}

function sl_local_secret()
{
    global $conn;
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $result = $conn->query("SELECT setting_value FROM saas_lottery_settings WHERE setting_key='local_result_secret' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    $secret = $row && (string) $row['setting_value'] !== '' ? (string) $row['setting_value'] : hash('sha256', __FILE__ . DB_NAME);
    return $secret;
}

function sl_result_bytes($gameCode, $issue, $block = 0)
{
    return hash_hmac('sha256', $gameCode . '|' . $issue . '|' . (int) $block, sl_local_secret(), true);
}

function sl_manual_overrides($gameCode)
{
    // Real-feed mode is immutable: database/admin overrides are never read.
    return array();
}

function sl_result_color($number)
{
    $number = (int) $number;
    $colors = array();
    if ($number === 0 || $number === 5) {
        $colors[] = 'violet';
    }
    if (in_array($number, array(1,3,5,7,9), true)) {
        array_unshift($colors, 'green');
    } else {
        array_unshift($colors, 'red');
    }
    return implode(',', $colors);
}

function sl_local_result($gameCode, $issue)
{
    $family = sl_game_family($gameCode);
    $overrides = sl_manual_overrides($gameCode);
    $premium = isset($overrides[$issue]) ? (string) $overrides[$issue] : '';
    $bytes = sl_result_bytes($gameCode, $issue);
    if ($premium === '') {
        if ($family === 'WinGo' || $family === 'TrxWinGo') {
            $premium = (string) (ord($bytes[0]) % 10);
        } elseif ($family === 'K3') {
            $premium = (string) ((ord($bytes[0]) % 6) + 1) . (string) ((ord($bytes[1]) % 6) + 1) . (string) ((ord($bytes[2]) % 6) + 1);
        } elseif ($family === 'D5') {
            for ($i = 0; $i < 5; $i++) {
                $premium .= (string) (ord($bytes[$i]) % 10);
            }
        } elseif ($family === 'MotoRace') {
            $cars = range(1, 10);
            for ($i = count($cars) - 1, $cursor = 0; $i > 0; $i--, $cursor++) {
                $j = ord($bytes[$cursor]) % ($i + 1);
                $tmp = $cars[$i];
                $cars[$i] = $cars[$j];
                $cars[$j] = $tmp;
            }
            $premium = implode(',', $cars);
        }
    }
    $item = array('issueNumber'=>$issue,'premium'=>$premium);
    if ($family === 'TrxWinGo') {
        $blockId = bin2hex($bytes);
        $item['blockId'] = substr($blockId, 0, 63) . $premium;
    }
    return sl_normalize_result_item($gameCode, $item);
}

function sl_moto_statistics($list)
{
    $statistics = array();
    for ($car = 1; $car <= 10; $car++) {
        $statistics[(string) $car] = array(0,0,0);
    }
    foreach ($list as $item) {
        $rank = array_map('intval', explode(',', (string) $item['premium']));
        for ($position = 0; $position < 3; $position++) {
            if (isset($rank[$position], $statistics[(string) $rank[$position]])) {
                $statistics[(string) $rank[$position]][$position]++;
            }
        }
    }
    return $statistics;
}

function sl_local_history($gameCode, $pageNo, $pageSize)
{
    global $SL_CONFIG;
    $pageNo = max(1, (int) $pageNo);
    $pageSize = min(100, max(1, (int) ($SL_CONFIG['history_page_size'] ?? $pageSize)));
    $totalPage = max(1, (int) ($SL_CONFIG['history_total_pages'] ?? 50));
    $totalCount = $totalPage * $pageSize;
    if ($pageNo > $totalPage) {
        return array(
            'data'=>array('list'=>array(),'pageNo'=>$pageNo,'totalPage'=>$totalPage,'totalCount'=>$totalCount),
            'code'=>0,'msg'=>'Succeed','msgCode'=>0,'serviceTime'=>sl_now_ms()
        );
    }
    $current = sl_local_current($gameCode);
    $interval = sl_interval_seconds($gameCode);
    $currentStart = (int) floor(((int) $current['current']['startTime']) / 1000);
    $list = array();
    for ($i = 0; $i < $pageSize; $i++) {
        $offset = (($pageNo - 1) * $pageSize) + $i + 1;
        $start = $currentStart - ($offset * $interval);
        $issue = sl_issue_number_for_start($gameCode, $start);
        $item = sl_local_result($gameCode, $issue);
        if ($item) {
            $list[] = $item;
        }
    }
    $data = array('list'=>$list,'pageNo'=>$pageNo,'totalPage'=>$totalPage,'totalCount'=>$totalCount);
    if (sl_game_family($gameCode) === 'MotoRace') {
        $data['statistics'] = sl_moto_statistics($list);
    }
    return array('data'=>$data,'code'=>0,'msg'=>'Succeed','msgCode'=>0,'serviceTime'=>sl_now_ms());
}

function sl_normalize_result_item($gameCode, $item)
{
    if (!is_array($item)) {
        return null;
    }
    $issue = isset($item['issueNumber']) ? (string) $item['issueNumber'] : '';
    if (!preg_match('/^\d{8,40}$/', $issue)) {
        return null;
    }
    $family = sl_game_family($gameCode);
    $premium = isset($item['premium']) ? (string) $item['premium'] : (isset($item['number']) ? (string) $item['number'] : '');
    $number = isset($item['number']) ? (string) $item['number'] : '';
    $color = isset($item['color']) ? (string) $item['color'] : '';
    $sum = isset($item['sum']) ? (int) $item['sum'] : 0;

    if ($family === 'WinGo' || $family === 'TrxWinGo') {
        if (!preg_match('/^[0-9]$/', $premium)) {
            return null;
        }
        $number = $premium;
        $color = $color !== '' ? $color : sl_result_color((int) $premium);
        $sum = 0;
    } elseif ($family === 'K3') {
        $premium = preg_replace('/\D/', '', $premium);
        if (!preg_match('/^[1-6]{3}$/', $premium)) {
            return null;
        }
        $number = '';
        $color = '';
        $sum = array_sum(array_map('intval', str_split($premium)));
    } elseif ($family === 'D5') {
        $premium = preg_replace('/\D/', '', $premium);
        if ($premium !== '' && strlen($premium) < 5) {
            $premium = str_pad($premium, 5, '0', STR_PAD_LEFT);
        }
        if (!preg_match('/^[0-9]{5}$/', $premium)) {
            return null;
        }
        $number = '';
        $color = '';
        $sum = array_sum(array_map('intval', str_split($premium)));
    } elseif ($family === 'MotoRace') {
        $parts = array_values(array_filter(array_map('trim', explode(',', $premium)), 'strlen'));
        $numbers = array_map('intval', $parts);
        $sorted = $numbers;
        sort($sorted);
        if (count($numbers) !== 10 || $sorted !== range(1, 10)) {
            return null;
        }
        $premium = implode(',', $numbers);
        $number = '';
        $color = '';
        $sum = 0;
    } else {
        return null;
    }
    $normalized = array('issueNumber'=>$issue,'number'=>$number,'color'=>$color,'premium'=>$premium,'sum'=>$sum);
    if ($family === 'TrxWinGo') {
        // The bundled TrxWinGo record screen displays blockchain-style
        // metadata. Preserve provider values when supplied and create stable
        // local equivalents when the secure local result engine is selected.
        $blockId = trim((string) ($item['blockId'] ?? ($item['blockID'] ?? '')));
        if ($blockId === '') {
            $generatedId = hash('sha256', 'trx|' . $gameCode . '|' . $issue);
            $blockId = substr($generatedId, 0, 63) . $premium;
        }
        $blockNumber = isset($item['blockNumber']) && (string) $item['blockNumber'] !== ''
            ? (string) $item['blockNumber']
            : (string) hexdec(substr($blockId, 0, 8));
        $blockTimestamp = isset($item['blockTimestamp']) && (int) $item['blockTimestamp'] > 0
            ? (int) $item['blockTimestamp']
            : 0;
        if ($blockTimestamp === 0) {
            $dayStart = strtotime(substr($issue, 0, 8) . ' 00:00:00');
            $sequence = (int) substr($issue, -4);
            if ($dayStart !== false && $sequence > 0) {
                $blockTimestamp = ($dayStart + (($sequence - 1) * sl_interval_seconds($gameCode))) * 1000;
            } else {
                $blockTimestamp = sl_now_ms();
            }
        }
        $normalized['blockId'] = $blockId;
        $normalized['blockNumber'] = $blockNumber;
        $normalized['blockTimestamp'] = $blockTimestamp;
    }
    return $normalized;
}

function sl_rebind_wingo_current_periods($gameCode, $payload)
{
    if (!is_array($payload)) {
        return $payload;
    }
    foreach (array('previous', 'current', 'next') as $key) {
        if (!isset($payload[$key]['startTime']) || !is_numeric($payload[$key]['startTime'])) {
            continue;
        }
        $rawStart = (float)$payload[$key]['startTime'];
        $roundStart = (int)floor($rawStart > 20000000000 ? $rawStart / 1000 : $rawStart);
        if ($roundStart > 0) {
            $payload[$key]['issueNumber'] = sl_issue_number_for_start($gameCode, $roundStart);
        }
    }
    return $payload;
}

function sl_rebind_wingo_history_periods($gameCode, $list)
{
    if (!$list) {
        return $list;
    }

    // The current AR014 feed is UTC-labelled, while some history mirrors
    // label the very same rounds from an Asia/Kolkata midnight. Detect that
    // future-looking offset once per batch and translate only the issue IDs;
    // the provider's winning premium remains untouched.
    $current = sl_local_current($gameCode);
    $currentIssue = (string)$current['current']['issueNumber'];
    $interval = sl_interval_seconds($gameCode);
    $currentStart = (int)floor(((float)$current['current']['startTime']) / 1000);
    $needsRebind = false;
    foreach ($list as $candidate) {
        $candidateIssue = isset($candidate['issueNumber']) ? (string)$candidate['issueNumber'] : '';
        if (!preg_match('/^[0-9]{17}$/', $candidateIssue)) continue;
        $candidateDate = substr($candidateIssue, 0, 4) . '-' . substr($candidateIssue, 4, 2) . '-' . substr($candidateIssue, 6, 2);
        $candidateDayStart = strtotime($candidateDate . ' 00:00:00 UTC');
        $candidateSequence = (int)substr($candidateIssue, -4);
        if ($candidateDayStart !== false && $candidateSequence > 0 && $candidateDayStart + (($candidateSequence - 1) * $interval) > $currentStart + 3600) {
            $needsRebind = true;
            break;
        }
    }
    if (!$needsRebind) {
        // A small provider clock lead is not the 5:30 timezone offset.
        return $list;
    }

    $canonical = array();
    foreach ($list as $index => $item) {
        $issue = isset($item['issueNumber']) ? (string)$item['issueNumber'] : '';
        if (!preg_match('/^[0-9]{17}$/', $issue)) {
            continue;
        }
        $date = substr($issue, 0, 4) . '-' . substr($issue, 4, 2) . '-' . substr($issue, 6, 2);
        $sequence = (int)substr($issue, -4);
        $utcDayStart = strtotime($date . ' 00:00:00 UTC');
        if ($utcDayStart === false || $sequence < 1) {
            continue;
        }
        $asUtc = $utcDayStart + (($sequence - 1) * $interval);
        $mappedIssue = $issue;
        if ($asUtc > $currentStart + 3600) {
            $localDayStart = strtotime($date . ' 00:00:00 Asia/Kolkata');
            if ($localDayStart === false) {
                continue;
            }
            $providerStart = $localDayStart + (($sequence - 1) * $interval);
            $mappedIssue = sl_issue_number_for_start($gameCode, $providerStart);
        }
        if (strcmp($mappedIssue, $currentIssue) >= 0) {
            continue;
        }
        $item['issueNumber'] = $mappedIssue;
        // Prefer a row that was already stored with the canonical ID. That
        // preserves an exact-period admin override when an old mirrored row
        // maps onto the same draw during a rolling upgrade.
        $isConverted = !hash_equals($issue, $mappedIssue);
        if (!isset($canonical[$mappedIssue]) || (!$isConverted && $canonical[$mappedIssue]['converted'])) {
            $canonical[$mappedIssue] = array('item'=>$item, 'converted'=>$isConverted);
        }
    }
    krsort($canonical, SORT_STRING);
    return array_values(array_map(function ($entry) {
        return $entry['item'];
    }, $canonical));
}

function sl_provider_current($gameCode)
{
    global $SL_CONFIG;
    $control = app_game_control($gameCode);
    $remoteRequired = !empty($SL_CONFIG['force_remote_draw']);
    if ($remoteRequired || ($control['result_source'] ?? 'local_random') === 'api') {
        $url = sl_external_url($gameCode, false) . (strpos(sl_external_url($gameCode, false), '?') === false ? '?' : '&') . 'ts=' . sl_now_ms();
        $data = sl_fetch_json($url, (int) ($control['api_timeout_seconds'] ?? 5));
        if (is_array($data) && isset($data['current']['issueNumber'], $data['current']['startTime'], $data['current']['endTime'])) {
            return sl_apply_period_lag($gameCode, sl_rebind_wingo_current_periods($gameCode, $data));
        }
        if ($remoteRequired || !app_setting_bool('game_api_fallback_to_random', false)) {
            return null;
        }
    }
    return sl_apply_period_lag($gameCode, sl_local_current($gameCode));
}

function sl_provider_history($gameCode, $pageNo = 1, $pageSize = 10)
{
    global $SL_CONFIG;
    $pageNo = max(1, (int) $pageNo);
    $pageSize = min(100, max(1, (int) ($SL_CONFIG['history_page_size'] ?? $pageSize)));
    $totalPage = max(1, (int) ($SL_CONFIG['history_total_pages'] ?? 50));
    $control = app_game_control($gameCode);
    $remoteRequired = !empty($SL_CONFIG['force_remote_draw']);
    if ($remoteRequired || ($control['result_source'] ?? 'local_random') === 'api') {
        $query = http_build_query(array('pageNo'=>$pageNo,'pageSize'=>$pageSize,'ts'=>sl_now_ms()));
        $url = sl_external_url($gameCode, true) . (strpos(sl_external_url($gameCode, true), '?') === false ? '?' : '&') . $query;
        $payload = sl_fetch_json($url, (int) ($control['api_timeout_seconds'] ?? 5));
        if (is_array($payload) && isset($payload['data']['list']) && is_array($payload['data']['list'])) {
            $normalized = array();
            foreach ($payload['data']['list'] as $item) {
                $valid = sl_normalize_result_item($gameCode, $item);
                if ($valid) {
                    $normalized[] = $valid;
                }
            }
            if ($normalized) {
                $normalized = sl_rebind_wingo_history_periods($gameCode, $normalized);
                $payload['data']['list'] = $normalized;
                // Match the AR014 public contract exactly: ten rows per page,
                // five hundred records, fifty pages.
                $payload['data']['pageNo'] = $pageNo;
                $payload['data']['totalPage'] = $totalPage;
                $payload['data']['totalCount'] = $totalPage * $pageSize;
                return $payload;
            }
        }
        if ($remoteRequired || !app_setting_bool('game_api_fallback_to_random', false)) {
            return null;
        }
    }
    return sl_local_history($gameCode, $pageNo, $pageSize);
}

function sl_wingo_admin_override_table($gameCode)
{
    static $map = array(
        'WinGo_30S'    => 'hastacalita_phalitansa_zehn',
        'WinGo_1M'     => 'hastacalita_phalitansa',
        'WinGo_3M'     => 'hastacalita_phalitansa_drei',
        'WinGo_5M'     => 'hastacalita_phalitansa_funf',
        'TrxWinGo_1M'  => 'hastacalita_phalitansa_aidudi',
        'TrxWinGo_3M'  => 'hastacalita_phalitansa_aidudi_drei',
        'TrxWinGo_5M'  => 'hastacalita_phalitansa_aidudi_funf',
        'TrxWinGo_10M' => 'hastacalita_phalitansa_aidudi_zehn',
    );
    return isset($map[$gameCode]) ? $map[$gameCode] : null;
}

function sl_wingo_current_issue($gameCode)
{
    $current = sl_local_current($gameCode);
    return (string)$current['current']['issueNumber'];
}

/**
 * Number of upstream periods to stay behind on every player-facing surface.
 * Configurable through saas_lottery/config_live_v4.php ('period_lag').
 */
function sl_period_lag_periods()
{
    global $SL_CONFIG;
    static $lag = null;
    if ($lag === null) {
        $lag = max(0, min(10, (int) ($SL_CONFIG['period_lag'] ?? 0)));
    }
    return $lag;
}

/**
 * Issue number shown as the currently open period. With period_lag=1 this is
 * the period that started one interval before the live boundary, matching the
 * wingoapi buffer: countdown stays wall-clock aligned, only the period numbers
 * and the result reveal are held one period behind the upstream feed.
 */
function sl_display_issue($gameCode)
{
    $lag = sl_period_lag_periods();
    $interval = sl_interval_seconds($gameCode);
    $now = time();
    $roundStart = $now - ($now % $interval) - ($lag * $interval);
    return sl_issue_number_for_start($gameCode, $roundStart);
}

/**
 * Highest issue number players may see in history right now.
 *
 * Two independent reveal signals race each other and the earlier one wins:
 *  1. the wall-clock boundary (sl_display_issue), and
 *  2. the provider's own latest closed result from the history batch just
 *     fetched — this keeps the reveal glued to the frontend countdown even
 *     when the provider's period labels run ahead of, or lag behind, the
 *     server clock, which is exactly the skew that made a result arrive one
 *     extra cycle after the on-screen timer ended.
 * The maximum of the two is used so the reveal never waits for the slower
 * signal and never exposes a period the provider has not closed yet.
 * For lag=0 this collapses to the historical live behaviour.
 */
function sl_visible_gate_issue($gameCode, array $providerList = array())
{
    $clockGate = sl_display_issue($gameCode);
    $lag = sl_period_lag_periods();
    if ($lag <= 0) {
        $clockGate = sl_wingo_current_issue($gameCode);
    }
    $latest = '';
    foreach ($providerList as $item) {
        if (!isset($item['issueNumber'])) {
            continue;
        }
        $issue = (string) $item['issueNumber'];
        if (preg_match('/^[0-9]{17}$/', $issue) && strcmp($issue, $latest) > 0) {
            $latest = $issue;
        }
    }
    if ($latest === '' || strlen($clockGate) !== 17) {
        return $clockGate;
    }
    $interval = sl_interval_seconds($gameCode);
    $dayStart = strtotime(substr($latest, 0, 4) . '-' . substr($latest, 4, 2) . '-' . substr($latest, 6, 2) . ' 00:00:00 UTC');
    $sequence = (int) substr($latest, -4);
    if ($dayStart === false || $sequence < 1) {
        return $clockGate;
    }
    $latestStart = $dayStart + (($sequence - 1) * $interval);
    // Sanity: the provider's latest closed period must sit within one cycle
    // of the local wall-clock boundary. A mislabelled batch (foreign-timezone
    // mirrors) is ignored so results can never be revealed prematurely; the
    // clock gate keeps serving until the data realigns.
    $liveStart = time() - (time() % $interval);
    if (abs($latestStart - $liveStart) > 2 * $interval) {
        return $clockGate;
    }
    // The provider's latest closed period is visible when lag=1 only once the
    // frontend's current period (that same latest one) has closed on-screen,
    // so the gate equals the provider's latest closed issue itself:
    // visible issues are everything strictly below that number.
    $providerGate = sl_issue_number_for_start($gameCode, $latestStart - (($lag - 1) * $interval));
    return strcmp($providerGate, $clockGate) > 0 ? $providerGate : $clockGate;
}

/**
 * Shift the period numbers of a current/next payload backwards by the
 * configured lag while keeping the provider's real start/end timestamps, so
 * the frontend countdown, the betting lock and the accepted issue number stay
 * in lockstep with one delayed period number.
 */
function sl_apply_period_lag($gameCode, $payload)
{
    if (!is_array($payload) || sl_period_lag_periods() <= 0) {
        return $payload;
    }
    $interval = sl_interval_seconds($gameCode);
    $lag = sl_period_lag_periods() * $interval;
    foreach (array('previous', 'current', 'next') as $key) {
        if (!isset($payload[$key]['startTime']) || !is_numeric($payload[$key]['startTime'])) {
            continue;
        }
        $rawStart = (float)$payload[$key]['startTime'];
        $roundStart = (int)floor($rawStart > 20000000000 ? $rawStart / 1000 : $rawStart);
        if ($roundStart > 0) {
            $payload[$key]['issueNumber'] = sl_issue_number_for_start($gameCode, $roundStart - $lag);
        }
    }
    return $payload;
}

function sl_prune_unsettled_future_results($gameCode, $currentIssue = '')
{
    global $conn;
    $currentIssue = $currentIssue !== '' ? (string)$currentIssue : sl_wingo_current_issue($gameCode);
    $stmt = $conn->prepare(
        "DELETE r FROM saas_lottery_results r "
        . "LEFT JOIN saas_lottery_bets b ON b.game_code=r.game_code "
        . "AND b.issue_number=r.issue_number AND b.status<>'pending' "
        . "WHERE r.game_code=? AND r.issue_number>=? AND b.id IS NULL"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to clean invalid WinGo periods');
    }
    $stmt->bind_param('ss', $gameCode, $currentIssue);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to clean invalid WinGo periods');
    }
    $stmt->close();
}

/**
 * Settlement must wait for the on-screen countdown of the chosen period, not
 * for the upstream result. With the display lag the real result of the
 * visible period is already cached a full cycle early, so pending bets are
 * held until the issue falls behind the visible-reveal gate — the exact
 * moment the countdown ends and the result is revealed in history. Because
 * this mirrors sl_visible_gate_issue, payout and on-screen reveal always land
 * on the same request cycle. With period_lag=0 the gate equals the live
 * current issue, i.e. the original immediate-settlement behaviour.
 */
function sl_settlement_gate_issue($gameCode, array $providerList = array())
{
    return sl_visible_gate_issue($gameCode, $providerList);
}

function sl_save_and_settle_results($gameCode, $list)
{
    global $conn;
    $currentIssue = sl_wingo_current_issue($gameCode);
    if ($currentIssue !== '') {
        sl_prune_unsettled_future_results($gameCode, $currentIssue);
    }
    $normalized = array();
    foreach ((array) $list as $rawItem) {
        $item = sl_normalize_result_item($gameCode, $rawItem);
        if ($item && ($currentIssue === '' || strcmp((string)$item['issueNumber'], $currentIssue) < 0)) {
            $normalized[(string) $item['issueNumber']] = $item;
        }
    }
    if (!$normalized) return;

    // Bets stay pending until the visible-reveal gate passes their issue, so
    // payout and the on-screen result always appear together.
    $settleGate = sl_settlement_gate_issue($gameCode, array_values($normalized));

    // WinGo uses an exact issue-bound override.  Keep the old one-shot
    // fallback only for legacy TrxWinGo admin pages that do not yet submit an
    // issue number.
    $periodBoundOverride = sl_admin_override_game_config($gameCode) !== null;
    $legacyOverrideNumber = null;
    $legacyOverrideTable = sl_wingo_admin_override_table($gameCode);
    if (!$periodBoundOverride && $legacyOverrideTable !== null) {
        try {
            $tbl   = $conn->real_escape_string($legacyOverrideTable);
            $ovRes = $conn->query("SELECT sankhye FROM `{$tbl}` WHERE sthiti='1' LIMIT 1");
            if ($ovRes && $ovRes->num_rows > 0) {
                $ovRow = $ovRes->fetch_assoc();
                $ovNum = (int)$ovRow['sankhye'];
                if ($ovNum >= 0 && $ovNum <= 9) {
                    $legacyOverrideNumber = (string)$ovNum;
                }
            }
        } catch (Throwable $e) {
            error_log('[saas-lottery override check] ' . $e->getMessage());
        }
    }
    $legacyOverrideApplied = false;

    // Read existing results and pending issues in two bulk queries. History is
    // polled frequently by the client, so settled rows should not trigger
    // hundreds of writes and transactions on every refresh.
    $escapedIssues = array();
    foreach (array_keys($normalized) as $issue) {
        $escapedIssues[] = "'" . $conn->real_escape_string($issue) . "'";
    }
    $gameEscaped = $conn->real_escape_string($gameCode);
    $issueSql = implode(',', $escapedIssues);
    $existing = array();
    $existingResult = $conn->query("SELECT issue_number,premium FROM saas_lottery_results WHERE game_code='" . $gameEscaped . "' AND issue_number IN (" . $issueSql . ")");
    while ($existingResult && ($row = $existingResult->fetch_assoc())) {
        $existing[(string) $row['issue_number']] = (string) $row['premium'];
    }
    $pending = array();
    $pendingResult = $conn->query("SELECT DISTINCT issue_number FROM saas_lottery_bets WHERE game_code='" . $gameEscaped . "' AND status='pending' AND issue_number IN (" . $issueSql . ")");
    while ($pendingResult && ($row = $pendingResult->fetch_assoc())) {
        $pending[(string) $row['issue_number']] = true;
    }

    foreach ($normalized as $issue => $item) {
        $issue = (string) $item['issueNumber'];
        $premium = (string) $item['premium'];
        $number = (string) $item['number'];
        $color = (string) $item['color'];
        $sum = (int) $item['sum'];

        if (isset($existing[$issue])) {
            // Period already stored — NEVER modify old history. With the
            // display lag active, a player can still legitimately bet on the
            // on-screen period after its upstream result was cached one
            // period earlier, so replay settlement for pending bets instead of
            // leaving them stuck. sl_settle_issue only touches rows that are
            // still status='pending', which keeps this idempotent.
            if (!empty($pending[$issue]) && ($settleGate === '' || strcmp($issue, $settleGate) < 0)) {
                $replay = sl_normalize_result_item($gameCode, array(
                    'issueNumber' => $issue,
                    'premium' => (string) $existing[$issue],
                ));
                if ($replay) {
                    sl_settle_issue($gameCode, $issue, $replay);
                    unset($pending[$issue]);
                }
            }
            continue;
        }

        // WinGo override must match this exact issue.  Therefore a backlog of
        // unsaved history rows can only receive their provider values.
        $adminOverrideNumber = null;
        $exactOverride = null;
        if ($periodBoundOverride) {
            try {
                $exactOverride = sl_admin_override_get($conn, $gameCode, $issue);
                if ($exactOverride) {
                    $adminOverrideNumber = (string)$exactOverride['premium'];
                }
            } catch (Throwable $e) {
                error_log('[saas-lottery period override check] ' . $e->getMessage());
            }
        } elseif ($legacyOverrideNumber !== null && !$legacyOverrideApplied) {
            $adminOverrideNumber = $legacyOverrideNumber;
            $legacyOverrideApplied = true;
        }

        if ($adminOverrideNumber !== null) {
            $overrideItem = sl_normalize_result_item($gameCode, array(
                'issueNumber'=>$issue,
                'premium'=>$adminOverrideNumber,
            ));
            if ($overrideItem) {
                $premium = (string)$overrideItem['premium'];
                $number = (string)$overrideItem['number'];
                $color = (string)$overrideItem['color'];
                $sum = (int)$overrideItem['sum'];
                $item = $overrideItem;
                error_log('[saas-lottery] admin override injected for exact period: game=' . $gameCode . ' issue=' . $issue . ' premium=' . $premium);
            }
        }

        $insert = $conn->prepare('INSERT IGNORE INTO saas_lottery_results(game_code,issue_number,premium,number,color,result_sum,provider_seen_at,created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');
        $insert->bind_param('sssssi', $gameCode, $issue, $premium, $number, $color, $sum);
        $insert->execute();
        $inserted = $insert->affected_rows === 1;
        $insert->close();
        if (!$inserted) {
            $check = $conn->prepare('SELECT premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? AND issue_number=? LIMIT 1');
            $check->bind_param('ss', $gameCode, $issue);
            $check->execute();
            $storedPremium = $storedNumber = $storedColor = null;
            $storedSum = 0;
            $check->bind_result($storedPremium, $storedNumber, $storedColor, $storedSum);
            $check->fetch();
            $check->close();
            $premium = (string)$storedPremium;
            $number = (string)$storedNumber;
            $color = (string)$storedColor;
            $sum = (int)$storedSum;
            $item['premium'] = $premium;
            $item['number'] = $number;
            $item['color'] = $color;
            $item['sum'] = $sum;
        }
        $existing[$issue] = $premium;
        $pending[$issue] = true;

        if ($exactOverride) {
            // The result row now exists and is immutable.  Clear only the
            // legacy active flag; retain the issue-bound row so a delayed
            // legacy settlement can resolve the same exact number.
            sl_admin_override_mark_applied($conn, $gameCode, $issue);
        }

        if (!empty($pending[$issue]) && sl_settlement_allowed($gameCode, $issue)) {
            sl_settle_issue($gameCode, $issue, $item);
        }
    }

    if ($legacyOverrideApplied && $legacyOverrideTable !== null) {
        try {
            $tbl = $conn->real_escape_string($legacyOverrideTable);
            $conn->query("UPDATE `{$tbl}` SET sthiti='0'");
        } catch (Throwable $e) {
            error_log('[saas-lottery override clear] ' . $e->getMessage());
        }
    }
}

function sl_sync_results($gameCode)
{
    // Same-trend result synchronization is permanently enabled. Admin/UI
    // flags cannot disable the authoritative provider pipeline.
    $payload = sl_provider_history($gameCode, 1, 10);
    if ($payload && isset($payload['data']['list'])) {
        sl_save_and_settle_results($gameCode, $payload['data']['list']);
    }
    return $payload;
}

function sl_combination_count($n, $r)
{
    $n = (int) $n;
    $r = (int) $r;
    if ($r < 0 || $n < $r) {
        return 0;
    }
    if ($r === 0 || $n === $r) {
        return 1;
    }
    $r = min($r, $n - $r);
    $result = 1;
    for ($i = 1; $i <= $r; $i++) {
        $result = (int) (($result * ($n - $r + $i)) / $i);
    }
    return $result;
}

function sl_selected_numbers($value, $min, $max)
{
    $parts = explode('_', (string) $value);
    $numbers = array();
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            return null;
        }
        $number = (int) $part;
        if ($number < $min || $number > $max || in_array($number, $numbers, true)) {
            return null;
        }
        $numbers[] = $number;
    }
    return $numbers;
}

function sl_bet_units($gameCode, $content)
{
    $family = sl_game_family($gameCode);
    $content = trim((string) $content);
    if ($family === 'WinGo' || $family === 'TrxWinGo') {
        return preg_match('/^(Num_[0-9]|Color_(green|red|violet)|BigSmall_(big|small))$/i', $content) ? 1 : 0;
    }
    if ($family === 'K3') {
        if (preg_match('/^SumNum_(\d{1,2})$/', $content, $m)) {
            return (int) $m[1] >= 3 && (int) $m[1] <= 18 ? 1 : 0;
        }
        if (preg_match('/^(SumBigSmall_(Big|Small)|SumOddEven_(Odd|Even)|NumSame3All_AAA|NumNear3All_ABC)$/', $content)) {
            return 1;
        }
        if (preg_match('/^NumSame2_([1-6])\1$/', $content) || preg_match('/^NumSame3_([1-6])\1\1$/', $content)) {
            return 1;
        }
        if (preg_match('/^NumSame2Mult_([1-6])\1_(.+)$/', $content, $m)) {
            $singles = sl_selected_numbers($m[2], 1, 6);
            return $singles && !in_array((int) $m[1], $singles, true) ? count($singles) : 0;
        }
        if (strpos($content, 'NumDiff3_') === 0) {
            $numbers = sl_selected_numbers(substr($content, 9), 1, 6);
            return $numbers && count($numbers) >= 3 ? sl_combination_count(count($numbers), 3) : 0;
        }
        if (strpos($content, 'NumDiff2_') === 0) {
            $numbers = sl_selected_numbers(substr($content, 9), 1, 6);
            return $numbers && count($numbers) >= 2 ? sl_combination_count(count($numbers), 2) : 0;
        }
        return 0;
    }
    if ($family === 'D5') {
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)Num_[0-9]$/', $content)) {
            return 1;
        }
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)BigSmall_(Big|Small)$/', $content)) {
            return 1;
        }
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)OddEven_(Odd|Even)$/', $content)) {
            return 1;
        }
        return preg_match('/^Sum(BigSmall_(Big|Small)|OddEven_(Odd|Even))$/', $content) ? 1 : 0;
    }
    if ($family === 'MotoRace') {
        if (preg_match('/^(First|Second|Third)Num_(10|[1-9])$/', $content)) {
            return 1;
        }
        return preg_match('/^(First|Second|Third)(BigSmall_(Big|Small)|OddEven_(Odd|Even))$/', $content) ? 1 : 0;
    }
    return 0;
}

function sl_normalize_bets($gameCode, $rawContent)
{
    $contents = is_array($rawContent) ? array_values($rawContent) : array($rawContent);
    if (!$contents || count($contents) > 100) {
        sl_fail(342, 'Bet content is invalid', 342, 200);
    }
    $bets = array();
    foreach ($contents as $content) {
        if (!is_scalar($content)) {
            sl_fail(342, 'Bet content is invalid', 342, 200);
        }
        $content = trim((string) $content);
        $units = sl_bet_units($gameCode, $content);
        if ($units < 1) {
            sl_fail(342, 'Bet content is invalid', 342, 200);
        }
        $bets[] = array('content'=>$content,'units'=>$units);
    }
    return $bets;
}

function sl_k3_sum_rate($sum)
{
    $rates = array(3=>207.36,4=>69.12,5=>34.56,6=>20.74,7=>13.83,8=>9.88,9=>8.30,10=>7.68,11=>7.68,12=>8.30,13=>9.88,14=>13.83,15=>20.74,16=>34.56,17=>69.12,18=>207.36);
    return isset($rates[(int) $sum]) ? $rates[(int) $sum] : 0.0;
}

function sl_evaluate_bet($gameCode, $content, $result)
{
    $family = sl_game_family($gameCode);
    $premium = (string) $result['premium'];
    if ($family === 'WinGo' || $family === 'TrxWinGo') {
        $number = (int) $premium;
        $parts = explode('_', strtolower($content), 2);
        if (count($parts) !== 2) {
            return array(0, 0.0);
        }
        list($type, $pick) = $parts;
        if ($type === 'num') {
            return array((string) $number === $pick ? 1 : 0, 9.0);
        }
        if ($type === 'bigsmall') {
            $won = ($pick === 'big' && $number >= 5) || ($pick === 'small' && $number <= 4);
            return array($won ? 1 : 0, 2.0);
        }
        if ($type === 'color') {
            if ($pick === 'violet') {
                return array(in_array($number, array(0,5), true) ? 1 : 0, 4.5);
            }
            if ($pick === 'green') {
                return array(in_array($number, array(1,3,5,7,9), true) ? 1 : 0, $number === 5 ? 1.5 : 2.0);
            }
            if ($pick === 'red') {
                return array(in_array($number, array(0,2,4,6,8), true) ? 1 : 0, $number === 0 ? 1.5 : 2.0);
            }
        }
        return array(0, 0.0);
    }

    if ($family === 'K3') {
        $dice = array_map('intval', str_split($premium));
        $sum = array_sum($dice);
        $counts = array_count_values($dice);
        if (preg_match('/^SumNum_(\d{1,2})$/', $content, $m)) {
            return array($sum === (int) $m[1] ? 1 : 0, sl_k3_sum_rate((int) $m[1]));
        }
        if (preg_match('/^SumBigSmall_(Big|Small)$/', $content, $m)) {
            $won = ($m[1] === 'Big' && $sum >= 11) || ($m[1] === 'Small' && $sum <= 10);
            return array($won ? 1 : 0, 2.0);
        }
        if (preg_match('/^SumOddEven_(Odd|Even)$/', $content, $m)) {
            $won = ($m[1] === 'Odd' && $sum % 2 === 1) || ($m[1] === 'Even' && $sum % 2 === 0);
            return array($won ? 1 : 0, 2.0);
        }
        if (preg_match('/^NumSame2_([1-6])\1$/', $content, $m)) {
            return array(($counts[(int) $m[1]] ?? 0) === 2 ? 1 : 0, 13.83);
        }
        if (preg_match('/^NumSame2Mult_([1-6])\1_(.+)$/', $content, $m)) {
            $pair = (int) $m[1];
            $singles = sl_selected_numbers($m[2], 1, 6) ?: array();
            $third = null;
            if (($counts[$pair] ?? 0) === 2) {
                foreach ($dice as $die) {
                    if ($die !== $pair) {
                        $third = $die;
                        break;
                    }
                }
            }
            return array($third !== null && in_array($third, $singles, true) ? 1 : 0, 69.12);
        }
        if (preg_match('/^NumSame3_([1-6])\1\1$/', $content, $m)) {
            return array(($counts[(int) $m[1]] ?? 0) === 3 ? 1 : 0, 207.36);
        }
        if ($content === 'NumSame3All_AAA') {
            return array(count($counts) === 1 ? 1 : 0, 34.56);
        }
        if (strpos($content, 'NumDiff3_') === 0) {
            $selected = sl_selected_numbers(substr($content, 9), 1, 6) ?: array();
            $unique = array_values(array_unique($dice));
            $won = count($unique) === 3 && count(array_intersect($unique, $selected)) === 3;
            return array($won ? 1 : 0, 34.56);
        }
        if ($content === 'NumNear3All_ABC') {
            $unique = array_values(array_unique($dice));
            sort($unique);
            $won = count($unique) === 3 && $unique[1] === $unique[0] + 1 && $unique[2] === $unique[1] + 1;
            return array($won ? 1 : 0, 8.64);
        }
        if (strpos($content, 'NumDiff2_') === 0) {
            $selected = sl_selected_numbers(substr($content, 9), 1, 6) ?: array();
            $matched = array_values(array_intersect(array_values(array_unique($dice)), $selected));
            return array(sl_combination_count(count($matched), 2), 6.91);
        }
        return array(0, 0.0);
    }

    if ($family === 'D5') {
        $digits = array_map('intval', str_split($premium));
        $positions = array('First'=>0,'Second'=>1,'Third'=>2,'Fourth'=>3,'Fifth'=>4);
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)Num_([0-9])$/', $content, $m)) {
            return array($digits[$positions[$m[1]]] === (int) $m[2] ? 1 : 0, 9.0);
        }
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)BigSmall_(Big|Small)$/', $content, $m)) {
            $value = $digits[$positions[$m[1]]];
            $won = ($m[2] === 'Big' && $value >= 5) || ($m[2] === 'Small' && $value <= 4);
            return array($won ? 1 : 0, 2.0);
        }
        if (preg_match('/^(First|Second|Third|Fourth|Fifth)OddEven_(Odd|Even)$/', $content, $m)) {
            $value = $digits[$positions[$m[1]]];
            $won = ($m[2] === 'Odd' && $value % 2 === 1) || ($m[2] === 'Even' && $value % 2 === 0);
            return array($won ? 1 : 0, 2.0);
        }
        $sum = array_sum($digits);
        if (preg_match('/^SumBigSmall_(Big|Small)$/', $content, $m)) {
            $won = ($m[1] === 'Big' && $sum >= 23) || ($m[1] === 'Small' && $sum <= 22);
            return array($won ? 1 : 0, 2.0);
        }
        if (preg_match('/^SumOddEven_(Odd|Even)$/', $content, $m)) {
            $won = ($m[1] === 'Odd' && $sum % 2 === 1) || ($m[1] === 'Even' && $sum % 2 === 0);
            return array($won ? 1 : 0, 2.0);
        }
        return array(0, 0.0);
    }

    if ($family === 'MotoRace') {
        $rank = array_map('intval', explode(',', $premium));
        $positions = array('First'=>0,'Second'=>1,'Third'=>2);
        if (preg_match('/^(First|Second|Third)Num_(10|[1-9])$/', $content, $m)) {
            return array($rank[$positions[$m[1]]] === (int) $m[2] ? 1 : 0, 9.8);
        }
        if (preg_match('/^(First|Second|Third)BigSmall_(Big|Small)$/', $content, $m)) {
            $value = $rank[$positions[$m[1]]];
            $won = ($m[2] === 'Big' && $value >= 6) || ($m[2] === 'Small' && $value <= 5);
            return array($won ? 1 : 0, 2.0);
        }
        if (preg_match('/^(First|Second|Third)OddEven_(Odd|Even)$/', $content, $m)) {
            $value = $rank[$positions[$m[1]]];
            $won = ($m[2] === 'Odd' && $value % 2 === 1) || ($m[2] === 'Even' && $value % 2 === 0);
            return array($won ? 1 : 0, 2.0);
        }
    }
    return array(0, 0.0);
}

/**
 * Return the net amount credited for a winning bet.
 *
 * Game odds remain gross display odds (for example 2X). Settlement always
 * deducts the mandatory 2% payout tax once and stores only the net amount, so
 * the winner popup, wallet, ledger and record APIs cannot disagree.
 */
function sl_tax_percent()
{
    $taxPercent = function_exists('app_setting') ? (float) app_setting('lottery_payout_tax_percent', '2.00') : 2.0;
    return max(0.0, min(100.0, $taxPercent));
}

function sl_stake_tax_fee($stake)
{
    return round(max(0.0, (float) $stake) * sl_tax_percent() / 100.0, 4);
}

function sl_net_payout_after_tax($grossPayout)
{
    $grossPayout = max(0.0, (float) $grossPayout);
    if ($grossPayout <= 0.0) {
        return 0.0;
    }
    return round($grossPayout * (100.0 - sl_tax_percent()) / 100.0, 2);
}

function sl_settle_issue($gameCode, $issue, $resultItem)
{
    global $conn;
    if (!app_setting_bool('auto_settlement_enabled', true)) {
        return;
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id,user_id,bet_content,amount,bet_multiple,stake FROM saas_lottery_bets WHERE game_code=? AND issue_number=? AND status='pending' FOR UPDATE");
        $stmt->bind_param('ss', $gameCode, $issue);
        $stmt->execute();
        $result = $stmt->get_result();
        $bets = array();
        while ($result && ($row = $result->fetch_assoc())) {
            $bets[] = $row;
        }
        $stmt->close();

        foreach ($bets as $bet) {
            list($winningUnits, $rate) = sl_evaluate_bet($gameCode, (string) $bet['bet_content'], $resultItem);
            $unitStake = (float) $bet['amount'] * (int) $bet['bet_multiple'];
            $grossPayout = $winningUnits > 0 ? round($unitStake * $rate * $winningUnits, 4) : 0.0;
            $payout = $winningUnits > 0 ? sl_net_payout_after_tax($grossPayout) : 0.0;
            $taxFee = sl_stake_tax_fee((float) $bet['stake']);
            $status = $winningUnits > 0 ? 'won' : 'lost';
            $userId = (int) $bet['user_id'];
            $betId = (int) $bet['id'];

            if ($payout > 0) {
                $wallet = $conn->prepare('SELECT motta FROM shonu_kaichila WHERE balakedara=? FOR UPDATE');
                $wallet->bind_param('i', $userId);
                $wallet->execute();
                $walletResult = $wallet->get_result();
                $walletRow = $walletResult ? $walletResult->fetch_assoc() : null;
                $wallet->close();
                if (!$walletRow) {
                    throw new RuntimeException('Wallet row missing during settlement');
                }
                $before = (float) $walletRow['motta'];
                $after = round($before + $payout, 4);
                $updateWallet = $conn->prepare('UPDATE shonu_kaichila SET motta=? WHERE balakedara=?');
                $updateWallet->bind_param('di', $after, $userId);
                $updateWallet->execute();
                $updateWallet->close();

                $entryKey = 'saas-settlement:' . $betId;
                $entryType = 'bet_payout';
                $ledger = $conn->prepare('INSERT IGNORE INTO saas_wallet_ledger(user_id,entry_key,entry_type,amount,balance_before,balance_after,created_at) VALUES (?,?,?,?,?,?,NOW())');
                $ledger->bind_param('issddd', $userId, $entryKey, $entryType, $payout, $before, $after);
                $ledger->execute();
                $ledger->close();
            }

            $premium = (string) $resultItem['premium'];
            $update = $conn->prepare("UPDATE saas_lottery_bets SET status=?,result_premium=?,payout=?,tax_fee=?,settled_at=NOW() WHERE id=? AND status='pending'");
            $update->bind_param('ssddi', $status, $premium, $payout, $taxFee, $betId);
            $update->execute();
            $update->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function sl_wallet_balance($userId)
{
    global $conn;
    $stmt = $conn->prepare('SELECT motta FROM shonu_kaichila WHERE balakedara=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $balance = null;
    $stmt->bind_result($balance);
    $found = $stmt->fetch();
    $stmt->close();
    return $found && is_numeric($balance) ? round((float) $balance, 4) : 0.0;
}

function sl_apply_team_commissions($sourceUserId, $turnover, $groupKey)
{
    global $conn;
    if ($turnover <= 0 || !app_table_exists('app_saas_commissions')) return;
    $stmt=$conn->prepare('SELECT code,code1,code2,code3,code4,code5 FROM shonu_subjects WHERE id=? LIMIT 1');
    if(!$stmt)return;$stmt->bind_param('i',$sourceUserId);$stmt->execute();$result=$stmt->get_result();$source=$result?$result->fetch_assoc():null;$stmt->close();if(!$source)return;
    $rates=[];for($i=1;$i<=6;$i++)$rates[$i]=(float)app_setting('team_commission_level'.$i.'_percent',[1=>0.85,2=>0.75,3=>0.50,4=>0.35,5=>0.20,6=>0.15][$i]);
    foreach($rates as $level=>$rate){
        $column=$level===1?'code':'code'.($level-1);$code=trim((string)($source[$column]??''));if($code==='')continue;
        $stmt=$conn->prepare('SELECT id FROM shonu_subjects WHERE owncode=? LIMIT 1');$stmt->bind_param('s',$code);$stmt->execute();$beneficiary=0;$stmt->bind_result($beneficiary);$found=$stmt->fetch();$stmt->close();if(!$found||$beneficiary<1)continue;
        $commission=round($turnover*$rate/100,4);
        $stmt=$conn->prepare('INSERT IGNORE INTO app_saas_commissions(request_group_key,source_user_id,beneficiary_user_id,level_no,turnover,commission_amount,created_at,credited_at) VALUES (?,?,?,?,?,?,NOW(),NULL)');$stmt->bind_param('siiidd',$groupKey,$sourceUserId,$beneficiary,$level,$turnover,$commission);$stmt->execute();$stmt->close();
    }
}

function sl_place_bet($user, $input)
{
    global $conn, $SL_CONFIG;
    if (!app_setting_bool('betting_enabled', true)) {
        sl_fail(405, 'Betting is temporarily disabled', 405, 200);
    }
    $gameCode = sl_game_code($input);
    if (!sl_game_enabled($gameCode)) {
        sl_fail(405, 'Game is under maintenance', 405, 200);
    }
    $issue = isset($input['issueNumber']) ? (string) $input['issueNumber'] : '';
    $rawContent = isset($input['betContent']) ? $input['betContent'] : null;
    $amount = isset($input['amount']) && is_numeric($input['amount']) ? (float) $input['amount'] : 0.0;
    $multipleRaw = $input['betMultiple'] ?? ($input['quantity'] ?? ($input['betCount'] ?? 0));
    $multiple = is_numeric($multipleRaw) ? (int) $multipleRaw : 0;
    if (!preg_match('/^\d{8,40}$/', $issue)) {
        sl_fail(342, 'Issue number is invalid', 342, 200);
    }
    $bets = sl_normalize_bets($gameCode, $rawContent);
    $totalUnits = 0;
    foreach ($bets as $bet) {
        $totalUnits += (int) $bet['units'];
    }
    $stake = round($amount * $multiple * $totalUnits, 4);
    if ($amount < 1 || $multiple < 1 || $multiple > 100000 || $stake <= 0 || $stake > (float) $SL_CONFIG['maximum_stake']) {
        sl_fail(401, 'Bet amount is invalid', 401, 200);
    }

    $current = sl_provider_current($gameCode);
    if (!$current) {
        sl_fail(503, 'Result feed is unavailable; betting is paused', 503, 200);
    }
    $currentIssue = (string) $current['current']['issueNumber'];
    $endTime = (int) $current['current']['endTime'];
    $control = app_game_control($gameCode);
    $lockSeconds = max((int) $SL_CONFIG['bet_lock_seconds'], (int) ($control['lock_before_close_seconds'] ?? 0));
    if ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000)) {
        sl_fail(404, 'Betting has stopped for the current period', 404, 200);
    }

    $userId = (int) $user['id'];
    $requestSeed = $userId . '|' . $gameCode . '|' . $issue . '|' . json_encode($bets) . '|' . number_format($amount, 4, '.', '') . '|' . $multiple . '|' . (string) ($input['signature'] ?? '') . '|' . (string) ($input['random'] ?? '');
    $groupKey = hash('sha256', $requestSeed);

    $conn->begin_transaction();
    try {
        // Reserve the whole client request before touching the wallet. This
        // makes simultaneous retries idempotent even when one request has
        // several bet-content rows.
        $reserve = $conn->prepare('INSERT IGNORE INTO saas_lottery_requests(request_group_key,user_id,game_code,issue_number,created_at) VALUES (?,?,?,?,NOW())');
        $reserve->bind_param('siss', $groupKey, $userId, $gameCode, $issue);
        $reserve->execute();
        $reserved = $reserve->affected_rows === 1;
        $reserve->close();
        if (!$reserved) {
            $duplicate = $conn->prepare('SELECT id FROM saas_lottery_bets WHERE request_group_key=? ORDER BY id ASC LIMIT 1');
            $duplicate->bind_param('s', $groupKey);
            $duplicate->execute();
            $duplicateId = null;
            $duplicate->bind_result($duplicateId);
            $duplicate->fetch();
            $duplicate->close();
            $conn->commit();
            return array('betId'=>(int)$duplicateId,'accepted'=>true,'duplicate'=>true,'balance'=>sl_wallet_balance($userId));
        }

        $wallet = $conn->prepare('SELECT motta FROM shonu_kaichila WHERE balakedara=? FOR UPDATE');
        $wallet->bind_param('i', $userId);
        $wallet->execute();
        $walletResult = $wallet->get_result();
        $walletRow = $walletResult ? $walletResult->fetch_assoc() : null;
        $wallet->close();
        if (!$walletRow) {
            throw new RuntimeException('Wallet row is missing');
        }
        $before = (float) $walletRow['motta'];
        if ($before + 0.00001 < $stake) {
            $conn->rollback();
            sl_fail(1, 'Balance is not enough', 142, 200);
        }
        $after = round($before - $stake, 4);
        $betIds = array();
        foreach ($bets as $index => $bet) {
            $content = (string) $bet['content'];
            $units = (int) $bet['units'];
            $rowStake = round($amount * $multiple * $units, 4);
            $rowTax = sl_stake_tax_fee($rowStake);
            $requestKey = hash('sha256', $groupKey . '|' . $index . '|' . $content);
            $insert = $conn->prepare("INSERT INTO saas_lottery_bets(user_id,game_code,issue_number,bet_content,amount,bet_multiple,bet_units,stake,tax_fee,request_group_key,request_key,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',NOW())");
            $insert->bind_param('isssdiiddss', $userId, $gameCode, $issue, $content, $amount, $multiple, $units, $rowStake, $rowTax, $groupKey, $requestKey);
            $insert->execute();
            $betIds[] = (int) $insert->insert_id;
            $insert->close();
        }

        $update = $conn->prepare('UPDATE shonu_kaichila SET motta=? WHERE balakedara=?');
        $update->bind_param('di', $after, $userId);
        $update->execute();
        $update->close();

        sl_apply_team_commissions($userId, $stake, $groupKey);

        $entryKey = 'saas-bet-group:' . $groupKey;
        $entryType = 'bet_stake';
        $negativeStake = -$stake;
        $ledger = $conn->prepare('INSERT INTO saas_wallet_ledger(user_id,entry_key,entry_type,amount,balance_before,balance_after,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $ledger->bind_param('issddd', $userId, $entryKey, $entryType, $negativeStake, $before, $after);
        $ledger->execute();
        $ledger->close();

        $vipProgress = app_vip_apply_experience($conn, $userId, $stake);
        $vipMark = $conn->prepare('UPDATE saas_lottery_bets SET vip_exp_applied=1 WHERE request_group_key=? AND vip_exp_applied=0');
        if (!$vipMark) {
            throw new RuntimeException('VIP bet marker unavailable');
        }
        $vipMark->bind_param('s', $groupKey);
        if (!$vipMark->execute() || $vipMark->affected_rows < 1) {
            $vipMark->close();
            throw new RuntimeException('VIP bet marker failed');
        }
        $vipMark->close();

        $conn->commit();
        return array(
            'betId'=>$betIds[0] ?? 0,
            'betIds'=>$betIds,
            'accepted'=>true,
            'balance'=>$after,
            'stake'=>$stake,
            'vipExperienceAdded'=>(int)$vipProgress['experience_added'],
            'vipExperience'=>(int)$vipProgress['experience'],
            'vipLevel'=>(int)$vipProgress['level']
        );
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function sl_cached_history($gameCode, $limit = 100, array $providerList = array())
{
    global $conn;
    $limit = min(500, max(1, (int) $limit));
    $currentIssue = sl_wingo_current_issue($gameCode);
    $visibleIssue = sl_visible_gate_issue($gameCode, $providerList);
    if ($currentIssue !== '') {
        sl_prune_unsettled_future_results($gameCode, $currentIssue);
    }
    if ($visibleIssue !== '') {
        $stmt = $conn->prepare('SELECT issue_number,premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? AND issue_number<? ORDER BY issue_number DESC LIMIT ?');
        $stmt->bind_param('ssi', $gameCode, $visibleIssue, $limit);
    } else {
        $stmt = $conn->prepare('SELECT issue_number,premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? ORDER BY issue_number DESC LIMIT ?');
        $stmt->bind_param('si', $gameCode, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($result && ($row = $result->fetch_assoc())) {
        $item = array('issueNumber'=>(string)$row['issue_number'],'number'=>(string)$row['number'],'color'=>(string)$row['color'],'premium'=>(string)$row['premium'],'sum'=>(int)$row['result_sum']);
        $list[] = sl_game_family($gameCode) === 'TrxWinGo' ? sl_normalize_result_item($gameCode, $item) : $item;
    }
    $stmt->close();
    return $list;
}

function sl_history_provider_fallback($gameCode, $payload, $pageNo, $pageSize)
{
    $source = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
        ? $payload['data']
        : array();
    $rawList = isset($source['list']) && is_array($source['list']) ? $source['list'] : array();
    $usable = array();
    foreach ($rawList as $rawItem) {
        $item = sl_normalize_result_item($gameCode, $rawItem);
        if ($item) {
            $usable[] = $item;
        }
    }
    // Canonicalise mirrored labels first, then apply the same reveal gate as
    // sl_history_page: provider's own latest closed issue vs the wall clock.
    $usable = sl_rebind_wingo_history_periods($gameCode, $usable);
    $gateIssue = sl_visible_gate_issue($gameCode, $usable);
    $list = array();
    foreach ($usable as $item) {
        if ($gateIssue === '' || strcmp((string)$item['issueNumber'], $gateIssue) < 0) {
            $list[] = $item;
        }
    }

    $totalCount = isset($source['totalCount']) && is_numeric($source['totalCount'])
        ? max(0, (int)$source['totalCount'])
        : count($list);
    $totalPage = isset($source['totalPage']) && is_numeric($source['totalPage'])
        ? max(0, (int)$source['totalPage'])
        : ($totalCount > 0 ? (int)ceil($totalCount / max(1, (int)$pageSize)) : 0);
    $data = array(
        'list' => $list,
        'pageNo' => max(1, (int)$pageNo),
        'totalPage' => $totalPage,
        'totalCount' => $totalCount
    );
    if (sl_game_family($gameCode) === 'MotoRace') {
        $data['statistics'] = sl_moto_statistics($list);
    }
    return $data;
}

function sl_log_history_failure($gameCode, $phase, Throwable $error)
{
    $context = array(
        'component' => 'saas_lottery_history',
        'game_code' => (string)$gameCode,
        'phase' => (string)$phase,
        'exception' => get_class($error),
        'error' => $error->getMessage()
    );
    if (function_exists('app_log_event')) {
        app_log_event('error', 'Lottery history storage failed; provider fallback returned', $context);
        return;
    }
    error_log('[saas_lottery_history] ' . (string)$phase . ': ' . $error->getMessage());
}

function sl_history_page($gameCode, $input = array())
{
    global $conn;
    static $schemaReady = null;
    static $storageReady = true;

    if ($schemaReady === null) {
        try {
            // Public draw history uses the same result cache as authenticated
            // APIs, so it must run the idempotent installer before any query.
            sl_install_schema();
            $schemaReady = true;
        } catch (Throwable $e) {
            $schemaReady = false;
            sl_log_history_failure($gameCode, 'schema_repair', $e);
        }
    }

    $pageNo = max(1, isset($input['pageNo']) ? (int) $input['pageNo'] : 1);
    $defaultSize = max(10, min(50, (int) app_setting('history_page_size', '10')));
    $pageSize = min(100, max(1, isset($input['pageSize']) ? (int) $input['pageSize'] : $defaultSize));
    $payload = null;
    try {
        $payload = sl_provider_history($gameCode, $pageNo, $pageSize);
    } catch (Throwable $e) {
        sl_log_history_failure($gameCode, 'provider_fetch', $e);
    }
    $fallback = sl_history_provider_fallback($gameCode, $payload, $pageNo, $pageSize);
    if (!$schemaReady || !$storageReady) {
        return $fallback;
    }

    try {
        if ($payload && isset($payload['data']['list'])) {
            sl_save_and_settle_results($gameCode, $payload['data']['list']);
        }

        $currentIssue = sl_wingo_current_issue($gameCode);
        // Page one reveals the just-closed period the moment either the clock
        // or the provider's own latest result says it is done; deeper pages
        // are older than both gates anyway and keep the plain clock gate.
        $providerList = (is_array($payload) && isset($payload['data']['list']) && is_array($payload['data']['list']))
            ? $payload['data']['list']
            : array();
        $visibleIssue = $pageNo === 1
            ? sl_visible_gate_issue($gameCode, $providerList)
            : sl_display_issue($gameCode);
        if ($currentIssue !== '') {
            sl_prune_unsettled_future_results($gameCode, $currentIssue);
        }
        if ($visibleIssue !== '') {
            $count = $conn->prepare('SELECT COUNT(*) FROM saas_lottery_results WHERE game_code=? AND issue_number<?');
            if (!$count) {
                throw new RuntimeException('Unable to prepare lottery history count');
            }
            $count->bind_param('ss', $gameCode, $visibleIssue);
        } else {
            $count = $conn->prepare('SELECT COUNT(*) FROM saas_lottery_results WHERE game_code=?');
            if (!$count) {
                throw new RuntimeException('Unable to prepare lottery history count');
            }
            $count->bind_param('s', $gameCode);
        }
        if (!$count->execute()) {
            throw new RuntimeException('Unable to count lottery history');
        }
        $total = 0;
        $count->bind_result($total);
        $count->fetch();
        $count->close();
        $offset = ($pageNo - 1) * $pageSize;
        if ($visibleIssue !== '') {
            $stmt = $conn->prepare('SELECT issue_number,premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? AND issue_number<? ORDER BY issue_number DESC LIMIT ? OFFSET ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare lottery history read');
            }
            $stmt->bind_param('ssii', $gameCode, $visibleIssue, $pageSize, $offset);
        } else {
            $stmt = $conn->prepare('SELECT issue_number,premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? ORDER BY issue_number DESC LIMIT ? OFFSET ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare lottery history read');
            }
            $stmt->bind_param('sii', $gameCode, $pageSize, $offset);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to read lottery history');
        }
        $result = $stmt->get_result();
        $list = array();
        while ($result && ($row = $result->fetch_assoc())) {
            $item = array('issueNumber'=>(string)$row['issue_number'],'number'=>(string)$row['number'],'color'=>(string)$row['color'],'premium'=>(string)$row['premium'],'sum'=>(int)$row['result_sum']);
            $list[] = sl_game_family($gameCode) === 'TrxWinGo' ? sl_normalize_result_item($gameCode, $item) : $item;
        }
        $stmt->close();
        $list = sl_rebind_wingo_history_periods($gameCode, $list);
        $data = array('list'=>$list,'pageNo'=>$pageNo,'totalPage'=>$total ? (int)ceil($total/$pageSize) : 0,'totalCount'=>(int)$total);
        if (sl_game_family($gameCode) === 'MotoRace') {
            $data['statistics'] = sl_moto_statistics($list);
        }
        return $data;
    } catch (Throwable $e) {
        $storageReady = false;
        sl_log_history_failure($gameCode, 'cache_read_or_settlement', $e);
        return $fallback;
    }
}

function sl_record_page($userId, $input)
{
    global $conn;
    $pageNo = max(1, isset($input['pageNo']) ? (int) $input['pageNo'] : 1);
    $pageSize = min(50, max(1, isset($input['pageSize']) ? (int) $input['pageSize'] : 10));
    $offset = ($pageNo - 1) * $pageSize;
    $gameCode = isset($input['gameCode']) && (string) $input['gameCode'] !== '' ? sl_game_code($input) : '';
    if ($gameCode !== '') {
        try {
            sl_sync_results($gameCode);
        } catch (Throwable $ignored) {
        }
    }

    if ($gameCode !== '') {
        $count = $conn->prepare('SELECT COUNT(*) FROM saas_lottery_bets WHERE user_id=? AND game_code=?');
        $count->bind_param('is', $userId, $gameCode);
    } else {
        $count = $conn->prepare('SELECT COUNT(*) FROM saas_lottery_bets WHERE user_id=?');
        $count->bind_param('i', $userId);
    }
    $count->execute();
    $total = 0;
    $count->bind_result($total);
    $count->fetch();
    $count->close();

    $fields = 'id,game_code,issue_number,bet_content,amount,bet_multiple,bet_units,stake,status,result_premium,payout,tax_fee,created_at';
    if ($gameCode !== '') {
        $stmt = $conn->prepare('SELECT '.$fields.' FROM saas_lottery_bets WHERE user_id=? AND game_code=? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('isii', $userId, $gameCode, $pageSize, $offset);
    } else {
        $stmt = $conn->prepare('SELECT '.$fields.' FROM saas_lottery_bets WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('iii', $userId, $pageSize, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $list = array();
    while ($result && ($row = $result->fetch_assoc())) {
        $state = $row['status'] === 'pending' ? 2 : ($row['status'] === 'won' ? 1 : 0);
        $stake = (float) $row['stake'];
        $payout = (float) $row['payout'];
        $taxFee = isset($row['tax_fee']) ? (float) $row['tax_fee'] : 0.0;
        if ($taxFee <= 0.0 && $stake > 0.0) { $taxFee = sl_stake_tax_fee($stake); }
        $winLose = $row['status'] === 'won' ? round($payout - $stake, 4) : ($row['status'] === 'lost' ? -$stake : 0.0);
        $premium = $row['result_premium'] === null ? '' : (string) $row['result_premium'];
        $recordFamily = sl_game_family((string) $row['game_code']);
        $isWingo = $recordFamily === 'WinGo' || $recordFamily === 'TrxWinGo';
        $contentParts = explode('_', (string) $row['bet_content'], 2);
        $playType = (string) ($contentParts[0] ?? '');
        $selectType = (string) ($contentParts[1] ?? '');
        // The bundled record components use `number` as a truthy result flag
        // (and expect "0" to remain a string), while K3 renders the dice from
        // `premium`. Return both the current and legacy aliases so every game
        // record view can render the same settled row.
        $recordNumber = ($premium !== '' && ($isWingo || $recordFamily === 'K3')) ? $premium : '';
        $list[] = array(
            'orderNo'=>(string)$row['id'],'issueNumber'=>(string)$row['issue_number'],'gameCode'=>(string)$row['game_code'],
            'betContent'=>(string)$row['bet_content'],'playType'=>$playType,'selectType'=>$selectType,
            'amount'=>$stake,'unitAmount'=>(float)$row['amount'],'betMultiple'=>(int)$row['bet_multiple'],'betCount'=>(int)$row['bet_multiple'],
            'betUnits'=>(int)$row['bet_units'],'realAmount'=>max(0.0, round($stake-$taxFee,4)),'fee'=>$taxFee,'serviceCharge'=>$taxFee,'tax'=>$taxFee,'taxAmount'=>$taxFee,'taxRate'=>sl_tax_percent(),'state'=>$state,'premium'=>$premium,
            'winLoseAmount'=>$winLose,'betTime'=>(string)$row['created_at'],'orderNumber'=>(string)$row['id'],
            'betAmount'=>$stake,'number'=>$recordNumber,'resultNumber'=>$isWingo && $premium !== '' ? (int)$premium : null,
            'color'=>$isWingo && $premium !== '' ? sl_result_color((int)$premium) : '',
            'winAmount'=>$payout,'profitAmount'=>$state === 2 ? 0.0 : abs($winLose),
            'createTime'=>(string)$row['created_at'],'addTime'=>(string)$row['created_at']
        );
    }
    $stmt->close();
    return array('list'=>$list,'pageNo'=>$pageNo,'totalPage'=>$total ? (int)ceil($total/$pageSize) : 0,'totalCount'=>(int)$total);
}

function sl_win_loss($userId, $input)
{
    global $conn;
    $issue = isset($input['issueNumber']) ? (string) $input['issueNumber'] : '';
    $gameCode = isset($input['gameCode']) && (string) $input['gameCode'] !== '' ? sl_game_code($input) : '';
    if ($gameCode === '') {
        $find = $conn->prepare('SELECT game_code FROM saas_lottery_bets WHERE user_id=? AND issue_number=? ORDER BY id DESC LIMIT 1');
        $find->bind_param('is', $userId, $issue);
        $find->execute();
        $find->bind_result($gameCode);
        $find->fetch();
        $find->close();
    }
    if ($gameCode !== '') {
        sl_sync_results($gameCode);
    }
    $stmt = $conn->prepare("SELECT COUNT(*),SUM(status='pending'),SUM(status='won'),COALESCE(SUM(payout),0) FROM saas_lottery_bets WHERE user_id=? AND issue_number=?");
    $stmt->bind_param('is', $userId, $issue);
    $stmt->execute();
    $total = $pending = $won = $winAmount = 0;
    $stmt->bind_result($total, $pending, $won, $winAmount);
    $stmt->fetch();
    $stmt->close();
    if ((int) $total === 0 || (int) $pending > 0) {
        return array('status'=>null,'winAmount'=>0);
    }
    return array('status'=>(int)$won > 0,'winAmount'=>(float)$winAmount);
}

function sl_history_values($gameCode, $item)
{
    $family = sl_game_family($gameCode);
    if ($family === 'MotoRace') {
        return array_map('intval', explode(',', (string) $item['premium']));
    }
    return array_map('intval', str_split((string) $item['premium']));
}

function sl_trend($gameCode)
{
    $payload = sl_sync_results($gameCode);
    $providerList = (is_array($payload) && isset($payload['data']['list']) && is_array($payload['data']['list']))
        ? $payload['data']['list']
        : array();
    $history = sl_cached_history($gameCode, 100, $providerList);
    $family = sl_game_family($gameCode);
    $positionCount = $family === 'D5' ? 5 : ($family === 'K3' || $family === 'MotoRace' ? 3 : 1);
    $numbers = $family === 'K3' ? range(1,6) : ($family === 'MotoRace' ? range(1,10) : range(0,9));
    $stats = array();
    for ($position = 1; $position <= $positionCount; $position++) {
        foreach ($numbers as $number) {
            $missing = 0;
            $open = 0;
            $maxContinuous = 0;
            $continuous = 0;
            $seen = false;
            foreach ($history as $item) {
                $values = sl_history_values($gameCode, $item);
                $value = isset($values[$position - 1]) ? (int) $values[$position - 1] : -1;
                if ($value === $number) {
                    $open++;
                    $continuous++;
                    $maxContinuous = max($maxContinuous, $continuous);
                    $seen = true;
                } else {
                    if (!$seen) {
                        $missing++;
                    }
                    $continuous = 0;
                }
            }
            $stats[] = array(
                'position'=>$position,'number'=>$number,'missingCount'=>$missing,
                'avgMissing'=>$open ? (int)round(count($history)/$open) : count($history),
                'openCount'=>$open,'maxContinuous'=>$maxContinuous
            );
        }
    }
    return $stats;
}
