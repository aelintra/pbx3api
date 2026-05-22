#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

SITE_NAME="${SITE_NAME:-pbx3-api.conf}"
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}"
ACME_SITE_NAME="${ACME_SITE_NAME:-pbx3-acme-http.conf}"
ACME_SOURCE="${ACME_SOURCE:-${REPO_ROOT}/config/nginx/pbx3-acme-http.conf}"
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

# Ubuntu's stock site also uses listen 80 default_server; only one allowed.
DEFAULT_SITE="/etc/nginx/sites-enabled/default"
if [ -e "${DEFAULT_SITE}" ] && [ -f "${ACME_SOURCE}" ]; then
	rm -f "${DEFAULT_SITE}"
	echo "Removed ${DEFAULT_SITE} (pbx3-acme-http.conf is default_server on :80)"
fi

if [ -f "${ACME_SOURCE}" ]; then
	ACME_AVAILABLE="/etc/nginx/sites-available/${ACME_SITE_NAME}"
	ACME_ENABLED="/etc/nginx/sites-enabled/${ACME_SITE_NAME}"
	cp "${ACME_SOURCE}" "${ACME_AVAILABLE}"
	ln -sfn "${ACME_AVAILABLE}" "${ACME_ENABLED}"
	mkdir -p /opt/pbx3/var/acme-challenge
	chown "${APP_USER}:${APP_GROUP}" /opt/pbx3/var/acme-challenge 2>/dev/null || true
	chmod 755 /opt/pbx3/var/acme-challenge
	echo "Installed nginx ACME site: ${ACME_AVAILABLE}"
fi

nginx -t

systemctl enable nginx >/dev/null 2>&1 || true
systemctl enable "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
systemctl start "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true

echo "Installed nginx site: ${TARGET_AVAILABLE}"
