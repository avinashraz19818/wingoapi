#!/usr/bin/env bash
# ==============================================================================
# WinGo API System - VPS Automated Deployment Script (Robust & Self-Healing)
# Target Domain: api.devlopedwithzayro.site
# ==============================================================================

DOMAIN="api.devlopedwithzayro.site"
INSTALL_DIR="/var/www/wingoapi"
DB_NAME="club532583_in999"
DB_USER="club532583_in999"
DB_PASS="club532583_in999"

echo "================================================================="
echo "   🚀 Starting WinGo API System Auto-Installer for ${DOMAIN}"
echo "================================================================="

if [ "$EUID" -ne 0 ]; then
  echo "[-] Please run as root: sudo bash setup_vps.sh"
  exit 1
fi

# 1. Kill any port 80 conflicts (e.g. Apache2)
echo "[+] Step 1: Freeing Port 80 (Checking for Apache2 / conflicting servers)..."
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
fuser -k 80/tcp 2>/dev/null || true

# 2. Install Dependencies
echo "[+] Step 2: Installing Required Packages..."
apt-get update -y
apt-get install -y nginx mariadb-server mariadb-client \
    php-cli php-fpm php-mysql php-curl php-sqlite3 php-mbstring php-xml \
    git curl certbot python3-certbot-nginx psmisc

# Detect PHP-FPM socket
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.3")
PHP_FPM_SOCK="/var/run/php/php${PHP_VERSION}-fpm.sock"
echo "[+] Using PHP ${PHP_VERSION} (Socket: ${PHP_FPM_SOCK})"

# 3. Database Setup (Supports root without password, with sudo, or prompts)
echo "[+] Step 3: Configuring Database & User (${DB_NAME})..."
systemctl start mariadb || systemctl start mysql

SQL_COMMANDS="
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
"

# Try native root socket first (Ubuntu default)
if mysql -e "$SQL_COMMANDS" 2>/dev/null; then
    echo "[+] Database configured via standard MySQL socket."
elif mariadb -e "$SQL_COMMANDS" 2>/dev/null; then
    echo "[+] Database configured via MariaDB root socket."
else
    echo "[!] MySQL requires a root password. Please enter MySQL root password:"
    mysql -u root -p -e "$SQL_COMMANDS"
fi

# 4. Import Database Schema
echo "[+] Step 4: Importing Database Tables from schema.sql..."
SCHEMA_PATH="${INSTALL_DIR}/schema.sql"
[ ! -f "$SCHEMA_PATH" ] && SCHEMA_PATH="./schema.sql"

if [ -f "$SCHEMA_PATH" ]; then
    mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$SCHEMA_PATH" 2>/dev/null || \
    mysql -h 127.0.0.1 -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "$SCHEMA_PATH"
    echo "[+] Schema imported successfully."
fi

# 5. Environment & Permissions
echo "[+] Step 5: Setting up .env and file permissions..."
mkdir -p "${INSTALL_DIR}"
if [ "$PWD" != "$INSTALL_DIR" ]; then
    cp -r ./* "${INSTALL_DIR}/" 2>/dev/null || true
fi

cp "${INSTALL_DIR}/.env.example" "${INSTALL_DIR}/.env" 2>/dev/null || true
sed -i "s|DB_NAME=.*|DB_NAME=${DB_NAME}|g" "${INSTALL_DIR}/.env" 2>/dev/null || true
sed -i "s|DB_USER=.*|DB_USER=${DB_USER}|g" "${INSTALL_DIR}/.env" 2>/dev/null || true
sed -i "s|DB_PASS=.*|DB_PASS=${DB_PASS}|g" "${INSTALL_DIR}/.env" 2>/dev/null || true
sed -i "s|API_DOMAIN=.*|API_DOMAIN=${DOMAIN}|g" "${INSTALL_DIR}/.env" 2>/dev/null || true

chown -R www-data:www-data "${INSTALL_DIR}"
chmod -R 755 "${INSTALL_DIR}"

# 6. Configure Nginx
echo "[+] Step 6: Configuring Nginx VirtualHost..."
cat << 'EOF' > "/etc/nginx/sites-available/${DOMAIN}.conf"
server {
    listen 80;
    listen [::]:80;
    server_name api.devlopedwithzayro.site;

    root /var/www/wingoapi;
    index index.php index.html;

    access_log /var/log/nginx/wingo_api_access.log;
    error_log /var/log/nginx/wingo_api_error.log;

    add_header 'Access-Control-Allow-Origin' '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization' always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:PHP_SOCK_PLACEHOLDER;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
EOF

sed -i "s|api.devlopedwithzayro.site|${DOMAIN}|g" "/etc/nginx/sites-available/${DOMAIN}.conf"
sed -i "s|/var/www/wingoapi|${INSTALL_DIR}|g" "/etc/nginx/sites-available/${DOMAIN}.conf"
sed -i "s|PHP_SOCK_PLACEHOLDER|${PHP_FPM_SOCK}|g" "/etc/nginx/sites-available/${DOMAIN}.conf"

# Remove default site if it blocks port 80
rm -f /etc/nginx/sites-enabled/default
ln -sf "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"

# Test and start Nginx
fuser -k 80/tcp 2>/dev/null || true
systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || true
systemctl restart nginx

# 7. Setup Systemd Daemon for 24/7 Sync & Settlement
echo "[+] Step 7: Configuring Systemd Sync & Settle Background Worker..."
cat << EOF > /etc/systemd/system/wingo-worker.service
[Unit]
Description=WinGo Automated Lottery Sync & Bet Settlement Daemon
After=network.target mysql.service mariadb.service
Wants=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php ${INSTALL_DIR}/cron/sync_worker.php --daemon --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable wingo-worker
systemctl restart wingo-worker

echo "================================================================="
echo "   ✅ Deployment Complete!"
echo "   API Domain: http://${DOMAIN}"
echo "   Worker Status: Active (Running 24/7)"
echo "   Check health: curl http://${DOMAIN}/api/health"
echo ""
echo "   👉 To Activate Free SSL Certificate, run:"
echo "   sudo certbot --nginx -d ${DOMAIN}"
echo "================================================================="
