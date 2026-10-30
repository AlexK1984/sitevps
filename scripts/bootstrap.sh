#!/usr/bin/env bash
set -Eeuo pipefail

readonly REPOSITORY_URL="https://github.com/AlexK1984/sitevps.git"
readonly REPOSITORY_BRANCH="alexk1984-vpn-subscriptions-site"
readonly CHECKOUT_DIR="/opt/vpnsite-bootstrap-source"

CLONE_CREATED=0

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [[ "$CLONE_CREATED" -eq 1 && -d "$CHECKOUT_DIR" ]]; then
        rm -rf -- "$CHECKOUT_DIR"
    fi
}

on_error() {
    local status="$1"
    printf 'Error: bootstrap failed at line %s (exit %s).\n' "${BASH_LINENO[0]:-unknown}" "$status" >&2
    exit "$status"
}

trap cleanup EXIT
trap 'on_error "$?"' ERR

if [[ "${EUID}" -ne 0 ]]; then
    fail "Run bootstrap as root (for example: curl -fsSL <bootstrap-url> | sudo bash)."
fi

if [[ ! -r /etc/os-release ]]; then
    fail "Cannot identify the operating system."
fi

# shellcheck disable=SC1091
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
    fail "This bootstrap supports Ubuntu 24.04 LTS only."
fi

if ! command -v git >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y git
fi

if [[ -e "$CHECKOUT_DIR" || -L "$CHECKOUT_DIR" ]]; then
    if [[ -L "$CHECKOUT_DIR" || ! -d "$CHECKOUT_DIR" || ! -e "$CHECKOUT_DIR/.git" ]]; then
        fail "$CHECKOUT_DIR already exists and is not a valid installer checkout; refusing to replace it."
    fi
    checkout_root="$(git -C "$CHECKOUT_DIR" rev-parse --show-toplevel 2>/dev/null)" ||
        fail "Cannot verify the existing checkout at $CHECKOUT_DIR."
    checkout_remote="$(git -C "$CHECKOUT_DIR" remote get-url origin 2>/dev/null)" ||
        fail "The existing checkout has no verifiable origin remote."
    checkout_branch="$(git -C "$CHECKOUT_DIR" branch --show-current 2>/dev/null)" ||
        fail "Cannot verify the branch of the existing checkout."
    if [[ "$checkout_root" != "$CHECKOUT_DIR" ||
        "$checkout_remote" != "$REPOSITORY_URL" ||
        "$checkout_branch" != "$REPOSITORY_BRANCH" ]]; then
        fail "The existing checkout does not match the expected repository and branch; refusing to reuse it."
    fi
    if [[ -n "$(git -C "$CHECKOUT_DIR" status --porcelain --untracked-files=all)" ]]; then
        fail "The installer checkout has local changes; preserve them and restore a clean checkout before retrying."
    fi
else
    mkdir -- "$CHECKOUT_DIR"
    CLONE_CREATED=1
    git clone --single-branch --branch "$REPOSITORY_BRANCH" "$REPOSITORY_URL" "$CHECKOUT_DIR"
    CLONE_CREATED=0
fi

if [[ ! -f "$CHECKOUT_DIR/scripts/install.sh" || ! -f "$CHECKOUT_DIR/artisan" ]]; then
    fail "The expected installer files are missing from the repository checkout."
fi

printf 'Using %s (%s) from %s.\n' "$REPOSITORY_BRANCH" "$REPOSITORY_URL" "$CHECKOUT_DIR"
exec bash "$CHECKOUT_DIR/scripts/install.sh"
