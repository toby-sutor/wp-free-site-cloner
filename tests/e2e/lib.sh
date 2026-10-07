#!/usr/bin/env bash
# Shared helpers for the WP Free Site Cloner e2e podman harness.
# Source this file, do not execute it directly:
#   source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
#
# All podman calls go through the host (this runs inside a flatpak sandbox;
# podman only exists on the host). Env vars do not cross flatpak-spawn, so
# any env var a podman/curl call needs must be passed with --env=FOO=bar or
# baked into the command line.

set -uo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$E2E_DIR/../.." && pwd)"
WORK_DIR="$E2E_DIR/.work"
mkdir -p "$WORK_DIR"

PLUGIN_SLUG="wp-free-site-cloner"
PLUGIN_MAIN="wp-free-site-cloner.php"

# Images are pinned by digest (the tag each digest was taken from is in the
# comment). To update: pull the tag, then
#   podman image inspect --format '{{index .RepoDigests 0}}' <image:tag>
MARIADB_IMAGE="docker.io/library/mariadb@sha256:5ae7fc7b20f8e07c0ee169ff24617b39cb99b4bfc147555cea758baa69834d9e" # mariadb:10.11
WP_CLI_IMAGE="docker.io/library/wordpress@sha256:07e56f9242a5c5e194bb0191c0f95a07fd1eefe712a231fc444d67eb51bb01e8" # wordpress:cli

# --- site registry -----------------------------------------------------
# shellcheck disable=SC2034 # used by up.sh/down.sh/other scripts that source this file
SITE_NAMES=(source dest dest74)

declare -A SITE_URL=(
  [source]="http://127.0.0.1:8081"
  [dest]="http://127.0.0.1:8082"
  [dest74]="http://127.0.0.1:8083"
)
declare -A SITE_PORT=(
  [source]=8081
  [dest]=8082
  [dest74]=8083
)
declare -A SITE_PHP=(
  [source]=8.3
  [dest]=8.3
  [dest74]=7.4
)
declare -A SITE_PREFIX=(
  [source]="wp_"
  [dest]="dst_"
  [dest74]="dst_"
)
declare -A SITE_ADMIN_USER=(
  [source]="admin"
  [dest]="destadmin"
  [dest74]="dest74admin"
)
declare -A SITE_ADMIN_PASS=(
  [source]="admin-pass-123"
  [dest]="dest-admin-456"
  [dest74]="dest74-admin-789"
)

# --- logging -------------------------------------------------------------
log() { printf '[e2e] %s\n' "$*" >&2; }
err() { printf '[e2e] ERROR: %s\n' "$*" >&2; }

# --- podman wrapper --------------------------------------------------------
# "podman" here is a literal argument to flatpak-spawn, not a recursive call.
podman() {
  flatpak-spawn --host podman "$@"
}

# --- naming helpers --------------------------------------------------------
pod_name()      { printf 'fsc-e2e-%s' "$1"; }
db_container()  { printf 'fsc-e2e-%s-db' "$1"; }
web_container() { printf 'fsc-e2e-%s-web' "$1"; }
db_vol()        { printf 'fsc-e2e-%s-db-vol' "$1"; }
web_vol()       { printf 'fsc-e2e-%s-web-vol' "$1"; }

wp_image_for_php() {
  case "$1" in
    8.3) printf 'docker.io/library/wordpress@sha256:4abf7a450ee477dde967584f8174d7e03221d224c4971a0c38d84e7254426e64' ;; # wordpress:php8.3-apache
    7.4) printf 'docker.io/library/wordpress@sha256:7e46cf3373751b6d62b7a0fc3a7d6686f641a34a2a0eb18947da5375c55fd009' ;; # wordpress:php7.4-apache
    *)
      err "unsupported php version: $1"
      return 1
      ;;
  esac
}

# --- readiness waits -------------------------------------------------------
_wait_for_db() {
  local name="$1" db i
  db="$(db_container "$name")"
  # shellcheck disable=SC2034 # loop counter, only used to bound the retry count
  for i in $(seq 1 90); do
    if podman exec "$db" mysqladmin ping -uroot -prootpass --silent >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  err "$name: db did not become ready in time"
  return 1
}

