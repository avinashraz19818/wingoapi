<?php
require __DIR__.'/../.w13l/api/_core/lottery_engine.php';
require __DIR__.'/../.w13l/api/_core/history_view.php';
$scenario='paged';$calls=[];
function lb_enabled(){return true;}
function lb_cfg(){global $scenario;return ['base'=>'https://fixture.invalid/'.getmypid().'/'.$scenario,'mode'=>'api'];}
function lb_issue_data($code){return ['issueNumber'=>'20260908100050124'];}
function lb_fetch_list($code,$ttl,$size){return fixtureRows(1);}
function lb_raw_list($code,$size,$page){global $calls,$scenario;$calls[]=$page;return fixtureRows($scenario==='ignored'?1:$page);}
function fixtureRows($page){$a=[];for($i=0;$i<10;$i++){$n=124-($page-1)*10-$i;if($n<1)break;$a[]=['issueNumber'=>'2026090810005'.sprintf('%04d',$n),'number'=>(string)($n%10)];}return $a;}
$r=h28_provider_rows('WinGo_30S');
if(count($r)<100 || count(h28_history_payload($r,1)['list'])!==10)throw new Exception('capped provider pagination failed');
if($r[0]['issueNumber']!=='20260908100050123')throw new Exception('current period leak');
$before=count($calls);h28_provider_rows('WinGo_30S');if(count($calls)!==$before)throw new Exception('cache missed');
$scenario='ignored';$calls=[];$r=h28_provider_rows('WinGo_30S');
if(count($r)!==9||$calls!==[2])throw new Exception('ignored pageNo must terminate without inventing rows');
foreach(['paged','ignored'] as $scenario){$key=hash('sha256',realpath(__DIR__.'/../.w13l/api/_core').'|'.lb_cfg()['base'].'|WinGo_30S');@unlink(sys_get_temp_dir().'/13l_provider29_'.$key.'.json');}
echo "PASS: pageSize cap10 -> >=100 closed; 10 chart rows; timer gate; cache; repeated-page stop\n";
