# Production WinGo Lottery & Betting API System

A robust, enterprise-grade WinGo Lottery API engine built in PHP & MySQL/MariaDB. It connects directly to external draw providers (`draw.ar-lottery01.com`), maintains local data consistency, serves real-time countdowns and period management, and executes atomic, ACID-compliant betting and payout settlements.

---

## 🚀 Key Architectural Improvements & Features

1. **Anti-Drift Period Synchronization**:
   - Calculates periods dynamically based on timestamp intervals (30s, 60s, 180s, 300s, 600s).
   - Prevents integer overflow and midnight rollover bugs on 32-bit and 64-bit PHP runtimes.
   - Dynamic lockouts (e.g. 5s on 30s/1m games, 15s on 5m games) so fast games never get locked out entirely.

2. **Official WinGo Color & Odds Rules Engine**:
   - **Number (0-9)**: Multiplier `9.0x`
   - **Pure Green (1, 3, 7, 9)**: Multiplier `2.0x`
   - **Pure Red (2, 4, 6, 8)**: Multiplier `2.0x`
   - **Half-Win Violet on 5 (Green+Violet)**: Multiplier `1.5x`
   - **Half-Win Violet on 0 (Red+Violet)**: Multiplier `1.5x`
   - **Violet (0 or 5)**: Multiplier `4.5x`
   - **Big (5-9) / Small (0-4)**: Multiplier `2.0x`
   - **Odd (1,3,5,7,9) / Even (0,2,4,6,8)**: Multiplier `2.0x`

3. **High-Concurrency Race Condition Prevention**:
   - Database operations use `BEGIN TRANSACTION` and row locking (`FOR UPDATE`) on wallet balances.
   - Prevents double-spending and duplicate settlements under heavy concurrent player traffic.

4. **Multi-Issue Settlement Engine**:
   - Settles all unsettled pending bets whose draw results are available, even if cron cycles are delayed.

5. **Cloudflare Resiliency & Fallback Simulation**:
   - Custom browser-grade headers with gzip decompression.
   - Intelligent mathematical fallback generator if external provider experiences outages or Cloudflare 403 blocks.

---

## 🗄️ Database Installation (phpMyAdmin / MySQL)