_wait_for_http() {
  local name="$1" port="$2" code i
  # shellcheck disable=SC2034 # loop counter, only used to bound the retry count
  for i in $(seq 1 90); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://127.0.0.1:${port}/" 2>/dev/null || true)"
    if [ -n "$code" ] && [ "$code" != "000" ]; then
      return 0
    fi
    sleep 2
  done
  err "$name: http did not respond in time on port $port"
  return 1
}

# --- core site lifecycle ----------------------------------------------------
# site_up <name> <port> <php-version>
# Brings up a mariadb + wordpress:phpX-apache pod, waits for readiness,
# fixes wp-content ownership, and runs wp core install if not already done.
site_up() {
  local name="$1" port="$2" phpver="$3"
  local prefix="${SITE_PREFIX[$name]:-wp_}"
  local pod db web dbvol webvol image
  pod="$(pod_name "$name")"
  db="$(db_container "$name")"
  web="$(web_container "$name")"
  dbvol="$(db_vol "$name")"
  webvol="$(web_vol "$name")"
  image="$(wp_image_for_php "$phpver")" || return 1

  if podman pod exists "$pod" 2>/dev/null; then
    log "$name: pod already exists"
  else
    log "$name: creating pod $pod (127.0.0.1:${port} -> 80)"
    podman pod create --name "$pod" -p "127.0.0.1:${port}:80" >/dev/null
  fi

  podman volume create "$dbvol" >/dev/null 2>&1 || true
  podman volume create "$webvol" >/dev/null 2>&1 || true

  if ! podman container exists "$db" 2>/dev/null; then
    log "$name: starting db ($db)"
    podman run -d --name "$db" --pod "$pod" \
      -e MARIADB_DATABASE=wordpress \
      -e MARIADB_USER=wpuser \
      -e MARIADB_PASSWORD=wppass \
      -e MARIADB_ROOT_PASSWORD=rootpass \
      -v "$dbvol":/var/lib/mysql \
      "$MARIADB_IMAGE" >/dev/null
  fi

  if ! podman container exists "$web" 2>/dev/null; then
    log "$name: starting web ($web, $image, prefix=$prefix)"
    podman run -d --name "$web" --pod "$pod" \
      -e WORDPRESS_DB_HOST=127.0.0.1 \
      -e WORDPRESS_DB_USER=wpuser \
      -e WORDPRESS_DB_PASSWORD=wppass \
      -e WORDPRESS_DB_NAME=wordpress \
      -e WORDPRESS_TABLE_PREFIX="$prefix" \
      -v "$webvol":/var/www/html \
      "$image" >/dev/null
  fi

  _wait_for_db "$name" || return 1
  _wait_for_http "$name" "$port" || return 1

  # Named volumes start out root-owned; the apache image's www-data (uid 33)
  # needs write access to wp-content (uploads, cache, etc).
  podman exec "$web" chown -R www-data:www-data /var/www/html/wp-content >/dev/null 2>&1 || true

  if ! site_wp "$name" core is-installed >/dev/null 2>&1; then
    log "$name: running wp core install"
    site_wp "$name" core install \
      --url="${SITE_URL[$name]}" \
      --title="FSC E2E $name" \
      --admin_user="${SITE_ADMIN_USER[$name]}" \
      --admin_password="${SITE_ADMIN_PASS[$name]}" \
      --admin_email="e2e-${name}@example.test" \
      --skip-email
  else
    log "$name: wp already installed"
  fi

  # `wp core install` runs via site_wp's wp-cli sidecar, which is --user
  # root (wp-cli refuses to run as its container's own root user otherwise).
  # Installing creates wp-content/uploads on first run, so that directory
  # (and anything else touched during install) ends up root-owned despite
  # the chown above having already run - the plugin's importer then can't
  # write into it (fails partway through the files phase with "Cannot
  # write ... (permissions?)"). Chown again now that install has run.
  podman exec "$web" chown -R www-data:www-data /var/www/html/wp-content >/dev/null 2>&1 || true
}

# site_down <name>
site_down() {
  local name="$1" pod db web dbvol webvol
  pod="$(pod_name "$name")"
  db="$(db_container "$name")"
  web="$(web_container "$name")"
  dbvol="$(db_vol "$name")"
  webvol="$(web_vol "$name")"
  log "$name: tearing down"
  podman pod rm -f "$pod" >/dev/null 2>&1 || true
  podman rm -f "$db" "$web" >/dev/null 2>&1 || true
  podman volume rm -f "$dbvol" "$webvol" >/dev/null 2>&1 || true
}

