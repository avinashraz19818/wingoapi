<?php
/**
 * Database Connection Helper (MySQL with automatic SQLite dev fallback)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dbType = DB_TYPE;
            if ($dbType === 'mysql') {
                try {
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
                        PDO::ATTR_TIMEOUT => 2
                    ]);
                } catch (PDOException $e) {
                    // In development/sandbox environments, fallback to SQLite automatically
                    if (!getenv('STRICT_MYSQL')) {
                        self::$instance = self::createSQLiteInstance();
                    } else {
                        http_response_code(500);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'code' => 500,
                            'msg' => 'MySQL Connection Failed: ' . $e->getMessage()
                        ]);
                        exit;
                    }
                }
            } else {
                self::$instance = self::createSQLiteInstance();
            }
        }
        return self::$instance;
    }

    private static function createSQLiteInstance(): PDO {
        $dbPath = SQLITE_FILE;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $isNew = !file_exists($dbPath);
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA foreign_keys = ON;");

        if ($isNew || filesize($dbPath) === 0) {
            self::initSQLiteSchema($pdo);
        }
        return $pdo;
    }

    public static function initSQLiteSchema(PDO $pdo): void {
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS wingo_games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            interval_seconds INTEGER NOT NULL,
            lock_seconds INTEGER DEFAULT 5,
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

        CREATE TABLE IF NOT EXISTS shonu_kaichila (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            balakedara INTEGER UNIQUE NOT NULL,
            motta REAL DEFAULT 10000.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        INSERT OR IGNORE INTO wingo_games (game_code, name, interval_seconds, lock_seconds, external_api_url) VALUES
        ('WinGo_30S', 'WinGo 30 Seconds', 30, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json'),
        ('WinGo_1M', 'WinGo 1 Minute', 60, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json'),
        ('WinGo_3M', 'WinGo 3 Minutes', 180, 10, 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json'),
        ('WinGo_5M', 'WinGo 5 Minutes', 300, 15, 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json'),
        ('WinGo_10M', 'WinGo 10 Minutes', 600, 30, 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json');

        INSERT OR IGNORE INTO shonu_kaichila (balakedara, motta) VALUES (1001, 10000.00);
SQL;
        $pdo->exec($schema);
    }
}
