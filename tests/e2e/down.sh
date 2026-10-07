#!/usr/bin/env bash
# Tears down all three e2e sites: pods, containers and volumes.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
# shellcheck disable=SC1091
source ./lib.sh

for name in "${SITE_NAMES[@]}"; do
  site_down "$name"
done

log "all sites down"
