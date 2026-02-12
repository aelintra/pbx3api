#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

echo "Installing pbx3api nginx site on port 44300"

# Default to the current clone path so local testing works.
# Override APP_ROOT/SOURCE_CONF/PHP_FPM_SERVICE as needed.
APP_ROOT="${APP_ROOT:-${REPO_ROOT}}" \
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}" \
sh "${SCRIPT_DIR}/install-nginx-site.sh"

echo "pbx3api installer completed"