1. Open **phpMyAdmin** and select your database (e.g. `club532583_in999`).
2. Go to the **SQL** tab and paste the contents of `schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS `wingo_games` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `interval_seconds` INT NOT NULL,
  `lock_seconds` INT NOT NULL DEFAULT 5,
  `external_api_url` VARCHAR(255) NOT NULL,
  `status` TINYINT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wingo_results` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `game_code` VARCHAR(50) NOT NULL,
  `issue_number` VARCHAR(50) NOT NULL,
  `number` TINYINT NOT NULL,
  `color` VARCHAR(30) NOT NULL,
  `premium` VARCHAR(50) DEFAULT NULL,
  `sum` INT DEFAULT 0,
  `draw_time` DATETIME NOT NULL,
  `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_game_issue` (`game_code`, `issue_number`),
  INDEX `idx_game_time` (`game_code`, `draw_time`),
  INDEX `idx_issue` (`issue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wingo_current_issue` (
  `game_code` VARCHAR(50) PRIMARY KEY,
  `current_issue` VARCHAR(50) NOT NULL,
  `current_start` DATETIME NOT NULL,
  `current_end` DATETIME NOT NULL,
  `next_issue` VARCHAR(50) NOT NULL,
  `next_start` DATETIME NOT NULL,
  `next_end` DATETIME NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wingo_bets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `game_code` VARCHAR(50) NOT NULL,
  `issue_number` VARCHAR(50) NOT NULL,
  `bet_type` VARCHAR(20) NOT NULL,
  `bet_value` VARCHAR(20) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `odds` DECIMAL(6,2) NOT NULL,
  `status` ENUM('pending','won','lost','cancelled') DEFAULT 'pending',
  `payout` DECIMAL(12,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `settled_at` DATETIME NULL,
  INDEX `idx_user_game_issue` (`user_id`, `game_code`, `issue_number`),
  INDEX `idx_issue_status` (`issue_number`, `status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shonu_kaichila` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `balakedara` BIGINT UNIQUE NOT NULL,
  `motta` DECIMAL(14,2) NOT NULL DEFAULT 10000.00,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_balakedara` (`balakedara`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `wingo_games` (`game_code`, `name`, `interval_seconds`, `lock_seconds`, `external_api_url`) VALUES
('WinGo_30S', 'WinGo 30 Seconds', 30, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_30S/GetHistoryIssuePage.json'),
('WinGo_1M', 'WinGo 1 Minute', 60, 5, 'https://draw.ar-lottery01.com/WinGo/WinGo_1M/GetHistoryIssuePage.json'),
('WinGo_3M', 'WinGo 3 Minutes', 180, 10, 'https://draw.ar-lottery01.com/WinGo/WinGo_3M/GetHistoryIssuePage.json'),
('WinGo_5M', 'WinGo 5 Minutes', 300, 15, 'https://draw.ar-lottery01.com/WinGo/WinGo_5M/GetHistoryIssuePage.json'),
('WinGo_10M', 'WinGo 10 Minutes', 600, 30, 'https://draw.ar-lottery01.com/WinGo/WinGo_10M/GetHistoryIssuePage.json')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
```

---

## 📡 API Reference

### 1. Get Current Issue & Countdown
- **Method**: `GET`
- **URL**: `https://yourdomain.com/api/get_issue.php?game=WinGo_1M`
- **Response**:
```json
{
  "code": 0,
  "msg": "Current issue retrieved successfully",
  "data": {
    "game_code": "WinGo_1M",
    "game_name": "WinGo 1 Minute",
    "interval": 60,
    "lock_seconds": 5,
    "issue_number": "2026082300732",
    "start_time": "2026-08-23 12:12:00",
    "end_time": "2026-08-23 12:13:00",
    "next_issue_number": "2026082300733",
    "next_start_time": "2026-08-23 12:13:00",
    "next_end_time": "2026-08-23 12:14:00",
    "seconds_left": 48,
    "is_locked": false,
    "server_time": "2026-08-23 12:12:12"
  }
}
```

### 2. Get Draw History
- **Method**: `GET`
- **URL**: `https://yourdomain.com/api/get_history.php?game=WinGo_1M&limit=10`
- **Response**:
```json
{
  "code": 0,
  "data": {
    "game_code": "WinGo_1M",
    "count": 10,
    "list": [
      {
        "issue_number": "2026082300731",
        "number": 7,
        "color": "green",
        "premium": "48921",
        "sum": 7,
        "draw_time": "2026-08-23 12:11:00"
      }
    ]
  }
}
```

### 3. Place Bet
- **Method**: `POST`
- **URL**: `https://yourdomain.com/api/place_bet.php`
- **Headers**: `Content-Type: application/json`
- **Body**:
```json
{
  "user_id": 1001,
  "game_code": "WinGo_1M",
  "bet_type": "color",
  "bet_value": "green",
  "amount": 100.00
}
```
- **Response**:
```json
{
  "code": 0,
  "msg": "Bet placed successfully",
  "data": {
    "bet_id": 104,
    "user_id": 1001,
    "game_code": "WinGo_1M",
    "issue_number": "2026082300732",
    "bet_type": "color",
    "bet_value": "green",
    "amount": 100,
    "odds": 2,
    "potential_payout": 200,
    "wallet_balance": 9900,
    "created_at": "2026-08-23 12:12:15"
  }
}
```

### 4. Sync External Draws & Settle Bets (Webhook / Cron)
- **Method**: `GET`
- **URL**: `https://yourdomain.com/api/sync.php`
- **Response**:
```json
{
  "code": 0,
  "msg": "Sync & Settlement executed successfully",
  "data": {
    "sync": {
      "WinGo_1M": { "game_code": "WinGo_1M", "fetched": 20, "saved": 20 }
    },
    "settlement": {
      "settled_count": 5,
      "won_count": 3,
      "lost_count": 2,
      "total_payout": 588.00
    }
  }
}
```

---

## 💻 Frontend Integration Snippet

```javascript
const API_BASE = 'https://yourdomain.com/api';

// 1. Fetch Current Issue & Countdown
async function getCurrentIssue(gameCode = 'WinGo_1M') {
  const res = await fetch(`${API_BASE}/get_issue.php?game=${gameCode}`);
  const json = await res.json();
  return json.data;
}

// 2. Fetch Draw History
async function getHistory(gameCode = 'WinGo_1M', limit = 50) {
  const res = await fetch(`${API_BASE}/get_history.php?game=${gameCode}&limit=${limit}`);
  const json = await res.json();
  return json.data.list;
}

// 3. Place Bet
async function placeBet(userId, gameCode, betType, betValue, amount) {
  const res = await fetch(`${API_BASE}/place_bet.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      user_id: userId,
      game_code: gameCode,
      bet_type: betType,
      bet_value: betValue,
      amount: amount
    })
  });
  return await res.json();
}
```
