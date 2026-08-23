#!/usr/bin/env bash
set -e

DOMAIN="api.devlopedwithzayro.site"
INSTALL_DIR="/var/www/wingoapi"

echo "[+] Step 1: Stopping conflicting services..."
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
fuser -k 80/tcp 443/tcp 2>/dev/null || true
killall -9 nginx 2>/dev/null || true

echo "[+] Step 2: Creating clean Nginx configuration for ${DOMAIN}..."
cat << 'EOF' > "/etc/nginx/sites-available/${DOMAIN}.conf"
server {
    listen 80;
    listen [::]:80;
    server_name api.devlopedwithzayro.site;

    root /var/www/wingoapi;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\. {
        deny all;
    }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.devlopedwithzayro.site;

    root /var/www/wingoapi;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/api.devlopedwithzayro.site/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.devlopedwithzayro.site/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    access_log /var/log/nginx/wingo_ssl_access.log;
    error_log /var/log/nginx/wingo_ssl_error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\. {
        deny all;
    }
}
EOF

echo "[+] Step 3: Enabling site..."
rm -f /etc/nginx/sites-enabled/*
ln -sf "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"

echo "[+] Step 4: Testing Nginx syntax..."
nginx -t

echo "[+] Step 5: Starting Nginx & Worker..."
fuser -k 80/tcp 443/tcp 2>/dev/null || true
systemctl start nginx || nginx
systemctl restart php8.3-fpm wingo-worker

echo "=========================================================="
echo "  ✅ Fixed! Test with: curl https://${DOMAIN}/api/health"
echo "=========================================================="
