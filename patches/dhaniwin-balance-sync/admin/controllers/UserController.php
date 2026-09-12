<?php
declare(strict_types=1);

class UserController
{
    public static function listUsers(array $params): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $draw = (int)($params['draw'] ?? 1);
        $start = (int)($params['start'] ?? 0);
        $length = (int)($params['length'] ?? 10);
        $search = trim((string)($params['search']['value'] ?? ''));

        try {
            $whereClause = "";
            $args = [];
            if ($search !== "") {
                $whereClause = " WHERE username LIKE ? OR nickname LIKE ? OR phone LIKE ? OR CAST(user_id AS CHAR) LIKE ? ";
                $searchWild = "%$search%";
                $args = [$searchWild, $searchWild, $searchWild, $searchWild];
            }

            $totalQuery = "SELECT COUNT(*) FROM api_users";
            $totalRecords = (int)$pdo->query($totalQuery)->fetchColumn();

            $filteredQuery = "SELECT COUNT(*) FROM api_users" . $whereClause;
            $stmt = $pdo->prepare($filteredQuery);
            $stmt->execute($args);
            $filteredRecords = (int)$stmt->fetchColumn();

            // Fetch records
            $dataQuery = "
                SELECT u.*, uc.win_rate_percent, uc.status AS control_status 
                FROM api_users u 
                LEFT JOIN user_control uc ON uc.user_id = u.user_id 
                " . $whereClause . " 
                ORDER BY u.id DESC 
                LIMIT $length OFFSET $start
            ";
            $stmt = $pdo->prepare($dataQuery);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();

            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'username' => htmlspecialchars((string)$row['username']),
                    'nickname' => htmlspecialchars((string)$row['nickname']),
                    'phone' => htmlspecialchars((string)($row['phone'] ?? '')),
                    'wallet_balance' => (float)$row['wallet_balance'],
                    'game_balance' => (float)$row['game_balance'],
                    'can_bet' => (int)$row['can_bet'],
                    'status' => (int)($row['status'] ?? 1),
                    'win_rate_percent' => $row['win_rate_percent'] !== null ? (int)$row['win_rate_percent'] : 50,
                    'control_status' => $row['control_status'] !== null ? (int)$row['control_status'] : 0,
                    'created_at' => $row['created_at']
                ];
            }

            return [
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 'data' => []];
        }
    }

    public static function saveUser(array $post): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        $id = (int)($post['id'] ?? 0);
        $userId = (int)($post['user_id'] ?? 0);
        $username = trim((string)($post['username'] ?? ''));
        $nickname = trim((string)($post['nickname'] ?? ''));
        $phone = trim((string)($post['phone'] ?? ''));
        $password = trim((string)($post['password'] ?? ''));
        $status = isset($post['status']) ? (int)$post['status'] : 1;
        $canBet = isset($post['can_bet']) ? (int)$post['can_bet'] : 1;

        if ($username === '') {
            return ['success' => false, 'message' => 'Username is required'];
        }

        try {
            if ($id > 0) {
                // Edit
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE api_users SET username = ?, nickname = ?, phone = ?, password = ?, status = ?, can_bet = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$username, $nickname, $phone, $password, $status, $canBet, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE api_users SET username = ?, nickname = ?, phone = ?, status = ?, can_bet = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$username, $nickname, $phone, $status, $canBet, $id]);
                }
                $message = "User updated successfully";
            } else {
                // Add
                if ($userId <= 0) {
                    $userId = mt_rand(100000, 999999);
                }
                if ($nickname === '') {
                    $nickname = 'Member' . strtoupper(substr(md5((string)$userId), 0, 8));
                }
                if ($password === '') {
                    $password = 'admin123';
                }

                // Check username uniqueness
                $stmt = $pdo->prepare("SELECT id FROM api_users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'message' => 'Username already exists'];
                }

                $stmt = $pdo->prepare("INSERT INTO api_users (user_id, username, nickname, phone, wallet_balance, game_balance, can_bet, password, status) VALUES (?, ?, ?, ?, 0.0, 0.0, ?, ?, ?)");
                $stmt->execute([$userId, $username, $nickname, $phone, $canBet, $password, $status]);
                $message = "User created successfully";
            }

            return ['success' => true, 'message' => $message];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public static function adjustBalance(int $userId, string $type, float $amount, string $notes): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        if ($amount === 0.0) {
            return ['success' => false, 'message' => 'Amount cannot be zero'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM api_users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'User not found'];
            }

            $column = $type === 'game' ? 'game_balance' : 'wallet_balance';
            $oldBalance = (float)$user[$column];
            $newBalance = $oldBalance + $amount;

            if ($newBalance < 0) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Insufficient balance for deduction'];
            }

            // Update user balance
            $stmt = $pdo->prepare("UPDATE api_users SET $column = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newBalance, $user['id']]);

            // Log transaction in wallet_logs
            $stmt = $pdo->prepare("INSERT INTO wallet_logs (user_id, type, amount, balance_before, balance_after, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user['user_id'], $type, $amount, $oldBalance, $newBalance, $notes]);

            $pdo->commit();
            return ['success' => true, 'message' => 'Balance adjusted successfully', 'new_balance' => $newBalance];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public static function setTargetControl(int $userId, int $winRate, int $status): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        if ($winRate < 0 || $winRate > 100) {
            return ['success' => false, 'message' => 'Win rate must be between 0 and 100'];
        }

        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT user_id FROM api_users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => 'User not found'];
            }

            $driver = api_db_driver($pdo);
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("INSERT INTO user_control (user_id, win_rate_percent, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE win_rate_percent = VALUES(win_rate_percent), status = VALUES(status), updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$userId, $winRate, $status]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM user_control WHERE user_id = ? LIMIT 1");
                $stmt->execute([$userId]);
                if ($stmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE user_control SET win_rate_percent = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
                    $stmt->execute([$winRate, $status, $userId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO user_control (user_id, win_rate_percent, status, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$userId, $winRate, $status]);
                }
            }

            return ['success' => true, 'message' => 'User win-rate target control updated successfully'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
