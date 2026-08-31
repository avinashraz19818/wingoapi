<?php

declare(strict_types=1);

namespace Lottery\Database;

use Lottery\Support\ApiException;
use Lottery\Support\Log;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. MySQL 8+ in production; SQLite is supported for local
 * development and the test-suite so the exact same SQL paths are exercised.
 *
 * Every query in the codebase goes through here with bound parameters —
 * string interpolation into SQL is never used.
 */
class Connection
{
    private PDO $pdo;
    private string $driver;
    private int $transactionDepth = 0;

    public function __construct(array $config)
    {
        $driver = strtolower((string) ($config['driver'] ?? 'mysql'));

        if ($driver === 'mysql') {
            try {
                $this->pdo    = $this->connectMysql($config);
                $this->driver = 'mysql';
                return;
            } catch (PDOException $e) {
                if (empty($config['allow_sqlite_fallback'])) {
                    Log::exception($e, ['stage' => 'mysql-connect']);
                    throw ApiException::server('Database connection failed', $e);
                }
                Log::warning('MySQL unavailable, falling back to SQLite', ['error' => $e->getMessage()]);
            }
        }

        $this->pdo    = $this->connectSqlite((string) ($config['sqlite_file'] ?? ''));
        $this->driver = 'sqlite';
    }

    private function connectMysql(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['name'] ?? 'lottery',
            $config['charset'] ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => (int) ($config['timeout'] ?? 5),
        ]);
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION transaction_isolation='READ-COMMITTED'");
        $pdo->exec("SET time_zone='+05:30'");

        return $pdo;
    }

    private function connectSqlite(string $file): PDO
    {
        if ($file !== ':memory:') {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Log::error('query failed', ['sql' => $sql, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return mixed */
    public function fetchValue(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insertGetId(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /** Nested-safe transaction using SAVEPOINTs. */
    public function transaction(callable $callback)
    {
        $this->begin();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function begin(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp' . $this->transactionDepth);
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT sp' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT sp' . $this->transactionDepth);
        }
    }

    /** Row-level lock clause (no-op on SQLite, which locks the whole DB). */
    public function forUpdate(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    /** Driver-specific "insert, ignore duplicate key" prefix. */
    public function insertIgnore(): string
    {
        return $this->isMysql() ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    }

    public function isDuplicateKey(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '23000' || $code === '23505') {
            return true;
        }
        return str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
