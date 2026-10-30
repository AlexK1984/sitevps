#!/usr/bin/env bash
set -Eeuo pipefail

readonly APP_USER="vpnsite"
readonly APP_DIR="/var/www/vpnsite"
readonly DATA_DIR="/var/lib/vpnsite"
readonly NGINX_SITE="/etc/nginx/sites-available/vpnsite"
readonly NGINX_ENABLED="/etc/nginx/sites-enabled/vpnsite"
readonly WORKER_SERVICE="/etc/systemd/system/vpnsite-worker.service"
readonly OWNER_MARKER="vpnsite-installer-v1"

TEMP_NGINX_CONFIG=""

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [[ -n "$TEMP_NGINX_CONFIG" && -e "$TEMP_NGINX_CONFIG" ]]; then
        rm -f -- "$TEMP_NGINX_CONFIG"
    fi
}

on_error() {
    local status="$1"
    printf 'Error: installation failed at line %s (exit %s).\n' "${BASH_LINENO[0]:-unknown}" "$status" >&2
    exit "$status"
}

trap cleanup EXIT
trap 'on_error "$?"' ERR

path_exists() {
    [[ -e "$1" || -L "$1" ]]
}

directory_is_empty() {
    [[ -d "$1" && ! -L "$1" ]] || return 1
    [[ -z "$(find "$1" -mindepth 1 -maxdepth 1 -print -quit)" ]]
}

normalize_public_ip() {
    python3 -c '
import ipaddress
import sys

try:
    address = ipaddress.ip_address(sys.argv[1].strip())
except ValueError:
    raise SystemExit(1)
if not address.is_global:
    raise SystemExit(1)
print(address)
' "$1"
}

detect_public_ip() {
    local family="$1"
    local response=""
    local curl_family

    [[ "$family" == "4" ]] && curl_family="-4" || curl_family="-6"
    if command -v curl >/dev/null 2>&1; then
        response="$(curl "$curl_family" --fail --silent --show-error --connect-timeout 5 --max-time 10 \
            https://api.ipify.org 2>/dev/null || true)"
    fi
    [[ -n "$response" ]] || return 1
    normalize_public_ip "$response" 2>/dev/null
}

check_listening_ports() {
    local port="$1"
    local listeners
    local listener
    local all_nginx=1

    if ! command -v ss >/dev/null 2>&1; then
        fail "Cannot inspect TCP port $port because 'ss' is unavailable. Install iproute2 and rerun."
    fi
    listeners="$(ss -H -ltnp "sport = :$port")" ||
        fail "Could not inspect TCP port $port. Check the system's socket tools and rerun."
    [[ -n "$listeners" ]] || return 0

    while IFS= read -r listener; do
        if [[ "$listener" != *'"nginx"'* ]]; then
            all_nginx=0
        fi
    done <<<"$listeners"
    if [[ "$all_nginx" -ne 1 ]]; then
        printf 'TCP port %s already has a non-Nginx listener:\n%s\n' "$port" "$listeners" >&2
        fail "Stop or reconfigure the unrelated service using TCP port $port, then rerun the installer."
    fi
}

