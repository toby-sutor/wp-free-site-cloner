#!/usr/bin/env bash
# Seeds the "source" site with realistic content: posts/pages with absolute
# URLs, real media library images, a long-path upload (>120 and >255 chars),
# a 30 MB binary upload, serialized/JSON/object options, user meta, a UTF-8
# multibyte title, plus the twentytwentyfour theme and classic-editor plugin
# so file migration of themes/plugins is exercised too.
#
# Run after up.sh. Not idempotent: intended to run once against a fresh
# source site.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
# shellcheck disable=SC1091
source ./lib.sh

NAME=source
WEB="$(web_container "$NAME")"
SITE="${SITE_URL[$NAME]}"

log "seeding $NAME ($SITE)"

# --- media library images -------------------------------------------------
IMG_DIR="$WORK_DIR/seed-images"
mkdir -p "$IMG_DIR"
python3 gen-test-image.py "$IMG_DIR/fsc-seed-1.png" 64 48 200 60 60
python3 gen-test-image.py "$IMG_DIR/fsc-seed-2.png" 96 96 60 160 60
python3 gen-test-image.py "$IMG_DIR/fsc-seed-3.png" 32 128 60 60 200

podman exec --user www-data "$WEB" mkdir -p /var/www/html/wp-content/uploads/fsc-seed
for f in fsc-seed-1 fsc-seed-2 fsc-seed-3; do
  podman cp "$IMG_DIR/$f.png" "$WEB:/var/www/html/wp-content/uploads/fsc-seed/$f.png"
done
podman exec "$WEB" chown -R www-data:www-data /var/www/html/wp-content/uploads/fsc-seed

for f in fsc-seed-1 fsc-seed-2 fsc-seed-3; do
  log "importing media $f.png"
  site_wp "$NAME" media import "/var/www/html/wp-content/uploads/fsc-seed/$f.png" \
    --title="FSC Seed Image $f" --porcelain >/dev/null
done

# --- long-path uploads ------------------------------------------------------
# One relative path clearly over 120 chars, one clearly over 255, both under
# wp-content/uploads, to exercise the tar writer's PAX long-name support.
SEG60=$(printf 'a%.0s' $(seq 1 60))
SEG100=$(printf 'b%.0s' $(seq 1 100))
REL120="fsc-long-path-test/${SEG60}-marker-a/${SEG60}-file-a.txt"
REL255="fsc-long-path-test/${SEG100}-dir-b/${SEG100}-dir-b2/${SEG100}-file-b.txt"

log "long path 1: ${#REL120} chars"
log "long path 2: ${#REL255} chars"

podman exec --user www-data "$WEB" sh -c "
  mkdir -p \"/var/www/html/wp-content/uploads/\$(dirname '$REL120')\" &&
  echo 'fsc long path test file (>120 chars)' > \"/var/www/html/wp-content/uploads/$REL120\" &&
  mkdir -p \"/var/www/html/wp-content/uploads/\$(dirname '$REL255')\" &&
  echo 'fsc long path test file (>255 chars)' > \"/var/www/html/wp-content/uploads/$REL255\"
"

# Persist the exact relative paths so assert.sh does not have to recompute
# the same generated strings independently.
{
  printf '%s\n' "$REL120"
  printf '%s\n' "$REL255"
} > "$WORK_DIR/seed-long-paths.txt"

# --- 30 MB binary upload ----------------------------------------------------
log "writing 30 MB binary upload (this takes a few seconds)"
podman exec --user www-data "$WEB" sh -c \
  "mkdir -p /var/www/html/wp-content/uploads/fsc-large && \
   head -c 31457280 /dev/urandom > /var/www/html/wp-content/uploads/fsc-large/fsc-bigfile-30mb.bin"

# --- posts, options, user meta (real WP serialization via wp eval-file) ----
podman cp seed-data.php "$WEB:/var/www/html/wp-content/fsc-seed-data.php"
site_wp "$NAME" eval-file /var/www/html/wp-content/fsc-seed-data.php
podman exec "$WEB" rm -f /var/www/html/wp-content/fsc-seed-data.php

# --- theme: twentytwentyfour (may or may not ship with core depending on
# the WP version bundled in the image; install is a no-op if already there)
if ! site_wp "$NAME" theme is-installed twentytwentyfour >/dev/null 2>&1; then
  log "installing theme twentytwentyfour"
  site_wp "$NAME" theme install twentytwentyfour
fi
site_wp "$NAME" theme activate twentytwentyfour

# --- plugin: classic-editor, to verify plugin files migrate -----------------
if ! site_wp "$NAME" plugin is-installed classic-editor >/dev/null 2>&1; then
  log "installing plugin classic-editor"
  site_wp "$NAME" plugin install classic-editor
fi
site_wp "$NAME" plugin activate classic-editor

log "seed complete for $NAME"
site_wp "$NAME" post list --field=post_title
