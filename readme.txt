=== WP Free Site Cloner ===
Tags: migration, backup, clone, restore, duplicate
Requires at least: 6.0
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Free backup, restore and clone plugin. No upsells, no import paywall: export wp-content and the database, import with URLs rewritten.

== Description ==

WP Free Site Cloner backs up, restores and clones a WordPress site: export
wp-content and the database into one archive, then import that archive on
another WordPress install with URLs and paths rewritten. It does this one
thing, for free, with no upsell, no accounts and no nags.

= Why =

Several popular migration plugins let you export for free, but restoring
the archive the convenient way (large archives, or importing from the
dashboard) needs a paid version. This plugin has no such limit.

= Features =

* Export wp-content and the database to a single .tar archive.
* Import on a fresh WordPress install with URLs, absolute paths and
  serialized data rewritten to the new site.
* Also imports archives made by All-in-One WP Migration (.wpress) and
  Duplicator (.zip, .daf), unencrypted and uncompressed only.
* Chunked, resumable export and import steps, so large sites do not hit
  PHP's max_execution_time.
* Large archives can be moved by FTP/SFTP instead of a browser upload.
* Integrity checks: each archive records its exact size and a SHA-256
  checksum per 64 MB; truncated or damaged copies are refused before the
  site is changed.
* Until the final switch the destination site is not changed: the
  database is imported into temporary tables and the files into a
  private staging folder. If the import fails or is cancelled before the
  switch, the site stays as it was.
* Plain admin page under Tools > Site Cloner: no jQuery, no build step, no
  external requests, no telemetry.

= Supported import formats =

* WP Free Site Cloner's own .tar (uncompressed).
* All-in-One WP Migration .wpress, unencrypted and uncompressed only.
* Duplicator .zip and .daf, unencrypted only.

Tested against All-in-One WP Migration 7.111 and Duplicator 5.0.5. Other
versions of those plugins may work but have not been verified. Encrypted
or compressed foreign archives are refused with a clear error.

= Requirements =

* PHP 7.4 or newer.
* WordPress 6.0 or newer.
* MySQL 5.7+ or MariaDB 10.3+.
* Free disk space of about the size of the site's files (plus the
  database dump) for an import; the import refuses to start otherwise.
* Single-site WordPress only; multisite networks are not supported.
* The PHP zip extension is required only to import a Duplicator .zip
  archive.

= What gets migrated =

Migrated: wp-content and the database tables that use the site's own
table prefix. Not migrated: WordPress core files, wp-config.php and
.htaccess (permalinks are regenerated on the destination instead). The
destination keeps its own database table prefix.

= Limitations =

* No multisite support.
* Dumps that use a connection charset of big5, gbk, sjis, cp932 or gb18030
  are refused on import, and a site whose database charset is one of them
  cannot be exported; under these charsets the server reads SQL differently
  from the import safety guard. utf8mb4, utf8, latin1 and the other
  single-byte charsets are fully supported.
* An archive from WordPress 6.8 or newer stores password hashes that an
  older WordPress core cannot verify; update the destination's WordPress
  core first, or nobody can log in. The import screen warns about this.
* A plugin or theme that needs a newer WordPress or PHP than the
  destination is automatically deactivated, or the theme is switched to a
  bundled default, so the site still loads; each change is logged as a
  warning shown on the done screen.
* On nginx, the storage folder has no .htaccess protection, so archives
  are kept under a randomly named, unguessable subdirectory with
  owner-only permissions instead; define FSC_STORAGE_DIR to keep them
  outside the web root, and delete archives once a migration is done
  regardless.
* A symlinked directory inside wp-content that points outside wp-content
  is not followed on import.
* Export automatically skips a built-in list of cache and backup
  directories from this and other backup plugins.
* Views and database triggers are not exported. A trigger on a table that
  an import replaces is removed with it (logged as a warning).

= Archive storage =

Archives are not encrypted: each one is a full copy of the site,
including password hashes, email addresses and API keys. Keep downloaded
copies safe and delete archives from the server once you are done.

