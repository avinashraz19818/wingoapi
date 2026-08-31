# Deployment guide

Target stack: **Ubuntu 22.04/24.04 or Debian 12**, **Nginx (or Apache) + PHP 8.3-FPM**,
**MySQL 8+**, Let's Encrypt TLS, timezone **Asia/Kolkata**.

## 1. Automated install

```bash
git clone <your-repo> /opt/lottery-api && cd /opt/lottery-api
sudo bash deploy/install.sh api.example.com
```

The script installs the packages, deploys to `/var/www/lottery-api`, creates the
MySQL database and user, generates `.env` with fresh secrets, runs the
migrations, configures PHP-FPM/Nginx, installs the systemd worker and requests a
certificate. It prints the generated admin token at the end.

## 2. Manual install

### 2.1 Packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml
```

Required PHP extensions: `pdo_mysql`, `curl` (a stream fallback exists), `mbstring`,
`json`, `hash`, `openssl`.

### 2.2 Database

```sql
CREATE DATABASE lottery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lottery'@'localhost' IDENTIFIED BY 'strong-password';
GRANT ALL PRIVILEGES ON lottery.* TO 'lottery'@'localhost';
FLUSH PRIVILEGES;
```

Recommended `my.cnf` additions:

```ini
[mysqld]
default_time_zone            = '+05:30'
transaction_isolation        = READ-COMMITTED
innodb_flush_log_at_trx_commit = 1
max_connections              = 300
innodb_buffer_pool_size      = 2G   # ~60% of RAM
```

### 2.3 Application

```bash
sudo rsync -a --exclude .git --exclude data/*.sqlite ./ /var/www/lottery-api/
cd /var/www/lottery-api
sudo cp .env.example .env && sudo nano .env      # fill in DB + secrets
sudo chown -R www-data:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo chmod 770 data && sudo chmod 600 .env
sudo -u www-data php tools/migrate.php
```

Generate strong secrets:

```bash
openssl rand -hex 32   # JWT_SECRET, DRAW_SECRET, SIGNATURE_SECRET
openssl rand -hex 24   # ADMIN_TOKEN
```

### 2.4 Web server

```bash
sudo cp deploy/php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/lottery.conf
sudo mkdir -p /var/log/php && sudo chown www-data:www-data /var/log/php
sudo systemctl restart php8.3-fpm

sudo cp deploy/nginx.conf /etc/nginx/sites-available/lottery-api.conf
sudo sed -i 's/api.example.com/your.domain/g' /etc/nginx/sites-available/lottery-api.conf
sudo ln -sf /etc/nginx/sites-available/lottery-api.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d your.domain --redirect
```

Apache users: `deploy/apache-vhost.conf` plus `a2enmod rewrite proxy_fcgi headers ssl`.

The document root is the repository root and **all** traffic is routed through
`index.php`; the vhosts deny direct access to `src/`, `tests/`, `tools/`, `cron/`,
`data/`, `.env`, `*.sqlite`, `*.log`, `*.md` and `*.sql`.

> Prefer an even tighter layout? Point the document root at a directory that only
> contains `index.php` and `api/Lottery.php` and keep the rest one level up.

### 2.5 Worker

Draws, settlement and copy-trade bets are driven by `cron/worker.php`.

```bash
sudo cp deploy/lottery-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now lottery-worker
journalctl -u lottery-worker -f
```

Without systemd, use `deploy/crontab.example` (six staggered passes per minute).
The API also settles lazily when history/records/win-loss are requested, so a
short worker outage never blocks payouts — it only delays them.

### 2.6 Timezone

```bash
sudo timedatectl set-timezone Asia/Kolkata
```

`TIMEZONE=Asia/Kolkata` in `.env` also sets PHP's timezone, MySQL's session
timezone (`+05:30`) and therefore the daily issue-number rollover.

## 3. Verification

```bash
curl -s https://your.domain/health | jq
curl -s "https://your.domain/api/Lottery?action=GetGameList" | jq '.data.groups[].lottery'

TOKEN=$(sudo -u www-data php /var/www/lottery-api/tools/token.php 1001 9876543210)
sudo -u www-data php /var/www/lottery-api/tools/wallet.php credit 1001 1000 "test float"

curl -s -X POST "https://your.domain/api/Lottery?action=WinGoBet" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"gameCode":"WinGo_1M","betType":"size","betContent":"big","amount":10}' | jq
```

## 4. Operations

| Task | Command |
|---|---|
| Schema status | `php tools/migrate.php --status` |
| Apply migrations | `php tools/migrate.php` |
| Regenerate `schema.sql` | `php tools/dump_schema.php > schema.sql` |
| Issue a JWT | `php tools/token.php <userId> <mobile>` |
| Compute a signature | `php tools/sign.php key=value ...` |
| Inspect / adjust a wallet | `php tools/wallet.php show\|credit\|debit <userId> [amount]` |
| Force a result | `POST ?action=SetResultOverride` with `X-Admin-Token` |
| Manual settlement | `POST ?action=SettleIssue` with `X-Admin-Token` |
| Run the test suite | `php tests/run.php` |

Application log: `data/app.log` (one JSON object per line — ship it to your log
stack). Worker log: `journalctl -u lottery-worker`.

### Backups

```bash
mysqldump --single-transaction --routines lottery | gzip > lottery-$(date +%F).sql.gz
```

`lot_wallet_ledger`, `lot_bets` and `lot_results` are append-only and are the
source of truth for any balance reconciliation.

## 5. Upgrades

```bash
cd /var/www/lottery-api
sudo -u www-data git pull
sudo -u www-data php tools/migrate.php        # new schema versions apply in order
sudo systemctl reload php8.3-fpm
sudo systemctl restart lottery-worker
```

Migrations are additive and versioned in `src/Database/Migrator.php`; the first
request after a deploy also self-heals the schema if the CLI step was skipped.

## 6. Security checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `JWT_SECRET`, `DRAW_SECRET`, `SIGNATURE_SECRET`, `ADMIN_TOKEN` are unique 32+ byte randoms
- [ ] `CORS_ORIGINS` lists your front-end origins instead of `*`
- [ ] `REQUIRE_SIGNATURE=true` once clients sign write requests
- [ ] `.env` is `chmod 600`, owned by `www-data`, never committed
- [ ] TLS with HSTS enabled (Certbot renew timer active)
- [ ] MySQL bound to `127.0.0.1`, dedicated user, no wildcard grants
- [ ] `RATE_LIMIT_PER_MIN` tuned; `TRUSTED_PROXIES` set if behind a CDN/LB
- [ ] `data/` is writable only by `www-data` and not web-reachable
- [ ] Off-host database backups scheduled and restore-tested
