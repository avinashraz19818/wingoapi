<?php
// Pure view tests: no site access, bets, provider requests, DB or wallet writes.
require __DIR__ . '/../.w13l/api/_core/lottery_engine.php';
require __DIR__ . '/../.w13l/api/_core/history_view.php';
function check28($value, string $message): void { if (!$value) throw new RuntimeException($message); }
$raw = [];
for ($i=1; $i<=25; $i++) $raw[] = ['issueNumber'=>'2026090810005'.sprintf('%04d',$i), 'number'=>(string)($i%10), 'gameCode'=>'WinGo_30S'];
$raw[] = $raw[0]; // dedup
$raw[] = ['issueNumber'=>'20260908100010020','number'=>'3','gameCode'=>'WinGo_1M'];
$raw[] = ['issueNumber'=>'bad','number'=>'7'];
$raw[] = ['issueNumber'=>'20260908100050002','number'=>''];
$raw[] = ['issueNumber'=>'20260908100050003','number'=>'99'];
$rows = h28_closed_rows($raw, 'WinGo_30S', '20260908100050024');
check28(count($rows)===23, 'only closed, valid, same-game, unique rows');
check28($rows[0]['issueNumber']==='20260908100050023', 'newest first');
check28(count(array_filter($rows,fn($r)=>$r['number']===0))===2, 'zero is a valid result');
check28($rows[3]['color']==='red,violet', 'zero result colour');
$p1=h28_history_payload($rows,1);$p2=h28_history_payload($rows,2);$p3=h28_history_payload($rows,3);$p4=h28_history_payload($rows,4);
check28(count($p1['list'])===10 && count($p2['list'])===10 && count($p3['list'])===3 && count($p4['list'])===0, 'pagination 10/10/3/0');
check28($p1['totalPage']===3 && $p1['totalCount']===23, 'real totals, no fabricated 500');
check28(!$p4['list'], 'no stale page fallback');
$stats=h28_statistics(array_map(fn($n)=>['number'=>$n],[5,5,0,5,2,2,2,9]));
check28(count($stats)===10 && $stats[5]['openCount']===3 && $stats[5]['maxContinuous']===2, 'stat digit count/run');
check28($stats[2]['missingCount']===4 && $stats[2]['maxContinuous']===3, 'stat missing/max');
check28($stats[1]['missingCount']===8 && $stats[1]['openCount']===0, 'absent digit');
check28(array_sum(array_column($stats,'openCount'))===8, 'stats sample not padded to 100');
// Boundary: same feed result remains hidden until current issue advances at zero.
$feed=[['issueNumber'=>'20260908100050024','number'=>'0']];
check28(count(h28_closed_rows($feed,'WinGo_30S','20260908100050024'))===0,'before timer zero');
check28(count(h28_closed_rows($feed,'WinGo_30S','20260908100050025'))===1,'after timer zero');
check28(h28_history_payload([],1)['totalCount']===0,'provider-empty means empty, not fake numbers');
echo "PASS: closed-period gate; game isolation; dedup; zero; pagination; stats; empty feed\n";
