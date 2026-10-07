<?php
/**
 * Thin database helper over $wpdb. Statements go straight to the mysqli
 * handle when available, bypassing wpdb::query()'s charset stripping, which
 * can silently alter or refuse large INSERTs with non-ASCII data.
 *
 * @package wp-free-site-cloner
 */

/**
 * Database helper (requires WordPress).
 */
class FSC_DB {

	/** Identifier pattern accepted for table and column names. */
	const IDENT = '/^[A-Za-z0-9_$]+$/';

	/**
	 * The wpdb instance.
	 *
	 * @return wpdb
	 */
	public static function wpdb() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * The mysqli handle, or null when wpdb uses another driver.
	 *
	 * @return mysqli|null
	 */
	private static function dbh() {
		$dbh = self::wpdb()->dbh;
		return $dbh instanceof mysqli ? $dbh : null;
	}

	/**
	 * Backtick-quote a validated identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 * @throws FSC_Exception On an invalid name.
	 */
	public static function ident( $name ) {
		if ( ! is_string( $name ) || ! preg_match( self::IDENT, $name ) || strlen( $name ) > 64 ) {
			throw new FSC_Exception( sprintf( 'Invalid table or column name: %s', is_string( $name ) ? substr( $name, 0, 80 ) : gettype( $name ) ) );
		}
		return '`' . $name . '`';
	}