# site_wp <name> <wp-cli args...>
# Runs wp-cli against the site's shared webroot volume, in the site's pod
# (so 127.0.0.1 reaches the db container). wp-config.php in the official
# docker wordpress image reads DB_* via getenv_docker() AT RUNTIME (it is
# the same dynamic wp-config-docker.php for every container, not a static
# generated file), so every wp-cli invocation must pass the same
# WORDPRESS_DB_* / WORDPRESS_TABLE_PREFIX env vars the web container got,
# or it falls back to host "mysql" and fails to connect.
#
# Runs as root unless SITE_WP_USER is set (e.g. SITE_WP_USER=33:33 for
# drive.php imports, so the files they write stay writable by www-data).
# Also bind-mounts the plugin repo at the same wp-content/plugins/<slug>
# path site_mount_plugin() uses for the web (Apache) container: wp-cli runs
# in its own throwaway container that only mounts the webroot volume, so
# without this mirror mount `wp plugin activate` (and `wp eval-file` against
# the plugin's own tests/e2e/drive.php) can never see the plugin - it does
# not exist anywhere inside the webroot volume, only in this bind mount.
site_wp() {
  local name="$1"
  shift
  local pod webvol prefix
  pod="$(pod_name "$name")"
  webvol="$(web_vol "$name")"
  prefix="${SITE_PREFIX[$name]:-wp_}"
  podman run --rm -i --pod "$pod" \
    -e WORDPRESS_DB_HOST=127.0.0.1 \
    -e WORDPRESS_DB_USER=wpuser \
    -e WORDPRESS_DB_PASSWORD=wppass \
    -e WORDPRESS_DB_NAME=wordpress \
    -e WORDPRESS_TABLE_PREFIX="$prefix" \
    -v "$webvol":/var/www/html \
    -v "$REPO_ROOT":/var/www/html/wp-content/plugins/$PLUGIN_SLUG:ro,z \
    -w /var/www/html \
    --user "${SITE_WP_USER:-root}" \
    "$WP_CLI_IMAGE" --allow-root "$@"
}

# site_mount_plugin <name>
# (Re)creates the web container with the plugin repo bind-mounted read-only
# at wp-content/plugins/<slug>, then tries to activate it. Safe to call more
# than once (e.g. after the plugin code changes on disk you do NOT need to
# call this again - it's a live bind mount; only call again if the container
# itself was recreated). Activation failure is logged, not fatal: the plugin
# may not exist yet if this runs before the plugin agent has committed it.
site_mount_plugin() {
  local name="$1"
  local pod web webvol image phpver port prefix
  pod="$(pod_name "$name")"
  web="$(web_container "$name")"
  webvol="$(web_vol "$name")"
  phpver="${SITE_PHP[$name]}"
  port="${SITE_PORT[$name]}"
  prefix="${SITE_PREFIX[$name]:-wp_}"
  image="$(wp_image_for_php "$phpver")" || return 1

  log "$name: (re)creating web container with plugin bind-mounted"
  podman rm -f "$web" >/dev/null 2>&1 || true
  podman run -d --name "$web" --pod "$pod" \
    -e WORDPRESS_DB_HOST=127.0.0.1 \
    -e WORDPRESS_DB_USER=wpuser \
    -e WORDPRESS_DB_PASSWORD=wppass \
    -e WORDPRESS_DB_NAME=wordpress \
    -e WORDPRESS_TABLE_PREFIX="$prefix" \
    -v "$webvol":/var/www/html \
    -v "$REPO_ROOT":/var/www/html/wp-content/plugins/$PLUGIN_SLUG:ro,z \
    "$image" >/dev/null

  _wait_for_http "$name" "$port" || return 1

  if [ ! -f "$REPO_ROOT/$PLUGIN_MAIN" ]; then
    log "$name: $PLUGIN_MAIN not present yet in repo root, skipping activation"
    return 0
  fi

  if site_wp "$name" plugin activate "$PLUGIN_SLUG"; then
    log "$name: plugin activated"
  else
    err "$name: plugin activate failed (see output above)"
    return 1
  fi
}
