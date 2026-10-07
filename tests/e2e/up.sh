#!/usr/bin/env bash
# Brings up all three e2e sites (source, dest, dest74), fresh WP installs,
# and mounts the plugin under test into each. Idempotent: safe to re-run.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
# shellcheck disable=SC1091
source ./lib.sh

for name in "${SITE_NAMES[@]}"; do
  site_up "$name" "${SITE_PORT[$name]}" "${SITE_PHP[$name]}"
done

for name in "${SITE_NAMES[@]}"; do
  site_mount_plugin "$name" || true
done

log "all sites up"
for name in "${SITE_NAMES[@]}"; do
  log "$name: ${SITE_URL[$name]}  admin=${SITE_ADMIN_USER[$name]}  pass=${SITE_ADMIN_PASS[$name]}  prefix=${SITE_PREFIX[$name]}  php=${SITE_PHP[$name]}"
done
