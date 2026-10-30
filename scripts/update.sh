#!/usr/bin/env bash
set -Eeuo pipefail

readonly REPOSITORY_URL="https://github.com/AlexK1984/sitevps.git"
readonly REPOSITORY_BRANCH="alexk1984-vpn-subscriptions-site"
readonly APP_USER="vpnsite"
readonly APP_DIR="/var/www/vpnsite"
readonly DATA_DIR="/var/lib/vpnsite"
readonly DB_FILE="$DATA_DIR/database.sqlite"
readonly PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
readonly OWNER_MARKER="vpnsite-installer-v1"

TEMP_DIR=""
MAINTENANCE_ACTIVE=0
SERVICES_STOPPED=0

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [[ -n "$TEMP_DIR" && -d "$TEMP_DIR" ]]; then
        rm -rf -- "$TEMP_DIR"
    fi
}

on_error() {
    local status="$1"
    local recovery_failed=0
    trap - ERR INT TERM
    set +e

    printf 'Error: update failed at line %s (exit %s).\n' "${BASH_LINENO[0]:-unknown}" "$status" >&2
    printf 'No backup was created, so the update cannot automatically roll back code or database migrations.\n' >&2

    if [[ "$SERVICES_STOPPED" -eq 1 ]]; then
        systemctl restart php8.3-fpm || recovery_failed=1
        systemctl restart vpnsite-worker || recovery_failed=1
        SERVICES_STOPPED=0
    fi
    if [[ "$MAINTENANCE_ACTIVE" -eq 1 ]]; then
        if runuser -u "$APP_USER" -- php "$APP_DIR/artisan" up; then
            MAINTENANCE_ACTIVE=0
        else
            recovery_failed=1
        fi
    fi
    if [[ "$recovery_failed" -eq 1 ]]; then
        printf 'Automatic service recovery failed. Inspect php8.3-fpm and vpnsite-worker, then run: sudo -u vpnsite php %s/artisan up\n' "$APP_DIR" >&2
    else
        printf 'Services were restarted and maintenance mode was cleared where possible. Review the site and logs before retrying.\n' >&2
    fi
    exit "$status"
}

trap cleanup EXIT
trap 'on_error "$?"' ERR
trap 'on_error 130' INT
trap 'on_error 143' TERM

if [[ "${EUID}" -ne 0 ]]; then
    fail "Run this updater as root."
fi

if [[ "$#" -ne 0 ]]; then
    fail "This updater always selects the latest commit from the configured release branch; no arguments are accepted."
fi

for command_name in curl git tar rsync composer php runuser flock systemctl awk grep head id tr; do
    command -v "$command_name" >/dev/null 2>&1 ||
        fail "Required command is missing: $command_name."
done

if [[ ! -r /etc/os-release ]]; then
    fail "Cannot identify the operating system."
fi

# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
    fail "This updater supports Ubuntu 24.04 LTS only."
fi

if [[ -L "$APP_DIR" || ! -d "$APP_DIR" || -L "$DATA_DIR" || ! -d "$DATA_DIR" ]]; then
    fail "The expected application or data directory is missing or is not an ordinary directory."
fi
if [[ ! -f "$DATA_DIR/.installed" || -L "$DATA_DIR/.installed" ||
    ! -f "$APP_DIR/.vpnsite-installer-owner" || -L "$APP_DIR/.vpnsite-installer-owner" ]]; then
    fail "This updater only supports a completed installation created by the VPN site installer."
fi
if [[ ! -f "$APP_DIR/.env" || -L "$APP_DIR/.env" ||
    ! -f "$APP_DIR/artisan" || ! -f "$APP_DIR/composer.lock" ||
    ! -f "$DB_FILE" || -L "$DB_FILE" ]]; then
    fail "A required application, environment, lockfile, or SQLite database file is missing or unsafe."
fi
if [[ "$(head -n 1 "$APP_DIR/.vpnsite-installer-owner")" != "$OWNER_MARKER "* ]]; then
    fail "The application ownership marker is not recognized."
