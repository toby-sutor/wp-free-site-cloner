#!/usr/bin/env bash
# Same-site restore on the source site (8081): seed plugin-like tables with
# foreign keys and CHECK constraints, export with drive.php, change the site,
# import the same archive on the same site, and check that the database
# (data, constraint names and definitions) equals the state at export time.
# Covers the v0.9.3 fix: FOREIGN KEY names (and CHECK names on MySQL 8) are
# unique per schema, so the temporary tables of the import must not reuse
# the live names.
#
# Run after up.sh + seed.sh. Leaves the source as seed.sh left it: the test
# tables and the archive are removed at the end (also on failure).
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1
# shellcheck disable=SC1091
source ./lib.sh

NAME=source
P="${SITE_PREFIX[$NAME]}"
URL="${SITE_URL[$NAME]}"
DB="$(db_container "$NAME")"
WEB="$(web_container "$NAME")"
DRIVE=wp-content/plugins/$PLUGIN_SLUG/tests/e2e/drive.php
OUT="$WORK_DIR/same-site"
mkdir -p "$OUT"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "PASS: $*"; }
bad() { FAIL=$((FAIL + 1)); echo "FAIL: $*"; }
q() { podman exec -i "$DB" mysql -N -uwpuser -pwppass wordpress "$@" 2>&1 | grep -v 'Using a password'; }
drive() { SITE_WP_USER=33:33 site_wp "$NAME" eval-file "$DRIVE" "$@"; }
TABLES="fsce2e_groups fsce2e_cache fsce2e_a fsce2e_b fsce2e_posts_ext fsce2e_liveonly"

