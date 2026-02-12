#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
SKIP_APT="${SKIP_APT:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run installer as root (sudo)." >&2
    exit 1
fi

echo "Installing pbx3api on Ubuntu 24.04 (nginx + PHP-FPM)"

install_runtime_packages() {
    if [ "${SKIP_APT}" = "1" ]; then
        echo "Skipping apt package install (SKIP_APT=1)"
        return
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        echo "apt-get not found. Install runtime dependencies manually." >&2
        return
    fi

    echo "Installing runtime packages via apt-get..."
    apt-get update
    apt-get install -y \
        nginx \
        composer \
        "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-sqlite3" \
        "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-ldap" \
        "php${PHP_VERSION}-gd"
}

install_php_deps() {
    if [ "${SKIP_COMPOSER}" = "1" ]; then
        echo "Skipping composer install (SKIP_COMPOSER=1)"
        return
    fi

    if [ ! -f "${REPO_ROOT}/composer.json" ]; then
        return
    fi

    if [ ! -f "${REPO_ROOT}/vendor/autoload.php" ]; then
        echo "Installing composer dependencies..."
        cd "${REPO_ROOT}"
        composer install --no-interaction --prefer-dist
    else
        echo "Composer dependencies already present (vendor/autoload.php found)"
    fi
}

install_runtime_packages
install_php_deps

# Default to the current clone path so local testing works.
# Override APP_ROOT/SOURCE_CONF/PHP_FPM_SERVICE/PHP_VERSION as needed.
APP_ROOT="${APP_ROOT:-${REPO_ROOT}}" \
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}" \
PHP_FPM_SERVICE="${PHP_FPM_SERVICE}" \
sh "${SCRIPT_DIR}/install-nginx-site.sh"

echo "pbx3api installer completed"
