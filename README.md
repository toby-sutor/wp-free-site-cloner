# WP Free Site Cloner

A free, GPL WordPress plugin to back up, restore and clone a site: export
wp-content and the database into one archive, then import that archive on
another WordPress install with URLs and paths rewritten.

Repository and releases: https://github.com/toby-sutor/wp-free-site-cloner

## Why

Several popular migration plugins let you export for free, but restoring
the archive the convenient way (large archives, or importing from the
dashboard) needs a paid version. This plugin does one thing, for free, with no upsell: export, move the
archive yourself, import. No nags, no accounts, no schedules, no extra
features.

## Features

- Export wp-content and the database to a single `.tar` archive.
- Import that archive on a fresh WordPress install, with the old site's
  URLs, absolute paths and serialized data rewritten to the new site.
- Also imports archives made by two other plugins, so you can move away
  from them without re-doing the export:
  - All-in-One WP Migration (`.wpress`), unencrypted and uncompressed.
  - Duplicator (`.zip` and `.daf`), unencrypted.
- Chunked, resumable export and import steps, so large sites do not hit
  PHP's `max_execution_time`.
- Big archives can be moved by FTP/SFTP instead of a browser upload.
- Archive integrity checks: every archive records its exact size and a
  SHA-256 checksum per 64 MB. A truncated download is refused before the
  upload starts, and the import verifies every checksum before it changes
  anything on the site.
- Until the final switch the destination site is not changed: the
  database is imported into temporary tables and the files into a private
  staging folder. If the import fails or is cancelled before the switch,
  the site stays as it was (see "How it works" below).
- Plain admin page under Tools > Site Cloner. No jQuery, no build step, no
  external requests, no telemetry.

## Supported import formats

| Format | Extension | Notes |
|---|---|---|
| WP Free Site Cloner (own) | `.tar` | uncompressed, produced by this plugin's own export |
| All-in-One WP Migration | `.wpress` | unencrypted and uncompressed archives only |
| Duplicator | `.zip`, `.daf` | unencrypted archives only |

Tested against All-in-One WP Migration 7.111 and Duplicator 5.0.5. Other
versions of those plugins may work, since the formats have been stable for
a while, but have not been verified.

Encrypted or compressed foreign archives are refused with a clear error
rather than silently mis-imported.

## Requirements

- PHP 7.4 or newer.
- WordPress 6.0 or newer.
- MySQL 5.7+ or MariaDB 10.3+.
- Free disk space of about the size of the site's files (plus the database
  dump) in the storage directory for an import; the import refuses to
  start otherwise.
- Single-site WordPress only. Multisite networks are not supported and are
  refused by the plugin (export and import are disabled on a multisite
  install).
- The `zip` PHP extension is required only to import a Duplicator `.zip`
  archive. Everything else (export, native `.tar` import, `.wpress`
  import, Duplicator `.daf` import) needs no extra PHP extensions.

## Install

