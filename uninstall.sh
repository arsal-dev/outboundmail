#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/addons/outboundmail"
BIN_TARGET="/usr/local/bin/log_outbound_mail.php"
CONF_TARGET="/etc/outboundmail_db.conf"
APP_CONF="${PLUGIN_DIR}/plugin.conf"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

echo "[1/3] Unregistering WHM plugin..."
if [[ -f "${APP_CONF}" ]]; then
  /usr/local/cpanel/bin/unregister_appconfig "${APP_CONF}" || true
fi
/scripts/rebuild_whmconf || true

echo "[2/3] Removing installed files..."
rm -rf "${PLUGIN_DIR}"
rm -f "${BIN_TARGET}"
rm -f "${CONF_TARGET}"

echo "[3/3] Done."
echo "Database and Exim config were not removed automatically."