cleanup() {
  local t
  for t in $TABLES; do q -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS \`${P}$t\`" >/dev/null; done
  if [ -n "${ARCHIVE:-}" ]; then
    podman exec "$WEB" sh -c "rm -f /var/www/html/wp-content/fsc-storage/private-*/'$ARCHIVE'"
  fi
}
trap cleanup EXIT

# Constraint state: names, types, references, rules, CHECK clauses, columns.
snap_constraints() {
  q -e "
SELECT 'TC', TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('FOREIGN KEY', 'CHECK') ORDER BY 2, 3;
SELECT 'RC', TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UNIQUE_CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 2, 3;
SELECT 'CC', CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 2;
SELECT 'KCU', TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 2, 3, 5;" | grep -v fsce2e_liveonly
}
# Data state: checksum of every site table, options by name (no transients/cron).
snap_data() {
  local t
  for t in $(q -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '$(printf %s "$P" | sed 's/_/\\\\_/g')%' AND table_name NOT IN ('${P}options', '${P}fsce2e_liveonly') ORDER BY table_name"); do
    q -e "CHECKSUM TABLE \`$t\` EXTENDED"
  done
  q -e "SELECT option_name, MD5(option_value) FROM \`${P}options\` WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT IN ('cron', 'rewrite_rules') ORDER BY option_name"
}

log "seeding foreign key tables on $NAME"
cleanup
q <<SQL
CREATE TABLE ${P}fsce2e_groups (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, parent_id bigint(20) unsigned DEFAULT NULL, name varchar(50) NOT NULL, PRIMARY KEY (id), KEY parent_id (parent_id), CONSTRAINT fk_fsce2e_parent FOREIGN KEY (parent_id) REFERENCES ${P}fsce2e_groups (id) ON DELETE SET NULL) ENGINE=InnoDB;
CREATE TABLE ${P}fsce2e_cache (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, group_id bigint(20) unsigned NOT NULL, lat decimal(9,6) NOT NULL, PRIMARY KEY (id), KEY group_id (group_id), FOREIGN KEY (group_id) REFERENCES ${P}fsce2e_groups (id) ON DELETE CASCADE, CONSTRAINT chk_fsce2e_lat CHECK (lat BETWEEN -90 AND 90), CHECK (lat <> 0)) ENGINE=InnoDB;
CREATE TABLE ${P}fsce2e_a (id int NOT NULL, b_id int DEFAULT NULL, PRIMARY KEY (id), KEY b_id (b_id)) ENGINE=InnoDB;
CREATE TABLE ${P}fsce2e_b (id int NOT NULL, a_id int DEFAULT NULL, PRIMARY KEY (id), KEY a_id (a_id), CONSTRAINT fk_fsce2e_b_a FOREIGN KEY (a_id) REFERENCES ${P}fsce2e_a (id) ON DELETE CASCADE) ENGINE=InnoDB;
ALTER TABLE ${P}fsce2e_a ADD FOREIGN KEY (b_id) REFERENCES ${P}fsce2e_b (id) ON DELETE SET NULL;
CREATE TABLE ${P}fsce2e_posts_ext (post_id bigint(20) unsigned NOT NULL, note varchar(20) DEFAULT NULL, PRIMARY KEY (post_id), CONSTRAINT fk_${P}fsce2e_posts_ext FOREIGN KEY (post_id) REFERENCES ${P}posts (ID) ON DELETE CASCADE) ENGINE=InnoDB;
INSERT INTO ${P}fsce2e_groups (id, parent_id, name) VALUES (1, NULL, 'root'), (2, 1, 'child'), (3, 2, 'grandchild'), (4, 1, 'other');
INSERT INTO ${P}fsce2e_cache (group_id, lat) VALUES (1, 48.1), (2, 52.5), (3, 1.5), (4, 10.25);
INSERT INTO ${P}fsce2e_a (id, b_id) VALUES (1, NULL), (2, NULL);
INSERT INTO ${P}fsce2e_b (id, a_id) VALUES (10, 1), (20, 2);
UPDATE ${P}fsce2e_a SET b_id = 20 WHERE id = 1;
INSERT INTO ${P}fsce2e_posts_ext (post_id, note) SELECT ID, 'ext' FROM ${P}posts ORDER BY ID LIMIT 1;
SQL

log "exporting $NAME"
FSC_QUIET=1 drive export > "$OUT/export.log" 2>&1
ARCHIVE=$(grep -o '"archive":"[^"]*"' "$OUT/export.log" | head -1 | cut -d'"' -f4)
[ -n "$ARCHIVE" ] && ok "export ($ARCHIVE)" || { bad "export failed: $(tail -c 400 "$OUT/export.log")"; exit 1; }
snap_constraints > "$OUT/con-before.txt"
snap_data > "$OUT/data-before.txt"

for run in 1 2; do
  log "run $run: changing the site, then restoring the archive on the same site"
  site_wp "$NAME" post create --post_title="fsce2e after export $run" --post_status=publish > /dev/null 2>&1
  site_wp "$NAME" option update fsce2e_marker "changed $run" > /dev/null 2>&1
  q -e "DELETE FROM ${P}fsce2e_groups WHERE id = 2; INSERT INTO ${P}fsce2e_a (id) VALUES (9$run); DELETE FROM ${P}fsce2e_posts_ext;"
  # A table that is not in the archive, with foreign keys to tables that are.
  q -e "DROP TABLE IF EXISTS ${P}fsce2e_liveonly; CREATE TABLE ${P}fsce2e_liveonly (id int NOT NULL, g bigint(20) unsigned DEFAULT NULL, PRIMARY KEY (id), KEY g (g), CONSTRAINT fk_fsce2e_liveonly FOREIGN KEY (g) REFERENCES ${P}fsce2e_groups (id) ON DELETE CASCADE) ENGINE=InnoDB; INSERT INTO ${P}fsce2e_liveonly VALUES (1, 4);"
  drive import "$ARCHIVE" > "$OUT/import-$run.log" 2>&1
  rc=$?
  [ $rc = 0 ] && ok "run $run: import succeeded" || bad "run $run: import failed: $(grep -o '"error":"[^"]*"' "$OUT/import-$run.log" | cut -c1-400)"
  snap_constraints > "$OUT/con-after-$run.txt"
  snap_data > "$OUT/data-after-$run.txt"
  diff "$OUT/con-before.txt" "$OUT/con-after-$run.txt" > "$OUT/con-$run.diff" && ok "run $run: constraints equal the state at export ($(grep -c '^TC' "$OUT/con-after-$run.txt"))" || bad "run $run: constraints differ: $(head -c 800 "$OUT/con-$run.diff")"
  diff "$OUT/data-before.txt" "$OUT/data-after-$run.txt" > "$OUT/data-$run.diff" && ok "run $run: data equals the state at export" || bad "run $run: data differs: $(head -c 800 "$OUT/data-$run.diff")"
  v=$(q -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE 'fsctmp\\_%' OR table_name LIKE 'fscold\\_%')")
  [ "$v" = 0 ] && ok "run $run: no fsctmp_/fscold_ tables" || bad "run $run: $v fsctmp_/fscold_ tables left"
  v=$(q -e "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'fsctmp\\_%'")
  [ "$v" = 0 ] && ok "run $run: no temporary constraint names" || bad "run $run: $v temporary constraint names"
  v=$(q -e "SELECT REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_fsce2e_liveonly'")
  [ "$v" = "${P}fsce2e_groups" ] && ok "run $run: foreign key of a table outside the archive points at the live table" || bad "run $run: fk_fsce2e_liveonly references '$v'"
  v=$(q -e "INSERT INTO ${P}fsce2e_cache (group_id, lat) VALUES (999, 1)")
  [[ "$v" == *1452* ]] && ok "run $run: foreign key enforced" || bad "run $run: foreign key not enforced: $v"
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$URL/")
  [ "$code" = 200 ] && ok "run $run: front page 200" || bad "run $run: front page $code"
  h=$(curl -s -D - -o /dev/null --max-time 30 --data-urlencode "log=${SITE_ADMIN_USER[$NAME]}" --data-urlencode "pwd=${SITE_ADMIN_PASS[$NAME]}" --data-urlencode wp-submit="Log In" --data-urlencode testcookie=1 -b wordpress_test_cookie=WP%20Cookie%20check "$URL/wp-login.php")
  [[ "${h,,}" == *wordpress_logged_in_* ]] && ok "run $run: admin login" || bad "run $run: admin login failed"
done

echo "----"
echo "PASS=$PASS FAIL=$FAIL"
[ $FAIL -eq 0 ]
