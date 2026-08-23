<?php
/**
 * Database Connection & Initialization Helper
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            try {
                if (DB_TYPE === 'mysql') {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                        DB_HOST,
                        DB_PORT,
                        DB_NAME,
                        DB_CHARSET
                    );
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                } else {
                    // SQLite for local sandbox / quick testing
                    $dbPath = SQLITE_FILE;
                    $isNew = !file_exists($dbPath);
                    self::$instance = new PDO('sqlite:' . $dbPath, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    // Enable WAL mode & foreign keys for SQLite
                    self::$instance->exec("PRAGMA journal_mode = WAL;");
                    self::$instance->exec("PRAGMA foreign_keys = ON;");

                    if ($isNew) {
                        self::initSQLiteSchema(self::$instance);
                    }
                }
            } catch (PDOException $e) {
                // If MySQL connection fails, return JSON error or throw
                if (php_sapi_name() === 'cli') {
                    die("Database connection failed: " . $e->getMessage() . "\n");
                }
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'code' => 500,
                    'msg' => 'Database connection failed: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        return self::$instance;
    }

    public static function initSQLiteSchema(PDO $pdo): void {
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS wingo_games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            interval_seconds INTEGER NOT NULL,
            external_api_url TEXT NOT NULL,
            status INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS wingo_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_code TEXT NOT NULL,
            issue_number TEXT NOT NULL,
            number INTEGER NOT NULL,
            color TEXT NOT NULL,
            premium TEXT,
            sum INTEGER,
            draw_time DATETIME NOT NULL,
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (game_code, issue_number)
        );
        CREATE INDEX IF NOT EXISTS idx_game_time ON wingo_results(game_code, draw_time);

        CREATE TABLE IF NOT EXISTS wingo_current_issue (
            game_code TEXT PRIMARY KEY,
            current_issue TEXT NOT NULL,
            current_start DATETIME NOT NULL,
            current_end DATETIME NOT NULL,
            next_issue TEXT NOT NULL,
            next_start DATETIME NOT NULL,
            next_end DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS wingo_bets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            game_code TEXT NOT NULL,
            issue_number TEXT NOT NULL,
            bet_type TEXT NOT NULL,
            bet_value TEXT NOT NULL,
            amount REAL NOT NULL,
            odds REAL NOT NULL,
            status TEXT DEFAULT 'pending',
            payout REAL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            settled_at DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_user_game_issue ON wingo_bets(user_id, game_code, issue_number);
        CREATE INDEX IF NOT EXISTS idx_issue_status ON wingo_bets(issue_number, status);

        -- User wallet table (matching shonu_kaichila)
        CREATE TABLE IF NOT EXISTS shonu_kaichila (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            balakedara INTEGER UNIQUE NOT NULL,
            motta REAL DEFAULT 10000.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- Insert initial game configs
        INSERT OR IGNORE INTO wingo_games (game_code, name, interval_seconds, external_api_url) VALUES
        ('WinGo_30S', 'WinGo 30 Seconds', 30, 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json'),
        ('WinGo_1M', 'WinGo 1 Minute', 60, 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json'),
        ('WinGo_3M', 'WinGo 3 Minutes', 180, 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json'),
        ('WinGo_5M', 'WinGo 5 Minutes', 300, 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json'),
        ('WinGo_10M', 'WinGo 10 Minutes', 600, 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json');

        -- Insert demo test user with 10,000 credits
        INSERT OR IGNORE INTO shonu_kaichila (balakedara, motta) VALUES (1001, 10000.00);
SQL;
        $pdo->exec($schema);
    }
}
