<?php
/** Narrow WinGo runtime integration, not ShreeWin's site-wide control-center installer. */
function app_install_schema(?mysqli $db = null): void
{
    global $conn;
    $db = $db ?? $conn;
    $db->query("CREATE TABLE IF NOT EXISTS veegame_saas_settings (setting_key VARCHAR(80) PRIMARY KEY,setting_value TEXT NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT IGNORE INTO veegame_saas_settings VALUES ('migration_state','active',NOW())");
}
function app_table_exists(string $table, ?mysqli $db = null): bool
{
    global $conn;
    $db = $db ?? $conn;
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->bind_param('s',$table);$s->execute();$s->bind_result($n);$s->fetch();$s->close();return (int)$n===1;
}
function app_column_exists(string $table, string $column): bool
{
    global $conn;
    $s=$conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->bind_param('ss',$table,$column);$s->execute();$s->bind_result($n);$s->fetch();$s->close();return (int)$n===1;
}
function app_schema_columns_exist(array $requirements, ?mysqli $db = null): bool
{
    global $conn;
    $db=$db??$conn;
    if (!$requirements) return true;
    $names=array_keys($requirements);$marks=implode(',',array_fill(0,count($names),'?'));
    $s=$db->prepare('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$marks.')');
    $s->bind_param(str_repeat('s',count($names)),...$names);$s->execute();$rows=$s->get_result();$found=[];
    while ($r=$rows->fetch_assoc()) $found[$r['TABLE_NAME']][$r['COLUMN_NAME']]=true;
    $s->close();
    foreach ($requirements as $table=>$columns) foreach ($columns as $column) if (empty($found[$table][$column])) return false;
    return true;
}

function app_setting(string $key, $default = '')
{
    global $conn;
    if (in_array($key,['betting_enabled','auto_settlement_enabled'],true)) {
        return in_array(app_setting('migration_state','preview'), $key==='auto_settlement_enabled' ? ['active','paused'] : ['active'], true) ? '1' : '0';
    }
    $s=$conn->prepare('SELECT setting_value FROM veegame_saas_settings WHERE setting_key=?');
    $s->bind_param('s',$key);$s->execute();$v=null;$s->bind_result($v);$ok=$s->fetch();$s->close();return $ok ? $v : $default;
}
function app_setting_bool(string $key, bool $default = false): bool
{
    return in_array(strtolower((string)app_setting($key,$default?'1':'0')),['1','true','yes','on'],true);
}
function app_game_control(string $gameCode): array
{
    return ['enabled'=>1,'result_source'=>'api','api_timeout_seconds'=>5,'lock_before_close_seconds'=>5];
}
