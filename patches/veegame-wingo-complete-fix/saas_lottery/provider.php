<?php
/** Authoritative public draw adapter. Never renumbers issues or generates/overrides results. */
function sl_fetch_json($url, $timeout=null)
{
    global $SL_CONFIG;
    $u=parse_url($url);
    if (($u['scheme']??'')!=='https' || ($u['host']??'')!=='draw.ar-lottery06.com' || isset($u['user']) || isset($u['port'])) return null;
    $curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$timeout??$SL_CONFIG['request_timeout_seconds'],
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'Veegame-Draw-Adapter/1.0']);
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if ($status!==200 || !is_string($body) || strlen($body)>1048576) return null;
    $data=json_decode($body,true);return is_array($data)?$data:null;
}
function sl_external_url($gameCode,$history): string
{
    global $SL_CONFIG;
    if (!sl_game_config($gameCode)) throw new InvalidArgumentException('Unsupported game');
    return $SL_CONFIG['draw_base_url'].'/WinGo/'.rawurlencode($gameCode).($history?'/GetHistoryIssuePage.json':'.json');
}
function sl_validate_current($gameCode,$data,$now=null)
{
    if (!is_array($data) || ($data['gameCode']??'')!==$gameCode || (int)($data['state']??0)!==1) return null;
    $now=$now??sl_now_ms();$interval=sl_interval_seconds($gameCode)*1000;
    foreach (['previous','current','next'] as $part) {
        $r=$data[$part]??[];
        if (!preg_match('/^\d{17}$/',(string)($r['issueNumber']??'')) || !is_numeric($r['startTime']??null) || !is_numeric($r['endTime']??null) || (int)$r['endTime']-(int)$r['startTime']!==$interval) return null;
    }
    $p=$data['previous'];$c=$data['current'];$n=$data['next'];
    if ((int)$p['endTime']!==(int)$c['startTime'] || (int)$c['endTime']!==(int)$n['startTime'] || strcmp($p['issueNumber'],$c['issueNumber'])>=0 || strcmp($c['issueNumber'],$n['issueNumber'])>=0) return null;
    if ($now<(int)$c['startTime'] || $now>=(int)$c['endTime']) return null;
    return $data;
}
function sl_provider_current($gameCode)
{
    static $memo=[];
    if (isset($memo[$gameCode]) && sl_validate_current($gameCode,$memo[$gameCode])) return $memo[$gameCode];
    for ($attempt=0;$attempt<3;$attempt++) {
        $candidate=sl_fetch_json(sl_external_url($gameCode,false).'?ts='.sl_now_ms());
        $valid=sl_validate_current($gameCode,$candidate);
        if ($valid) return $memo[$gameCode]=$valid;
        $end=(int)($candidate['current']['endTime']??0);$age=sl_now_ms()-$end;
        if (!is_array($candidate) || ($candidate['gameCode']??'')!==$gameCode || $age<0 || $age>5000 || $attempt===2) break;
        usleep(400000); // Bounded publication-boundary retry; never manufacture a period.
    }
    return null;
}
function sl_normalize_result_item($gameCode,$item)
{
    if (!sl_game_config($gameCode) || !is_array($item)) return null;
    $issue=(string)($item['issueNumber']??'');$number=(string)($item['premium']??$item['number']??'');
    if (!preg_match('/^\d{17}$/',$issue) || !preg_match('/^[0-9]$/',$number)) return null;
    if (isset($item['number']) && (string)$item['number']!==$number) return null;
    return ['issueNumber'=>$issue,'premium'=>$number,'number'=>$number,'color'=>sl_result_color((int)$number),'sum'=>0];
}
function sl_provider_history($gameCode,$pageNo=1,$pageSize=10)
{
    $pageNo=max(1,min(50,(int)$pageNo));$pageSize=10;
    $payload=sl_fetch_json(sl_external_url($gameCode,true).'?'.http_build_query(['pageNo'=>$pageNo,'pageSize'=>$pageSize,'ts'=>sl_now_ms()]));
    if (!is_array($payload['data']['list']??null) || (isset($payload['code']) && (int)$payload['code']!==0)) {
        // Fallback to the existing Veegame WinGo history when the external feed is unavailable.
        global $conn;
        $table = sl_game_family($gameCode)==='WinGo' ? 'gellaluhogiondu_phalitansa' : '';
        if ($table && isset($conn) && $conn instanceof mysqli) {
            $offset=($pageNo-1)*$pageSize;
            $q=$conn->query("SELECT kalaparichaya,phalitansa,banna,bele FROM `$table` ORDER BY shonu DESC LIMIT ".(int)$pageSize." OFFSET ".(int)$offset);
            if ($q) {
                $list=[]; while($r=$q->fetch_assoc()) $list[]=array('issueNumber'=>(string)$r['kalaparichaya'],'number'=>(string)$r['phalitansa'],'colour'=>(string)$r['banna'],'premium'=>(string)$r['bele']);
                if ($list) return array('code'=>0,'data'=>array('list'=>$list,'pageNo'=>$pageNo,'totalPage'=>$pageNo,'totalCount'=>count($list)));
            }
        }
        return null;
    }
    $list=[];$seen=[];
    foreach ($payload['data']['list'] as $raw) {
        $row=sl_normalize_result_item($gameCode,$raw);
        if (!$row || isset($seen[$row['issueNumber']])) return null;
        $seen[$row['issueNumber']]=true;$list[]=$row;
    }
    if (!$list) return null;
    $payload['data']['list']=$list;return $payload;
}
function sl_save_and_settle_results($gameCode,$list): void
{
    global $conn;
    $current=sl_provider_current($gameCode);
    if (!$current) return; // No local-clock result synthesis when a feed is unavailable.
    foreach ($list as $raw) {
        $row=sl_normalize_result_item($gameCode,$raw);
        if (!$row || strcmp($row['issueNumber'],$current['current']['issueNumber'])>=0) continue;
        $issue=$row['issueNumber'];$premium=$row['premium'];$number=$row['number'];$color=$row['color'];$sum=0;
        $s=$conn->prepare('INSERT IGNORE INTO veegame_saas_results(game_code,issue_number,premium,number,color,result_sum,provider_seen_at,created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');
        $s->bind_param('sssssi',$gameCode,$issue,$premium,$number,$color,$sum);$s->execute();$s->close();
        $s=$conn->prepare('SELECT premium FROM veegame_saas_results WHERE game_code=? AND issue_number=?');
        $s->bind_param('ss',$gameCode,$issue);$s->execute();$stored=null;$s->bind_result($stored);$s->fetch();$s->close();
        if ((string)$stored!==$premium) {
            // Immutable conflicting evidence: do not pay from either value automatically.
            error_log('[veegame] Provider result conflict for '.$gameCode.' '.$issue);
            continue;
        }
        // Retry pending settlements even if the result was saved on an earlier request.
        sl_settle_issue($gameCode,$issue,$row);
    }
}
function sl_sync_results($gameCode)
{
    $payload=sl_provider_history($gameCode);
    if ($payload) sl_save_and_settle_results($gameCode,$payload['data']['list']);
    return $payload;
}
function sl_history_page($gameCode,$input=[])
{
    $current=sl_provider_current($gameCode);$payload=sl_provider_history($gameCode,$input['pageNo']??1,10);
    if (!$current || !$payload) sl_fail(503,'Provider history temporarily unavailable',503,503);
    $payload['data']['list']=array_values(array_filter($payload['data']['list'],fn($r)=>strcmp($r['issueNumber'],$current['current']['issueNumber'])<0));
    sl_save_and_settle_results($gameCode,$payload['data']['list']);
    return $payload['data'];
}
function sl_cached_history($gameCode,$limit=100)
{
    global $conn;
    $s=$conn->prepare('SELECT issue_number AS issueNumber,premium,number,color,result_sum AS sum FROM veegame_saas_results WHERE game_code=? ORDER BY issue_number DESC LIMIT ?');
    $limit=max(1,min(500,(int)$limit));$s->bind_param('si',$gameCode,$limit);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function sl_win_loss($userId,$input)
{
    global $conn;
    $game=sl_game_code($input);$issue=(string)($input['issueNumber']??'');sl_sync_results($game);
    $s=$conn->prepare("SELECT COUNT(*),COALESCE(SUM(status='pending'),0),COALESCE(SUM(status='won'),0),COALESCE(SUM(payout),0) FROM veegame_saas_bets WHERE user_id=? AND game_code=? AND issue_number=?");
    $s->bind_param('iss',$userId,$game,$issue);$s->execute();$s->bind_result($count,$pending,$won,$amount);$s->fetch();$s->close();
    return !$count || $pending ? ['status'=>null,'winAmount'=>0] : ['status'=>(bool)$won,'winAmount'=>(float)$amount];
}
