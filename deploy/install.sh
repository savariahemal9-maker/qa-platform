#!/bin/bash
##
## install.sh – One-command EC2 setup for QA Platform
## Run as: sudo bash install.sh
## Server: Ubuntu 24.04, t3.micro, Mumbai (ap-south-1)
##

set -e
echo "=== QA Platform: Server Setup ==="

# ── 1. System update ──────────────────────────────────────────────────────
apt-get update -y
apt-get upgrade -y

# ── 2. Nginx ──────────────────────────────────────────────────────────────
apt-get install -y nginx

# ── 3. PHP 8.3 + FPM + extensions ────────────────────────────────────────
apt-get install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt-get update -y
apt-get install -y php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-redis php8.3-gd

# ── 4. MySQL 8 ───────────────────────────────────────────────────────────
apt-get install -y mysql-server
mysql -e "CREATE DATABASE IF NOT EXISTS qa_platform CHARACTER SET utf8mb4;"
mysql -e "CREATE USER IF NOT EXISTS 'qa_user'@'localhost' IDENTIFIED BY 'CHANGE_DB_PASSWORD';"
mysql -e "GRANT ALL ON qa_platform.* TO 'qa_user'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# ── 5. Node.js 22 (LTS) ──────────────────────────────────────────────────
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

# ── 6. Playwright + Chromium ──────────────────────────────────────────────
apt-get install -y \
    libglib2.0-0 libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 \
    libcups2 libdrm2 libdbus-1-3 libxcb1 libxkbcommon0 libx11-6 \
    libxcomposite1 libxdamage1 libxext6 libxfixes3 libxrandr2 \
    libgbm1 libpango-1.0-0 libcairo2 libasound2

# ── 7. PM2 (process manager for Node) ────────────────────────────────────
npm install -g pm2

# ── 8. Deploy app ─────────────────────────────────────────────────────────
mkdir -p /var/www/qa-platform
# (Files are already there from git clone)

# Install Node dependencies
cd /var/www/qa-platform/engine
npm install
npx playwright install chromium

# ── 9. Nginx config ───────────────────────────────────────────────────────
cp /var/www/qa-platform/nginx/qa-platform.conf /etc/nginx/sites-available/qa-platform
ln -sf /etc/nginx/sites-available/qa-platform /etc/nginx/sites-enabled/qa-platform
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# ── 10. Run schema ────────────────────────────────────────────────────────
mysql -u qa_user -pCHANGE_DB_PASSWORD qa_platform < /var/www/qa-platform/database/schema.sql

# ── 11. Start engine with PM2 ─────────────────────────────────────────────
cd /var/www/qa-platform/engine
ENGINE_PORT=3001 \
ENGINE_SECRET=CHANGE_ENGINE_SECRET \
PHP_API_URL=http://localhost/api \
pm2 start server.js --name qa-engine --max-memory-restart 512M

pm2 startup systemd -u www-data --hp /var/www
pm2 save

# ── 12. Certbot SSL ───────────────────────────────────────────────────────
apt-get install -y certbot python3-certbot-nginx
# Run after DNS is pointed:
# certbot --nginx -d testingqa.hemalsavaria.com

echo ""
echo "=== Setup complete! ==="
echo "Engine running on port 3001"
echo "PHP-FPM: $(php-fpm8.3 -v | head -1)"
echo "Next: point testingqa.hemalsavaria.com → $(curl -s ifconfig.me)"
echo "Then run: certbot --nginx -d testingqa.hemalsavaria.com"
