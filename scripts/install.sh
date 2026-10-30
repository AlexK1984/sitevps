#!/usr/bin/env bash
set -Eeuo pipefail

readonly APP_USER="vpnsite"
readonly APP_DIR="/var/www/vpnsite"
readonly DATA_DIR="/var/lib/vpnsite"
readonly NGINX_SITE="/etc/nginx/sites-available/vpnsite"
readonly NGINX_ENABLED="/etc/nginx/sites-enabled/vpnsite"
readonly WORKER_SERVICE="/etc/systemd/system/vpnsite-worker.service"

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

if [[ "${EUID}" -ne 0 ]]; then
    fail "Run this installer with sudo or as root."
fi

if [[ ! -r /etc/os-release ]]; then
    fail "Cannot identify the operating system."
fi

# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
    fail "This installer supports Ubuntu 24.04 LTS only."
fi

SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SOURCE_DIR
if [[ ! -f "$SOURCE_DIR/artisan" || ! -f "$SOURCE_DIR/composer.json" ]]; then
    fail "Run scripts/install.sh from a repository checkout."
fi

if [[ -e "$DATA_DIR/.installed" ]]; then
    fail "An installation already exists. This script will not overwrite it."
fi

if [[ ( -e "$DATA_DIR" || -e "$APP_DIR" ) && ! -e "$DATA_DIR/.installing" ]]; then
    fail "Installation paths already exist without an installer recovery marker."
fi

read -r -p "Website domain (for example vpn.example.com): " DOMAIN
if [[ ! "$DOMAIN" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; then
    fail "Enter a valid public DNS domain name."
fi

read -r -p "Email address for Let's Encrypt: " CERT_EMAIL
if [[ ! "$CERT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    fail "Enter a valid certificate email address."
fi

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    certbot composer git nginx php8.3-bcmath php8.3-cli php8.3-curl \
    php8.3-fpm php8.3-mbstring php8.3-sqlite3 php8.3-xml php8.3-zip rsync unzip

install -d -o root -g root -m 755 "$DATA_DIR"
touch "$DATA_DIR/.installing"

if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$APP_DIR" --create-home --shell /usr/sbin/nologin "$APP_USER"
fi

install -d -o "$APP_USER" -g www-data -m 2770 "$DATA_DIR"
install -d -o "$APP_USER" -g www-data -m 2770 "$APP_DIR"
rsync -a \
    --exclude='/.git/' \
    --exclude='/.env' \
    --exclude='/vendor/' \
    --exclude='/node_modules/' \
    --exclude='/database/database.sqlite' \
    --exclude='/bootstrap/cache/*.php' \
    --exclude='/storage/framework/views/*.php' \
    --exclude='/storage/logs/*.log' \
    "$SOURCE_DIR/" "$APP_DIR/"

chown -R "$APP_USER":www-data "$APP_DIR"
chmod -R u=rwX,g=rX,o= "$APP_DIR"
chmod -R g+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec chmod g+s {} +

if [[ ! -f "$APP_DIR/.env" ]]; then
    install -o "$APP_USER" -g www-data -m 640 "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

set_env() {
    local key="$1"
    local value="$2"
    local escaped="$value"
    escaped="${escaped//\\/\\\\}"
    escaped="${escaped//&/\\&}"
    escaped="${escaped//|/\\|}"

    if grep -q "^${key}=" "$APP_DIR/.env"; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" "$APP_DIR/.env"
    else
        printf '%s=%s\n' "$key" "$value" >>"$APP_DIR/.env"
    fi
}

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${DOMAIN}"
set_env APP_LOCALE ru
set_env APP_FALLBACK_LOCALE ru
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$DATA_DIR/database.sqlite"
set_env SESSION_DRIVER database
set_env SESSION_ENCRYPT true
set_env CACHE_STORE database
set_env QUEUE_CONNECTION database
set_env LOG_LEVEL warning
set_env MAIL_FROM_ADDRESS "$CERT_EMAIL"

touch "$DATA_DIR/database.sqlite"
chown "$APP_USER":www-data "$DATA_DIR/database.sqlite"
chmod 660 "$DATA_DIR/database.sqlite"

sudo -u "$APP_USER" -- composer install \
    --working-dir="$APP_DIR" \
    --no-dev --no-interaction --no-progress --optimize-autoloader

APP_KEY="$(sudo -u "$APP_USER" -- php "$APP_DIR/artisan" key:generate --show --no-ansi)"
if [[ ! "$APP_KEY" =~ ^base64:[A-Za-z0-9+/=]+$ ]]; then
    fail "Laravel did not generate a valid application key."
fi
set_env APP_KEY "$APP_KEY"
chown "$APP_USER":www-data "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

sudo -u "$APP_USER" -- php "$APP_DIR/artisan" migrate --force --no-interaction
sudo -u "$APP_USER" -- php "$APP_DIR/artisan" config:cache
sudo -u "$APP_USER" -- php "$APP_DIR/artisan" route:cache
sudo -u "$APP_USER" -- php "$APP_DIR/artisan" view:cache

sed -i \
    -e 's/^pm = .*/pm = ondemand/' \
    -e 's/^pm.max_children = .*/pm.max_children = 6/' \
    -e 's/^;*pm.process_idle_timeout = .*/pm.process_idle_timeout = 10s/' \
    -e 's/^pm.max_requests = .*/pm.max_requests = 500/' \
    /etc/php/8.3/fpm/pool.d/www.conf

cat >"$NGINX_SITE" <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name __DOMAIN__;
    root /var/www/vpnsite/public;
    index index.php;
    charset utf-8;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico {
        access_log off;
        log_not_found off;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
sed -i "s/__DOMAIN__/${DOMAIN}/g" "$NGINX_SITE"
if [[ -e "$NGINX_ENABLED" && ! -L "$NGINX_ENABLED" ]]; then
    fail "The Nginx site path already exists and is not a symlink."
fi

if [[ -L "$NGINX_ENABLED" && "$(readlink "$NGINX_ENABLED")" != "$NGINX_SITE" ]]; then
    fail "The Nginx site symlink points to another configuration."
fi

ln -sfn "$NGINX_SITE" "$NGINX_ENABLED"

systemctl enable --now php8.3-fpm nginx
nginx -t
systemctl reload nginx

certbot --nginx --non-interactive --agree-tos --redirect \
    --email "$CERT_EMAIL" -d "$DOMAIN"

read -r -p "Administrator email: " ADMIN_EMAIL
if [[ ! "$ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    fail "Enter a valid administrator email address."
fi
sudo -u "$APP_USER" -- php "$APP_DIR/artisan" app:make-admin "$ADMIN_EMAIL"

cat >"$WORKER_SERVICE" <<'UNIT'
[Unit]
Description=VPN subscriptions database queue worker
After=network.target

[Service]
Type=simple
User=vpnsite
Group=www-data
WorkingDirectory=/var/www/vpnsite
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=1 --timeout=90 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=100
MemoryMax=256M

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now vpnsite-worker
touch "$DATA_DIR/.installed"
chown "$APP_USER":www-data "$DATA_DIR/.installed"
chmod 640 "$DATA_DIR/.installed"
rm -f "$DATA_DIR/.installing"

printf '\nInstallation complete: https://%s\n' "$DOMAIN"
printf 'Configure YooKassa and Telegram in %s/.env, then run:\n' "$APP_DIR"
printf '  sudo -u %s php %s/artisan config:clear\n' "$APP_USER" "$APP_DIR"
