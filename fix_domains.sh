#!/usr/bin/env bash
set -e

echo "[+] Step 1: Scanning /etc/nginx/sites-available for existing configs..."

# Re-enable all available sites in /etc/nginx/sites-available/
for conf in /etc/nginx/sites-available/*; do
    if [ -f "$conf" ]; then
        filename=$(basename "$conf")
        echo "Enabling site: $filename"
        ln -sf "$conf" "/etc/nginx/sites-enabled/$filename"
    fi
done

echo "[+] Step 2: Ensuring api.devlopedwithzayro.site is strictly bound to its own server_name..."
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

echo "[+] Step 4: Restarting Nginx & PHP..."
systemctl restart nginx php8.3-fpm

echo "=========================================================="
echo "  ✅ Main Domain & Subdomain separation fixed!"
echo "=========================================================="
