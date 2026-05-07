#!/usr/bin/env bash
set -euo pipefail

# Optional cPanel post-update reapply hook.
# Copy to /scripts/postupcp on server to auto-reinstall plugin files after updates.

REPO_DIR="${REPO_DIR:-/root/outboundmail}"
if [[ -x "${REPO_DIR}/install.sh" ]]; then
  "${REPO_DIR}/install.sh" --skip-db || true
fi
