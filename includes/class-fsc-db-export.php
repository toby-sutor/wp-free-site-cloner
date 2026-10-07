<?php
/**
 * Chunked database dump via $wpdb (no exec, no mysqldump). Table names are
 * written with the {{FSC_PREFIX}} placeholder.
 *
 * @package wp-free-site-cloner
 */

/**
 * Database exporter. State lives in the job cursor under "db".
 */
class FSC_DB_Export {

	const PLACEHOLDER = '{{FSC_PREFIX}}';

	/** Target size of one INSERT statement in bytes. */
	const STATEMENT_BYTES = 1048576;

	/** Target memory per fetched batch in bytes. */
	const BATCH_BYTES = 8388608;

	/**
	 * Tables of this site: current prefix, no views, minus other installs whose
	 * prefix starts with ours (e.g. wp_old_ next to wp_).
	 *
	 * @param string $prefix Live prefix.
	 * @return array array( tables => names, skipped => names, views => names ).
	 */
	public static function site_tables( $prefix ) {
		$all     = FSC_DB::tables_with_prefix( $prefix );
		$foreign = self::foreign_prefixes( $prefix, $all );
		$tables  = array();
		$skipped = array();
		foreach ( $all as $t ) {
			$is_foreign = false;
			foreach ( $foreign as $f ) {
				if ( 0 === strpos( $t, $f ) ) {
					$is_foreign = true;
					break;
				}
			}
			if ( $is_foreign || ! preg_match( FSC_DB::IDENT, $t ) ) {
				$skipped[] = $t;
			} else {
				$tables[] = $t;
			}
		}
		return array(
			'tables'  => $tables,
			'skipped' => $skipped,
			'views'   => FSC_DB::views_with_prefix( $prefix ),
		);
	}

	/**
	 * Prefixes of other WordPress installs in the same database that start
	 * with $prefix (e.g. wp_old_ next to wp_), detected by an options, posts
	 * and postmeta table.
	 *
	 * @param string     $prefix Live prefix.
	 * @param array|null $all    Tables starting with $prefix (queried when null).
	 * @return array
	 */
	public static function foreign_prefixes( $prefix, $all = null ) {
		if ( null === $all ) {
			$all = FSC_DB::tables_with_prefix( $prefix );
		}
		$set     = array_fill_keys( $all, true );
		$foreign = array();
		foreach ( $all as $t ) {
			if ( ! preg_match( '/options$/', $t ) ) {
				continue;
			}
			$other = substr( $t, 0, -strlen( 'options' ) );
			if ( $other !== $prefix && strlen( $other ) > strlen( $prefix ) && isset( $set[ $other . 'posts' ] ) && isset( $set[ $other . 'postmeta' ] ) ) {
				$foreign[] = $other;
			}
		}
		return $foreign;
	}

	/**
	 * Estimated data size of the given tables in bytes.
	 *
	 * @param array $tables Names.
	 * @return array name => array( rows, avg, bytes ).
	 */
	public static function table_status( array $tables ) {
		$want = array_fill_keys( $tables, true );
		$out  = array();
		$like = FSC_DB::escape( FSC_DB::esc_like( FSC_DB::wpdb()->prefix ) );
		foreach ( FSC_DB::rows( "SHOW TABLE STATUS LIKE '" . $like . "%'" ) as $s ) {
			if ( isset( $want[ $s['Name'] ] ) ) {
				$out[ $s['Name'] ] = array(
					'rows'  => (int) $s['Rows'],
					'avg'   => (int) $s['Avg_row_length'],
					'bytes' => (int) $s['Data_length'],
				);
			}
		}
		return $out;
	}

