<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

// Temporary browser repair. Delete repair.php after it shows DONE.
$host = 'localhost';
$dbName = 'club532583_dhsuraj';
$dbUser = 'club532583_dhsuraj';
$dbPass = 'club532583_dhsuraj';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "MySQL connection: OK\n";
    $pdo->exec("ALTER TABLE api_users MODIFY game_balance DECIMAL(18,4) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE api_users MODIFY wallet_balance DECIMAL(18,4) NOT NULL DEFAULT 0");
    echo "Balance columns: OK\n";

    $stmt = $pdo->prepare(
        "UPDATE api_users
         SET game_balance = wallet_balance,
             updated_at = CURRENT_TIMESTAMP
         WHERE game_balance = 0 AND wallet_balance > 0"
    );
    $stmt->execute();

    echo "Users synced: " . $stmt->rowCount() . "\n";
    echo "DONE - now delete repair.php and login again.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Check database name, username, password and MySQL access.\n";
}
