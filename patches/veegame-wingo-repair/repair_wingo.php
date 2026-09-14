<?php
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$connFile = $root . '/evenvessis/conn.php';
$issueFile = $root . '/evenvessis/api/webapi/GetGameIssue.php';
if (!is_file($connFile) || !is_file($issueFile)) {
    exit("ERROR: Upload repair_wingo.php to Veegame root (same folder as evenvessis).\n");
}
require_once $connFile;
if (!isset($conn) || !($conn instanceof mysqli)) exit("ERROR: conn.php did not create a MySQL connection.\n");
if ($conn->connect_errno) exit("ERROR: MySQL: {$conn->connect_error}\n");
echo "MySQL connection: OK\n";
$tables=['gelluonduhogu','gelluonduhogu_drei','gelluonduhogu_funf','gelluonduhogu_zehn'];
foreach($tables as $t){$q=$conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($t)."'"); if(!$q||$q->num_rows===0){echo "MISSING TABLE: $t\n";}else{echo "TABLE OK: $t\n";}}
$src=file_get_contents($issueFile);
$backup=$issueFile.'.backup-'.date('YmdHis');
file_put_contents($backup,$src);
$old="$bearer = explode(\" \", \\$_SERVER['HTTP_AUTHORIZATION']);\n\t\t\t\t$author = $bearer[1];";
$new="$authHeader = \\$_SERVER['HTTP_AUTHORIZATION'] ?? '';\n                $bearer = preg_split('/\\\\s+/', trim($authHeader));\n                $author = $bearer[1] ?? '';";
$changed=false;
if(strpos($src,$old)!==false){$src=str_replace($old,$new,$src,1);$changed=true;}
$old2="\$sesnum = mysqli_num_rows(\$sesresult);";
$new2="\$sesnum = \$sesresult instanceof mysqli_result ? mysqli_num_rows(\$sesresult) : 0;";
if(strpos($src,$old2)!==false){$src=str_replace($old2,$new2,$src,1);$changed=true;}
if($changed){file_put_contents($issueFile,$src);echo "GetGameIssue.php repaired.\n";}else{echo "No matching unsafe code found; original file backed up.\n";}
echo "Backup: ".basename($backup)."\nDONE - delete repair_wingo.php now, clear cache, test WinGo.\n";