* Location: wp-content/fsc-storage/private-<random>/ by default. To keep
  archives outside the web root, add
  define( 'FSC_STORAGE_DIR', '/absolute/path' ); to wp-config.php. An
  invalid path is reported on the admin page and storage stays disabled
  until it is fixed.
* Permissions: folders 0700, files 0600. Define FS_CHMOD_DIR and
  FS_CHMOD_FILE to use other modes (for example when your FTP account
  is a different system user than PHP).
* Automatic deletion is off by default. Set
  define( 'FSC_ARCHIVE_RETENTION_DAYS', 14 ); or use the
  fsc_archive_retention_days filter to delete older archives daily.
  Unfinished uploads and temporary files older than 24 hours are always
  deleted. The admin page warns about old archives and offers a "Delete
  all archives" button.

= Security =

Only import archives you created or fully trust: an archive contains PHP
code (plugins, themes, mu-plugins) and user accounts that become active on
this site.

Admin actions require an administrator session and a nonce. The one
necessary exception is the import step endpoint, since the users table is
replaced mid-import; it instead requires a random per-job secret token
(32 bytes). It works only for that import, expires 2 hours after the last
import step and is revoked 2 minutes after the import finishes or fails.
During the final switch the site briefly shows WordPress' maintenance
page; a failure before the tables are swapped moves the files back. If the
browser is closed during the switch and nobody continues it, the switch is
undone automatically after 5 minutes without progress (or finished, if the
database was already swapped) on the next visit to the site.
Archive file names, extracted paths and imported
SQL are all strictly validated; imported SQL is confined to the plugin's
own temporary tables until a single atomic swap makes it live. Archive
storage lives in a randomly named private subdirectory with owner-only
permissions, or outside the web root with FSC_STORAGE_DIR. Unexpected
errors are written to the PHP error log and shown only as a short
reference. Report vulnerabilities privately:
https://github.com/toby-sutor/wp-free-site-cloner/security/advisories/new

== Installation ==

1. Download wp-free-site-cloner-<version>.zip from
   https://github.com/toby-sutor/wp-free-site-cloner/releases (each
   release lists SHA256SUMS and has a build provenance attestation).
   In WordPress: Plugins > Add New Plugin > Upload Plugin, choose the
   plugin zip file, then Install and Activate.
2. Open Tools > Site Cloner.
3. Repeat on the destination site: the plugin must be installed and
   activated on both sides.

= Usage =

1. On the source site: Tools > Site Cloner > Export > Start export.
2. Move the resulting archive to the destination: download and re-upload
   it through the Import section, or FTP/SFTP it directly into the
   storage folder shown there for large files. Browser downloads of very
   large files can end early on some hosts; compare the byte size shown
   in the Archives list, or fetch large archives by FTP/SFTP.
3. On the destination (a fresh WordPress install with this plugin
   active): Import, pick the archive, review the confirmation screen,
   confirm, and wait for it to finish.
4. Log in with the *old* site's credentials; the users table came from
   the source site.

== Frequently Asked Questions ==

= Does this support multisite? =

No. Both export and import are disabled on a multisite network.

= Can it schedule automatic backups? =

No, that is deliberately out of scope. Use a dedicated backup plugin for
scheduled or off-site backups.

= Will an export from this plugin open in All-in-One WP Migration or
Duplicator, or the other way around? =

This plugin can import unencrypted, uncompressed .wpress and Duplicator
.zip/.daf archives, but its own export format is a plain .tar that those
other plugins do not read.

= Why do I log in with the old site's password after import, not the
destination's? =

Because the whole database, including the users table, is replaced by the
source site's.

= Is the archive encrypted? =

No, it is a plain, uncompressed archive on purpose. Treat it as a full
copy of your site's private data: store it safely and delete it once you
are done with it.

== Changelog ==

