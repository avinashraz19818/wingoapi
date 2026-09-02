#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  Provision a fresh Ubuntu 22.04/24.04 or Debian 12 VPS for the Lottery API.
#
#    sudo bash deploy/install.sh api.example.com
#
#  Installs Nginx + PHP 8.3-FPM + MySQL 8, deploys this checkout to
#  /var/www/lottery-api, creates the database, runs the migrations, installs
#  the worker service and requests a Let's Encrypt certificate.
# ---------------------------------------------------------------------------
set -euo pipefail

DOMAIN="${1:-}"
APP_DIR="/var/www/lottery-api"
DB_NAME="lottery"
DB_USER="lottery"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "$DOMAIN" ]]; then
  echo "Usage: sudo bash deploy/install.sh <api-domain>" >&2
  exit 1
fi
if [[ $EUID -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

say "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
  nginx mysql-server certbot python3-certbot-nginx rsync openssl \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml \
  || apt-get install -y -qq nginx mysql-server certbot python3-certbot-nginx rsync openssl \
       php-fpm php-cli php-mysql php-curl php-mbstring php-xml

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

say "Deploying application to ${APP_DIR}"
mkdir -p "$APP_DIR"
rsync -a --delete \
  --exclude '.git' --exclude 'data/*.sqlite*' --exclude '.env' --exclude 'node_modules' \
  "$REPO_DIR"/ "$APP_DIR"/
mkdir -p "$APP_DIR/data"
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;
chmod 770 "$APP_DIR/data"

say "Configuring MySQL"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

say "Writing .env"
if [[ ! -f "$APP_DIR/.env" ]]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  sed -i \
    -e "s|^API_DOMAIN=.*|API_DOMAIN=${DOMAIN}|" \
    -e "s|^DB_NAME=.*|DB_NAME=${DB_NAME}|" \
    -e "s|^DB_USER=.*|DB_USER=${DB_USER}|" \
    -e "s|^DB_PASS=.*|DB_PASS=${DB_PASS}|" \
    -e "s|^JWT_SECRET=.*|JWT_SECRET=$(openssl rand -hex 32)|" \
    -e "s|^DRAW_SECRET=.*|DRAW_SECRET=$(openssl rand -hex 32)|" \
    -e "s|^SIGNATURE_SECRET=.*|SIGNATURE_SECRET=$(openssl rand -hex 32)|" \
    -e "s|^ADMIN_TOKEN=.*|ADMIN_TOKEN=$(openssl rand -hex 24)|" \
    -e "s|^LOG_PATH=.*|LOG_PATH=${APP_DIR}/data/app.log|" \
    "$APP_DIR/.env"
  chown www-data:www-data "$APP_DIR/.env"
  chmod 600 "$APP_DIR/.env"
  echo "  new .env written (secrets generated)"
else
  echo "  existing .env kept"
fi

say "Applying database migrations"
sudo -u www-data php "$APP_DIR/tools/migrate.php"

say "Configuring PHP-FPM"
sed "s/8\.3/${PHP_VER}/g" "$APP_DIR/deploy/php-fpm-pool.conf" > "/etc/php/${PHP_VER}/fpm/pool.d/lottery.conf"
mkdir -p /var/log/php && chown www-data:www-data /var/log/php
systemctl restart "php${PHP_VER}-fpm"

say "Configuring Nginx"
sed -e "s/api\.example\.com/${DOMAIN}/g" \
    -e "s/php8\.3-fpm-lottery\.sock/php${PHP_VER}-fpm-lottery.sock/g" \
    "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/lottery-api.conf
ln -sf /etc/nginx/sites-available/lottery-api.conf /etc/nginx/sites-enabled/lottery-api.conf
rm -f /etc/nginx/sites-enabled/default
mkdir -p /var/www/certbot
nginx -t && systemctl reload nginx

say "Installing the worker service"
sed "s|/usr/bin/php|$(command -v php)|" "$APP_DIR/deploy/lottery-worker.service" \
  > /etc/systemd/system/lottery-worker.service
systemctl daemon-reload
systemctl enable --now lottery-worker

say "Requesting TLS certificate"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect \
  || echo "  certbot failed — point DNS at this host and re-run: certbot --nginx -d ${DOMAIN}"

say "Done"
cat <<INFO

  API base   : https://${DOMAIN}/api/Lottery
  Health     : curl https://${DOMAIN}/health
  Admin token: grep ADMIN_TOKEN ${APP_DIR}/.env
  Worker     : systemctl status lottery-worker
  Logs       : journalctl -u lottery-worker -f  |  ${APP_DIR}/data/app.log

  Next steps:
    * set DRAW_BASE_URL in ${APP_DIR}/.env to your real draw provider
    * set CORS_ORIGINS to your front-end origins (avoid * in production)
    * consider REQUIRE_SIGNATURE=true once clients sign their write requests

INFO
