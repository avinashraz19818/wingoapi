<?php
declare(strict_types=1);

class FinanceController
{
    public static function listRecharges(array $params): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $draw = (int)($params['draw'] ?? 1);
        $start = (int)($params['start'] ?? 0);
        $length = (int)($params['length'] ?? 10);
        $search = trim((string)($params['search']['value'] ?? ''));
        $statusFilter = trim((string)($params['status'] ?? ''));

        try {
            $conditions = [];
            $args = [];
            
            if ($search !== "") {
                $conditions[] = "(r.order_no LIKE ? OR r.utr LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
                $searchWild = "%$search%";
                $args = array_merge($args, [$searchWild, $searchWild, $searchWild, $searchWild]);
            }
            
            if ($statusFilter !== "") {
                $conditions[] = "r.status = ?";
                $args[] = $statusFilter;
            }

            $whereClause = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

            $totalQuery = "SELECT COUNT(*) FROM recharge_orders";
            $totalRecords = (int)$pdo->query($totalQuery)->fetchColumn();

            $filteredQuery = "SELECT COUNT(*) FROM recharge_orders r LEFT JOIN api_users u ON u.id = r.user_id" . $whereClause;
            $stmt = $pdo->prepare($filteredQuery);
            $stmt->execute($args);
            $filteredRecords = (int)$stmt->fetchColumn();

            // Fetch records
            $dataQuery = "
                SELECT r.*, u.username, u.nickname, u.phone AS user_phone, u.user_id AS player_id
                FROM recharge_orders r 
                LEFT JOIN api_users u ON u.id = r.user_id 
                " . $whereClause . " 
                ORDER BY r.id DESC 
                LIMIT $length OFFSET $start
            ";
            $stmt = $pdo->prepare($dataQuery);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();

            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'order_no' => $row['order_no'],
                    'player_id' => (int)$row['player_id'],
                    'username' => htmlspecialchars((string)($row['username'] ?? 'Anonymous')),
                    'nickname' => htmlspecialchars((string)($row['nickname'] ?? 'Member')),
                    'amount' => (float)$row['amount'],
                    'payment_type' => $row['payment_type'] ?? 'UPI',
                    'method_name' => $row['method_name'] ?? 'UPI Gateway',
                    'status' => $row['status'],
                    'utr' => htmlspecialchars((string)($row['utr'] ?? '')),
                    'screenshot_url' => $row['screenshot_url'],
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

    public static function listWithdrawals(array $params): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['draw' => 0, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
        }

        $draw = (int)($params['draw'] ?? 1);
        $start = (int)($params['start'] ?? 0);
        $length = (int)($params['length'] ?? 10);
        $search = trim((string)($params['search']['value'] ?? ''));
        $statusFilter = trim((string)($params['status'] ?? ''));

        try {
            $conditions = [];
            $args = [];
            
            if ($search !== "") {
                $conditions[] = "(w.order_no LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
                $searchWild = "%$search%";
                $args = array_merge($args, [$searchWild, $searchWild, $searchWild]);
            }
            
            if ($statusFilter !== "") {
                $conditions[] = "w.status = ?";
                $args[] = $statusFilter;
            }

            $whereClause = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

            $totalQuery = "SELECT COUNT(*) FROM withdraw_orders";
            $totalRecords = (int)$pdo->query($totalQuery)->fetchColumn();

            $filteredQuery = "SELECT COUNT(*) FROM withdraw_orders w LEFT JOIN api_users u ON u.id = w.user_id" . $whereClause;
            $stmt = $pdo->prepare($filteredQuery);
            $stmt->execute($args);
            $filteredRecords = (int)$stmt->fetchColumn();

            // Fetch records
            $dataQuery = "
                SELECT w.*, u.username, u.nickname, u.phone AS user_phone, u.user_id AS player_id
                FROM withdraw_orders w 
                LEFT JOIN api_users u ON u.id = w.user_id 
                " . $whereClause . " 
                ORDER BY w.id DESC 
                LIMIT $length OFFSET $start
            ";
            $stmt = $pdo->prepare($dataQuery);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();

            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'order_no' => $row['order_no'],
                    'player_id' => (int)$row['player_id'],
                    'username' => htmlspecialchars((string)($row['username'] ?? 'Anonymous')),
                    'nickname' => htmlspecialchars((string)($row['nickname'] ?? 'Member')),
                    'amount' => (float)$row['amount'],
                    'payment_type' => $row['payment_type'] ?? 'UPI',
                    'status' => $row['status'],
                    'account_json' => $row['account_json'],
                    'remarks' => htmlspecialchars((string)($row['remarks'] ?? '')),
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

    public static function updateRechargeStatus(int $id, string $status, string $remarks = ''): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM recharge_orders WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }

            $oldStatus = $order['status'];
            if (self::isFinalStatus($oldStatus)) {
                return ['success' => false, 'message' => 'Order already has a final status: ' . $oldStatus];
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE recharge_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $id]);

