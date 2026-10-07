#!/usr/bin/env bash
# Produce real foreign-plugin export archives from the e2e "source" site, for
# format research and importer fixtures:
#   - All-in-One WP Migration (.wpress)
#   - Duplicator Lite, DupArchive mode (.daf) and ZipArchive mode (.zip)
#
# Standalone: NOT called by up.sh / seed.sh / assert.sh. Needs the source site
# to be up (./up.sh && ./seed.sh). Installs and activates the plugins
# "all-in-one-wp-migration" and "duplicator" on the source site and leaves them
# active.
#
# Both exports skip wp-content/plugins/wp-free-site-cloner (the bind-mounted
# repo: .git, vendor/, tests/); ai1wm also skips the Duplicator backup dirs.
#
# Archives are written OUTSIDE the repo, to $SAMPLES_DIR (default below), and
# are removed from the site afterwards so a later export does not pick up the
# previous run's archives.
#
# Usage: ./samples.sh [ai1wm|duplicator|all]   (default: all)

# shellcheck disable=SC1091
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

SAMPLES_DIR="${SAMPLES_DIR:-${FSC_E2E_DATA:-$HOME/.cache/wp-free-site-cloner-e2e}/samples}"
SITE=source
WEB="$(web_container "$SITE")"
WP_CONTENT=/var/www/html/wp-content
URL="${SITE_URL[$SITE]}"
WHAT="${1:-all}"

mkdir -p "$SAMPLES_DIR"
PRODUCED=()

# wp-cli runs as root in a separate container; keep wp-content writable for
# www-data. The plugin repo bind mount is read-only, so ignore its errors.
fix_perms() {
  podman exec "$WEB" chown -R www-data:www-data "$WP_CONTENT" >/dev/null 2>&1 || true
}

ensure_plugin() {
  local slug="$1"
  if site_wp "$SITE" plugin is-installed "$slug" >/dev/null 2>&1; then
    site_wp "$SITE" plugin activate "$slug" >/dev/null 2>&1 || true
  else
    log "installing $slug"
    site_wp "$SITE" plugin install "$slug" --activate >/dev/null || {
      err "plugin install failed: $slug"
      return 1
    }
  fi
  fix_perms
}

# copy_out <path-in-container>: copy a file to $SAMPLES_DIR, then delete it
# from the site.
copy_out() {
  local src="$1" base
  base="$(basename "$src")"
  podman cp "$WEB:$src" "$SAMPLES_DIR/$base" || {
    err "podman cp failed: $src"
    return 1
  }
  podman exec "$WEB" rm -f "$src"
  PRODUCED+=("$SAMPLES_DIR/$base")
}

# --- All-in-One WP Migration ---------------------------------------------
# Excluded (paths relative to wp-content): other plugins' backup dirs, and the
# bind-mounted plugin repo (it carries .git, vendor/ and tests/).
AI1WM_EXCLUDE="duplicator-backups,backups-dup-lite,plugins/$PLUGIN_SLUG"

run_ai1wm() {
  local key archive
  ensure_plugin all-in-one-wp-migration || return 1
  key="$(site_wp "$SITE" option get ai1wm_secret_key 2>/dev/null | tr -d '\r\n')"
  if [ -z "$key" ]; then
    err "ai1wm: option ai1wm_secret_key is empty"
    return 1
  fi
  log "ai1wm: driving export over admin-ajax.php"
  archive="$(python3 "$E2E_DIR/samples_ai1wm.py" "$URL" "$key" "$AI1WM_EXCLUDE")" || {
    err "ai1wm: export failed"
    return 1
  }
  copy_out "$WP_CONTENT/ai1wm-backups/$archive"
}

# --- Duplicator ----------------------------------------------------------
# PHP snippets run via wp eval. Duplicator 5.x has no WP-CLI command; its
# BackupRequestService queues a background build and the build is advanced by
# the nopriv admin-ajax action duplicator_process_worker.

# Server detection normally runs from wp-cron on a web hit. Run it directly so
# the build can start; the loopback probe fails in this pod (the site URL port
# is only published on the host), which switches Duplicator to client-side
# kickoff, i.e. the worker is driven by requests from outside (this script).
DUP_PHP_DETECT='
if (!\Duplicator\Utils\AsyncSetupActions::isServerDetected()) {
    \Duplicator\Utils\AsyncSetupActions::runDetection();
}
echo \Duplicator\Utils\AsyncSetupActions::isServerDetected() ? "detected" : "not-detected";'

DUP_PHP_RUNNING='echo \Duplicator\Package\DupPackage::isPackageRunning() ? "running" : "idle";'

