#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

SITE_NAME="${SITE_NAME:-pbx3-api.conf}"
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}"
TARGET_AVAILABLE="/etc/nginx/sites-available/${SITE_NAME}"
TARGET_ENABLED="/etc/nginx/sites-enabled/${SITE_NAME}"
APP_ROOT="${APP_ROOT:-${REPO_ROOT}}"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
PHP_FPM_SOCKET="${PHP_FPM_SOCKET:-/run/php/${PHP_FPM_SERVICE}.sock}"

mkdir -p "${APP_ROOT}/public"
chown -R "${APP_USER}:${APP_GROUP}" "${APP_ROOT}"

if [ ! -f "${SOURCE_CONF}" ]; then
    echo "Missing nginx site source: ${SOURCE_CONF}" >&2
    exit 1
fi

mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
cp "${SOURCE_CONF}" "${TARGET_AVAILABLE}"

# Align fastcgi socket with selected PHP-FPM service.
sed -i "s|^[[:space:]]*fastcgi_pass[[:space:]]\\+unix:[^;]*;|        fastcgi_pass unix:${PHP_FPM_SOCKET};|" "${TARGET_AVAILABLE}"

ln -sfn "${TARGET_AVAILABLE}" "${TARGET_ENABLED}"

nginx -t

systemctl enable nginx >/dev/null 2>&1 || true
systemctl enable "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
systemctl start "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true

echo "Installed nginx site: ${TARGET_AVAILABLE}"
