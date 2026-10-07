# E2E test harness (podman)

Brings up real WordPress + MariaDB stacks in podman to exercise the plugin's
export/import flow end to end: a source site, and two destination sites
(one PHP 8.3, one PHP 7.4) with a different table prefix than the source.

## Requirements

- This runs inside a flatpak sandbox. `podman`, `curl`, `python3` are used
  directly; podman itself only exists on the host, reached through
  `flatpak-spawn --host podman ...` (all scripts do this for you via
  `lib.sh`, you don't need to type it).
- Ports 8081-8083 must be free on 127.0.0.1.
- Network access (to pull images from docker.io, and to install plugins
  from wordpress.org during seed.sh/samples.sh).

## Quick start

```sh
cd tests/e2e
./up.sh        # brings up source (8081), dest (8082), dest74 (8083)
./seed.sh      # seeds the source site with test content
./assert.sh source http://127.0.0.1:8081 http://dummy-old-url.invalid
               # sanity check: run assert's checks against the source
               # site itself, with a URL that was never real, so
               # everything should PASS (15/15). This proves the checks
               # themselves work before trusting them against a real
               # migration.
./down.sh      # tear everything down
```

To actually test the plugin: use the admin UI at the URLs below (the
plugin repo root is bind-mounted read-only into every site's
`wp-content/plugins/wp-free-site-cloner/`, live - edit plugin code on the
host and reload the browser, no rebuild needed), export from source,
import into dest or dest74, then:

```sh
./assert.sh dest    http://127.0.0.1:8082 http://127.0.0.1:8081
./assert.sh dest74  http://127.0.0.1:8083 http://127.0.0.1:8081
```

Each should print `PASS=... FAIL=0` and exit 0.

## Sites

| name    | url                     | port | php | table prefix | admin user     | admin password     |
|---------|-------------------------|------|-----|---------------|----------------|---------------------|
| source  | http://127.0.0.1:8081   | 8081 | 8.3 | `wp_`         | admin          | admin-pass-123      |
| dest    | http://127.0.0.1:8082   | 8082 | 8.3 | `dst_`        | destadmin      | dest-admin-456      |
| dest74  | http://127.0.0.1:8083   | 8083 | 7.4 | `dst_`        | dest74admin    | dest74-admin-789    |

The destination sites use different admin credentials than the source on
purpose: after a real import, the *source* admin/admin-pass-123 login
should work on the destination (that's one of assert.sh's checks), which
only proves anything if the destination didn't already share those
credentials.

DB credentials (internal to each pod, not exposed outside it): user
`wpuser` / `wppass`, root `rootpass`, database `wordpress`.

Images: `docker.io/library/mariadb:10.11`,
`docker.io/library/wordpress:php8.3-apache`,
`docker.io/library/wordpress:php7.4-apache` (this tag stopped being
rebuilt after WordPress core dropped PHP 7.4 support; it still exists on
the registry and pulls a WP 6.1.1-era core, which is fine for our
purposes - the plugin only needs wp-content + DB, not core), and
`docker.io/library/wordpress:cli` as an ephemeral wp-cli sidecar.
`lib.sh` pins each of them by digest (`image@sha256:...`), with the tag
the digest was taken from in a comment next to it. To update one, pull
the tag, read the new digest with
`podman image inspect --format '{{index .RepoDigests 0}}' <image:tag>`
and replace it in `lib.sh` (keep the tag comment).

## How it's wired up

Each site is one podman pod (`fsc-e2e-<name>`) with its port published to
127.0.0.1 only. Inside the pod, a mariadb container and a wordpress
apache container share the pod's network namespace, so they talk to each
other over `127.0.0.1`. wp-cli runs as a throwaway container joined to
the same pod for each command (see `site_wp` in `lib.sh`).

The official `wordpress:phpX-apache` image ships a `wp-config.php` that
reads `WORDPRESS_DB_*` / `WORDPRESS_TABLE_PREFIX` from the process
environment *at runtime* (via a `getenv_docker()` helper), not baked in
at image-build time. That means every container that touches this
wp-config.php - the apache container AND every wp-cli sidecar
invocation - needs the same `WORDPRESS_DB_HOST=127.0.0.1` (etc.) env
vars passed explicitly. `site_wp` in `lib.sh` does this for you; if you
add new podman invocations, remember it too.

Named volumes (`fsc-e2e-<name>-db-vol`, `fsc-e2e-<name>-web-vol`) hold
the DB and the full webroot, so state survives `site_mount_plugin`
recreating the web container. Fresh named volumes are root-owned;
`site_up` chowns `wp-content` to `www-data` (uid 33) after first boot so
uploads/cache are writable by the web server.

`site_mount_plugin <name>` (re)creates the web container with the repo
root bind-mounted read-only (`:ro,z`) at
`wp-content/plugins/wp-free-site-cloner/`, then tries
`wp plugin activate wp-free-site-cloner`. It's safe to call again at any
time; it's a no-op-ish container recreate (a few seconds), and the
bind mount is live, so you normally do NOT need to call it again just
because plugin files changed on disk - only if the container itself was
removed. If the plugin's main file doesn't exist in the repo root yet,
activation is skipped with a warning rather than failing the script.

## Scripts

- `lib.sh` - shared functions, sourced by every other script. Not meant
  to be run directly.
- `up.sh` / `down.sh` - bring all three sites up (idempotent: safe to
  re-run) or remove everything (pods, containers, volumes).
- `seed.sh` (+ `seed-data.php`, `gen-test-image.py`) - seeds the source
  site: posts/pages with absolute URLs in their content, real media
  library images, an upload path over 120 chars and one over 255 chars
  (exercises long-path/PAX handling), a 30 MB binary upload, a
  serialized-array option and a serialized-object option (both
  containing the site URL, written via real `update_option()` calls so
  they're genuinely `maybe_serialize()`d, not hand-crafted strings), a
  JSON-escaped-URL option (`json_encode()` escapes `/` as `\/` by
  default - a common trap for naive search/replace code), user meta, a
  UTF-8 multibyte post title (emoji + umlauts + CJK), the
  twentytwentyfour theme, and the classic-editor plugin (to check
  plugin files migrate too). Not idempotent - meant to run once against
  a fresh `up.sh`.
- `assert.sh <dest-name> <dest-url> <old-url>` - checks a migration
  landed correctly: siteurl/home match, no leftover old-url in the DB
  (plain and JSON-escaped forms), the serialized/JSON options still
  round-trip, upload file count and total bytes match the source, both
  long-path files exist, the *source* admin credentials work on the
  destination, the front page returns 200 and mentions the new url but
  not the old one, and classic-editor is present and active. Prints
  `PASS: ...` / `FAIL: ...` per check and a final `PASS=n FAIL=n` line;
  exits non-zero if anything failed. `<dest-name>` is looked up via
  `lib.sh`'s naming helpers to find the right podman containers -
  pass `source`, `dest`, or `dest74`.
- `same-site.sh` - restores a backup onto the site it was made from
  (the core backup-and-restore case; all other scripts migrate between
  sites). Runs on `source` after `up.sh` + `seed.sh`: creates plugin-like
  tables with foreign keys (custom names, auto-generated `_ibfk_` names, a
  self-reference, two tables referencing each other, `ON DELETE CASCADE`,
  a reference to `wp_posts`) and CHECK constraints (named and unnamed),
  exports with `drive.php`, changes the site (new post, new option,
  deleted and inserted rows in the FK tables, plus a new table outside the
  archive with a foreign key to an archived table), imports the same
  archive and checks that data checksums and the constraint state
  (`information_schema` TABLE_CONSTRAINTS, REFERENTIAL_CONSTRAINTS,
  CHECK_CONSTRAINTS, KEY_COLUMN_USAGE) equal the state at export, that no
  `fsctmp_`/`fscold_` tables or temporary constraint names are left, that
  the outside table's foreign key points at the live table, and that the
  site loads and the admin can log in. It does this twice in a row. The
  test tables and the archive are removed at the end (also on failure),
  so `source` is left as `seed.sh` made it; `assert.sh source ...` still
  passes afterwards. The shared stack runs MariaDB only; MySQL 8.4 (where
  CHECK names are also unique per database) was checked on a private stack.
  `drive.php` runs through `site_wp` with `SITE_WP_USER=33:33`, so the
  files the import writes stay owned by www-data (`site_wp` runs as root
  by default).
- `samples.sh` - separate, optional, not part of the normal
  up/seed/assert/down flow. Installs the free All-in-One WP Migration
  and Duplicator (Lite) plugins on the source site, drives a real
  export from each headlessly, and saves the resulting archives to
  `$FSC_E2E_DATA/samples/` (default `~/.cache/wp-free-site-cloner-e2e/samples/`)
  (outside the repo). Used to write
  `internal/formats/wpress.md` and `internal/formats/duplicator.md`
  from real, observed archives. Run it on demand, not as part of every
  test cycle.

## Working directories

- `$FSC_E2E_DATA` (default `~/.cache/wp-free-site-cloner-e2e/`) - sample
  archives (`samples/`), browser downloads (`ui-downloads/`) and
  screenshots (`screens/`). Kept outside the repo on purpose: the repo is
  bind-mounted as the plugin, so anything large inside it would end up in
  the next export. `ui-errors.js` and `ui-screens-v4.js` expect fixtures
  here (truncated archives in `ui-downloads/truncated/`, a 0.9.0 archive at
  `v091/old090.tar`).

- `.work/` (gitignored) - scratch space for seed.sh (generated test
  images, etc). Not meant to survive `down.sh`/cleanup; nothing
  important is kept only here.
- `node_modules/` (gitignored) - if `samples.sh` needs Playwright to
  drive Duplicator's admin UI, its `package.json` lives in this
  directory and `npm i` populates `node_modules/` here (via
  `flatpak-spawn --host npm i`, since node only exists on the host).

## Troubleshooting

- `podman pod exists`/`container exists` checks make `site_up` and
  `site_mount_plugin` safe to re-run; if something gets into a weird
  state, `./down.sh && ./up.sh` is the reliable reset.
- If a site never answers on its port, check
  `flatpak-spawn --host podman logs fsc-e2e-<name>-web` and
  `...-db` for the actual error - most often a stale volume from a
  previous, differently-configured run. `./down.sh` removes volumes
  too, so a fresh `./up.sh` after that always starts clean.
- `wp-config.php`'s dynamic `getenv_docker()` behavior (see above) is
  the most common source of "works for the web container, fails for
  wp-cli" bugs if you hand-write new podman commands instead of using
  `site_wp`.