1. Download `wp-free-site-cloner-<version>.zip` from
   [GitHub Releases](https://github.com/toby-sutor/wp-free-site-cloner/releases)
   (or build one yourself, see "Development" below).
2. Optional, recommended: verify the download. Each release has a
   `SHA256SUMS` file and a build provenance attestation:

   ```sh
   sha256sum -c SHA256SUMS
   gh attestation verify wp-free-site-cloner-<version>.zip --repo toby-sutor/wp-free-site-cloner
   ```
3. In WordPress: Plugins > Add New Plugin > Upload Plugin, choose the zip,
   Install, then Activate.
4. Open Tools > Site Cloner.

The plugin needs to be installed and activated on both the source and the
destination site.

## Usage

1. **Export** on the source site: Tools > Site Cloner > Export > Start
   export. When it finishes, the archive appears in the Archives list with
   a Download link.
2. **Move** the archive to the destination site. Two options:
   - Download it through the browser, then upload it in the destination's
     Import section (drag-and-drop or Choose file); the upload is sent in
     chunks so it works for large files too.
   - For very large archives, skip the browser upload: FTP/SFTP the file
     directly into the storage folder shown on the destination's admin
     page (Import section, under the drop zone). It then appears in that
     site's Archives list, ready to import.
   - Browser downloads of multi-GB files can end early (proxy or PHP
     timeouts on the host). The Archives list shows the exact size in
     bytes: compare it with the downloaded file. For large archives,
     downloading the file by FTP/SFTP from the source's storage folder is
     the more reliable route.
3. **Import** on the destination site (a fresh WordPress install with this
   plugin installed and activated): pick the archive, check the
   confirmation screen (old URL vs. new URL, any warnings), confirm, and
   wait for it to finish.
4. **Log in** with the *old* site's credentials once the import is done -
   the whole users table came from the source site, replacing the
   destination's.

## What gets migrated

Migrated: `wp-content` (themes, plugins, uploads, everything else under
it, minus the exclusions below) and the database tables that use the
site's own table prefix.

Not migrated, and not touched on the destination:
- WordPress core files.
- `wp-config.php`.
- `.htaccess` - permalinks are regenerated on the destination instead
  (rewrite rules are flushed as part of finishing the import).

The destination keeps its own database table prefix (the one already in
its `wp-config.php`); the imported data is rewritten into that prefix, not
carried over from the source.

Export includes every table that uses the site's exact table prefix, even
one created by another plugin (for example Duplicator's own tables) - if
that matters to you, check the database before exporting.

## After import

Log in at the destination with the *old* (source) site's admin
credentials, not the destination's previous ones.

## Limitations and known issues

- **No multisite.** A multisite network is refused outright, on both
  export and import.
- **Database connection charset.** Dumps whose connection charset is
  `big5`, `gbk`, `sjis`, `cp932` or `gb18030` are refused on import (and a
  site running one of them as its database charset cannot be exported). Under
  those multibyte charsets the server reads statement text differently from
  the import's safety guard, which a crafted archive could abuse, so they are
  not allowed. `utf8mb4`, `utf8`, `latin1`, `ascii` and the other single-byte
  charsets are fully supported.
- **WordPress 6.8+ password hashes.** WordPress 6.8 changed how password
  hashes are stored (`$wp$2y$...`). An older WordPress core cannot verify
  those hashes, so nobody could log in after importing a 6.8+ archive onto
  an older WordPress destination. The import inspection screen warns about
  this specifically; update the destination's WordPress core first if you
  hit it.
- **Incompatible plugins and themes.** If an active plugin or theme in the
  archive declares a WordPress or PHP version the destination does not
  meet, it is automatically deactivated (plugins) or replaced with a
  bundled default theme (themes) during import, so the site still loads.
  Each change is logged as a `WARNING:` line, shown prominently on the
  import's done screen. Update WordPress/PHP on the destination and
  re-activate them afterwards.
- **nginx and archive storage.** By default archives are stored under
  `wp-content/fsc-storage/`, protected by a `.htaccess` deny rule that
  nginx does not read. Instead, the actual files live in a randomly named,
  unguessable subdirectory of that folder with owner-only permissions, so
  the URL cannot be guessed. Better: move storage outside the web root
  with `FSC_STORAGE_DIR` (see "Archive storage" below). Either way, delete
  archives once a migration is done - they are a full copy of your site's
  private data.
- **Symlinks.** A symlinked directory inside `wp-content` that points
  outside `wp-content` is not followed on import; the entry is skipped and
  logged. This is deliberate: an archive's file list should not be able to
  make the importer write outside wp-content.
- **Excluded cache/backup directories.** Export automatically skips a
  built-in list of cache and backup directories that are either
  regenerable or belong to other backup plugins, so they are not carried
  over into the archive: this plugin's own storage, `cache`, `upgrade`,
  `upgrade-temp-backup`, `wflogs`, `debug.log`, and the backup folders of
  All-in-One WP Migration, UpdraftPlus, Duplicator, WPvivid, Backuply,
  BackWPup, Backup Guard, WP Staging and BackupBuddy, plus a few well-known
  cache folders (Elegant Themes cache, LiteSpeed cache, WP Hummingbird
  cache).
- **Foreign-source warnings are English only.** Warning text produced
  while reading a `.wpress` or Duplicator archive is not translated.
- **Views and triggers.** Database views and triggers are not exported
  (both carry a `DEFINER` and are not portable). A trigger on a table that
  an import replaces is removed together with that table; the import logs
  a `WARNING:` line for each one.
- **Foreign keys and CHECK constraints** are kept, including their names:
  during the import they carry temporary names (constraint names are
  unique per database, and the live tables still hold the originals), which
  are put back right after the switch. Auto-generated names
  (`<table>_ibfk_<n>`, `<table>_chk_<n>`) follow a changed table prefix.
  If an original name cannot be put back (for example because another
  table took it in the meantime), the constraint keeps working under its
  temporary `fsctmp_...` name and the import logs a `WARNING:` line.

## Archive storage

Archives are **not encrypted**. Each one is a full copy of the site: the
database with password hashes, email addresses, API keys and other
secrets stored in options, plus every file under `wp-content`. Anyone who
gets hold of an archive gets all of that. Keep downloaded copies somewhere
safe and delete archives from the server once a migration is done.

- **Location.** Default: `wp-content/fsc-storage/private-<random>/`, inside
  the web root, protected by deny rules and the random folder name. To
  keep archives outside the web root, add this to `wp-config.php` (above
  the "That's all, stop editing!" line):

  ```php
  define( 'FSC_STORAGE_DIR', '/home/example/fsc-storage' );
  ```

  The path must be absolute, without `.` or `..` segments and not a
  symbolic link; the directory is created if its parent exists. An
  invalid value is reported on the admin page and storage stays disabled
  until it is fixed (archives never silently fall back into the web root).
  A path inside the web root works but shows a warning. Archives already
  in the old `wp-content/fsc-storage/` are not moved: the admin page
  reports them, move or delete them yourself.
- **Permissions.** Storage folders are set to `0700` and files to `0600`
  (owner only). If PHP runs as a different system user than your FTP/SFTP
  account, that account may then not be able to read archives; define
  `FS_CHMOD_DIR` and `FS_CHMOD_FILE` in `wp-config.php` to choose other
  modes (the admin page warns when they let other accounts read archives).
- **Automatic deletion.** Off by default, because many people keep
  archives as backups. To delete archives older than a number of days
  once a day, set `define( 'FSC_ARCHIVE_RETENTION_DAYS', 14 );` in
  `wp-config.php` or use the `fsc_archive_retention_days` filter (`0`
  turns it off). The admin page shows the current setting. Whatever the
  setting, unfinished uploads and temporary files older than 24 hours are
  deleted by the same daily task, and the admin page shows a notice with
  a "Delete all archives" button once archives are older than the
  retention period (7 days while automatic deletion is off).

## How it works

- Export and import both run as a sequence of short, time-budgeted AJAX
  steps polled by the admin page's JavaScript, each saving a checkpoint, so
  a slow host with a low `max_execution_time` does not time out, and a
  dropped connection resumes from the last checkpoint instead of starting
  over.
- The import first checks the archive, then loads the database into
  temporary tables (schema, data and the search-replace pass) and unpacks
  the files into a staging folder inside the plugin's storage directory.
  Only the last step changes the live site: for a moment the site shows
  WordPress' maintenance page while the unpacked folders (each plugin,
  each theme, each uploads month, ...) are moved into wp-content and the
  database tables are swapped in one atomic step. A folder from the
  archive replaces the site's folder of the same name completely; folders
  the archive does not contain are kept. If anything fails before the
  tables are swapped, the moved folders are moved back and the site is
  unchanged. If the server stops the request in the middle of the switch,
  the import continues (or, if you cancel it, is undone) on the next
  request. If nobody continues it (for example the browser tab was
  closed), the switch is undone automatically after 5 minutes without
  progress: the next visit to the site moves the previous files back and
  ends the maintenance page, and the site is exactly as before. If the
  database had already been swapped, the maintenance page ends instead,
  the new site stays live and the remaining clean-up runs on the next page
  load or by WP-Cron. The wait can be changed with
  `define( 'FSC_SWITCH_STALE_SECONDS', 300 );` in wp-config.php (10 to
  3600 seconds). After the swap the replaced files are deleted.
- If `FSC_STORAGE_DIR` is on a different filesystem than wp-content, files
  are copied instead of moved during the switch, so the maintenance window
  lasts longer.
- Integrity: the archive manifest (first entry) holds the archive's total
  size, and the last entry, `fsc-checksum.json`, holds a SHA-256 checksum
  for every 64 MB of the archive. The browser compares the size before
  uploading, the server checks it again after the last upload chunk, and
  the first import step ("Verify archive") recomputes every checksum
  before any database table or file is touched. A mismatch names the
  damaged position so you know the copy is bad, not the site. Archives
  from 0.9.0 have no checksums and import with a warning.
- URLs, absolute paths, and their JSON-escaped and URL-encoded forms are
  replaced throughout the database with a serialization-aware walker, so
  serialized PHP arrays and objects that contain the old URL are rewritten
  correctly instead of being corrupted by a naive string replace.

## Security notes

- **Only import archives you created or fully trust.** An archive contains
  PHP code (plugins, themes, mu-plugins) and user accounts that become
  active on the destination site; importing one is equivalent to
  installing that code and those accounts. The import confirmation screen
  says so too. The file and SQL restrictions below are a safety net for a
  damaged or tampered archive, not a sandbox for an untrusted one.
- Every admin action requires an administrator session (`manage_options`)
  and a WordPress nonce, with one necessary exception: the import step
  endpoint. Importing replaces the users table partway through, which
  invalidates the browser's session cookie mid-job, so that endpoint
  instead requires a random per-job secret token (32 bytes). It works only
  for that import, expires 2 hours after the last import step and is
  revoked 2 minutes after the import finishes or fails.
- Uploaded and inspected archive file names are strictly validated:
  allowed characters, length limit, a fixed set of extensions, no path
  traversal, and no "double extension" names (like `site.php.tar`) that
  some server configurations would execute.
- Paths extracted from an archive are confined to `wp-content`; entries
  that would land outside it, or a symlink that would resolve outside it,
  are rejected.
- SQL statements read from an imported dump are filtered to a small
  allow-list of statement types, and every table name in them is checked
  to be one of the plugin's own temporary import tables. A crafted dump
  cannot reach the site's live tables, run arbitrary SQL, or affect server
  state.
- Archive storage lives in a randomly named private subdirectory with
  owner-only permissions, so its path cannot be guessed even where the web
  server does not honor `.htaccess`, and it can be moved outside the web
  root with `FSC_STORAGE_DIR`. Archives are not encrypted; see "Archive
  storage".
- During an import the staging folder (inside the storage directory,
  owner-only permissions) holds the archive's extracted files, including
  PHP, until the switch. Where `.htaccess` is ignored (nginx) it is
  protected only by the random private directory name, like the archives.
- Crafted dumps: statements that use subqueries, file functions, sleeps,
  variables, other databases, or table engines/options that reach other
  servers or files are skipped and logged by statement kind and table
  only. Logs and error messages contain no row data or absolute paths.
- Unexpected server errors are written to the PHP error log; the browser
  only gets a generic message with a short reference to find the entry.
- Report vulnerabilities privately, see [`SECURITY.md`](SECURITY.md).

## FAQ

**Does this support multisite?**
No. Both export and import are disabled on a multisite network.

**Can it schedule automatic backups?**
No, that is deliberately out of scope. Use a dedicated backup plugin for
scheduled or off-site backups; this plugin is for a one-off backup, clone
or migration.

**Will an export from this plugin open in All-in-One WP Migration or
Duplicator, or the other way around?**
This plugin can import unencrypted, uncompressed `.wpress` and Duplicator
`.zip`/`.daf` archives, but its own export format is a plain `.tar` that
those other plugins do not read.

**Why does the destination need the old site's login after import, not
its own?**
Because the whole database, including the users table, is replaced by the
source site's.

**Is the archive encrypted?**
No. It is a plain, uncompressed archive on purpose, so any standard tool
can open it if needed. Treat it as a full copy of your site's private
data: store it somewhere safe and delete it once you are done with it.
Use `FSC_STORAGE_DIR` to keep archives outside the web root, see
"Archive storage".

## Development

### Unit tests

PHPUnit, run inside a PHP container (no PHP install needed on the host):

```sh
podman run --rm -v "$PWD:/repo:ro,z" -w /repo docker.io/library/php:8.3-cli \
  sh -c "php -v && composer --version >/dev/null 2>&1 || true; php vendor/bin/phpunit"
```

or with Docker, drop the `podman` for `docker`. `composer.json` targets
PHP 7.4+; the suite is run on PHP 7.4 through 8.5 in CI.

### End-to-end tests

See [`tests/e2e/README.md`](tests/e2e/README.md) for the podman-based
WordPress + MariaDB test harness and the Playwright-driven UI scenarios.

### Building a release zip

```sh
bin/build-zip.sh
```

Builds `build/wp-free-site-cloner-<version>.zip` from a clean `git
archive` (so untracked and ignored files, including `internal/` and
`tests/`, are never included), and fails if the working tree is dirty
unless `--allow-dirty` is passed.

Pushing a `v<version>` tag runs `.github/workflows/release.yml`, which
builds the same zip, writes `SHA256SUMS`, creates a build provenance
attestation and attaches both files to a GitHub Release. The tag must
match the plugin's `Version` header.

### Translations

`languages/wp-free-site-cloner.pot` is generated with WP-CLI:

```sh
wp i18n make-pot . languages/wp-free-site-cloner.pot --slug=wp-free-site-cloner \
  --domain=wp-free-site-cloner --exclude=vendor,tests,internal,build,bin
```

## License

GPLv2 or later. See [`LICENSE`](LICENSE).

## Trademarks

All-in-One WP Migration and Duplicator are trademarks of their respective
owners; this project is not affiliated with them. WordPress is a trademark
of the WordPress Foundation.
