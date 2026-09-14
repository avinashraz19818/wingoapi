<?php
header('Content-Type:text/plain; charset=utf-8');
require_once __DIR__.'/evenvessis/conn.php';
if (!isset($conn) || $conn->connect_errno) exit("Database connection failed\n");
$conn->query("CREATE TABLE IF NOT EXISTS veegame_saas_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stmt=$conn->prepare("INSERT INTO veegame_saas_settings(setting_key,setting_value,updated_at) VALUES('migration_state','active',NOW()) ON DUPLICATE KEY UPDATE setting_value='active',updated_at=NOW()");
if (!$stmt || !$stmt->execute()) exit("Could not enable betting: ".($conn->error)."\n");
echo "WinGo betting enabled\nDONE\nDelete activate_wingo.php now.\n";