check_firewall_status() {
    local ufw_status=""
    local service
    local nft_ruleset=""

    if command -v ufw >/dev/null 2>&1; then
        ufw_status="$(ufw status 2>/dev/null)" ||
            fail "Could not inspect UFW status; inspect the firewall manually before continuing."
        if grep -Fq 'Status: active' <<<"$ufw_status"; then
            printf '%s\n' "$ufw_status" >&2
            fail "UFW is active. Preserve SSH access, explicitly allow inbound TCP 80/443 (for example: ufw allow 80/tcp && ufw allow 443/tcp), verify with 'ufw status', then rerun."
        fi
    fi

    for service in firewalld nftables netfilter-persistent; do
        if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$service"; then
            fail "The $service firewall service is active. Explicitly permit inbound TCP 80/443 in its existing policy (without changing SSH access), verify the rules, then rerun."
        fi
    done

    if command -v nft >/dev/null 2>&1; then
        nft_ruleset="$(nft list ruleset 2>/dev/null)" ||
            fail "Could not inspect nftables rules; inspect the firewall manually before continuing."
        if grep -Eq '^[[:space:]]*(chain|hook|type|policy|drop|reject|jump|goto)[[:space:]]' <<<"$nft_ruleset"; then
            fail "An nftables ruleset is present. Verify that inbound TCP 80/443 is explicitly allowed (and SSH remains reachable), then rerun."
        fi
    fi

    local iptables_command
    local input_rules
    for iptables_command in iptables ip6tables; do
        if command -v "$iptables_command" >/dev/null 2>&1; then
            input_rules="$("$iptables_command" -S INPUT 2>/dev/null)" ||
                fail "Could not inspect $iptables_command INPUT rules; inspect the firewall manually before continuing."
            if grep -Eq '^(-A|-I) INPUT([[:space:]]|$)' <<<"$input_rules" ||
                ! grep -Fxq -- '-P INPUT ACCEPT' <<<"$input_rules"; then
                fail "$iptables_command has INPUT filtering rules or a non-accept policy. Verify inbound TCP 80/443 and SSH access in that firewall, then rerun."
            fi
        fi
    done

    printf 'No active local firewall rules that could block inbound traffic were detected.\n'
    read -r -p "Confirm your VPS/provider firewall permits inbound TCP 80 and 443 while retaining SSH access [y/N]: " FIREWALL_CONFIRMED ||
        fail "Firewall confirmation is required."
    if [[ ! "$FIREWALL_CONFIRMED" =~ ^[Yy]([Ee][Ss])?$ ]]; then
        fail "Before rerunning, allow inbound TCP 80/443 in the VPS/provider firewall and verify TCP 22 remains available for SSH."
    fi
}

if [[ "${EUID}" -ne 0 ]]; then
    fail "Run this installer as root (for example: sudo bash scripts/install.sh)."
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
if [[ ! -f "$SOURCE_DIR/artisan" || ! -f "$SOURCE_DIR/composer.json" || ! -f "$SOURCE_DIR/.env.example" ]]; then
    fail "Run scripts/install.sh from a repository checkout."
fi

PARTIAL_INSTALL=0
RECORDED_DOMAIN=""
if path_exists "$DATA_DIR"; then
    if [[ -L "$DATA_DIR" || ! -d "$DATA_DIR" ]]; then
        fail "$DATA_DIR exists but is not an ordinary directory."
    fi
    if path_exists "$DATA_DIR/.installed"; then
        fail "An installation already exists. This script will not overwrite it."
    fi
    if [[ -f "$DATA_DIR/.installing" && ! -L "$DATA_DIR/.installing" ]]; then
        IFS=' ' read -r marker_name RECORDED_DOMAIN marker_extra < "$DATA_DIR/.installing" || true
        if [[ "$marker_name" != "$OWNER_MARKER" || -n "${marker_extra:-}" ||
            ! "$RECORDED_DOMAIN" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ||
            "$(cat "$DATA_DIR/.installing")" != "$OWNER_MARKER $RECORDED_DOMAIN" ]]; then
            fail "Installation data exists without a valid installer ownership marker; refusing to modify it."
        fi
        PARTIAL_INSTALL=1
    elif ! directory_is_empty "$DATA_DIR"; then
        fail "Installation data exists without an installer recovery marker; refusing to modify it."
    fi
fi

if path_exists "$APP_DIR"; then
    if [[ -L "$APP_DIR" || ! -d "$APP_DIR" ]]; then
        fail "$APP_DIR exists but is not an ordinary directory."
    fi
    if [[ "$PARTIAL_INSTALL" -eq 1 ]]; then
        if [[ -f "$APP_DIR/.vpnsite-installer-owner" && ! -L "$APP_DIR/.vpnsite-installer-owner" ]]; then
            if [[ "$(cat "$APP_DIR/.vpnsite-installer-owner")" != "$OWNER_MARKER $RECORDED_DOMAIN" ]]; then
                fail "$APP_DIR has an unexpected installer ownership marker."
            fi
        elif ! directory_is_empty "$APP_DIR"; then
            fail "$APP_DIR contains data without its installer ownership marker; refusing to modify it."
        fi
    elif ! directory_is_empty "$APP_DIR"; then
        fail "$APP_DIR already contains application data; refusing to overwrite it."
    fi
fi

for owned_directory in "$APP_DIR/vendor" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"; do
    if path_exists "$owned_directory" && { [[ -L "$owned_directory" ]] || [[ ! -d "$owned_directory" ]]; }; then
        fail "$owned_directory is not an ordinary directory; refusing to follow or replace it."
    fi
done

