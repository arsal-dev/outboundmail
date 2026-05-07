#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PLUGIN_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/addons/outboundmail"
BIN_TARGET="/usr/local/bin/log_outbound_mail.php"
CONF_TARGET="/etc/outboundmail_db.conf"
APP_CONF="${PLUGIN_DIR}/plugin.conf"

MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-}"
OBM_DB_NAME="${OBM_DB_NAME:-outbound_mail}"
OBM_DB_USER="${OBM_DB_USER:-obm}"
OBM_DB_PASS="${OBM_DB_PASS:-CHANGE_ME_STRONG_PASSWORD}"

usage() {
  cat <<EOF
Usage: sudo ./install.sh [options]

Options:
  --db-pass <password>      Database password for ${OBM_DB_USER}@localhost
  --mysql-root-user <user>  MySQL root/admin user (default: root)
  --mysql-root-pass <pass>  MySQL root/admin password (optional)
  --skip-db                 Skip database setup
  -h, --help                Show help
EOF
}

SKIP_DB=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --db-pass) OBM_DB_PASS="$2"; shift 2 ;;
    --mysql-root-user) MYSQL_ROOT_USER="$2"; shift 2 ;;
    --mysql-root-pass) MYSQL_ROOT_PASS="$2"; shift 2 ;;
    --skip-db) SKIP_DB=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Missing required command: $1" >&2; exit 1; }
}

require_cmd mysql
require_cmd install

if [[ ! -f "${PROJECT_ROOT}/plugin/index.php" ]]; then
  echo "Run from repository root where plugin/ exists." >&2
  exit 1
fi

mysql_exec() {
  local query="$1"
  if [[ -n "${MYSQL_ROOT_PASS}" ]]; then
    mysql -u"${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" -e "${query}"
  else
    mysql -u"${MYSQL_ROOT_USER}" -e "${query}"
  fi
}

if [[ ${SKIP_DB} -eq 0 ]]; then
  echo "[1/6] Configuring MySQL database and user..."
  mysql_exec "CREATE DATABASE IF NOT EXISTS \`${OBM_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql_exec "CREATE USER IF NOT EXISTS '${OBM_DB_USER}'@'localhost' IDENTIFIED BY '${OBM_DB_PASS}';"
  mysql_exec "ALTER USER '${OBM_DB_USER}'@'localhost' IDENTIFIED BY '${OBM_DB_PASS}';"
  mysql_exec "GRANT ALL PRIVILEGES ON \`${OBM_DB_NAME}\`.* TO '${OBM_DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
  if [[ -n "${MYSQL_ROOT_PASS}" ]]; then
    mysql -u"${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASS}" "${OBM_DB_NAME}" < "${PROJECT_ROOT}/sql/schema.sql"
  else
    mysql -u"${MYSQL_ROOT_USER}" "${OBM_DB_NAME}" < "${PROJECT_ROOT}/sql/schema.sql"
  fi
else
  echo "[1/6] Skipping database setup as requested..."
fi

echo "[2/6] Installing shared DB config..."
cat > "${CONF_TARGET}" <<EOF
<?php
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => '${OBM_DB_NAME}',
    'user' => '${OBM_DB_USER}',
    'pass' => '${OBM_DB_PASS}',
    'charset' => 'utf8mb4',
];
EOF
chown root:mailnull "${CONF_TARGET}"
chmod 640 "${CONF_TARGET}"

echo "[3/6] Installing Exim transport filter script..."
install -m 755 -o root -g mailnull "${PROJECT_ROOT}/bin/log_outbound_mail.php" "${BIN_TARGET}"

echo "[4/6] Installing WHM plugin files..."
mkdir -p "${PLUGIN_DIR}"
install -m 644 -o root -g root "${PROJECT_ROOT}/plugin/index.php" "${PLUGIN_DIR}/index.php"
install -m 644 -o root -g root "${PROJECT_ROOT}/plugin/style.css" "${PLUGIN_DIR}/style.css"
install -m 644 -o root -g root "${PROJECT_ROOT}/plugin/plugin.conf" "${PLUGIN_DIR}/plugin.conf"

echo "[5/6] Registering plugin in WHM..."
/usr/local/cpanel/bin/register_appconfig "${APP_CONF}" || true
/scripts/rebuild_whmconf || true

echo "[6/6] Install complete."
echo
echo "Next steps:"
echo "  1) Add Exim router/transport snippet from exim/exim_snippet.conf in WHM Exim Advanced Editor."
echo "  2) Rebuild/restart Exim."
echo "  3) Send a test email and verify in WHM > Plugins > Outbound Email Monitor."
