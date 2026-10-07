#!/usr/bin/env bash
# assert.sh <dest-name> <dest-url> <old-url>
#
# Checks that a migration into <dest-name> (a site known to lib.sh, e.g.
# "dest" or "dest74") succeeded: URLs rewritten, no leftover old-url in the
# DB, serialized data intact, uploads carried over, long-path file present,
# the *source* admin credentials work, front page renders. Prints PASS/FAIL
# per check and exits non-zero if anything failed.
#
# Sanity self-check: run against the source site itself with a dummy old
# url, e.g.:
#   ./assert.sh source http://127.0.0.1:8081 http://dummy-old-url.invalid
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
# shellcheck disable=SC1091
source ./lib.sh

if [ "$#" -ne 3 ]; then
  err "usage: assert.sh <dest-name> <dest-url> <old-url>"
  exit 2
fi

DEST_NAME="$1"
DEST_URL="${2%/}"
OLD_URL="${3%/}"
OLD_URL_JSON="${OLD_URL//\//\\/}"

# Fixed credentials the SOURCE site was installed with (see lib.sh); a
# successful migration must let the old admin log in on the destination.
SOURCE_ADMIN_USER="${SITE_ADMIN_USER[source]}"
SOURCE_ADMIN_PASS="${SITE_ADMIN_PASS[source]}"

# Same deterministic long-path strings seed.sh creates (kept in sync here so
# assert.sh does not depend on .work/seed-long-paths.txt surviving).
SEG60=$(printf 'a%.0s' $(seq 1 60))
SEG100=$(printf 'b%.0s' $(seq 1 100))
REL120="fsc-long-path-test/${SEG60}-marker-a/${SEG60}-file-a.txt"
REL255="fsc-long-path-test/${SEG100}-dir-b/${SEG100}-dir-b2/${SEG100}-file-b.txt"

PASS=0
FAIL=0

ok()   { PASS=$((PASS + 1)); printf 'PASS: %s\n' "$*"; }
bad()  { FAIL=$((FAIL + 1)); printf 'FAIL: %s\n' "$*"; }

DEST_WEB="$(web_container "$DEST_NAME")"
SOURCE_WEB="$(web_container source)"

log "asserting $DEST_NAME ($DEST_URL) against old url $OLD_URL"

# 1. siteurl / home ----------------------------------------------------------
got_siteurl="$(site_wp "$DEST_NAME" option get siteurl 2>/dev/null | tr -d '\r')"
got_siteurl="${got_siteurl%/}"
if [ "$got_siteurl" = "$DEST_URL" ]; then
  ok "siteurl == $DEST_URL"
else
  bad "siteurl expected $DEST_URL, got '$got_siteurl'"
fi

got_home="$(site_wp "$DEST_NAME" option get home 2>/dev/null | tr -d '\r')"
got_home="${got_home%/}"
if [ "$got_home" = "$DEST_URL" ]; then
  ok "home == $DEST_URL"
else
  bad "home expected $DEST_URL, got '$got_home'"
fi

# 2. no leftover old url (plain and JSON-escaped) in the DB -----------------
search_out="$(site_wp "$DEST_NAME" db search "$OLD_URL" --all-tables 2>/dev/null || true)"
if [ -z "$(printf '%s' "$search_out" | tr -d '[:space:]')" ]; then
  ok "no occurrences of old url in DB"
else
  bad "old url still present in DB:"
  printf '%s\n' "$search_out" | sed 's/^/     /'
fi

if [ "$OLD_URL_JSON" != "$OLD_URL" ]; then
  search_json_out="$(site_wp "$DEST_NAME" db search "$OLD_URL_JSON" --all-tables 2>/dev/null || true)"
  if [ -z "$(printf '%s' "$search_json_out" | tr -d '[:space:]')" ]; then
    ok "no occurrences of JSON-escaped old url in DB"
  else
    bad "JSON-escaped old url still present in DB:"
    printf '%s\n' "$search_json_out" | sed 's/^/     /'
  fi
fi