	/**
	 * Backtick-quote any constraint name (constraint names from a dump may
	 * hold characters table names may not).
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws FSC_Exception On an empty, too long or NUL-containing name.
	 */
	public static function quote_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 64 || false !== strpos( $name, "\0" ) ) {
			throw new FSC_Exception( 'Invalid constraint name.' );
		}
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Database server flavour (cached per request).
	 *
	 * @return array array( mariadb => bool, version => string '' when unknown ).
	 */
	public static function server() {
		static $info = null;
		if ( null === $info ) {
			$v = '';
			try {
				$v = (string) self::value( 'SELECT VERSION()' );
			} catch ( FSC_Exception $e ) {
				$v = '';
			}
			$info = array(
				'mariadb' => false !== stripos( $v, 'mariadb' ),
				'version' => preg_match( '/(\d+\.\d+\.\d+)/', $v, $m ) ? $m[1] : '',
			);
		}
		return $info;
	}

	/**
	 * Whether CHECK constraint names are unique per schema (MySQL 8.0.16+;
	 * MariaDB keeps them per table). True when the server is unknown.
	 *
	 * @return bool
	 */
	public static function check_names_schema_wide() {
		$s = self::server();
		if ( $s['mariadb'] ) {
			return false;
		}
		return '' === $s['version'] || version_compare( $s['version'], '8.0.16', '>=' );
	}

	/**
	 * Turn FOREIGN_KEY_CHECKS off for this session.
	 *
	 * @return string|null Previous value, for fk_checks_restore().
	 */
	public static function fk_checks_off() {
		try {
			$prev = self::value( 'SELECT @@SESSION.foreign_key_checks' );
		} catch ( FSC_Exception $e ) {
			$prev = null;
		}
		self::exec( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		return $prev;
	}

	/**
	 * Restore FOREIGN_KEY_CHECKS after fk_checks_off().
	 *
	 * @param string|null $prev Previous value.
	 */
	public static function fk_checks_restore( $prev ) {
		if ( null !== $prev && '0' !== (string) $prev ) {
			self::exec( 'SET SESSION FOREIGN_KEY_CHECKS = 1' );
		}
	}

	/**
	 * Escape a string value for use inside single quotes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function escape( $value ) {
		$dbh = self::dbh();
		if ( $dbh ) {
			return mysqli_real_escape_string( $dbh, (string) $value );
		}
		return strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Quoted SQL literal for a value (NULL for null).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . self::escape( (string) $value ) . "'";
	}

	/**
	 * Escape LIKE wildcards.
	 *
	 * @param string $s String.
	 * @return string
	 */
	public static function esc_like( $s ) {
		return addcslashes( (string) $s, '_%\\' );
	}

	/**
	 * Execute a statement.
	 *
	 * @param string $sql SQL.
	 * @return array array( ok => bool, errno => int, error => string ).
	 */
	public static function exec( $sql ) {
		$dbh = self::dbh();
		if ( $dbh ) {
			try {
				$res = mysqli_query( $dbh, $sql );
			} catch ( Exception $e ) {
				return array(
					'ok'    => false,
					'errno' => (int) $e->getCode(),
					'error' => $e->getMessage(),
				);
			}
			if ( $res instanceof mysqli_result ) {
				mysqli_free_result( $res );
			}
			if ( false === $res ) {
				return array(
					'ok'    => false,
					'errno' => (int) mysqli_errno( $dbh ),
					'error' => (string) mysqli_error( $dbh ),
				);
			}
			return array(
				'ok'    => true,
				'errno' => 0,
				'error' => '',
			);
		}
		$wpdb       = self::wpdb();
		$suppress   = $wpdb->suppress_errors( true );
		$res        = $wpdb->query( $sql );
		$wpdb->suppress_errors( $suppress );
		return array(
			'ok'    => false !== $res,
			'errno' => false === $res ? 1 : 0,
			'error' => false === $res ? (string) $wpdb->last_error : '',
		);
	}

	/**
	 * Execute a statement or throw.
	 *
	 * @param string $sql SQL.
	 * @throws FSC_Exception On failure.
	 */
	public static function must( $sql ) {
		$r = self::exec( $sql );
		if ( ! $r['ok'] ) {
			throw new FSC_Exception( self::error_message( $r['errno'], $r['error'], $sql ) );
		}
	}

	/**
	 * Fetch all rows as associative arrays (values are strings or null).
	 *
	 * @param string $sql SQL.
	 * @return array
	 * @throws FSC_Exception On failure.
	 */
	public static function rows( $sql ) {
		$dbh = self::dbh();
		if ( $dbh ) {
			try {
				$res = mysqli_query( $dbh, $sql );
			} catch ( Exception $e ) {
				throw new FSC_Exception( self::error_message( $e->getCode(), $e->getMessage(), $sql ) );
			}
			if ( false === $res ) {
				throw new FSC_Exception( self::error_message( mysqli_errno( $dbh ), mysqli_error( $dbh ), $sql ) );
			}
			if ( ! ( $res instanceof mysqli_result ) ) {
				return array();
			}
			$out = array();
			while ( null !== ( $row = mysqli_fetch_assoc( $res ) ) ) {
				$out[] = $row;
			}
			mysqli_free_result( $res );
			return $out;
		}
		$wpdb     = self::wpdb();
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new FSC_Exception( self::error_message( 0, $wpdb->last_error, $sql ) );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch rows until $max_bytes of values are in memory (at least one row).
	 * The result is read unbuffered, so a batch of very large rows (big
	 * options, post content) never sits in memory twice or all at once.
	 *
	 * @param string $sql       SQL (a SELECT).
	 * @param int    $max_bytes Stop after this many bytes of values.
	 * @return array array( rows => list, complete => bool false when stopped early ).
	 * @throws FSC_Exception On failure.
	 */
	public static function rows_limited( $sql, $max_bytes ) {
		$dbh = self::dbh();
		if ( ! $dbh ) {
			return array(
				'rows'     => self::rows( $sql ),
				'complete' => true,
			);
		}
		try {
			$res = mysqli_query( $dbh, $sql, MYSQLI_USE_RESULT );
		} catch ( Exception $e ) {
			throw new FSC_Exception( self::error_message( $e->getCode(), $e->getMessage(), $sql ) );
		}
		if ( false === $res ) {
			throw new FSC_Exception( self::error_message( mysqli_errno( $dbh ), mysqli_error( $dbh ), $sql ) );
		}
		if ( ! ( $res instanceof mysqli_result ) ) {
			return array(
				'rows'     => array(),
				'complete' => true,
			);
		}
		$out      = array();
		$bytes    = 0;
		$complete = true;
		try {
			while ( null !== ( $row = mysqli_fetch_assoc( $res ) ) && false !== $row ) {
				$out[] = $row;
				foreach ( $row as $v ) {
					$bytes += null === $v ? 0 : strlen( $v );
				}
				if ( $bytes >= $max_bytes ) {
					$complete = false;
					break;
				}
			}
		} catch ( Exception $e ) {
			mysqli_free_result( $res );
			throw new FSC_Exception( self::error_message( $e->getCode(), $e->getMessage(), $sql ) );
		}
		// Frees (and discards) the rest of an unbuffered result.
		mysqli_free_result( $res );
		if ( $complete && mysqli_errno( $dbh ) ) {
			throw new FSC_Exception( self::error_message( mysqli_errno( $dbh ), mysqli_error( $dbh ), $sql ) );
		}
		return array(
			'rows'     => $out,
			'complete' => $complete,
		);
	}

	/**
	 * Rows returned with numeric keys (for SHOW statements with variable column names).
	 *
	 * @param string $sql SQL.
	 * @return array
	 */
	public static function rows_num( $sql ) {
		return array_map( 'array_values', self::rows( $sql ) );
	}

	/**
	 * First column of the first row, or null.
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public static function value( $sql ) {
		$rows = self::rows_num( $sql );
		return isset( $rows[0][0] ) ? $rows[0][0] : null;
	}

	/**
	 * Base tables (not views) whose name starts with $prefix, exact and case-sensitive.
	 *
	 * @param string $prefix Prefix.
	 * @return array Names.
	 */
	public static function tables_with_prefix( $prefix ) {
		$out = array();
		foreach ( self::rows_num( "SHOW FULL TABLES LIKE '" . self::escape( self::esc_like( $prefix ) ) . "%'" ) as $row ) {
			if ( 0 === strpos( $row[0], $prefix ) && ( ! isset( $row[1] ) || 'VIEW' !== strtoupper( $row[1] ) ) ) {
				$out[] = $row[0];
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Views whose name starts with $prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return array Names.
	 */
	public static function views_with_prefix( $prefix ) {
		$out = array();
		foreach ( self::rows_num( "SHOW FULL TABLES LIKE '" . self::escape( self::esc_like( $prefix ) ) . "%'" ) as $row ) {
			if ( 0 === strpos( $row[0], $prefix ) && isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ) {
				$out[] = $row[0];
			}
		}
		return $out;
	}

	/**
	 * Column info: list of array( name, type, key (PRI|...), generated bool, nullable bool ).
	 *
	 * @param string $table Table.
	 * @return array
	 */
	public static function columns( $table ) {
		$out = array();
		foreach ( self::rows( 'SHOW COLUMNS FROM ' . self::ident( $table ) ) as $c ) {
			$extra = isset( $c['Extra'] ) ? strtoupper( (string) $c['Extra'] ) : '';
			$out[] = array(
				'name'      => $c['Field'],
				'type'      => strtolower( (string) $c['Type'] ),
				'key'       => (string) $c['Key'],
				'generated' => false !== strpos( $extra, 'GENERATED' ),
			);
		}
		return $out;
	}

	/**
	 * Primary key columns in index order.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	public static function primary_key( $table ) {
		$cols = array();
		foreach ( self::rows( 'SHOW INDEX FROM ' . self::ident( $table ) ) as $i ) {
			if ( 'PRIMARY' === $i['Key_name'] ) {
				$cols[ (int) $i['Seq_in_index'] ] = $i['Column_name'];
			}
		}
		ksort( $cols );
		return array_values( $cols );
	}

	/**
	 * Whether a column type is binary (dumped as hex).
	 *
	 * @param string $type Column type.
	 * @return bool
	 */
	public static function is_binary_type( $type ) {
		return (bool) preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/i', $type );
	}

	/**
	 * Whether a column type holds text (subject to search-replace).
	 *
	 * @param string $type Column type.
	 * @return bool
	 */
	public static function is_text_type( $type ) {
		return (bool) preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext)\b/i', $type );
	}

	/**
	 * Whether a column type is an integer.
	 *
	 * @param string $type Column type.
	 * @return bool
	 */
	public static function is_int_type( $type ) {
		return (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/i', $type );
	}

	/**
	 * Statement excerpt for error messages and the job log: kind and object
	 * names only. Cut before the first VALUES/VALUE/SET/WHERE keyword, string
	 * literal or hex literal, so row data (emails, password hashes, option
	 * values) never reaches job.json, the log or the browser.
	 *
	 * @param string $sql SQL.
	 * @param int    $len Max length.
	 * @return string
	 */
	public static function snippet( $sql, $len = 120 ) {
		$s   = substr( (string) $sql, 0, 4096 );
		$cut = strlen( $s );
		if ( preg_match( '/\b(?:VALUES?|SET|WHERE)\b|[\'"]|\b0x[0-9a-f]/i', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			$cut = $m[0][1];
		}
		$full = trim( preg_replace( '/\s+/', ' ', substr( $s, 0, $cut ) ) );
		$out  = strlen( $full ) > $len ? substr( $full, 0, $len ) : $full;
		return ( $cut < strlen( (string) $sql ) || $out !== $full ) ? $out . ' ...' : $out;
	}

	/**
	 * MySQL error text without row values ("Duplicate entry 'x' for key",
	 * "Incorrect string value: '...' for column") and without the database
	 * name ("`db`.`table`", "'db/constraint'").
	 *
	 * @param string      $error Error text.
	 * @param string|null $db    Database name (default DB_NAME).
	 * @return string
	 */
	public static function safe_error( $error, $db = null ) {
		$out = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'(?=\\s+for\\s+(?:key|column))/i", "'...'", (string) $error );
		if ( null === $db && defined( 'DB_NAME' ) ) {
			$db = (string) DB_NAME;
		}
		if ( is_string( $db ) && '' !== $db ) {
			$out = str_replace( array( '`' . $db . '`.', "'" . $db . '/', '`' . $db . '/' ), array( '', "'", '`' ), $out );
		}
		return $out;
	}

	/**
	 * Error message for a failed statement (no row values). With WP_DEBUG
	 * the full statement start goes to the PHP error log only.
	 *
	 * @param int    $errno Error number.
	 * @param string $error Error text.
	 * @param string $sql   Statement.
	 * @return string
	 */
	public static function error_message( $errno, $error, $sql ) {
		self::debug_log( 'Database error [' . (int) $errno . '] ' . $error, $sql );
		return sprintf( 'Database error [%d] %s. Statement: %s', (int) $errno, self::safe_error( $error ), self::snippet( $sql ) );
	}

	/**
	 * Write a statement excerpt to the PHP error log when WP_DEBUG is on.
	 *
	 * @param string $context What happened.
	 * @param string $sql     Statement.
	 */
	public static function debug_log( $context, $sql ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'WP Free Site Cloner: ' . $context . ' SQL: ' . preg_replace( '/\s+/', ' ', substr( (string) $sql, 0, 1000 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Session settings used by export and import requests.
	 *
	 * @param string|null $names Connection charset for SET NAMES (import only).
	 * @throws FSC_Exception When $names is not on the rewriter's charset allowlist.
	 */
	public static function session( $names = null ) {
		self::exec( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		self::exec( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		self::exec( "SET SESSION time_zone = '+00:00'" );
		if ( null !== $names && '' !== (string) $names ) {
			// Defence in depth: never apply a charset the server lexes differently from this plugin's guard.
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $names ) || ! FSC_SQL_Rewriter::charset_allowed( $names ) ) {
				throw new FSC_Exception( 'Refusing an unsupported connection charset.' );
			}
			self::exec( 'SET NAMES ' . $names );
		}
	}
}
