#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
PHP_FPM_SOCKET="${PHP_FPM_SOCKET:-/run/php/${PHP_FPM_SERVICE}.sock}"
PBX3_SQLITE_PATH="${PBX3_SQLITE_PATH:-${REPO_ROOT}/../pbx3/db/sqlite.db}"
SKIP_APT="${SKIP_APT:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
SKIP_ARTISAN="${SKIP_ARTISAN:-0}"

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
        ssl-cert \
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

ensure_snakeoil_cert() {
    cert_path="/etc/ssl/certs/ssl-cert-snakeoil.pem"
    key_path="/etc/ssl/private/ssl-cert-snakeoil.key"
    need_regen=0

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ]; then
        need_regen=1
    fi

    if [ "${need_regen}" -eq 0 ] && command -v openssl >/dev/null 2>&1; then
        if ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1; then
            need_regen=1
        elif ! openssl pkey -in "${key_path}" -noout >/dev/null 2>&1; then
            need_regen=1
        else
            cert_modulus="$(openssl x509 -noout -modulus -in "${cert_path}" 2>/dev/null || true)"
            key_modulus="$(openssl rsa -noout -modulus -in "${key_path}" 2>/dev/null || true)"
            if [ -z "${cert_modulus}" ] || [ "${cert_modulus}" != "${key_modulus}" ]; then
                need_regen=1
            fi
        fi
    fi

    if [ "${need_regen}" -eq 0 ]; then
        return
    fi

    echo "Generating fallback snakeoil certificate for nginx..."

    if command -v make-ssl-cert >/dev/null 2>&1; then
        make-ssl-cert generate-default-snakeoil --force-overwrite >/dev/null 2>&1 || true
    fi

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ] || ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1; then
        if command -v openssl >/dev/null 2>&1; then
            mkdir -p /etc/ssl/certs /etc/ssl/private
            openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
                -keyout "${key_path}" \
                -out "${cert_path}" \
                -subj "/CN=pbx3api.local" >/dev/null 2>&1
            chmod 600 "${key_path}"
        fi
    fi

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ]; then
        echo "Unable to create fallback certificate (${cert_path})." >&2
        echo "Install ssl-cert or provide custom cert paths in config/nginx/pbx3-api.conf." >&2
        exit 1
    fi

    if command -v openssl >/dev/null 2>&1; then
        if ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1 || ! openssl pkey -in "${key_path}" -noout >/dev/null 2>&1; then
            echo "Unable to create fallback certificate (${cert_path})." >&2
            echo "Install ssl-cert or provide custom cert paths in config/nginx/pbx3-api.conf." >&2
            exit 1
        fi
    fi
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

set_env_value() {
    env_file="$1"
    key="$2"
    value="$3"

    if [ ! -f "${env_file}" ]; then
        return
    fi

    if grep -q "^${key}=" "${env_file}"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${env_file}"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${env_file}"
    fi
}

bootstrap_laravel_app() {
    if [ "${SKIP_ARTISAN}" = "1" ]; then
        echo "Skipping Laravel bootstrap (SKIP_ARTISAN=1)"
        return
    fi

    if [ ! -f "${REPO_ROOT}/artisan" ]; then
        return
    fi

    if [ ! -f "${REPO_ROOT}/.env" ] && [ -f "${REPO_ROOT}/.env.example" ]; then
        echo "Creating .env from .env.example"
        cp "${REPO_ROOT}/.env.example" "${REPO_ROOT}/.env"
    fi

    # Avoid requiring Laravel session/cache/queue tables on first boot.
    set_env_value "${REPO_ROOT}/.env" "SESSION_DRIVER" "file"
    set_env_value "${REPO_ROOT}/.env" "CACHE_STORE" "file"
    set_env_value "${REPO_ROOT}/.env" "QUEUE_CONNECTION" "sync"

    mkdir -p "${REPO_ROOT}/database"
    if [ ! -f "${PBX3_SQLITE_PATH}" ]; then
        echo "PBX3 sqlite database not found at ${PBX3_SQLITE_PATH}" >&2
        echo "Set PBX3_SQLITE_PATH to the correct sqlite.db path and rerun installer." >&2
        exit 1
    fi

    pbx3_db_dir="$(dirname "${PBX3_SQLITE_PATH}")"
    chown www-data:www-data "${pbx3_db_dir}" "${PBX3_SQLITE_PATH}"
    chmod 775 "${pbx3_db_dir}"
    chmod 664 "${PBX3_SQLITE_PATH}"

    ln -sfn "${PBX3_SQLITE_PATH}" "${REPO_ROOT}/database/database.sqlite"

    mkdir -p "${REPO_ROOT}/storage/logs" "${REPO_ROOT}/bootstrap/cache"
    chown -R root:root "${REPO_ROOT}"
    chown -R www-data:www-data "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache"
    find "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache" -type d -exec chmod 775 {} \;
    find "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache" -type f -exec chmod 664 {} \;
    chown root:www-data "${REPO_ROOT}/.env"
    chmod 664 "${REPO_ROOT}/.env"

    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- test -r "${PBX3_SQLITE_PATH}" || {
            echo "www-data cannot read sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
        runuser -u www-data -- test -w "${PBX3_SQLITE_PATH}" || {
            echo "www-data cannot write sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
    else
        su -s /bin/sh www-data -c "test -r '${PBX3_SQLITE_PATH}'" || {
            echo "www-data cannot read sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
        su -s /bin/sh www-data -c "test -w '${PBX3_SQLITE_PATH}'" || {
            echo "www-data cannot write sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
    fi

    if ! command -v php >/dev/null 2>&1; then
        return
    fi

    echo "Running Laravel setup commands"
    cd "${REPO_ROOT}"
    php artisan key:generate --force || true

    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan config:clear || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan cache:clear || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan route:clear || true"
    else
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan config:clear || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan cache:clear || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan route:clear || true"
    fi
}

install_runtime_packages
install_php_deps
ensure_snakeoil_cert
bootstrap_laravel_app

# Default to the current clone path so local testing works.
# Override APP_ROOT/SOURCE_CONF/PHP_FPM_SERVICE/PHP_FPM_SOCKET/PHP_VERSION/PBX3_SQLITE_PATH as needed.
APP_ROOT="${APP_ROOT:-${REPO_ROOT}}" \
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}" \
PHP_FPM_SERVICE="${PHP_FPM_SERVICE}" \
PHP_FPM_SOCKET="${PHP_FPM_SOCKET}" \
PBX3_SQLITE_PATH="${PBX3_SQLITE_PATH}" \
sh "${SCRIPT_DIR}/install-nginx-site.sh"

echo "pbx3api installer completed"
