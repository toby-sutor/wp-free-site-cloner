<?php
/**
 * Database import into the temp prefix, search-replace on the temp tables
 * and the final table swap.
 *
 * @package wp-free-site-cloner
 */

/**
 * Database importer (requires WordPress).
 */
class FSC_DB_Import {

	const TEMP = 'fsctmp_';
	const OLD  = 'fscold_';

	/** @var callable|null Tests only: called right after the RENAME TABLE, before the job records it. */
	public static $rename_hook = null;

	/** @var callable|null Tests only: called after every constraint ALTER of fix_constraints(). */
	public static $cons_hook = null;

	/** Seconds the RENAME TABLE of the switch waits for locks on the tables before it gives up. */
	const RENAME_LOCK_WAIT = 30;

	/** Checkpoint after this many bytes of SQL inside one unit. */
	const UNIT_BYTES = 4194304;

	/** Rows per search-replace batch. */
	const SR_BATCH = 200;

	/** Memory budget of one search-replace batch in bytes (at least one row is always read). */
	const SR_BATCH_BYTES = 16777216;

	/**
	 * Drop every table with the given prefix.
	 *
	 * @param string $prefix fsctmp_ or fscold_.
	 * @return int Tables dropped.
	 */
	public static function drop_prefixed( $prefix ) {
		$n = 0;
		// Also called outside import steps (cron, admin, cancel): tables linked by
		// foreign keys only drop in any order with the checks off.
		$fkc = FSC_DB::fk_checks_off();
		try {
			foreach ( FSC_DB::tables_with_prefix( $prefix ) as $t ) {
				if ( preg_match( FSC_DB::IDENT, $t ) && FSC_DB::exec( 'DROP TABLE IF EXISTS ' . FSC_DB::ident( $t ) )['ok'] ) {
					++$n;
				}
			}
			foreach ( FSC_DB::views_with_prefix( $prefix ) as $v ) {
				if ( preg_match( FSC_DB::IDENT, $v ) ) {
					FSC_DB::exec( 'DROP VIEW IF EXISTS ' . FSC_DB::ident( $v ) );
				}
			}
		} finally {
			FSC_DB::fk_checks_restore( $fkc );
		}
		return $n;
	}

