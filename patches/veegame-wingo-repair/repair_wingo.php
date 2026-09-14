<?php
header('Content-Type: text/plain; charset=utf-8');
echo "Veegame WinGo repair started\n";
$issueFile = __DIR__ . '/evenvessis/api/webapi/GetGameIssue.php';
if (!is_file($issueFile)) { exit("ERROR: GetGameIssue.php not found at: $issueFile\nCheck that this file is in the same root as evenvessis.\n"); }
$src = file_get_contents($issueFile);
if ($src === false) exit("ERROR: Cannot read GetGameIssue.php. Check permissions.\n");
$backup = $issueFile . '.backup-' . date('YmdHis');
if (!copy($issueFile, $backup)) exit("ERROR: Cannot create backup. Check permissions.\n");
$changed = false;
$patterns = array(
    "\$bearer = explode(\" \", \$_SERVER['HTTP_AUTHORIZATION']);\n\t\t\t\t\$author = \$bearer[1];",
    "\$bearer = explode(\" \", \$_SERVER['HTTP_AUTHORIZATION']);\r\n\t\t\t\t\$author = \$bearer[1];"
);
$replacement = "\$authHeader = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';\n                \$bearer = preg_split('/\\s+/', trim(\$authHeader));\n                \$author = \$bearer[1] ?? '';";
foreach ($patterns as $pattern) { if (strpos($src, $pattern) !== false) { $src = str_replace($pattern, $replacement, $src); $changed = true; break; } }
if (strpos($src, '$sesnum = mysqli_num_rows($sesresult);') !== false) { $src = str_replace('$sesnum = mysqli_num_rows($sesresult);', '$sesnum = $sesresult instanceof mysqli_result ? mysqli_num_rows($sesresult) : 0;', $src); $changed = true; }
if ($changed && file_put_contents($issueFile, $src) === false) exit("ERROR: Cannot write GetGameIssue.php. Check permissions.\n");
echo $changed ? "GetGameIssue.php repaired\n" : "No matching unsafe code found; backup still created\n";
echo "Backup: " . basename($backup) . "\nDONE\nDelete repair_wingo.php now.\n";
