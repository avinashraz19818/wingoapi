<?php
declare(strict_types=1);
// Run from DhaniWin root: php repair.php
// Remove this file after running.
header('Content-Type: text/plain; charset=utf-8');
$configFile = __DIR__ . '/../api/config.php';
if (!is_file($configFile)) { exit("ERROR: upload this folder into DhaniWin root.\n"); }
$config = require $configFile;
$db = $config['db']['mysql'] ?? [];
$host = getenv('DHANI_DB_HOST') ?: ($db['host'] ?? 'localhost');
$name = getenv('DHANI_DB_NAME') ?: ($db['database'] ?? '');
$user = getenv('DHANI_DB_USER') ?: ($db['username'] ?? '');
$pass = getenv('DHANI_DB_PASS') ?: ($db['password'] ?? '');
try {
  $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  echo "MySQL: OK ($name)\n";
  $pdo->exec("ALTER TABLE api_users MODIFY game_balance DECIMAL(18,4) NOT NULL DEFAULT 0");
  $pdo->exec("ALTER TABLE api_users MODIFY wallet_balance DECIMAL(18,4) NOT NULL DEFAULT 0");
  echo "Columns: OK\n";
  $count = (int)$pdo->query("SELECT COUNT(*) FROM api_users")->fetchColumn();
  echo "Users: $count\n";
  // Repair only accounts whose game balance is zero while wallet has funds.
  $st = $pdo->prepare("UPDATE api_users SET game_balance = wallet_balance, updated_at = CURRENT_TIMESTAMP WHERE game_balance = 0 AND wallet_balance > 0");
  $st->execute();
  echo "Synced zero game balances from wallet: {$st->rowCount()}\n";
  echo "DONE. Remove repair.php now. Log out/in and test Wingo.\n";
} catch (Throwable $e) { http_response_code(500); echo "ERROR: MySQL connection failed\n" . $e->getMessage() . "\n"; }