= 0.9.4 =
* Security: a database dump that selects a connection charset the server reads
  differently from the import guard (big5, gbk, sjis, cp932, gb18030) is now
  refused before any change is made. This closes a way a crafted or tampered
  archive could run a hidden sub-query during import and read other tables or
  server files. Exporting a site that uses such a charset is refused for the
  same reason.
* Hardening: the import now limits how far a .zip or .daf archive may expand
  while extracting, verifies the CRC of large Duplicator entries, skips known
  backup folders and checks the table prefix in native archives, and rejects
  malformed tar headers and switch journals.
* Reliability: the table switch decides the commit point consistently across
  every path, so an interrupted switch can no longer leave the old files under
  the new database; cancelling or replacing a job re-reads its state first; and
  a locked table can no longer stall the switch indefinitely.
* Build: releases are published only from the main branch with the tests
  passing; continuous integration now covers PHP 7.4 through 8.5.

= 0.9.3 =
* Fixed: restoring onto the same site failed for tables with foreign keys
  ("Can't create table ... errno: 121"). Foreign key names (and, on MySQL
  8, CHECK constraint names) are unique per database, so the import now
  gives them temporary names and puts the original names back after the
  switch.
* Fixed: foreign keys of tables that are not in the archive pointed at the
  removed old tables after an import; they now point at the imported
  tables.
* Fixed: temporary tables linked by foreign keys could not be removed
  after a cancelled or failed import outside an import step.
* Database triggers are not part of archives: export logs skipped
  triggers, and an import logs a warning for each trigger of a replaced
  table.
* Error messages no longer show the database name.

= 0.9.2 =
Security hardening release.
* Archive storage: folders and files are readable by their owner only
  (0700/0600, or FS_CHMOD_DIR/FS_CHMOD_FILE when defined).
* New FSC_STORAGE_DIR setting to keep archives outside the web root; the
  admin page shows the storage location, permissions and any warnings.
* Daily cleanup of unfinished uploads and temporary files older than 24
  hours; optional automatic deletion of old archives
  (FSC_ARCHIVE_RETENTION_DAYS or the fsc_archive_retention_days filter,
  off by default); notice and "Delete all archives" button when old
  archives are kept.
* The Archives list and the documentation state clearly that archives are
  not encrypted.
* The import confirmation warns that an archive's code and user accounts
  become active on the site.
* Unexpected server errors no longer surface as a bare HTTP 500: they are
  logged and answered with a short reference.
* Stricter type checks of archive metadata (all formats).
* Import no longer writes into wp-content before the final switch;
  failures before the switch leave the site unchanged (files are staged,
  moved in with a journal and moved back on error).
* Short maintenance mode during the switch; an interrupted switch resumes
  on the next request. A switch nobody continues is undone automatically
  after 5 minutes (FSC_SWITCH_STALE_SECONDS), or finished if the database
  was already swapped.
* Stricter SQL filter for imported dumps (table engines and options,
  subqueries, file functions, references to other databases).
* Logs and error messages no longer contain row data or absolute paths.
* The import token expires 2 hours after the last step and is revoked 2
  minutes after the import ends.
* Limits against crafted archives (checksum segment size, gzip expansion,
  repeated crash loops) and a disk space check before an import starts.
* The plugin's own folder is protected from being overwritten by an
  import regardless of upper/lower case.
* Release builds publish SHA256SUMS and a build provenance attestation;
  CI actions are pinned to exact versions.
* Translation template (languages/wp-free-site-cloner.pot) added.

= 0.9.1 =
* Archive integrity: the manifest records the exact archive size and a
  new last entry holds a SHA-256 checksum per 64 MB.
* Import starts with a "Verify archive" step that checks every checksum
  before anything is changed; damaged archives are refused with the
  position of the damage. Archives from 0.9.0 import with a warning.
* Truncated archives are detected right after the upload and on the
  confirmation screen, with received and expected byte counts.
* Progress: every step reports its current item and counters (tables,
  rows, files, bytes) and the time of the last progress.
* Downloads: output compression is switched off, ETag/Last-Modified and
  If-Range allow browsers to resume a broken download.

= 0.9.0 =
* Initial public beta.