fi
SITE_DOMAIN="$(awk 'NR == 1 { print $2 }' "$APP_DIR/.vpnsite-installer-owner")"
if [[ ! "$SITE_DOMAIN" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; then
    fail "The installer ownership marker does not contain a valid domain."
fi
if ! grep -Fxq 'DB_CONNECTION=sqlite' "$APP_DIR/.env" ||
    ! grep -Fxq "DB_DATABASE=$DB_FILE" "$APP_DIR/.env" ||
    ! grep -Eq '^APP_KEY=.+$' "$APP_DIR/.env"; then
    fail "The existing .env does not match the installer's SQLite setup or has no APP_KEY; refusing to alter it."
fi
if [[ ! -f "$PHP_FPM_POOL" || -L "$PHP_FPM_POOL" ]] ||
    ! grep -Eq '^[[:space:]]*user[[:space:]]*=[[:space:]]*vpnsite[[:space:]]*$' "$PHP_FPM_POOL" ||
    ! grep -Eq '^[[:space:]]*group[[:space:]]*=[[:space:]]*www-data[[:space:]]*$' "$PHP_FPM_POOL"; then
    fail "PHP-FPM must run as vpnsite:www-data; refusing to alter a custom pool."
fi
if ! id -Gn "$APP_USER" | tr ' ' '\n' | grep -Fxq www-data ||
    ! runuser -u "$APP_USER" -- test -w "$DB_FILE" ||
    ! runuser -u "$APP_USER" -- test -w "$DATA_DIR"; then
    fail "The vpnsite account must have the installer's www-data group and write access to SQLite."
fi
if [[ ! -f /etc/systemd/system/vpnsite-worker.service ||
    -L /etc/systemd/system/vpnsite-worker.service ]] ||
    ! grep -Fqx 'User=vpnsite' /etc/systemd/system/vpnsite-worker.service ||
    ! grep -Fqx 'Group=www-data' /etc/systemd/system/vpnsite-worker.service ||
    ! grep -Fqx 'WorkingDirectory=/var/www/vpnsite' /etc/systemd/system/vpnsite-worker.service ||
    ! grep -Fqx 'ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=1 --timeout=90 --max-time=3600' /etc/systemd/system/vpnsite-worker.service; then
    fail "The queue worker does not match the installer's expected service; refusing to restart an unrelated service."
fi

if [[ ! -d /run/lock ]]; then
    fail "The system lock directory /run/lock is unavailable."
fi
exec 9>/run/lock/vpnsite-update.lock
flock -n 9 || fail "Another VPN site update is already running."

RELEASE_SHA="$(git ls-remote "$REPOSITORY_URL" "refs/heads/$REPOSITORY_BRANCH" | awk 'NR == 1 { print $1 }')"
if [[ ! "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    fail "Could not resolve the latest commit from the configured release branch."
fi

TEMP_DIR="$(mktemp -d /var/tmp/vpnsite-update.XXXXXX)"
ARCHIVE="$TEMP_DIR/release.tar.gz"
SOURCE_DIR="$TEMP_DIR/source"
mkdir -m 700 "$SOURCE_DIR"
curl --fail --location --silent --show-error \
    "https://codeload.github.com/AlexK1984/sitevps/tar.gz/$RELEASE_SHA" \
    --output "$ARCHIVE"
tar -xzf "$ARCHIVE" --no-same-owner --no-same-permissions --strip-components=1 -C "$SOURCE_DIR"
if [[ ! -f "$SOURCE_DIR/artisan" || ! -f "$SOURCE_DIR/composer.json" ||
    ! -f "$SOURCE_DIR/composer.lock" || ! -f "$SOURCE_DIR/.env.example" ]]; then
    fail "The downloaded release does not have the expected Laravel application files."
fi

printf 'Prepared latest commit %s from branch %s.\n' "$RELEASE_SHA" "$REPOSITORY_BRANCH"
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" down --retry=60
MAINTENANCE_ACTIVE=1
SERVICES_STOPPED=1
systemctl stop vpnsite-worker
systemctl stop php8.3-fpm

rsync -a --chown=root:www-data --chmod=D750,F640 \
    --exclude='/.git/' \
    --exclude='/.env' \
    --include='/.env.example' \
    --exclude='/.env.*' \
    --exclude='/vendor/' \
    --exclude='/node_modules/' \
    --exclude='/storage/' \
    --exclude='/bootstrap/cache/' \
    --exclude='/database/database.sqlite' \
    "$SOURCE_DIR/" "$APP_DIR/"

runuser -u "$APP_USER" -- composer install \
    --working-dir="$APP_DIR" \
    --no-dev --no-interaction --no-progress --optimize-autoloader
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" migrate --force --no-interaction
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" optimize:clear
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" config:cache
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" route:cache
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" view:cache

systemctl restart php8.3-fpm
systemctl restart vpnsite-worker
SERVICES_STOPPED=0
runuser -u "$APP_USER" -- php "$APP_DIR/artisan" up
MAINTENANCE_ACTIVE=0
curl --fail --location --silent --show-error --connect-timeout 5 --max-time 30 \
    --output /dev/null "https://$SITE_DOMAIN/"

printf 'Update to %s completed. No backup was created, so this update has no automatic rollback.\n' "$RELEASE_SHA"