	/**
	 * Server capabilities used by the rewriter.
	 *
	 * @return array array( collations => name => charset, engines => list ).
	 */
	public static function capabilities() {
		$collations = array();
		try {
			foreach ( FSC_DB::rows( 'SHOW COLLATION' ) as $c ) {
				$collations[ $c['Collation'] ] = $c['Charset'];
			}
		} catch ( FSC_Exception $e ) {
			$collations = null;
		}
		$engines = array();
		try {
			foreach ( FSC_DB::rows( 'SHOW ENGINES' ) as $e ) {
				if ( in_array( strtoupper( $e['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
					$engines[] = $e['Engine'];
				}
			}
		} catch ( FSC_Exception $e ) {
			$engines = null;
		}
		return array(
			'collations'  => $collations ? $collations : null,
			'engines'     => $engines ? $engines : null,
			'check_names' => FSC_DB::check_names_schema_wide(),
		);
	}

	/**
	 * Prepare the DB phase: drop leftovers, probe the server.
	 *
	 * @param FSC_Job     $job Job.
	 * @param FSC_Storage $st  Storage.
	 * @throws FSC_Exception On errors.
	 */
	public static function prepare( FSC_Job $job, FSC_Storage $st ) {
		if ( 0 === strpos( FSC_DB::wpdb()->prefix, self::TEMP ) || 0 === strpos( FSC_DB::wpdb()->prefix, self::OLD ) ) {
			throw new FSC_Exception( 'The table prefix of this site collides with the temporary prefix ' . self::TEMP . '.' );
		}
		$n = self::drop_prefixed( self::TEMP );
		if ( $n ) {
			$job->log( sprintf( 'Removed %d leftover temporary tables.', $n ) );
		}
		$caps = self::capabilities();
		file_put_contents( $st->tmp_file( $job->data['id'], 'caps' ), json_encode( $caps ) );
		$sql = $st->tmp_file( $job->data['id'], 'sql' );
		@unlink( $st->tmp_file( $job->data['id'], 'mark' ) );
		$names = 'utf8mb4';
		if ( is_array( $caps['collations'] ) && ! in_array( 'utf8mb4', $caps['collations'], true ) ) {
			$names = 'utf8';
		}
		$job->set(
			'db',
			array(
				'offset'    => 0,
				'delimiter' => ';',
				'names'     => $names,
				'executed'  => 0,
				'skipped'   => 0,
				'size'      => max( 1, (int) @filesize( $sql ) ),
			)
		);
	}

	/**
	 * Rewriter for this job.
	 *
	 * @param FSC_Job     $job Job.
	 * @param FSC_Storage $st  Storage.
	 * @return FSC_SQL_Rewriter
	 */
	private static function rewriter( FSC_Job $job, FSC_Storage $st ) {
		$caps = json_decode( (string) @file_get_contents( $st->tmp_file( $job->data['id'], 'caps' ) ), true );
		$rw   = new FSC_SQL_Rewriter(
			$job->data['meta']['sql_prefix'],
			self::TEMP,
			isset( $caps['collations'] ) ? $caps['collations'] : null,
			isset( $caps['engines'] ) ? $caps['engines'] : null
		);
		$meta = $job->data['meta'];
		$live = FSC_DB::wpdb()->prefix;
		$real = '' !== (string) $meta['prefix'] ? (string) $meta['prefix'] : $live;
		// Unknown (job started by an older version): treat CHECK names as schema-wide; fix_constraints() copes either way.
		$rw->set_constraints( $job->data['id'], array( $meta['sql_prefix'], $meta['prefix'] ), $real, $live, isset( $caps['check_names'] ) ? (bool) $caps['check_names'] : true );
		if ( ! empty( $meta['key_placeholder'] ) ) {
			// Put the archive's real prefix back; swap() then renames it to the live prefix.
			$rw->set_key_placeholder( $meta['key_placeholder'], '' !== (string) $meta['prefix'] ? $meta['prefix'] : FSC_DB::wpdb()->prefix );
		}
		return $rw;
	}

	/**
	 * Import statements until the deadline or UNIT_BYTES.
	 *
	 * @param FSC_Job     $job      Job.
	 * @param FSC_Storage $st       Storage.
	 * @param float       $deadline Deadline.
	 * @param bool        $resumed  True on the first unit after an interrupted request.
	 * @return bool True when the dump is fully imported.
	 * @throws FSC_Exception On a failing statement.
	 */
	public static function import_step( FSC_Job $job, FSC_Storage $st, $deadline, $resumed ) {
		$db       = $job->get( 'db' );
		$id       = $job->data['id'];
		$mark     = $st->tmp_file( $id, 'mark' );
		$replay   = 0;
		if ( $resumed && is_file( $mark ) ) {
			$replay = (int) trim( (string) file_get_contents( $mark ) );
			if ( $replay > $db['offset'] ) {
				$job->log( sprintf( 'Replaying statements between byte %d and %d.', $db['offset'], $replay ) );
			}
		}
		FSC_DB::session( $db['names'] );
		$rw     = self::rewriter( $job, $st );
		$reader = new FSC_SQL_Reader( $st->tmp_file( $id, 'sql' ), $db['offset'], $db['delimiter'], self::max_statement() );
		$mfp    = fopen( $mark, 'c' );
		$start  = $db['offset'];
		$done   = false;
		while ( true ) {
			$stmt = $reader->next();
			if ( null === $stmt ) {
				$done = true;
				break;
			}
			$pos = $reader->statement_offset();
			$r   = $rw->rewrite( $stmt );
			if ( 'names' === $r['action'] ) {
				if ( empty( $r['allowed'] ) ) {
					// Only temp tables exist at this point; the failure path drops them and reports the site unchanged.
					throw new FSC_Exception(
						sprintf(
							/* translators: %s: connection charset name from the dump. */
							__( 'The database dump selects the connection charset "%s", which this plugin refuses because the database server does not read statement text under it the same way the import guard does (a crafted dump could slip past the guard). Dumps using big5, gbk, sjis, cp932 or gb18030 cannot be imported safely. Nothing was changed on this site.', 'wp-free-site-cloner' ),
							FSC_SQL_Rewriter::display_name( $r['charset'] )
						)
					);
				}
				$db['names'] = $r['charset'];
				FSC_DB::session( $db['names'] );
			} elseif ( 'skip' === $r['action'] ) {
				++$db['skipped'];
				if ( $db['skipped'] <= 50 && 'executable comment' !== $r['reason'] && 'statement type not imported' !== $r['reason'] ) {
					// Kind, table and reason only: the statement itself may carry row data.
					$job->log( sprintf( 'Skipped %s statement%s at byte %d: %s.', strtoupper( $r['kind'] ), '' !== $r['table'] ? ' for table ' . $r['table'] : '', $pos, $r['reason'] ) );
					FSC_DB::debug_log( 'Skipped statement at byte ' . $pos . ' (' . $r['reason'] . ').', $stmt );
				}
			} else {
				if ( ! empty( $r['cons'] ) ) {
					// Temp name => original name; recorded before the statement runs (replay-safe: names are deterministic).
					$job->set( 'cons', $r['cons'] + (array) $job->get( 'cons', array() ) );
				}
				self::run_statement( $r['sql'], $rw, $pos, $reader->offset() <= $replay, $job );
				++$db['executed'];
				// Progress detail only: which table the dump is at.
				if ( preg_match( '/^\s*(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+`?([^`\s(]+)/i', substr( $stmt, 0, 256 ), $m ) ) {
					if ( 0 === stripos( $m[1], 'CREATE' ) ) {
						$db['tables_created'] = ( isset( $db['tables_created'] ) ? (int) $db['tables_created'] : 0 ) + 1;
					}
					$db['cur_table'] = $m[2];
				}
			}
			if ( $mfp ) {
				fseek( $mfp, 0 );
				fwrite( $mfp, str_pad( (string) $reader->offset(), 20 ) );
			}
			$db['offset']    = $reader->offset();
			$db['delimiter'] = $reader->delimiter();
			if ( microtime( true ) >= $deadline || $db['offset'] - $start >= self::UNIT_BYTES ) {
				break;
			}
		}
		if ( $mfp ) {
			fclose( $mfp );
		}
		$reader->close();
		$job->set( 'db', $db );
		return $done;
	}

	/**
	 * Largest statement this request can hold: a statement is in memory about
	 * four times (buffer, statement, rewrite, driver), so a quarter of the free
	 * memory, capped at 256 MB. A larger one fails with a clear error instead
	 * of a PHP out-of-memory fatal.
	 *
	 * @return int Bytes.
	 */
	public static function max_statement() {
		$cap   = 268435456;
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		if ( $limit <= 0 ) {
			return $cap;
		}
		return (int) max( 4194304, min( $cap, ( $limit - memory_get_usage() ) / 4 ) );
	}

	/**
	 * Execute one rewritten statement with one fallback retry.
	 *
	 * @param string           $sql    Statement.
	 * @param FSC_SQL_Rewriter $rw     Rewriter.
	 * @param int              $pos    Byte offset of the statement.
	 * @param bool             $replay Statement may already have run before a crash.
	 * @param FSC_Job          $job    Job.
	 * @throws FSC_Exception When it fails.
	 */
	private static function run_statement( $sql, FSC_SQL_Rewriter $rw, $pos, $replay, FSC_Job $job ) {
		$r = FSC_DB::exec( $sql );
		if ( $r['ok'] ) {
			return;
		}
		if ( $replay && in_array( $r['errno'], array( 1062, 1050 ), true ) ) {
			return;
		}
		$retry = $rw->retry_rewrite( $sql );
		if ( $retry !== $sql ) {
			$r2 = FSC_DB::exec( $retry );
			if ( $r2['ok'] ) {
				$job->log( sprintf( 'Statement at byte %d succeeded after a compatibility rewrite (first error: %s).', $pos, FSC_DB::safe_error( $r['error'] ) ) );
				return;
			}
			$r = $r2;
		}
		$hint = '';
		if ( in_array( $r['errno'], array( 2006, 1153, 2013 ), true ) ) {
			$hint = ' The statement may be larger than the MySQL max_allowed_packet setting.';
		}
		FSC_DB::debug_log( 'Database import failed at byte ' . $pos . ': [' . $r['errno'] . '] ' . $r['error'], $sql );
		throw new FSC_Exception( sprintf( 'Database import failed at byte %d: [%d] %s.%s Statement: %s', $pos, $r['errno'], FSC_DB::safe_error( $r['error'] ), $hint, FSC_DB::snippet( $sql ) ) );
	}

	/**
	 * Progress of the import between 0 and 1.
	 *
	 * @param array $db Cursor.
	 * @return float
	 */
	public static function import_progress( array $db ) {
		return min( 1, $db['offset'] / max( 1, $db['size'] ) );
	}

	/**
	 * Set up search-replace over all temp tables.
	 *
	 * @param FSC_Job $job Job.
	 * @throws FSC_Exception When the dump did not create an options table.
	 */
	public static function replace_init( FSC_Job $job ) {
		$tables = FSC_DB::tables_with_prefix( self::TEMP );
		if ( ! in_array( self::TEMP . 'options', $tables, true ) ) {
			throw new FSC_Exception( 'The database dump did not contain an options table for the expected table prefix. Nothing was changed on this site.' );
		}
		$job->set(
			'sr',
			array(
				'tables'  => $tables,
				'ti'      => 0,
				'table'   => null,
				'changed' => 0,
			)
		);
	}

	/**
	 * One search-replace batch. Replay-safe: see FSC_SR_Journal.
	 *
	 * @param FSC_Job     $job Job.
	 * @param FSC_Storage $st  Storage (journal file).
	 * @return bool True when all tables are processed.
	 * @throws FSC_Exception On database errors.
	 */
	public static function replace_step( FSC_Job $job, FSC_Storage $st ) {
		$sr = $job->get( 'sr' );
		if ( $sr['ti'] >= count( $sr['tables'] ) ) {
			self::force_urls( $job );
			return true;
		}
		$engine = new FSC_Search_Replace( $job->data['pairs'] );
		FSC_DB::session();
		if ( null === $sr['table'] ) {
			$t     = $sr['tables'][ $sr['ti'] ];
			$cols  = FSC_DB::columns( $t );
			$text  = array();
			$all   = array();
			foreach ( $cols as $c ) {
				if ( $c['generated'] || ! preg_match( FSC_DB::IDENT, $c['name'] ) ) {
					continue;
				}
				$all[] = $c['name'];
				if ( FSC_DB::is_text_type( $c['type'] ) ) {
					$text[] = $c['name'];
				}
			}
			$pk          = FSC_DB::primary_key( $t );
			$sr['table'] = array(
				'name'   => $t,
				'pk'     => $pk,
				'text'   => array_values( array_diff( $text, $pk ) ),
				'all'    => $all,
				'last'   => null,
				'offset' => 0,
			);
			if ( empty( $sr['table']['text'] ) || empty( $engine->pairs() ) ) {
				$sr['table'] = null;
				++$sr['ti'];
			}
			$job->set( 'sr', $sr );
			return false;
		}

		$tb   = $sr['table'];
		$q    = FSC_DB::ident( $tb['name'] );
		$text = $tb['text'];
		if ( $tb['pk'] ) {
			$sel   = array_unique( array_merge( $tb['pk'], $text ) );
			$where = array();
			$likes = array();
			foreach ( $text as $c ) {
				foreach ( $engine->like_terms() as $term ) {
					$likes[] = FSC_DB::ident( $c ) . " LIKE '%" . FSC_DB::escape( FSC_DB::esc_like( $term ) ) . "%'";
				}
			}
			$where[] = '(' . implode( ' OR ', $likes ) . ')';
			if ( null !== $tb['last'] ) {
				$where[] = '(' . implode( ',', array_map( array( 'FSC_DB', 'ident' ), $tb['pk'] ) ) . ') > (' . implode( ',', array_map( array( 'FSC_DB', 'quote' ), $tb['last'] ) ) . ')';
			}
			$sql = 'SELECT ' . implode( ',', array_map( array( 'FSC_DB', 'ident' ), $sel ) ) . " FROM $q WHERE " . implode( ' AND ', $where )
				. ' ORDER BY ' . implode( ',', array_map( array( 'FSC_DB', 'ident' ), $tb['pk'] ) ) . ' LIMIT ' . self::SR_BATCH;
		} else {
			$sql = 'SELECT ' . implode( ',', array_map( array( 'FSC_DB', 'ident' ), $tb['all'] ) ) . " FROM $q LIMIT " . self::SR_BATCH . ' OFFSET ' . (int) $tb['offset'];
		}
		$fetch   = FSC_DB::rows_limited( $sql, self::SR_BATCH_BYTES );
		$rows    = $fetch['rows'];
		$journal = new FSC_SR_Journal( $st->tmp_file( $job->data['id'], 'srj' ) );
		$journal->begin( $tb['name'], $tb['pk'] ? $tb['last'] : $tb['offset'] );
		$pk      = $tb['pk'];
		$offset  = (int) $tb['offset'];
		$sr['changed'] += $journal->process(
			$rows,
			$text,
			function ( $row, $i ) use ( $pk, $offset ) {
				if ( ! $pk ) {
					return 'o' . ( $offset + $i );
				}
				$k = array();
				foreach ( $pk as $c ) {
					$k[] = $row[ $c ];
				}
				return json_encode( $k );
			},
			$engine,
			function ( $row, $new ) use ( $q, $tb ) {
				$set = array();
				foreach ( $new as $c => $v ) {
					$set[] = FSC_DB::ident( $c ) . ' = ' . FSC_DB::quote( $v );
				}
				$cond = array();
				foreach ( ( $tb['pk'] ? $tb['pk'] : $tb['all'] ) as $c ) {
					$cond[] = FSC_DB::ident( $c ) . ( null === $row[ $c ] ? ' IS NULL' : ' = ' . FSC_DB::quote( $row[ $c ] ) );
				}
				FSC_DB::must( "UPDATE $q SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) . ' LIMIT 1' );
			}
		);
		$journal->close();
		$n = count( $rows );
		if ( $tb['pk'] && $n > 0 ) {
			$last                = end( $rows );
			$sr['table']['last'] = array();
			foreach ( $tb['pk'] as $c ) {
				$sr['table']['last'][] = $last[ $c ];
			}
		}
		$sr['table']['offset'] += $n;
		if ( $fetch['complete'] && $n < self::SR_BATCH ) {
			$sr['table'] = null;
			++$sr['ti'];
		}
		$job->set( 'sr', $sr );
		return false;
	}

	/**
	 * Progress of search-replace between 0 and 1.
	 *
	 * @param array $sr Cursor.
	 * @return float
	 */
	public static function replace_progress( array $sr ) {
		return min( 1, $sr['ti'] / max( 1, count( $sr['tables'] ) ) );
	}

	/**
	 * Set home and siteurl in the temp options table to this site's values.
	 *
	 * @param FSC_Job $job Job.
	 */
	private static function force_urls( FSC_Job $job ) {
		$t = FSC_DB::ident( self::TEMP . 'options' );
		foreach ( array( 'home', 'siteurl' ) as $opt ) {
			FSC_DB::must( "UPDATE $t SET option_value = " . FSC_DB::quote( $job->data['target'][ $opt ] ) . " WHERE option_name = '" . $opt . "'" );
		}
	}

	/**
	 * Prepare the swap on the temp tables only (URLs, prefixed keys, active
	 * plugins, theme, compatibility net, transients). Replay-safe: every
	 * change is idempotent. Nothing live changes here.
	 *
	 * @param FSC_Job $job   Job.
	 * @param string  $stage Staging directory with the extracted wp-content
	 *                       (plugin and theme headers are read from there first), or ''.
	 * @throws FSC_Exception On errors.
	 */
	public static function swap_prepare( FSC_Job $job, $stage = '' ) {
		$live = FSC_DB::wpdb()->prefix;
		FSC_DB::ident( $live . 'x' );
		FSC_DB::session();
		$temp = FSC_DB::tables_with_prefix( self::TEMP );
		if ( ! in_array( self::TEMP . 'options', $temp, true ) ) {
			throw new FSC_Exception( 'Temporary tables are missing; the import cannot be completed. The site was not changed.' );
		}
		$opt = FSC_DB::ident( self::TEMP . 'options' );
		self::force_urls( $job );

		$old = (string) $job->data['meta']['prefix'];
		if ( '' !== $old && $old !== $live && preg_match( FSC_DB::IDENT, $old ) ) {
			$exists = FSC_DB::value( "SELECT COUNT(*) FROM $opt WHERE option_name = " . FSC_DB::quote( $old . 'user_roles' ) );
			if ( (int) $exists > 0 ) {
				FSC_DB::must( "DELETE FROM $opt WHERE option_name = " . FSC_DB::quote( $live . 'user_roles' ) );
				FSC_DB::must( "UPDATE $opt SET option_name = " . FSC_DB::quote( $live . 'user_roles' ) . ' WHERE option_name = ' . FSC_DB::quote( $old . 'user_roles' ) );
			}
			if ( in_array( self::TEMP . 'usermeta', $temp, true ) ) {
				$um = FSC_DB::ident( self::TEMP . 'usermeta' );
				FSC_DB::must(
					"UPDATE $um SET meta_key = CONCAT(" . FSC_DB::quote( $live ) . ', SUBSTRING(meta_key, ' . ( strlen( $old ) + 1 ) . "))
					WHERE meta_key LIKE '" . FSC_DB::escape( FSC_DB::esc_like( $old ) ) . "%' AND LEFT(meta_key, " . strlen( $old ) . ') = BINARY ' . FSC_DB::quote( $old )
					. ( 0 === strpos( $live, $old ) ? ' AND LEFT(meta_key, ' . strlen( $live ) . ') <> BINARY ' . FSC_DB::quote( $live ) : '' )
				);
			}
			$job->log( sprintf( 'Renamed prefixed option and user meta keys from %s to %s.', $old, $live ) );
		}

		$meta  = $job->data['meta'];
		$extra = isset( $meta['active_plugins'] ) && is_array( $meta['active_plugins'] ) ? $meta['active_plugins'] : array();
		self::fix_active_plugins( $opt, $job->data['plugin_basename'], $extra );
		if ( ! empty( $meta['theme'] ) && is_array( $meta['theme'] ) ) {
			self::fill_theme( $opt, $meta['theme'] );
		}
		self::compat_plugins( $opt, $job, $stage );
		self::compat_theme( $opt, $job, $stage );
		FSC_DB::must( "DELETE FROM $opt WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'" );
	}

	/**
	 * Whether the temp tables still exist, for FSC_Recovery::commit_state().
	 * Asked on a connection that works: wpdb first replaces a handle that
	 * died (for example during the RENAME TABLE itself).
	 *
	 * @return bool|null Null when the database cannot tell.
	 */
	public static function temp_exists() {
		try {
			$wpdb = FSC_DB::wpdb();
			if ( ! is_object( $wpdb ) || ( method_exists( $wpdb, 'check_connection' ) && ! $wpdb->check_connection( false ) ) ) {
				return null;
			}
			return FSC_Recovery::temp_state(
				function ( $sql ) {
					try {
						return FSC_DB::rows_num( $sql );
					} catch ( FSC_Exception $e ) {
						return null;
					}
				}
			);
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether the RENAME TABLE (commit point) of an import has run:
	 * FSC_Recovery::commit_state() with this site's database.
	 *
	 * @param FSC_Job $job Job (the caller holds its lock).
	 * @return string FSC_Recovery::COMMITTED, NOT_COMMITTED or UNKNOWN.
	 */
	public static function commit_state( FSC_Job $job ) {
		return FSC_Recovery::commit_state( $job, array( __CLASS__, 'temp_exists' ) );
	}

	/**
	 * Swap the temp tables in with one atomic RENAME TABLE (the only moment
	 * the live database changes). Run swap_prepare() first.
	 *
	 * @param FSC_Job $job Job.
	 * @return bool True when the tables are swapped. False when it cannot
	 *              be told yet whether a RENAME started by an earlier request
	 *              ran (nothing was done; try again with the next request).
	 * @throws FSC_Exception When the tables were not swapped.
	 */
	public static function swap_rename( FSC_Job $job ) {
		$live = FSC_DB::wpdb()->prefix;
		FSC_DB::ident( $live . 'x' );
		if ( ! empty( $job->data['swapping'] ) ) {
			// An earlier request started the RENAME: it is decided before another one is sent.
			$state = self::commit_state( $job );
			if ( FSC_Recovery::COMMITTED === $state ) {
				self::drop_prefixed( self::OLD );
				return true;
			}
			if ( FSC_Recovery::UNKNOWN === $state ) {
				return false;
			}
		}
		FSC_DB::session();
		$temp = FSC_DB::tables_with_prefix( self::TEMP );
		if ( ! in_array( self::TEMP . 'options', $temp, true ) ) {
			throw new FSC_Exception( 'Temporary tables are missing; the import cannot be completed.' );
		}
		self::drop_prefixed( self::OLD );
		$foreign  = FSC_DB_Export::foreign_prefixes( $live );
		$pairs    = array();
		$replaced = array();
		foreach ( $temp as $i => $t ) {
			$suffix = substr( $t, strlen( self::TEMP ) );
			$target = $live . $suffix;
			foreach ( $foreign as $f ) {
				if ( 0 === strpos( $target, $f ) ) {
					FSC_DB::exec( 'DROP TABLE IF EXISTS ' . FSC_DB::ident( $t ) );
					$job->log( sprintf( 'Skipped table %s: its name belongs to another WordPress installation in this database (prefix %s).', $target, $f ) );
					unset( $temp[ $i ] );
					continue 2;
				}
			}
			FSC_DB::ident( $target );
			FSC_DB::ident( self::OLD . $suffix );
			if ( null !== FSC_DB::value( "SHOW TABLES LIKE '" . FSC_DB::escape( FSC_DB::esc_like( $target ) ) . "'" ) ) {
				$pairs[]             = FSC_DB::ident( $target ) . ' TO ' . FSC_DB::ident( self::OLD . $suffix );
				$replaced[ $target ] = true;
			}
			$pairs[] = FSC_DB::ident( $t ) . ' TO ' . FSC_DB::ident( $target );
		}
		$triggers = self::triggers_on( $replaced );
		// Tables in use (a backup, a long transaction) must not hold the switch, and maintenance mode, without limit.
		FSC_DB::exec( 'SET SESSION lock_wait_timeout = ' . self::RENAME_LOCK_WAIT );
		$job->data['swapping'] = true;
		try {
			$job->save();
		} catch ( FSC_Exception $e ) {
			// Nothing was sent: this failure is certainly before the commit point.
			$job->data['swapping'] = false;
			throw $e;
		}
		$sql = 'RENAME TABLE ' . implode( ', ', $pairs );
		$res = FSC_DB::exec( $sql );
		if ( ! $res['ok'] ) {
			if ( 1205 === $res['errno'] ) {
				throw new FSC_Exception( sprintf( 'The tables of the site stayed locked by other database activity (a backup or a long-running query) for %d seconds, so the database was not switched.', self::RENAME_LOCK_WAIT ) );
			}
			throw new FSC_Exception( FSC_DB::error_message( $res['errno'], $res['error'], $sql ) );
		}
		if ( null !== self::$rename_hook ) {
			call_user_func( self::$rename_hook );
		}
		$job->data['swapped']  = true;
		$job->data['phase']    = 'finalize';
		$job->save();
		$job->log( sprintf( 'Swapped in %d tables.', count( $temp ) ) );
		foreach ( $triggers as $tr ) {
			$job->log( sprintf( 'WARNING: Trigger %s on table %s was removed with the replaced table (archives do not contain triggers).', $tr[0], $tr[1] ) );
		}
		$n = self::drop_prefixed( self::OLD );
		$job->log( sprintf( 'Dropped %d replaced tables.', $n ) );
		return true;
	}

	/**
	 * Triggers on the given tables (best effort: needs the TRIGGER privilege to see them).
	 *
	 * @param array $tables Table name => true.
	 * @return array List of array( trigger, table ).
	 */
	private static function triggers_on( array $tables ) {
		$out = array();
		if ( empty( $tables ) ) {
			return $out;
		}
		try {
			foreach ( FSC_DB::rows( 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME' ) as $r ) {
				if ( isset( $tables[ $r['EVENT_OBJECT_TABLE'] ] ) ) {
					$out[] = array( FSC_SQL_Rewriter::display_name( $r['TRIGGER_NAME'] ), $r['EVENT_OBJECT_TABLE'] );
				}
			}
		} catch ( FSC_Exception $e ) {
			unset( $e );
		}
		return $out;
	}

	/**
	 * Whether a table belongs to this site (live prefix, not a temp/old
	 * table, not another WordPress install whose prefix starts with ours).
	 *
	 * @param string $t       Table.
	 * @param string $live    Live prefix.
	 * @param array  $foreign Foreign prefixes.
	 * @return bool
	 */
	private static function own_table( $t, $live, array $foreign ) {
		if ( 0 !== strpos( $t, $live ) || 0 === strpos( $t, self::TEMP ) || 0 === strpos( $t, self::OLD ) || ! preg_match( FSC_DB::IDENT, $t ) ) {
			return false;
		}
		foreach ( $foreign as $f ) {
			if ( 0 === strpos( $t, $f ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * After the commit point (finalize): give the constraints of the
	 * imported tables their final names and point foreign keys that still
	 * reference fscold_/fsctmp_ tables at the live tables. Every change is one
	 * atomic ALTER TABLE; the work is read from information_schema on every
	 * call, so a killed request simply continues. A failing constraint is
	 * logged as WARNING and kept under its temporary name.
	 *
	 * @param FSC_Job $job      Job.
	 * @param float   $deadline Deadline (microtime).
	 * @return bool True when done.
	 */
	public static function fix_constraints( FSC_Job $job, $deadline ) {
		$live    = FSC_DB::wpdb()->prefix;
		$foreign = FSC_DB_Export::foreign_prefixes( $live );
		$map     = (array) $job->get( 'cons', array() );
		$failed  = (array) $job->get( 'cons_failed', array() );
		$like    = function ( $p ) {
			return "'" . FSC_DB::escape( FSC_DB::esc_like( $p ) ) . "%'";
		};
		$fkc     = FSC_DB::fk_checks_off();
		$moved   = 0;
		$named   = 0;
		try {
			// 1. Foreign keys whose parent was renamed away (InnoDB follows the parent's rename).
			$rows = FSC_DB::rows(
				'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND (REFERENCED_TABLE_NAME LIKE ' . $like( self::OLD ) . ' OR REFERENCED_TABLE_NAME LIKE ' . $like( self::TEMP ) . ') ORDER BY TABLE_NAME, CONSTRAINT_NAME'
			);
			foreach ( $rows as $r ) {
				$t    = $r['TABLE_NAME'];
				$name = $r['CONSTRAINT_NAME'];
				if ( ! self::own_table( $t, $live, $foreign ) || isset( $failed[ $t . '/' . $name ] ) ) {
					continue;
				}
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				$parent = $live . substr( $r['REFERENCED_TABLE_NAME'], strlen( self::TEMP ) );
				$tmp    = FSC_SQL_Rewriter::temp_constraint_name( $job->data['id'], substr( $t, strlen( $live ) ), 'r:' . $name );
				$clause = FSC_SQL_Rewriter::constraint_clause( self::show_create( $t ), $name );
				$clause = null === $clause || ! preg_match( FSC_DB::IDENT, $parent ) ? null : FSC_SQL_Rewriter::point_references( $clause, $parent );
				$err    = null === $clause ? 'definition not found' : '';
				if ( '' === $err ) {
					$map[ $tmp ] = isset( $map[ $name ] ) ? $map[ $name ] : $name;
					$job->set( 'cons', $map );
					$job->save();
					$err = self::alter_constraint( $t, 'DROP FOREIGN KEY ' . FSC_DB::quote_name( $name ), 'ADD CONSTRAINT ' . FSC_DB::quote_name( $tmp ) . ' ' . $clause );
					$moved += '' === $err ? 1 : 0;
				}
				if ( '' !== $err ) {
					$failed[ $t . '/' . $name ] = true;
					$job->set( 'cons_failed', $failed );
					$job->log( sprintf( 'WARNING: The foreign key %s on table %s still references the removed table %s and could not be pointed at %s (%s). Re-create it, or the table may refuse new rows.', FSC_SQL_Rewriter::display_name( $name ), $t, $r['REFERENCED_TABLE_NAME'], $parent, $err ) );
				}
			}

			// 2. Temporary names back to the original (or live auto-style) names.
			$rows    = FSC_DB::rows(
				"SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('FOREIGN KEY', 'CHECK') AND CONSTRAINT_NAME LIKE " . $like( self::TEMP ) . ' ORDER BY TABLE_NAME, CONSTRAINT_NAME'
			);
			$mariadb = FSC_DB::server()['mariadb'];
			foreach ( $rows as $r ) {
				$t    = $r['TABLE_NAME'];
				$name = $r['CONSTRAINT_NAME'];
				if ( ! self::own_table( $t, $live, $foreign ) || isset( $failed[ $t . '/' . $name ] ) ) {
					continue;
				}
				$fk   = 'FOREIGN KEY' === strtoupper( $r['CONSTRAINT_TYPE'] );
				$want = null;
				if ( isset( $map[ $name ] ) ) {
					$want = (string) $map[ $name ];
				} elseif ( preg_match( '/^' . preg_quote( self::TEMP, '/' ) . '(.+)_(ibfk|chk)_(\d+)$/', $name, $m ) && $m[1] === substr( $t, strlen( $live ) ) && ( $fk ? 'ibfk' : 'chk' ) === $m[2] ) {
					// Auto-style name the RENAME did not follow (MariaDB keeps CHECK names).
					$want = $t . '_' . $m[2] . '_' . $m[3];
				}
				if ( null === $want || '' === $want || strlen( $want ) > 64 ) {
					continue;
				}
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				$clause = FSC_SQL_Rewriter::constraint_clause( self::show_create( $t ), $name );
				$err    = 'definition not found';
				if ( null !== $clause ) {
					$drop = $fk ? 'DROP FOREIGN KEY ' : ( $mariadb ? 'DROP CONSTRAINT ' : 'DROP CHECK ' );
					$err  = self::alter_constraint( $t, $drop . FSC_DB::quote_name( $name ), 'ADD CONSTRAINT ' . FSC_DB::quote_name( $want ) . ' ' . $clause );
					$named += '' === $err ? 1 : 0;
				}
				if ( '' !== $err ) {
					$failed[ $t . '/' . $name ] = true;
					$job->set( 'cons_failed', $failed );
					$job->log( sprintf( 'WARNING: The %s constraint %s on table %s keeps its temporary name %s: renaming it failed (%s). It works the same; rename it by hand if a plugin looks for it by name.', $fk ? 'foreign key' : 'CHECK', FSC_SQL_Rewriter::display_name( $want ), $t, $name, $err ) );
				}
			}
		} finally {
			FSC_DB::fk_checks_restore( $fkc );
			if ( $moved > 0 ) {
				$job->log( sprintf( 'Pointed %d foreign keys of tables that are not in the archive at the imported tables.', $moved ) );
			}
			if ( $named > 0 ) {
				$job->log( sprintf( 'Gave %d database constraints their original names.', $named ) );
			}
		}
		return true;
	}

	/**
	 * SHOW CREATE TABLE text ('' on errors).
	 *
	 * @param string $t Table.
	 * @return string
	 */
	private static function show_create( $t ) {
		try {
			$row = FSC_DB::rows_num( 'SHOW CREATE TABLE ' . FSC_DB::ident( $t ) );
		} catch ( FSC_Exception $e ) {
			return '';
		}
		return isset( $row[0][1] ) ? (string) $row[0][1] : '';
	}

	/**
	 * One ALTER TABLE that drops a constraint and adds its replacement,
	 * in place where the server can (no table copy), otherwise with its
	 * default algorithm.
	 *
	 * @param string $t    Table.
	 * @param string $drop DROP clause.
	 * @param string $add  ADD clause.
	 * @return string '' on success, else the (value-free) error text.
	 */
	private static function alter_constraint( $t, $drop, $add ) {
		$sql = 'ALTER TABLE ' . FSC_DB::ident( $t ) . ' ' . $drop . ', ' . $add;
		$r   = FSC_DB::exec( $sql . ', ALGORITHM=INPLACE' );
		if ( ! $r['ok'] && in_array( $r['errno'], array( 1845, 1846 ), true ) ) {
			$r = FSC_DB::exec( $sql );
		}
		if ( null !== self::$cons_hook ) {
			call_user_func( self::$cons_hook, $r['ok'] );
		}
		if ( $r['ok'] ) {
			return '';
		}
		FSC_DB::debug_log( 'Constraint rename failed [' . $r['errno'] . '] ' . $r['error'], $sql );
		return '[' . $r['errno'] . '] ' . FSC_DB::safe_error( $r['error'] );
	}

	/**
	 * Requirement headers of a plugin or theme file, completed from a
	 * readme.txt next to it (plugins often declare them only there).
	 *
	 * @param string $file   Main plugin file or style.css.
	 * @param string $readme readme.txt path or ''.
	 * @return array array( wp => version, php => version ).
	 */
	private static function requirements( $file, $readme = '' ) {
		$keys = array(
			'wp'  => 'Requires at least',
			'php' => 'Requires PHP',
		);
		$h    = get_file_data( $file, $keys );
		if ( '' !== $readme && is_file( $readme ) && ( '' === $h['wp'] || '' === $h['php'] ) ) {
			$r = get_file_data( $readme, $keys );
			foreach ( $keys as $k => $unused ) {
				if ( '' === $h[ $k ] ) {
					$h[ $k ] = $r[ $k ];
				}
			}
		}
		return $h;
	}

	/**
	 * Why this server cannot run something with these requirements, or ''.
	 *
	 * @param array $req array( wp, php ).
	 * @return string
	 */
	private static function unmet( array $req ) {
		global $wp_version;
		$wp  = trim( (string) $req['wp'] );
		$php = trim( (string) $req['php'] );
		if ( preg_match( '/^\d+(\.\d+)*$/', $wp ) && version_compare( $wp_version, $wp, '<' ) ) {
			return sprintf( 'requires WordPress %s, this site runs %s', $wp, $wp_version );
		}
		if ( preg_match( '/^\d+(\.\d+)*$/', $php ) && version_compare( PHP_VERSION, $php, '<' ) ) {
			return sprintf( 'requires PHP %s, this server runs %s', $php, PHP_VERSION );
		}
		return '';
	}

	/**
	 * Deactivate imported plugins that need a newer WordPress or PHP than this
	 * server has, so the first request after the swap does not white-screen.
	 *
	 * @param string  $opt Quoted options table.
	 * @param FSC_Job $job Job.
	 */
	private static function compat_plugins( $opt, FSC_Job $job, $stage = '' ) {
		$raw     = FSC_DB::value( "SELECT option_value FROM $opt WHERE option_name = 'active_plugins'" );
		$plugins = null === $raw ? array() : @unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( ! is_array( $plugins ) ) {
			return;
		}
		$keep = array();
		$off  = 0;
		foreach ( $plugins as $p ) {
			$file = '';
			if ( is_string( $p ) && false === strpos( $p, '..' ) ) {
				// The staged copy replaces the live one at the switch.
				$file = '' !== $stage && is_file( $stage . '/plugins/' . $p ) ? $stage . '/plugins/' . $p : WP_PLUGIN_DIR . '/' . $p;
			}
			if ( '' === $file || $p === $job->data['plugin_basename'] || ! is_file( $file ) ) {
				$keep[] = $p;
				continue;
			}
			$why = self::unmet( self::requirements( $file, false === strpos( $p, '/' ) ? '' : dirname( $file ) . '/readme.txt' ) );
			if ( '' === $why ) {
				$keep[] = $p;
				continue;
			}
			++$off;
			$job->log( sprintf( 'WARNING: Deactivated plugin %s: it %s. Update WordPress or PHP, then activate it again.', $p, $why ) );
		}
		if ( $off ) {
			FSC_DB::must( "UPDATE $opt SET option_value = " . FSC_DB::quote( serialize( array_values( $keep ) ) ) . " WHERE option_name = 'active_plugins'" );
		}
	}

	/**
	 * Switch to a bundled default theme when the imported theme (or its
	 * parent) needs a newer WordPress or PHP than this server has.
	 *
	 * @param string  $opt Quoted options table.
	 * @param FSC_Job $job Job.
	 */
	private static function compat_theme( $opt, FSC_Job $job, $stage = '' ) {
		$live = WP_CONTENT_DIR . '/themes';
		// Theme folder after the switch: the staged one replaces the live one.
		$dir = function ( $slug ) use ( $live, $stage ) {
			return '' !== $stage && is_dir( $stage . '/themes/' . $slug ) ? $stage . '/themes/' . $slug : $live . '/' . $slug;
		};
		$why = '';
		$bad = '';
		foreach ( array( 'stylesheet', 'template' ) as $key ) {
			$slug = (string) FSC_DB::value( "SELECT option_value FROM $opt WHERE option_name = '$key'" );
			if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) || ! is_file( $dir( $slug ) . '/style.css' ) ) {
				continue;
			}
			$why = self::unmet( self::requirements( $dir( $slug ) . '/style.css', $dir( $slug ) . '/readme.txt' ) );
			if ( '' !== $why ) {
				$bad = $slug;
				break;
			}
		}
		if ( '' === $bad ) {
			return;
		}
		$candidates = array();
		if ( defined( 'WP_DEFAULT_THEME' ) ) {
			$candidates[] = (string) WP_DEFAULT_THEME;
		}
		$bundled = array();
		foreach ( array_merge( (array) glob( $live . '/twenty*', GLOB_ONLYDIR ), '' !== $stage ? (array) glob( $stage . '/themes/twenty*', GLOB_ONLYDIR ) : array() ) as $d ) {
			if ( is_string( $d ) ) {
				$bundled[] = basename( $d );
			}
		}
		$bundled = array_unique( $bundled );
		rsort( $bundled, SORT_STRING );
		foreach ( $bundled as $b ) {
			$candidates[] = $b;
		}
		foreach ( array_unique( $candidates ) as $slug ) {
			$css = $dir( $slug ) . '/style.css';
			if ( $slug === $bad || ! preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) || ! is_file( $css ) ) {
				continue;
			}
			$h = get_file_data( $css, array( 'template' => 'Template' ) );
			if ( '' !== trim( $h['template'] ) || '' !== self::unmet( self::requirements( $css ) ) ) {
				continue;
			}
			foreach ( array( 'template', 'stylesheet' ) as $key ) {
				FSC_DB::must( "UPDATE $opt SET option_value = " . FSC_DB::quote( $slug ) . " WHERE option_name = '$key'" );
			}
			$job->log( sprintf( 'WARNING: The imported theme %s %s; switched to the bundled theme %s. Update WordPress or PHP, then activate %s again under Appearance > Themes.', $bad, $why, $slug, $bad ) );
			return;
		}
		$job->log( sprintf( 'WARNING: The imported theme %s %s and no compatible bundled theme was found; the site may not load until WordPress or PHP is updated.', $bad, $why ) );
	}

	/**
	 * Fill empty template/stylesheet options from archive metadata (AIOWPM
	 * blanks them in its dump and keeps the theme in package.json).
	 *
	 * @param string $opt   Quoted options table.
	 * @param array  $theme array( template => slug, stylesheet => slug ).
	 */
	private static function fill_theme( $opt, array $theme ) {
		foreach ( array( 'template', 'stylesheet' ) as $key ) {
			$slug = isset( $theme[ $key ] ) ? (string) $theme[ $key ] : '';
			if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) ) {
				continue;
			}
			$cur = FSC_DB::value( "SELECT option_value FROM $opt WHERE option_name = '$key'" );
			if ( null === $cur ) {
				FSC_DB::must( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('$key', " . FSC_DB::quote( $slug ) . ", 'yes')" );
			} elseif ( '' === (string) $cur ) {
				FSC_DB::must( "UPDATE $opt SET option_value = " . FSC_DB::quote( $slug ) . " WHERE option_name = '$key'" );
			}
		}
	}

	/**
	 * Keep this plugin active under its installed basename and drop other
	 * copies of it from active_plugins.
	 *
	 * @param string $opt      Quoted options table.
	 * @param string $basename plugin_basename() of this plugin.
	 * @param array  $extra    Plugins the archive lists as active outside the dump.
	 */
	private static function fix_active_plugins( $opt, $basename, array $extra = array() ) {
		$raw     = FSC_DB::value( "SELECT option_value FROM $opt WHERE option_name = 'active_plugins'" );
		$plugins = null === $raw ? array() : @unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}
		foreach ( $extra as $p ) {
			if ( is_string( $p ) && preg_match( '#^[A-Za-z0-9._ -]+(/[A-Za-z0-9._ -]+)?\.php$#', $p ) && false === strpos( $p, '..' ) ) {
				$plugins[] = $p;
			}
		}
		$main = basename( $basename );
		$out  = array();
		foreach ( $plugins as $p ) {
			if ( is_string( $p ) && basename( $p ) !== $main ) {
				$out[] = $p;
			}
		}
		$out[] = $basename;
		sort( $out );
		$value = serialize( array_values( array_unique( $out ) ) );
		if ( null === $raw ) {
			FSC_DB::must( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('active_plugins', " . FSC_DB::quote( $value ) . ", 'yes')" );
		} else {
			FSC_DB::must( "UPDATE $opt SET option_value = " . FSC_DB::quote( $value ) . " WHERE option_name = 'active_plugins'" );
		}
	}
}
