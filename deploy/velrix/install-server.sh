#!/usr/bin/env bash
# =============================================================================
# Kisauni Result System - first-time server setup on a fresh Debian 12 machine
# (Velrix "Machine", size Medium: 2 cores / 4 GiB).
#
# What it does (safe to run again; it skips what is already done):
#   1. Installs Nginx, PHP 8.2-FPM (+ extensions), MariaDB, Git, Composer, Certbot.
#   2. Creates the MariaDB database + user (random password, stored only in .env).
#   3. Clones the app from GitHub into /var/www/kisauni and runs composer install.
#   4. Writes .env (production values, new APP_KEY), private backup folder
#      /var/backups/kisauni (outside the web root), file permissions.
#   5. Nginx site serving ONLY /public, PHP/MariaDB tuned for 4 GiB RAM.
#   6. Daily verified database backup (cron, 02:00) and the firewall (22/80/443).
#
# It does NOT import school data and does NOT run migrations: the real data is
# imported from the old server's backup in a separate, checked step.
#
# Usage (as root):  bash install-server.sh [domain]
# =============================================================================
set -euo pipefail

DOMAIN="${1:-kisaunips.ostexs.com}"
REPO="https://github.com/otthnachia6-ship-it/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL.git"
BRANCH="main"
APP_DIR="/var/www/kisauni"
BACKUP_DIR="/var/backups/kisauni"
DB_NAME="kisauni_results"
DB_USER="kisauni"
PHP_V="8.2"

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo bash $0"; exit 1; }
grep -q 'VERSION_ID="12"' /etc/os-release || echo "WARNING: this script was written for Debian 12."

say "1/8 Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q nginx mariadb-server git unzip curl ca-certificates ufw cron \
  certbot python3-certbot-nginx \
  "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-mysql" "php${PHP_V}-mbstring" "php${PHP_V}-xml" \
  "php${PHP_V}-zip" "php${PHP_V}-gd" "php${PHP_V}-curl" "php${PHP_V}-bcmath" "php${PHP_V}-intl" \
  "php${PHP_V}-opcache" "php${PHP_V}-sqlite3"

if ! command -v composer >/dev/null; then
  say "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
fi

say "2/8 Tuning PHP and MariaDB for 4 GiB RAM"
cat > "/etc/php/${PHP_V}/fpm/conf.d/90-kisauni.ini" <<'INI'
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
expose_php = Off
date.timezone = Africa/Dar_es_Salaam
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
INI
cp "/etc/php/${PHP_V}/fpm/conf.d/90-kisauni.ini" "/etc/php/${PHP_V}/cli/conf.d/90-kisauni.ini"
sed -i 's/^pm.max_children = .*/pm.max_children = 20/' "/etc/php/${PHP_V}/fpm/pool.d/www.conf"

cat > /etc/mysql/mariadb.conf.d/90-kisauni.cnf <<'CNF'
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
max_connections = 100
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
bind-address = 127.0.0.1
CNF
systemctl restart mariadb

say "3/8 Database and user"
if [ ! -f /root/.kisauni_db_password ]; then
  head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32 > /root/.kisauni_db_password
  chmod 600 /root/.kisauni_db_password
fi
DB_PASS="$(cat /root/.kisauni_db_password)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

say "4/8 Application code from GitHub (${BRANCH})"
if [ -d "${APP_DIR}/.git" ]; then
  git -C "${APP_DIR}" pull --ff-only origin "${BRANCH}"
else
  git clone --branch "${BRANCH}" "${REPO}" "${APP_DIR}"
fi
cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet

say "5/8 .env and folders"
mkdir -p "${BACKUP_DIR}"
if [ ! -f .env ]; then
  cp .env.ostexs.example .env
  sed -i \
    -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
    -e "s|^BACKUP_PATH=.*|BACKUP_PATH=${BACKUP_DIR}|" \
    -e "s|^MIN_PASSWORD_LENGTH=.*|MIN_PASSWORD_LENGTH=4|" \
    .env
  # HTTPS is added in a later step; until then the session cookie must not be HTTPS-only.
  sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|' .env
  php artisan key:generate --force --no-interaction
else
  echo ".env already exists - left unchanged."
fi
chmod 640 .env

chown -R www-data:www-data storage bootstrap/cache "${BACKUP_DIR}"
chmod -R ug+rwX storage bootstrap/cache
chmod 700 "${BACKUP_DIR}"
chown root:www-data .env

say "6/8 Nginx site (serves only /public)"
cat > /etc/nginx/sites-available/kisauni <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} _;
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size 25M;
    server_tokens off;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_read_timeout 120;
    }

    # Never serve hidden files (.env, .git ...)
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/kisauni /etc/nginx/sites-enabled/kisauni
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl restart "php${PHP_V}-fpm" nginx

say "7/8 Daily verified database backup (02:00) "
cat > /etc/cron.d/kisauni <<CRON
# Kisauni Result System - verified database backup every night (retention from .env)
0 2 * * * www-data cd ${APP_DIR} && php artisan db:backup >> ${APP_DIR}/storage/logs/backup-cron.log 2>&1
CRON
chmod 644 /etc/cron.d/kisauni

say "8/8 Firewall (SSH, HTTP, HTTPS only)"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ufw status | sed -n '1,10p'

# Caches are built as the web user, so the app can rebuild them later.
for c in config:cache route:cache view:cache; do
  runuser -u www-data -- php artisan "$c" --no-interaction >/dev/null
done

say "Done. Next (separate, checked steps):"
cat <<NEXT
  - Import the school database backup from the old server, then run:  php artisan migrate --force
  - Create the Developer account:  php artisan developer:create devuser --name="System Developer"
  - Point ${DOMAIN} to this machine, then HTTPS:  certbot --nginx -d ${DOMAIN}
    and set SESSION_SECURE_COOKIE=true in .env, then: php artisan config:cache
  Database password is in /root/.kisauni_db_password (and in ${APP_DIR}/.env). Never share it.
NEXT
