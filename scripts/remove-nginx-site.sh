#!/bin/sh
set -eu

SITE_NAME="${SITE_NAME:-pbx3-api.conf}"
TARGET_AVAILABLE="/etc/nginx/sites-available/${SITE_NAME}"
TARGET_ENABLED="/etc/nginx/sites-enabled/${SITE_NAME}"

rm -f "${TARGET_ENABLED}"

if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx >/dev/null 2>&1 || true
fi

echo "Removed nginx site symlink: ${TARGET_ENABLED}"
echo "Kept site definition in place: ${TARGET_AVAILABLE}"