	/**
	 * Prepare the dump: table list, header.
	 *
	 * @param FSC_Job $job      Job.
	 * @param string  $sql_path SQL file.
	 * @throws FSC_Exception On errors.
	 */
	public static function init( FSC_Job $job, $sql_path ) {
		$wpdb   = FSC_DB::wpdb();
		$prefix = $wpdb->prefix;
		$list   = self::site_tables( $prefix );
		foreach ( $list['views'] as $v ) {
			$job->log( sprintf( 'Skipped view %s (views are not exported).', $v ) );
		}
		foreach ( $list['skipped'] as $t ) {
			$job->log( sprintf( 'Skipped table %s (belongs to another installation or has an unsupported name).', $t ) );
		}
		try {
			$want = array_fill_keys( $list['tables'], true );
			foreach ( FSC_DB::rows( 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME' ) as $r ) {
				if ( isset( $want[ $r['EVENT_OBJECT_TABLE'] ] ) ) {
					$job->log( sprintf( 'Skipped trigger %s on table %s (triggers are not exported).', FSC_SQL_Rewriter::display_name( $r['TRIGGER_NAME'] ), $r['EVENT_OBJECT_TABLE'] ) );
				}
			}
		} catch ( FSC_Exception $e ) {
			unset( $e );
		}
		if ( empty( $list['tables'] ) ) {
			throw new FSC_Exception( 'No database tables found for the table prefix ' . $prefix . '.' );
		}
		$status = self::table_status( $list['tables'] );
		$tables = array();
		$total  = 0;
		foreach ( $list['tables'] as $t ) {
			$rows     = isset( $status[ $t ] ) ? $status[ $t ]['rows'] : 0;
			$tables[] = array(
				'name' => $t,
				'rows' => $rows,
				'avg'  => isset( $status[ $t ] ) ? $status[ $t ]['avg'] : 0,
			);
			$total += $rows;
		}
		$charset = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
		if ( ! FSC_SQL_Rewriter::charset_allowed( $charset ) ) {
			// The import refuses such charsets, so a dump written with one would be un-importable.
			throw new FSC_Exception(
				sprintf(
					/* translators: %s: database connection charset name. */
					__( 'This site uses the database charset "%s", which cannot be cloned safely (the server reads statement text under it differently from the import guard). Supported charsets include utf8mb4, utf8, latin1 and the other single-byte charsets; big5, gbk, sjis, cp932 and gb18030 are not supported.', 'wp-free-site-cloner' ),
					FSC_SQL_Rewriter::display_name( $charset )
				)
			);
		}
		$header  = "-- WP Free Site Cloner database dump\n"
			. '-- Created: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
			. "-- Table prefix is written as " . self::PLACEHOLDER . "\n\n"
			. 'SET NAMES ' . preg_replace( '/[^A-Za-z0-9_]/', '', $charset ) . ";\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n"
			. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET time_zone = '+00:00';\n\n";
		if ( false === file_put_contents( $sql_path, $header ) ) {
			throw new FSC_Exception( 'Cannot write the temporary SQL file.' );
		}
		$job->set(
			'db',
			array(
				'prefix'     => $prefix,
				'tables'     => $tables,
				'ti'         => 0,
				'table'      => null,
				'rows_done'  => 0,
				'rows_total' => max( 1, $total ),
				'size'       => strlen( $header ),
			)
		);
		$job->log( sprintf( 'Exporting %d tables (about %d rows).', count( $tables ), $total ) );
	}

	/**
	 * Table name with the placeholder in place of the live prefix.
	 *
	 * @param string $table  Table.
	 * @param string $prefix Live prefix.
	 * @return string
	 */
	public static function placeholder_name( $table, $prefix ) {
		return 0 === strpos( $table, $prefix ) ? self::PLACEHOLDER . substr( $table, strlen( $prefix ) ) : $table;
	}

	/**
	 * Rewrite a SHOW CREATE TABLE statement to use the placeholder.
	 *
	 * @param string $create Statement.
	 * @param string $table  Table.
	 * @param string $prefix Live prefix.
	 * @return string
	 */
	public static function rewrite_create( $create, $table, $prefix ) {
		$create = preg_replace(
			'/^CREATE TABLE `' . preg_quote( $table, '/' ) . '`/',
			'CREATE TABLE `' . self::placeholder_name( $table, $prefix ) . '`',
			$create,
			1
		);
		return preg_replace_callback(
			'/\bREFERENCES `([^`]+)`/',
			function ( $m ) use ( $prefix ) {
				return 'REFERENCES `' . FSC_DB_Export::placeholder_name( $m[1], $prefix ) . '`';
			},
			$create
		);
	}

	/**
	 * Start a table: write DROP + CREATE, work out the pagination mode.
	 *
	 * @param array $db  Cursor.
	 * @param mixed $out File handle.
	 * @throws FSC_Exception On errors.
	 */
	private static function begin_table( array &$db, $out ) {
		$info  = $db['tables'][ $db['ti'] ];
		$t     = $info['name'];
		$row   = FSC_DB::rows_num( 'SHOW CREATE TABLE ' . FSC_DB::ident( $t ) );
		if ( empty( $row[0][1] ) ) {
			throw new FSC_Exception( 'SHOW CREATE TABLE failed for ' . $t );
		}
		$name   = self::placeholder_name( $t, $db['prefix'] );
		$create = self::rewrite_create( $row[0][1], $t, $db['prefix'] );
		self::write( $out, "\nDROP TABLE IF EXISTS `" . $name . "`;\n" . $create . ";\n" );

		$cols = array();
		$bin  = array();
		$int  = array();
		foreach ( FSC_DB::columns( $t ) as $c ) {
			if ( $c['generated'] ) {
				continue;
			}
			FSC_DB::ident( $c['name'] );
			$cols[]            = $c['name'];
			$bin[]             = 0 === strpos( $c['type'], 'bit' ) ? 'bit' : ( FSC_DB::is_binary_type( $c['type'] ) ? 'hex' : '' );
			$int[ $c['name'] ] = FSC_DB::is_int_type( $c['type'] );
		}
		$pk    = FSC_DB::primary_key( $t );
		$mode  = ( 1 === count( $pk ) && ! empty( $int[ $pk[0] ] ) ) ? 'keyset' : 'offset';
		$batch = 1000;
		if ( $info['avg'] > 0 ) {
			$batch = (int) max( 1, min( 1000, floor( self::BATCH_BYTES / $info['avg'] ) ) );
		}
		$db['table'] = array(
			'name'   => $t,
			'target' => $name,
			'cols'   => $cols,
			'bin'    => $bin,
			'mode'   => $mode,
			'pk'     => $pk,
			'last'   => null,
			'offset' => 0,
			'batch'  => $batch,
			'where'  => $t === $db['prefix'] . 'options'
				? "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'"
				: '',
		);
	}

	/**
	 * Write to the SQL file.
	 *
	 * @param resource $out  Handle.
	 * @param string   $data Data.
	 * @throws FSC_Exception On a short write.
	 */
	private static function write( $out, $data ) {
		if ( fwrite( $out, $data ) !== strlen( $data ) ) {
			throw new FSC_Exception( 'Writing the temporary SQL file failed (disk full?).' );
		}
	}

	/**
	 * SQL literal for one value.
	 *
	 * @param mixed  $v   Value.
	 * @param string $bin '' for text, 'hex' for binary, 'bit' for BIT columns.
	 * @return string
	 */
	public static function literal( $v, $bin ) {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( 'bit' === $bin && ctype_digit( (string) $v ) ) {
			return (string) $v;
		}
		if ( '' !== $bin ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . FSC_DB::escape( $v ) . "'";
	}

	/**
	 * Dump one batch of rows (or start/finish a table).
	 *
	 * @param FSC_Job $job      Job.
	 * @param string  $sql_path SQL file.
	 * @return bool True when all tables are done.
	 * @throws FSC_Exception On errors.
	 */
	public static function step( FSC_Job $job, $sql_path ) {
		$db = $job->get( 'db' );
		if ( $db['ti'] >= count( $db['tables'] ) ) {
			return true;
		}
		$out = @fopen( $sql_path, 'c+b' );
		if ( ! $out ) {
			throw new FSC_Exception( 'Cannot open the temporary SQL file.' );
		}
		ftruncate( $out, $db['size'] );
		fseek( $out, $db['size'] );

		if ( null === $db['table'] ) {
			self::begin_table( $db, $out );
		} else {
			$tb    = $db['table'];
			$q     = FSC_DB::ident( $tb['name'] );
			$cols  = implode( ',', array_map( array( 'FSC_DB', 'ident' ), $tb['cols'] ) );
			$where = array();
			if ( '' !== $tb['where'] ) {
				$where[] = $tb['where'];
			}
			if ( 'keyset' === $tb['mode'] ) {
				$pk = FSC_DB::ident( $tb['pk'][0] );
				if ( null !== $tb['last'] ) {
					$where[] = $pk . ' > ' . FSC_DB::quote( $tb['last'] );
				}
				$sql = "SELECT $cols FROM $q" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . " ORDER BY $pk LIMIT " . (int) $tb['batch'];
			} else {
				$order = $tb['pk'] ? ' ORDER BY ' . implode( ',', array_map( array( 'FSC_DB', 'ident' ), $tb['pk'] ) ) : '';
				$sql   = "SELECT $cols FROM $q" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . $order . ' LIMIT ' . (int) $tb['batch'] . ' OFFSET ' . (int) $tb['offset'];
			}
			$fetch = FSC_DB::rows_limited( $sql, self::BATCH_BYTES );
			$rows  = $fetch['rows'];
			$head  = 'INSERT INTO `' . $tb['target'] . '` (' . $cols . ') VALUES ';
			$buf  = '';
			foreach ( $rows as $row ) {
				$vals = array();
				$i    = 0;
				foreach ( $row as $v ) {
					$vals[] = self::literal( $v, $tb['bin'][ $i ] );
					++$i;
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				unset( $vals );
				if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::STATEMENT_BYTES ) {
					self::write( $out, $head . $buf . ";\n" );
					$buf = '';
				}
				$buf .= ( '' === $buf ? '' : ",\n" ) . $tuple;
			}
			if ( '' !== $buf ) {
				self::write( $out, $head . $buf . ";\n" );
			}
			$n                = count( $rows );
			$db['rows_done'] += $n;
			if ( 'keyset' === $tb['mode'] && $n > 0 ) {
				$last                = end( $rows );
				$db['table']['last'] = $last[ $tb['pk'][0] ];
			}
			$db['table']['offset'] += $n;
			if ( $fetch['complete'] && $n < $tb['batch'] ) {
				$db['table'] = null;
				++$db['ti'];
			}
		}
		fflush( $out );
		$db['size'] = ftell( $out );
		fclose( $out );
		$job->set( 'db', $db );
		return $db['ti'] >= count( $db['tables'] );
	}

	/**
	 * Progress between 0 and 1.
	 *
	 * @param array $db Cursor.
	 * @return float
	 */
	public static function progress( array $db ) {
		$by_rows   = min( 1, $db['rows_done'] / max( 1, $db['rows_total'] ) );
		$by_tables = $db['ti'] / max( 1, count( $db['tables'] ) );
		return max( $by_rows * 0.9, $by_tables );
	}
}