            // If approved, add balance to user
            if (in_array(strtolower($status), ['approved', 'success', 'completed', 'paid'], true)) {
                $bonus = 0.0;
                if (api_setting_bool('first_recharge_bonus_enabled', true)) {
                    // Check if it's their first approved recharge
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM recharge_orders WHERE user_id = ? AND id <> ? AND LOWER(status) IN ('approved','success','completed','complete','paid')");
                    $stmt->execute([$order['user_id'], $id]);
                    $priorApproved = (int)$stmt->fetchColumn();
                    if ($priorApproved === 0) {
                        $bonus = min(
                            (float)$order['amount'] * api_setting_float('first_recharge_bonus_percent', 10.0) / 100,
                            api_setting_float('first_recharge_bonus_max', 500.0)
                        );
                    }
                }

                $totalAdd = (float)$order['amount'] + $bonus;

                // Fetch user details
                $stmt = $pdo->prepare("SELECT user_id, wallet_balance, game_balance FROM api_users WHERE id = ? LIMIT 1");
                $stmt->execute([$order['user_id']]);
                $userRow = $stmt->fetch();
                if ($userRow) {
                    $playerUserId = (int)$userRow['user_id'];
                    $oldBal = (float)$userRow['wallet_balance'];
                    $oldGame = (float)$userRow['game_balance'];
                    $newBal = $oldBal + $totalAdd;
                    $newGame = $oldGame + $totalAdd;

                    // Approved deposit credits both wallet and Wingo game balance.
                    $stmt = $pdo->prepare("UPDATE api_users SET wallet_balance = ?, game_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newBal, $newGame, $order['user_id']]);

                    // Wallet log
                    $stmt = $pdo->prepare("INSERT INTO wallet_logs (user_id, type, amount, balance_before, balance_after, notes) VALUES (?, 'wallet', ?, ?, ?, ?)");
                    $stmt->execute([
                        $playerUserId, 
                        $totalAdd, 
                        $oldBal, 
                        $newBal, 
                        "Recharge deposit approved (Order: " . $order['order_no'] . ($bonus > 0 ? " including first deposit bonus " . $bonus : "") . ")"
                    ]);
                }
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'Recharge status updated to ' . $status];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public static function updateWithdrawalStatus(int $id, string $status, string $remarks = ''): array
    {
        $pdo = api_pdo();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM withdraw_orders WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }

            $oldStatus = $order['status'];
            if (self::isFinalStatus($oldStatus)) {
                return ['success' => false, 'message' => 'Order already has a final status: ' . $oldStatus];
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE withdraw_orders SET status = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $remarks, $id]);

            // If rejected, refund the user's wallet balance
            if (in_array(strtolower($status), ['rejected', 'cancelled', 'canceled', 'failed'], true)) {
                $amount = (float)$order['amount'];

                $stmt = $pdo->prepare("SELECT user_id, wallet_balance FROM api_users WHERE id = ? LIMIT 1");
                $stmt->execute([$order['user_id']]);
                $userRow = $stmt->fetch();
                if ($userRow) {
                    $playerUserId = (int)$userRow['user_id'];
                    $oldBal = (float)$userRow['wallet_balance'];
                    $newBal = $oldBal + $amount;

                    $stmt = $pdo->prepare("UPDATE api_users SET wallet_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$newBal, $order['user_id']]);

                    // Wallet log
                    $stmt = $pdo->prepare("INSERT INTO wallet_logs (user_id, type, amount, balance_before, balance_after, notes) VALUES (?, 'wallet', ?, ?, ?, ?)");
                    $stmt->execute([
                        $playerUserId, 
                        $amount, 
                        $oldBal, 
                        $newBal, 
                        "Withdrawal order rejected refund (Order: " . $order['order_no'] . "). Reason: " . $remarks
                    ]);
                }
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'Withdrawal status updated to ' . $status];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public static function bulkApproveRecharges(array $ids): array
    {
        $success = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $res = self::updateRechargeStatus((int)$id, 'Approved');
            if ($res['success']) {
                $success++;
            } else {
                $failed++;
            }
        }
        return ['success' => true, 'message' => "Bulk process complete: $success approved, $failed failed"];
    }

    public static function bulkRejectRecharges(array $ids, string $reason = ''): array
    {
        $success = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $res = self::updateRechargeStatus((int)$id, 'Rejected', $reason);
            if ($res['success']) {
                $success++;
            } else {
                $failed++;
            }
        }
        return ['success' => true, 'message' => "Bulk process complete: $success rejected, $failed failed"];
    }

    private static function isFinalStatus(string $status): bool
    {
        $finalStates = ['approved', 'success', 'completed', 'complete', 'paid', 'rejected', 'cancelled', 'canceled', 'failed'];
        return in_array(strtolower($status), $finalStates, true);
    }
}