if [[ "$PARTIAL_INSTALL" -eq 0 ]]; then
    if path_exists "$NGINX_SITE" || path_exists "$NGINX_ENABLED" || path_exists "$WORKER_SERVICE"; then
        fail "An Nginx site or worker service already uses this installer's reserved path."
    fi
    if id "$APP_USER" >/dev/null 2>&1; then
        fail "The system account '$APP_USER' already exists; refusing to reuse an unrelated account."
    fi
else
    if path_exists "$NGINX_SITE" && { [[ ! -f "$NGINX_SITE" ]] || [[ -L "$NGINX_SITE" ]]; }; then
        fail "The owned Nginx site path is not a regular file."
    fi
    if path_exists "$NGINX_SITE" &&
        { ! grep -Fqx "    server_name $RECORDED_DOMAIN;" "$NGINX_SITE" ||
            ! grep -Fqx '    root /var/www/vpnsite/public;' "$NGINX_SITE"; }; then
        fail "The Nginx site path no longer matches this installer; refusing to replace it."
    fi
    if path_exists "$NGINX_ENABLED" &&
        { [[ ! -L "$NGINX_ENABLED" ]] || [[ "$(readlink "$NGINX_ENABLED")" != "$NGINX_SITE" ]]; }; then
        fail "The Nginx enabled-site path is not the expected installer symlink."
    fi
    if path_exists "$WORKER_SERVICE" &&
        { [[ ! -f "$WORKER_SERVICE" ]] || [[ -L "$WORKER_SERVICE" ]] ||
            ! grep -Fqx 'Description=VPN subscriptions database queue worker' "$WORKER_SERVICE" ||
            ! grep -Fq 'WorkingDirectory=/var/www/vpnsite' "$WORKER_SERVICE" ||
            ! grep -Fq 'User=vpnsite' "$WORKER_SERVICE" ||
            ! grep -Fq 'ExecStart=/usr/bin/php artisan queue:work database' "$WORKER_SERVICE"; }; then
        fail "The worker service path contains an unexpected service; refusing to replace it."
    fi
    if id "$APP_USER" >/dev/null 2>&1; then
        app_user_record="$(getent passwd "$APP_USER")"
        IFS=: read -r _ _ _ _ _ app_user_home app_user_shell <<<"$app_user_record"
        if [[ "$app_user_home" != "$APP_DIR" || "$app_user_shell" != "/usr/sbin/nologin" ]] ||
            ! id -Gn "$APP_USER" | tr ' ' '\n' | grep -Fxq www-data; then
            fail "The existing '$APP_USER' account does not match this installer's account."
        fi
    fi
fi

