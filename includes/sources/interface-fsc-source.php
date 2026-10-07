<?php
/**
 * Archive source interface. A source knows one archive format and exposes
 * its metadata, its SQL dump and its wp-content files through resumable,
 * JSON-serializable cursors (plain arrays, start with array()).
 *
 * @package wp-free-site-cloner
 */

interface FSC_Source {

	/**
	 * Whether this source can read the file (by extension and signature).
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function detect( $path );

	/**
	 * Metadata of the archived site. Keys:
	 *   format       string  native|wpress|duplicator
	 *   home         string  old home URL
	 *   siteurl      string  old site URL
	 *   abspath      string  old ABSPATH ('' if unknown)
	 *   content_dir  string  old WP_CONTENT_DIR ('' if unknown)
	 *   uploads_url  string  old uploads base URL ('' if unknown)
	 *   prefix       string  old real table prefix ('' if unknown)
	 *   sql_prefix   string  table prefix as written in the SQL (placeholder or real prefix)
	 *   sql_entry    string  name of the SQL entry inside the archive
	 *   multisite    bool
	 *   wp_version   string
	 *   file_count   int|null
	 *   sql_sha256   string  ('' if unknown)
	 * Optional:
	 *   format_name      string  human-readable format for the UI
	 *   warnings         array   strings shown on the confirmation screen
	 *   key_placeholder  string  placeholder written for the prefix at the start of
	 *                            option names / user meta keys (restored to prefix on import)
	 *   active_plugins   array   plugins to add to active_plugins on import
	 *   theme            array   template/stylesheet used when the dump leaves them empty
	 *
	 * @return array
	 * @throws FSC_Exception When the archive is unreadable or unsupported.
	 */
	public function meta();

	/**
	 * Copy the SQL dump to $dest, resumable.
	 *
	 * @param string $dest     Destination file.
	 * @param array  $cursor   Cursor, updated in place.
	 * @param float  $deadline microtime deadline.
	 * @return bool True when complete.
	 * @throws FSC_Exception On read or write errors.
	 */
	public function extract_sql( $dest, array &$cursor, $deadline );

	/**
	 * Advance to the next wp-content entry. Unread data of the previous entry is skipped.
	 *
	 * @param array $cursor Cursor, updated in place.
	 * @return array|null array( path => relative to wp-content, type => file|dir|symlink|hardlink|other, size, mtime ) or null at the end.
	 * @throws FSC_Exception On a corrupt archive.
	 */
	public function files_next( array &$cursor );

	/**
	 * Read the next piece of the current entry ('' when exhausted).
	 *
	 * @param array $cursor Cursor, updated in place.
	 * @param int   $max    Maximum bytes.
	 * @return string
	 * @throws FSC_Exception On a corrupt archive.
	 */
	public function files_read( array &$cursor, $max );

	/**
	 * Progress of the file walk between 0 and 1.
	 *
	 * @param array $cursor Cursor.
	 * @return float
	 */
	public function files_progress( array $cursor );

	/**
	 * Largest total size (bytes) the wp-content entries may legitimately expand
	 * to while being extracted. The extractor refuses an archive as a
	 * decompression bomb once the bytes it has written pass this cap.
	 *
	 * @return float
	 * @throws FSC_Exception When the metadata needed for the estimate is unreadable.
	 */
	public function extract_budget();
}
