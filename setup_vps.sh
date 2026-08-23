#!/usr/bin/env bash
# ==============================================================================
# WinGo API System - VPS Automated Deployment Script
# Target Domain: api.devlopedwithzayro.site
# Compatible with: Ubuntu 20.04 / 22.04 / 24.04 & Debian 11 / 12
# ==============================================================================

set -e

DOMAIN="api.devlopedwithzayro.site"
INSTALL_DIR="/var/www/wingoapi"
DB_NAME="club532583_in999"
DB_USER="club532583_in999"
DB_PASS="club532583_in999"

echo "================================================================="
echo "   🚀 Starting WinGo API System Auto-Installer for ${DOMAIN}"
echo "================================================================="

# Check root
if [ "$EUID" -ne 0 ]; then
  echo "[-] Please run as root: sudo bash setup_vps.sh"
  exit 1
fi

# 1. Update Package Lists & Install Dependencies
echo "[+] Step 1: Installing System Dependencies (Nginx, PHP, MariaDB, Certbot)..."
apt-get update -y
apt-get install -y nginx mariadb-server mariadb-client \
    php-cli php-fpm php-mysql php-curl php-sqlite3 php-mbstring php-xml \
    git curl certbot python3-certbot-nginx

# Determine PHP-FPM socket
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_FPM_SOCK="/var/run/php/php${PHP_VERSION}-fpm.sock"
echo "[+] Detected PHP ${PHP_VERSION} (Socket: ${PHP_FPM_SOCK})"

# 2. Setup Database & User
echo "[+] Step 2: Setting up MariaDB / MySQL Database (${DB_NAME})..."
systemctl start mariadb || systemctl start mysql
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
mysql -e "FLUSH PRIVILEGES;"

# 3. Import Schema
echo "[+] Step 3: Importing Database Schema..."
if [ -f "${INSTALL_DIR}/schema.sql" ]; then
    mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${INSTALL_DIR}/schema.sql"
elif [ -f "./schema.sql" ]; then
    mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "./schema.sql"
fi

# 4. Prepare Directory & Environment
echo "[+] Step 4: Configuring Project Files & Permissions..."
mkdir -p "${INSTALL_DIR}"
if [ "$PWD" != "$INSTALL_DIR" ]; then
    cp -r ./* "${INSTALL_DIR}/" 2>/dev/null || true
fi

cp "${INSTALL_DIR}/.env.example" "${INSTALL_DIR}/.env" 2>/dev/null || true
sed -i "s|DB_NAME=.*|DB_NAME=${DB_NAME}|g" "${INSTALL_DIR}/.env"
sed -i "s|DB_USER=.*|DB_USER=${DB_USER}|g" "${INSTALL_DIR}/.env"
sed -i "s|DB_PASS=.*|DB_PASS=${DB_PASS}|g" "${INSTALL_DIR}/.env"
sed -i "s|API_DOMAIN=.*|API_DOMAIN=${DOMAIN}|g" "${INSTALL_DIR}/.env"

chown -R www-data:www-data "${INSTALL_DIR}"
chmod -R 755 "${INSTALL_DIR}"

# 5. Configure Nginx VirtualHost
echo "[+] Step 5: Configuring Nginx VirtualHost for ${DOMAIN}..."
cat << 'EOF' > "/etc/nginx/sites-available/${DOMAIN}.conf"
server {
    listen 80;
    listen [::]:80;
    server_name api.devlopedwithzayro.site;

    root /var/www/wingoapi;
    index index.php index.html;

    access_log /var/log/nginx/wingo_api_access.log;
    error_log /var/log/nginx/wingo_api_error.log;

    # CORS Headers
    add_header 'Access-Control-Allow-Origin' '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range,Authorization' always;

    # Routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
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

ln -sf "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"
nginx -t && systemctl reload nginx

# 6. Setup Systemd Daemon for 24/7 Automated Sync & Settle
echo "[+] Step 6: Configuring Systemd Sync & Settle Daemon..."
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
echo "   Check health: curl http://${DOMAIN}/api/health"
echo "   To enable Free SSL: certbot --nginx -d ${DOMAIN}"
echo "================================================================="