printf 'Required inbound ports: TCP 22 (SSH administration), TCP 80 (HTTP and certificate validation), and TCP 443 (HTTPS).\n'
printf 'Ensure your VPS firewall/security group allows these ports; this installer will not change firewall rules.\n\n'
read -r -p "Website domain (for example vpn.example.com): " DOMAIN || fail "A website domain is required."
if [[ ! "$DOMAIN" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; then
    fail "Enter a valid public DNS domain name."
fi
DOMAIN="${DOMAIN,,}"
if [[ "$PARTIAL_INSTALL" -eq 1 && "$DOMAIN" != "$RECORDED_DOMAIN" ]]; then
    fail "This partial installation belongs to $RECORDED_DOMAIN; rerun with that domain to resume."
fi
DNS_ADDRESSES=()
while IFS= read -r dns_address; do
    [[ -n "$dns_address" ]] && DNS_ADDRESSES+=("$dns_address")
done < <(
    {
        getent ahostsv4 "$DOMAIN" 2>/dev/null || true
        getent ahostsv6 "$DOMAIN" 2>/dev/null || true
    } | awk '{print $1}' | sort -u
)
if [[ "${#DNS_ADDRESSES[@]}" -eq 0 ]]; then
    fail "The domain has no resolvable A/AAAA records. Point it to this VPS, wait for DNS propagation, and retry."
fi

PUBLIC_IPS=()
for address_family in 4 6; do
    if detected_ip="$(detect_public_ip "$address_family")"; then
        PUBLIC_IPS+=("$detected_ip")
    fi
done
if [[ "${#PUBLIC_IPS[@]}" -eq 0 ]]; then
    printf 'Automatic public-IP detection is unavailable. Enter the VPS public IPv4/IPv6 address(es) explicitly, comma-separated.\n'
    read -r -p "VPS public IP address(es): " manual_public_ips ||
        fail "Public-IP confirmation is required to validate DNS."
    normalized_public_ips="$(python3 -c '
import ipaddress
import sys

try:
    addresses = [ipaddress.ip_address(part.strip()) for part in sys.argv[1].split(",")]
except ValueError:
    raise SystemExit(1)
if not addresses or any(not address.is_global for address in addresses):
    raise SystemExit(1)
print(",".join(dict.fromkeys(str(address) for address in addresses)))
' "$manual_public_ips")" ||
        fail "Enter globally routable public IP address(es), separated by commas."
    IFS=, read -r -a PUBLIC_IPS <<<"$normalized_public_ips"
fi

for dns_address in "${DNS_ADDRESSES[@]}"; do
    dns_matches_vps=0
    for public_ip in "${PUBLIC_IPS[@]}"; do
        if [[ "$dns_address" == "$public_ip" ]]; then
            dns_matches_vps=1
            break
        fi
    done
    if [[ "$dns_matches_vps" -ne 1 ]]; then
        printf 'Resolved A/AAAA address %s does not match a detected or manually confirmed VPS public IP.\n' "$dns_address" >&2
        fail "Correct every A/AAAA record for $DOMAIN to this VPS and wait for DNS propagation before rerunning."
    fi
done

read -r -p "Email address for Let's Encrypt: " CERT_EMAIL || fail "A certificate email address is required."
if [[ ! "$CERT_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    fail "Enter a valid certificate email address."
fi

read -r -p "Administrator email: " ADMIN_EMAIL || fail "An administrator email address is required."
if [[ ! "$ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    fail "Enter a valid administrator email address."
fi

if path_exists "$APP_DIR/.env" && { [[ ! -f "$APP_DIR/.env" ]] || [[ -L "$APP_DIR/.env" ]]; }; then
    fail "$APP_DIR/.env is not a regular file; refusing to modify it."
fi
if path_exists "$DATA_DIR/database.sqlite" &&
    { [[ ! -f "$DATA_DIR/database.sqlite" ]] || [[ -L "$DATA_DIR/database.sqlite" ]]; }; then
    fail "$DATA_DIR/database.sqlite is not a regular file; refusing to modify it."
fi

if [[ -d /etc/nginx/sites-enabled || -d /etc/nginx/conf.d ]]; then
    while IFS= read -r conflicting_config; do
        [[ "$conflicting_config" == "$NGINX_ENABLED" ]] && continue
        fail "The domain $DOMAIN already appears in Nginx configuration $conflicting_config; refusing to claim it."
    done < <(grep -R -F -l -- "$DOMAIN" /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null || true)
fi

check_listening_ports 80
check_listening_ports 443
check_firewall_status

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    certbot composer git nginx php8.3-bcmath php8.3-cli php8.3-curl \
    php8.3-fpm php8.3-mbstring php8.3-sqlite3 php8.3-xml php8.3-zip rsync unzip

if [[ "$PARTIAL_INSTALL" -eq 0 ]]; then
    if [[ ! -d "$DATA_DIR" ]]; then
        install -d -o root -g root -m 755 "$DATA_DIR"
    fi
    printf '%s %s\n' "$OWNER_MARKER" "$DOMAIN" >"$DATA_DIR/.installing"
    chown root:root "$DATA_DIR/.installing"
    chmod 600 "$DATA_DIR/.installing"
fi

if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --gid www-data --home-dir "$APP_DIR" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
fi
chown root:www-data "$DATA_DIR"
chmod 3770 "$DATA_DIR"

if [[ ! -d "$APP_DIR" ]]; then
    install -d -o root -g root -m 755 "$APP_DIR"
fi
if [[ ! -f "$APP_DIR/.vpnsite-installer-owner" ]]; then
    printf '%s %s\n' "$OWNER_MARKER" "$DOMAIN" >"$APP_DIR/.vpnsite-installer-owner"
fi
chown root:root "$APP_DIR/.vpnsite-installer-owner"
chmod 440 "$APP_DIR/.vpnsite-installer-owner"

rsync -a --ignore-existing \
    --exclude='/.git/' \
    --exclude='/.env' \
    --exclude='/vendor/' \
    --exclude='/node_modules/' \
    --exclude='/storage/' \
    --exclude='/bootstrap/cache/' \
    --exclude='/database/database.sqlite' \
    "$SOURCE_DIR/" "$APP_DIR/"

chown -R root:www-data "$APP_DIR"
chmod -R u=rwX,g=rX,o= "$APP_DIR"
chown root:root "$APP_DIR/.vpnsite-installer-owner"
chmod 440 "$APP_DIR/.vpnsite-installer-owner"
install -d -o "$APP_USER" -g www-data -m 2770 \
    "$APP_DIR/vendor" \
    "$APP_DIR/storage/app/private" \
    "$APP_DIR/storage/framework/cache/data" \
    "$APP_DIR/storage/framework/sessions" \
    "$APP_DIR/storage/framework/testing" \
    "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/logs" \
    "$APP_DIR/bootstrap/cache"
chown -R "$APP_USER":www-data "$APP_DIR/vendor" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R u=rwX,g=rwX,o= "$APP_DIR/vendor" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/vendor" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec chmod 2770 {} +

if [[ ! -f "$APP_DIR/.env.example" ]]; then
    fail "The repository checkout is missing .env.example."
fi
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
chown "$APP_USER":www-data "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

runuser -u "$APP_USER" -- composer install \
    --working-dir="$APP_DIR" \
    --no-dev --no-interaction --no-progress --optimize-autoloader

if grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]+$' "$APP_DIR/.env"; then
    :
elif grep -Eq '^APP_KEY=.+$' "$APP_DIR/.env"; then
    fail "An existing APP_KEY is invalid; refusing to replace it because encrypted application data may depend on it."
else
    APP_KEY="$(runuser -u "$APP_USER" -- php "$APP_DIR/artisan" key:generate --show --no-ansi)"
    if [[ ! "$APP_KEY" =~ ^base64:[A-Za-z0-9+/=]+$ ]]; then
        fail "Laravel did not generate a valid application key."
    fi
    set_env APP_KEY "$APP_KEY"
    unset APP_KEY
fi

touch "$DATA_DIR/database.sqlite"
chown "$APP_USER":www-data "$DATA_DIR/database.sqlite"
chmod 660 "$DATA_DIR/database.sqlite"

runuser -u "$APP_USER" -- php "$APP_DIR/artisan" migrate --force --no-interaction
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" config:cache
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" route:cache
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" view:cache

check_listening_ports 80
check_listening_ports 443

TEMP_NGINX_CONFIG="$(mktemp "${NGINX_SITE}.installing.XXXXXX")"
cat >"$TEMP_NGINX_CONFIG" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root /var/www/vpnsite/public;
    index index.php;
    charset utf-8;
    client_max_body_size 10m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico {
        access_log off;
        log_not_found off;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
NGINX
chmod 644 "$TEMP_NGINX_CONFIG"
mv -f -- "$TEMP_NGINX_CONFIG" "$NGINX_SITE"
TEMP_NGINX_CONFIG=""

if [[ -e "$NGINX_ENABLED" && ! -L "$NGINX_ENABLED" ]]; then
    fail "The Nginx enabled-site path already exists and is not a symlink."
fi
if [[ -L "$NGINX_ENABLED" && "$(readlink "$NGINX_ENABLED")" != "$NGINX_SITE" ]]; then
    fail "The Nginx site symlink points to another configuration."
fi
if [[ ! -L "$NGINX_ENABLED" ]]; then
    ln -s "$NGINX_SITE" "$NGINX_ENABLED"
fi

nginx -t
systemctl enable --now php8.3-fpm nginx
systemctl reload nginx

certbot --nginx --non-interactive --agree-tos --redirect \
    --email "$CERT_EMAIL" -d "$DOMAIN"

if path_exists "$DATA_DIR/.admin-created" &&
    { [[ ! -f "$DATA_DIR/.admin-created" ]] || [[ -L "$DATA_DIR/.admin-created" ]]; }; then
    fail "The administrator setup marker is not a regular file."
fi
if [[ ! -f "$DATA_DIR/.admin-created" ]]; then
    runuser -u "$APP_USER" -- php "$APP_DIR/artisan" app:make-admin "$ADMIN_EMAIL"
    touch "$DATA_DIR/.admin-created"
    chown root:root "$DATA_DIR/.admin-created"
    chmod 600 "$DATA_DIR/.admin-created"
fi

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
chown root:root "$DATA_DIR/.installed"
chmod 600 "$DATA_DIR/.installed"
rm -f -- "$DATA_DIR/.installing"

printf '\nInstallation complete: https://%s\n' "$DOMAIN"
printf 'Configure YooKassa and Telegram in %s/.env, then run:\n' "$APP_DIR"
printf '  sudo -u %s php %s/artisan config:clear\n' "$APP_USER" "$APP_DIR"
