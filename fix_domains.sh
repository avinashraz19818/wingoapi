#!/usr/bin/env bash
set -e

echo "[+] Step 1: Cleaning sites-enabled and enabling active production domains..."
rm -f /etc/nginx/sites-enabled/*

# Enable Main Domain
if [ -f "/etc/nginx/sites-available/devlopedwithzayro.site" ]; then
    ln -sf "/etc/nginx/sites-available/devlopedwithzayro.site" "/etc/nginx/sites-enabled/devlopedwithzayro.site"
    echo "Enabling devlopedwithzayro.site"
fi

# Enable Protector subdomain if exists
if [ -f "/etc/nginx/sites-available/protector.devlopedwithzayro.site" ]; then
    ln -sf "/etc/nginx/sites-available/protector.devlopedwithzayro.site" "/etc/nginx/sites-enabled/protector.devlopedwithzayro.site"
    echo "Enabling protector.devlopedwithzayro.site"
fi

# Enable Neura AI if valid
if [ -f "/etc/nginx/sites-available/neura-ai" ]; then
    ln -sf "/etc/nginx/sites-available/neura-ai" "/etc/nginx/sites-enabled/neura-ai"
    echo "Enabling neura-ai"
fi

echo "[+] Step 2: Configuring api.devlopedwithzayro.site..."
cat << 'EOF' > "/etc/nginx/sites-available/api.devlopedwithzayro.site.conf"
# HTTP
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

# HTTPS
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

ln -sf "/etc/nginx/sites-available/api.devlopedwithzayro.site.conf" "/etc/nginx/sites-enabled/api.devlopedwithzayro.site.conf"

echo "[+] Step 3: Testing Nginx syntax..."
nginx -t

echo "[+] Step 4: Restarting Nginx..."
systemctl restart nginx php8.3-fpm

echo "=========================================================="
echo "  ✅ Main Domain (devlopedwithzayro.site) & Subdomain (api.devlopedwithzayro.site) both LIVE!"
echo "=========================================================="
