# WinGo Automated Lottery & Betting API Engine

Production-grade Backend API system for **WinGo / Colour Trading** games.  
Designed for Linux VPS deployment under domain **`api.devlopedwithzayro.site`**.

---

## 🌟 System Architecture & Capabilities

1. **Auto Sync Engine**: Pulls draw results from external provider (`draw.ar-lottery01.com`) or fallback simulator.
2. **ACID Settlement Engine**: Atomically calculates winners, applies official color rules (Green/Red 2x, Half-Violet 1.5x on 0/5, Violet 4.5x, Number 9x), and credits player wallets.
3. **Period & Timing Management**: Millisecond-accurate period numbering (`YYYYMMDDxxxxx`) with dynamic lockout prevention for ultra-fast 30s games.
4. **Race-Condition Protection**: Row-level locking (`FOR UPDATE`) on balance checks and settlements.
5. **REST API Gateway**: Clean JSON API with global CORS headers for easy frontend/app integration.
6. **24/7 VPS Background Daemon**: Integrated Systemd worker for continuous background synchronization.

---

## ⚡ Zero-Delay Result Delivery (no more 5s wait after the countdown)

**The old problem:** the open period number was derived from "whatever row the last sync happened to
write", and history deliberately hid the newest row. So when a player's countdown hit `00`, the
result, the history list and the bet win/lose popup all waited for the *next* cron cycle (~5s, or
30-60s on cron-job.org) before updating. The client saw this as "API is slow".

**How it works now:**

| Step | Behaviour |
| --- | --- |
| Period number | Derived from the **wall clock** (`floor(secondsSinceMidnight/interval)+1`), exactly like the provider numbers its own periods. Rollover is instant and never waits for a sync. |
| History | Returns every **closed** period (`issue_number < open issue`) — the period that closed one second ago is included immediately. The still-open period stays hidden so its result can never leak early. |
| Result pull | `ResultSyncService::ensureLiveResult()` — if a period has just closed and its row is missing, the **first client request pulls it from the provider right away** instead of waiting for cron. Single-flight via `flock` + throttled (`LIVE_PULL_MIN_GAP`), so 1000 players cause exactly one upstream call. |
| Settlement | Bets are settled in the **same request** that pulled the result (`BetService::ensureSettled()`), so the win/lose popup and wallet balance update together with the countdown. |
| Worker | All games are fetched **in parallel** (`curl_multi`) with short timeouts, so one cycle costs the slowest call instead of the sum of all of them. |
| Caching | Every JSON response sends `Cache-Control: no-store` so no browser/nginx/CDN can serve a stale history. |

**Integrity guard:** a bet is only settled once its period has **closed**. A provider that publishes
a result early can never settle (or reveal) a period that is still accepting bets.

### Tuning (`.env`)

```ini
ISSUE_OFFSET=0            # 0 = live/exact (default). -1 = legacy 1-period lag. +1 = ahead.
LIVE_PULL_ENABLED=1       # on-demand pull of the just-closed result
LIVE_PULL_MIN_GAP=0.8     # min seconds between two on-demand pulls per game
LIVE_PULL_WINDOW=10       # only auto-pull in the first N seconds of a new period
LIVE_PULL_MAX_WAIT=2.5    # max seconds a request waits for an in-flight pull
UPSTREAM_TIMEOUT=3        # provider HTTP timeout (runs inside live requests - keep short)
UPSTREAM_CONNECT_TIMEOUT=2
UPSTREAM_FALLBACK=0       # 1 = invent fake results when the provider is down (settles real bets!)
```

> **Note on `ISSUE_OFFSET`:** it used to default to `-1`, which is what put the displayed period one
> step behind the provider and produced the delay. It now defaults to `0`. If your frontend was
> built around the lagged numbering, set `ISSUE_OFFSET=-1` to restore it.

---

## ⚡ 1-Command VPS Setup (Ubuntu / Debian)

On your VPS server, run:

```bash
# 1. Clone your repository
git clone https://github.com/avinashraz19818/wingoapi.git /var/www/wingoapi
cd /var/www/wingoapi

# 2. Run Automated Setup Script (Root)
sudo bash setup_vps.sh

# 3. (Optional) Activate Free SSL Certificate
sudo certbot --nginx -d api.devlopedwithzayro.site
```

The installer will automatically:
- Install Nginx, PHP-FPM, MariaDB/MySQL, and Certbot.
- Create database `club532583_in999` and import `schema.sql`.
- Configure Nginx VirtualHost for `api.devlopedwithzayro.site`.
- Launch the background sync service `wingo-worker.service`.