# 3. serialized options still unserialize -------------------------------
# fsc_test_serialized_widget and fsc_test_object_option are stored as real
# PHP arrays/objects (maybe_serialize'd by WP); wp-cli's --format=json must
# show a JSON object/array if unserialize succeeded.
for opt in fsc_test_serialized_widget fsc_test_object_option; do
  val="$(site_wp "$DEST_NAME" option get "$opt" --format=json 2>/dev/null || true)"
  first_char="$(printf '%s' "$val" | sed 's/^[[:space:]]*//' | cut -c1)"
  if [ "$first_char" = "{" ] || [ "$first_char" = "[" ]; then
    ok "option $opt still unserializes to a structured value"
  else
    bad "option $opt did not unserialize (got: $val)"
  fi
done

# fsc_test_json_option is deliberately a JSON STRING (not a PHP array), to
# exercise JSON-escaped-slash search/replace. Check it is still valid,
# parseable JSON containing "site".
json_val="$(site_wp "$DEST_NAME" option get fsc_test_json_option 2>/dev/null || true)"
if printf '%s' "$json_val" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "site" in d' >/dev/null 2>&1; then
  ok "option fsc_test_json_option is still valid, parseable JSON"
else
  bad "option fsc_test_json_option is not valid JSON (got: $json_val)"
fi

# 4. uploads file count and total bytes match source -------------------------
src_stats="$(podman exec "$SOURCE_WEB" sh -c \
  "find /var/www/html/wp-content/uploads -type f -printf '%s\n' 2>/dev/null | awk '{c++; s+=\$1} END{print c, s}'")"
dst_stats="$(podman exec "$DEST_WEB" sh -c \
  "find /var/www/html/wp-content/uploads -type f -printf '%s\n' 2>/dev/null | awk '{c++; s+=\$1} END{print c, s}'")"
src_count="${src_stats% *}"; src_bytes="${src_stats#* }"
dst_count="${dst_stats% *}"; dst_bytes="${dst_stats#* }"
if [ -n "$src_count" ] && [ "$src_count" = "$dst_count" ] && [ "$src_bytes" = "$dst_bytes" ]; then
  ok "uploads match source: $dst_count files, $dst_bytes bytes"
else
  bad "uploads mismatch: source=$src_count files/$src_bytes bytes dest=$dst_count files/$dst_bytes bytes"
fi

# 5. long-path files exist ----------------------------------------------------
if podman exec "$DEST_WEB" test -f "/var/www/html/wp-content/uploads/$REL120"; then
  ok "long path file present (${#REL120} chars)"
else
  bad "long path file missing (${#REL120} chars): $REL120"
fi
if podman exec "$DEST_WEB" test -f "/var/www/html/wp-content/uploads/$REL255"; then
  ok "long path file present (${#REL255} chars)"
else
  bad "long path file missing (${#REL255} chars): $REL255"
fi

# 6. source admin login works on dest ----------------------------------------
login_headers="$(curl -s -D - -o /dev/null --max-time 10 \
  --data-urlencode "log=${SOURCE_ADMIN_USER}" \
  --data-urlencode "pwd=${SOURCE_ADMIN_PASS}" \
  --data-urlencode "wp-submit=Log In" \
  --data-urlencode "redirect_to=${DEST_URL}/wp-admin/" \
  --data-urlencode "testcookie=1" \
  "${DEST_URL}/wp-login.php")"
if [[ "${login_headers,,}" == *"wordpress_logged_in_"* ]]; then
  ok "source admin ($SOURCE_ADMIN_USER) can log in on dest"
else
  bad "source admin ($SOURCE_ADMIN_USER) could not log in on dest"
fi

# 7. front page 200, contains dest url, not old url --------------------------
front_body="$(curl -s --max-time 10 "${DEST_URL}/")"
front_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${DEST_URL}/")"
if [ "$front_code" = "200" ]; then
  ok "front page returns HTTP 200"
else
  bad "front page returned HTTP $front_code"
fi
if [[ "$front_body" == *"$DEST_URL"* ]]; then
  ok "front page contains dest url"
else
  bad "front page does not contain dest url"
fi
if [[ "$front_body" == *"$OLD_URL"* ]]; then
  bad "front page still contains old url"
else
  ok "front page does not contain old url"
fi

# 8. classic-editor plugin present and active --------------------------------
ce_status="$(site_wp "$DEST_NAME" plugin list --name=classic-editor --field=status 2>/dev/null | tr -d '\r\n')"
if [ "$ce_status" = "active" ]; then
  ok "classic-editor plugin present and active"
else
  bad "classic-editor plugin status: '$ce_status' (expected active)"
fi

echo "----"
echo "PASS=$PASS FAIL=$FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
exit 0
