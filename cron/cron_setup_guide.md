# WinGo Cron & Automation Setup Guide

To ensure your WinGo system automatically pulls external results, advances game periods, and settles player bets without manual intervention, configure automated sync tasks using either **cron-job.org** (cloud-based) or your server's native **cPanel / Linux Crontab**.

---

## Method 1: Using cron-job.org (Recommended for Shared Hosting & cPanel)

[cron-job.org](https://cron-job.org) is a free, reliable cloud cron service that pings your API over HTTPS.

### Job 1: Sync Results & Auto-Settle Bets
1. Log into your [cron-job.org](https://cron-job.org) account.
2. Click **Create Cronjob**.
3. Fill in the parameters:
   - **Title**: `WinGo Live Sync & Settle`
   - **URL**: `https://yourdomain.com/api/sync.php`
   - **Schedule**: Every 30 seconds (or Every 1 minute)
   - **Request Method**: `GET`
   - **Timezone**: UTC or Asia/Kolkata
   - **Notifications**: Enable email notifications on failure.
4. Click **Create**.

---

## Method 2: cPanel Cron Jobs (Linux Server)

If you have access to cPanel:

1. Open **cPanel** and search for **Cron Jobs**.
2. Under **Add New Cron Job**:
   - Common Settings: **Every Minute (* * * * *)**
   - Command (runs every minute and automatically syncs & settles):
   ```bash
   /usr/local/bin/php /home/username/public_html/cron/sync_worker.php >> /home/username/public_html/cron/sync.log 2>&1
   ```
3. Click **Add New Cron Job**.

---

## Method 3: VPS / Dedicated Server Daemon (Systemd / Supervisor)

For high-volume production deployments with zero latency, run the worker as a continuous background daemon:

### Systemd Service Configuration (`/etc/systemd/system/wingo-worker.service`)
```ini
[Unit]
Description=WinGo Sync and Settlement Worker Daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/wingo-api
ExecStart=/usr/bin/php /var/www/html/wingo-api/cron/sync_worker.php --daemon --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Enable & Start Service:
```bash
sudo systemctl daemon-reload
sudo systemctl enable wingo-worker
sudo systemctl start wingo-worker
sudo systemctl status wingo-worker
```