# Queue one build in the given archive mode (2 = ZipArchive, 3 = DupArchive).
# - BackupRequestService refuses to queue while in client-side kickoff mode,
#   so the kickoff override is forced to "server" just for the request.
# - The default template gets a directory filter for the bind-mounted plugin
#   repo (copied into the package at creation).
# Kickoff override and template filters are restored right after the request.
# The global build mode is read when the build starts, so it is left set and
# restored by dup_php_set_mode after the build.
# Prints "ID <package id> <name hash> <previous build mode>" or "ERROR ...".
dup_php_request() {
  cat <<PHP
use Duplicator\Package\ClientSideKick as C;
use Duplicator\Models\DynamicGlobalEntity as D;
use Duplicator\Models\GlobalEntity as G;
use Duplicator\Models\TemplateEntity as T;
\$g = G::getInstance();
\$oldMode = \$g->getBuildMode();
\$d = D::getInstance();
\$oldOverride = \$d->getValString(C::KICKOFF_OVERRIDE_KEY);
\$t = T::getDefaultTemplate();
\$oldFilterOn = \$t->archive_filter_on;
\$oldFilterDirs = \$t->archive_filter_dirs;
\$t->archive_filter_on = true;
\$t->archive_filter_dirs = WP_CONTENT_DIR . "/plugins/$PLUGIN_SLUG";
\$t->save();
\$g->setBuildMode($1);
\$d->setValString(C::KICKOFF_OVERRIDE_KEY, "server", true);
\$r = (new \Duplicator\Package\BackupRequestService())->request("fsc-e2e-samples", "format sample");
if (\$oldOverride === "") { \$d->removeVal(C::KICKOFF_OVERRIDE_KEY, true); } else { \$d->setValString(C::KICKOFF_OVERRIDE_KEY, \$oldOverride, true); }
\$t->archive_filter_on = \$oldFilterOn;
\$t->archive_filter_dirs = \$oldFilterDirs;
\$t->save();
if (is_wp_error(\$r)) {
    \$g->setBuildMode(\$oldMode);
    echo "ERROR " . \$r->get_error_code() . ": " . \$r->get_error_message();
} else {
    echo "ID " . \$r . " " . \Duplicator\Package\DupPackage::getById(\$r)->getNameHash() . " " . \$oldMode;
}
PHP
}

dup_php_set_mode() {
  site_wp "$SITE" eval "\Duplicator\Models\GlobalEntity::getInstance()->setBuildMode($1);" >/dev/null 2>&1
}

dup_status() {
  site_wp "$SITE" eval "\$x = (new \Duplicator\Package\BackupRequestService())->getStatus($1); echo is_wp_error(\$x) ? 'failed' : \$x['status'];" 2>/dev/null | tr -d '\r\n'
}

dup_kick() {
  curl -s -o /dev/null --max-time 300 "$URL/wp-admin/admin-ajax.php?action=duplicator_process_worker" || true
}

# A build that is already running (started by anyone) holds Duplicator's
# process lock and blocks a new request. Drive the worker until it is done.
dup_drain() {
  local i st
  for i in $(seq 1 100); do
    st="$(site_wp "$SITE" eval "$DUP_PHP_RUNNING" 2>/dev/null | tr -d '\r\n')"
    [ "$st" = running ] || return 0
    [ "$i" -eq 1 ] && log "duplicator: another build is running, driving it to completion first"
    dup_kick
    dup_kick
    dup_kick
  done
  err "duplicator: a running build did not finish"
  return 1
}

# run_dup_build <mode> <ext>
run_dup_build() {
  local mode="$1" ext="$2" out id hash oldmode i st f
  dup_drain || return 1
  out="$(site_wp "$SITE" eval "$(dup_php_request "$mode")" 2>&1 | tail -1)"
  fix_perms
  case "$out" in
    ID\ *)
      read -r _ id hash oldmode <<<"$out"
      ;;
    *)
      err "duplicator: request failed: $out"
      return 1
      ;;
  esac
  log "duplicator: build $id ($hash) queued, driving process worker"
  st=queued
  for i in $(seq 1 200); do
    dup_kick
    if [ $((i % 3)) -eq 0 ]; then
      st="$(dup_status "$id")"
      case "$st" in complete | failed | cancelled | missing) break ;; esac
    fi
  done
  fix_perms
  dup_php_set_mode "$oldmode"
  if [ "$st" != complete ]; then
    err "duplicator: build $id ended with status '$st'"
    return 1
  fi
  if ! podman exec "$WEB" test -f "$WP_CONTENT/duplicator-backups/${hash}_archive.$ext"; then
    err "duplicator: build $id did not produce ${hash}_archive.$ext (build mode changed by another process?):"
    podman exec "$WEB" sh -c "ls $WP_CONTENT/duplicator-backups/ | grep '$hash'" >&2
    return 1
  fi
  for f in "${hash}_archive.$ext" "${hash}_installer.php.bak"; do
    copy_out "$WP_CONTENT/duplicator-backups/$f" || return 1
  done
}

run_duplicator() {
  local det
  ensure_plugin duplicator || return 1
  det="$(site_wp "$SITE" eval "$DUP_PHP_DETECT" 2>&1 | tail -1)"
  fix_perms
  if [ "$det" != detected ]; then
    err "duplicator: server detection did not complete: $det"
    return 1
  fi
  run_dup_build 3 daf || return 1
  run_dup_build 2 zip || return 1
}

# --- main ------------------------------------------------------------------
rc=0
case "$WHAT" in
  ai1wm) run_ai1wm || rc=1 ;;
  duplicator) run_duplicator || rc=1 ;;
  all)
    run_ai1wm || rc=1
    run_duplicator || rc=1
    ;;
  *)
    err "usage: $0 [ai1wm|duplicator|all]"
    exit 2
    ;;
esac

echo
echo "Plugin versions:"
site_wp "$SITE" plugin list --fields=name,status,version 2>/dev/null |
  grep -E '^(name|all-in-one-wp-migration|duplicator)[[:space:]]' 
echo
echo "Produced files:"
for f in "${PRODUCED[@]}"; do
  printf '%12s  %s\n' "$(stat -c %s "$f")" "$f"
done
exit "$rc"