---

## 📡 REST API Reference (`api.devlopedwithzayro.site`)

### Base URL:
`https://api.devlopedwithzayro.site`

---

### 1. Get Live Period & Countdown
- **URL**: `GET /api/issue?game=WinGo_1M` (or `GET /api/get_issue.php?game=WinGo_1M`)
- **Query Params**:
  - `game`: `WinGo_30S` | `WinGo_1M` | `WinGo_3M` | `WinGo_5M` | `WinGo_10M`
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
    "issue_number": "2026082300404",
    "start_time": "2026-08-23 06:43:00",
    "end_time": "2026-08-23 06:44:00",
    "next_issue_number": "2026082300405",
    "next_start_time": "2026-08-23 06:44:00",
    "next_end_time": "2026-08-23 06:45:00",
    "last_issue_number": "2026082300403",
    "result_pending": false,
    "seconds_left": 42,
    "is_locked": false,
    "server_time": "2026-08-23 06:43:18"
  }
}
```

`last_issue_number` is the period that just closed and `result_pending` tells the client whether its
result is already stored (`false` = ready to render). Calling this endpoint also triggers the
zero-delay pull described above.

---

### 2. Get Draw History
- **URL**: `GET /api/history?game=WinGo_1M&limit=50`
- **Response**:
```json
{
  "code": 0,
  "msg": "Draw history retrieved successfully",
  "data": {
    "game_code": "WinGo_1M",
    "count": 10,
    "list": [
      {
        "issue_number": "2026082300403",
        "number": 7,
        "color": "green",
        "premium": "48921",
        "sum": 7,
        "draw_time": "2026-08-23 06:43:00"
      }
    ]
  }
}
```

---

### 3. Place User Bet
- **URL**: `POST /api/bet` (or `POST /api/place_bet.php`)
- **Headers**: `Content-Type: application/json`
- **Payload**:
```json
{
  "user_id": 1001,
  "game_code": "WinGo_1M",
  "bet_type": "color",
  "bet_value": "green",
  "amount": 100.00
}
```
- **Supported Bet Types & Values**:
  - `bet_type`: `"number"`, `bet_value`: `"0"` to `"9"` (Odds: **9.0x**)
  - `bet_type`: `"color"`, `bet_value`: `"green"` | `"red"` (Odds: **2.0x**, **1.5x** on 0/5 half-violet)
  - `bet_type`: `"color"`, `bet_value`: `"violet"` (Odds: **4.5x**)
  - `bet_type`: `"big_small"`, `bet_value`: `"big"` | `"small"` (Odds: **2.0x**)
  - `bet_type`: `"odd_even"`, `bet_value`: `"odd"` | `"even"` (Odds: **2.0x**)
- **Response**:
```json
{
  "code": 0,
  "msg": "Bet placed successfully",
  "data": {
    "bet_id": 2,
    "user_id": 1001,
    "game_code": "WinGo_1M",
    "issue_number": "2026082300404",
    "bet_type": "color",
    "bet_value": "green",
    "amount": 100,
    "odds": 2,
    "potential_payout": 200,
    "wallet_balance": 9900,
    "created_at": "2026-08-23 06:43:03"
  }
}
```

---

### 4. Get User Bet History
- **URL**: `GET /api/user-bets?user_id=1001&limit=20`
- **Response**:
```json
{
  "code": 0,
  "data": {
    "user_id": 1001,
    "count": 1,
    "bets": [
      {
        "id": 2,
        "issue_number": "2026082300404",
        "bet_type": "color",
        "bet_value": "green",
        "amount": 100,
        "odds": 2,
        "status": "won",
        "payout": 196.00,
        "draw_number": 7,
        "draw_color": "green"
      }
    ]
  }
}
```

---

### 5. Trigger External Sync & Settlement
- **URL**: `GET /api/sync` (or `GET /api/sync.php`)
- Automatically pulls results from `draw.ar-lottery01.com`, writes to database, and settles all pending bets.

---

### 6. Wallet Balance & Deposit
- **Check Balance**: `GET /api/wallet?user_id=1001`
- **Deposit/Recharge**: `POST /api/wallet` with `{"user_id": 1001, "amount": 5000}`

---

### 7. System Health Check
- **URL**: `GET /api/health` or `GET /`

---

## ⚙️ Cron Configuration (Optional Alternative to Systemd)

If not using the built-in systemd service, configure on **cron-job.org**:
- **URL**: `https://api.devlopedwithzayro.site/api/sync`
- **Schedule**: Every 30 seconds
- **Method**: `GET`
