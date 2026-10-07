<?php
/**
 * Statement filter and rewriter for the DB import. No WordPress dependency.
 *
 * Only CREATE TABLE, DROP TABLE, INSERT, REPLACE and ALTER TABLE (without
 * RENAME) are executed, and only when every table they name starts with the
 * source prefix; those names are rewritten to the temp prefix. SET NAMES is
 * reported separately. Everything else is skipped.
 *
 * A final guard re-reads every executed statement with a lexer that follows
 * the server's rules (strings, quoted names, plain comments ignored, versioned
 * comments read as code) and skips it when it contains a subquery, a function
 * that reads files or waits, a variable, a qualified (db.table) name, a
 * non-allowlisted engine or a table option that reaches other tables, servers
 * or files.
 *
 * @package wp-free-site-cloner
 */

/**
 * SQL rewriter.
 */
class FSC_SQL_Rewriter {

	/** Engines kept as they are (when the server has them); anything else becomes InnoDB. */
	const SAFE_ENGINES = array( 'innodb', 'myisam', 'aria' );

	/**
	 * Words (outside strings and plain comments) that make any imported
	 * statement skip: subqueries, file access, waits and locks, sequences.
	 * Dumps from mysqldump, AIOWPM, Duplicator and this plugin never need them.
	 */
	const DENY_WORDS = array( 'SELECT', 'UNION', 'LOAD', 'LOAD_FILE', 'OUTFILE', 'DUMPFILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'SYS_EXEC', 'SYS_EVAL', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT', 'MASTER_GTID_WAIT', 'WAIT_FOR_EXECUTED_GTID_SET', 'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS', 'NEXTVAL', 'SETVAL', 'LASTVAL' );

	/** Table options of CREATE/ALTER that reach other tables, servers or files. */
	const DENY_OPTIONS = array( 'CONNECTION', 'UNION', 'INSERT_METHOD', 'FILE_NAME', 'TABLE_TYPE', 'SRCDEF', 'DBNAME', 'OPTION_LIST', 'TABNAME', 'SECONDARY_ENGINE' );

	/**
	 * Connection charsets a dump may select with SET NAMES. An allowlist: a
	 * charset is honoured only when the server lexes statement text under it
	 * byte for byte the way this plugin's lexer does - 0x5c is always a
	 * backslash escape and 0x27 ' / 0x22 " / 0x60 ` are always quotes. Under a
	 * multibyte charset where one of those bytes can be the trailing byte of a
	 * two-byte character (big5, cp932, gb18030, gbk, sjis) the server ends a
	 * quoted string somewhere the guard does not, which lets a crafted dump
	 * smuggle a live subquery past the guard. Those charsets, and the charsets
	 * that cannot be a client connection charset (ucs2, utf16, utf16le, utf32),
	 * are refused. Derived empirically against MariaDB 10.11 and MySQL 8.4
	 * (every charset in SHOW CHARACTER SET, every lead byte 0x80..0xff).
	 */
	const SAFE_CHARSETS = array(
		'armscii8', 'ascii', 'binary', 'cp1250', 'cp1251', 'cp1256', 'cp1257', 'cp850', 'cp852', 'cp866',
		'dec8', 'eucjpms', 'euckr', 'gb2312', 'geostd8', 'greek', 'hebrew', 'hp8', 'keybcs2', 'koi8r',
		'koi8u', 'latin1', 'latin2', 'latin5', 'latin7', 'macce', 'macroman', 'swe7', 'tis620', 'ujis',
		'utf8', 'utf8mb3', 'utf8mb4',
	);

	/** Identifier token: backticked or bare. */
	const NAME = '(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';

	/** @var string */
	private $source;

	/** @var string */
	private $target;

	/** @var array|null lowercased collation => charset */
	private $collations;

	/** @var array|null lowercased charset => true */
	private $charsets;

	/** @var array|null lowercased engine => true */
	private $engines;

	/** @var array|null array( placeholder, replacement, tables => suffix => true ) */
	private $key_fix = null;

	/** @var array|null Constraint renaming: salt, prefixes, real, live, check (see set_constraints()). */
	private $cons = null;

	/**
	 * Constructor.
	 *
	 * @param string     $source     Prefix used in the dump (placeholder or real prefix).
	 * @param string     $target     Temp prefix.
	 * @param array|null $collations Supported collations (name => charset) or null if unknown.
	 * @param array|null $engines    Supported engines (names) or null if unknown.
	 */
	public function __construct( $source, $target, $collations = null, $engines = null ) {
		$this->source = (string) $source;
		$this->target = (string) $target;
		if ( is_array( $collations ) ) {
			$this->collations = array();
			$this->charsets   = array();
			foreach ( $collations as $name => $cs ) {
				$this->collations[ strtolower( $name ) ] = strtolower( $cs );
				$this->charsets[ strtolower( $cs ) ]     = true;
			}
		}
		if ( is_array( $engines ) ) {
			$this->engines = array();
			foreach ( $engines as $e ) {
				$this->engines[ strtolower( $e ) ] = true;
			}
		}
	}

	/**
	 * Rename constraints with schema-wide names (FOREIGN KEY everywhere,
	 * CHECK on MySQL 8.0.16+) so the temp tables do not collide with the
	 * live ones; see constraint_name().
	 *
	 * @param string $salt     Job id (temp names differ per import).
	 * @param array  $prefixes Prefixes the dump may use in auto-style names (placeholder, real prefix).
	 * @param string $real     The archive's real prefix (replaces the placeholder inside custom names).
	 * @param string $live     Live prefix (length check of the final auto-style name).
	 * @param bool   $check    Whether CHECK names are schema-wide on this server.
	 */
	public function set_constraints( $salt, array $prefixes, $real, $live, $check ) {
		$list = array();
		foreach ( $prefixes as $p ) {
			if ( is_string( $p ) && '' !== $p ) {
				$list[] = $p;
			}
		}
		$this->cons = array(
			'salt'     => (string) $salt,
			'prefixes' => array_values( array_unique( $list ) ),
			'real'     => (string) $real,
			'live'     => (string) $live,
			'check'    => (bool) $check,
		);
	}

	/**
	 * Temporary name of a custom-named constraint: the same for every
	 * statement of one import (crash replay, DROP FOREIGN KEY), different
	 * for every import.
	 *
	 * @param string $salt   Job id.
	 * @param string $suffix Table name without prefix.
	 * @param string $name   Original name.
	 * @return string
	 */
	public static function temp_constraint_name( $salt, $suffix, $name ) {
		return 'fsctmp_c' . substr( md5( $salt . "\0" . strtolower( $suffix ) . "\0" . strtolower( $name ) ), 0, 20 );
	}

	/**
	 * New name of a constraint in an imported statement.
	 *
	 * @param string $name   Name in the dump.
	 * @param string $suffix Table name without prefix.
	 * @param string $kind   fk or check.
	 * @param array  $map    Receives temp => original name for custom names.
	 * @return string|false|null New name, false to drop the name (server generates one), null to keep it.
	 */
	public function constraint_name( $name, $suffix, $kind, array &$map ) {
		if ( null === $this->cons || ( 'check' === $kind && ! $this->cons['check'] ) ) {
			return null;
		}
		$tag = 'fk' === $kind ? 'ibfk' : 'chk';
		if ( 'check' === $kind && preg_match( '/^CONSTRAINT_\d+$/i', $name ) ) {
			// MariaDB's generated CHECK name; MySQL generates <table>_chk_<n> instead.
			return false;
		}
		if ( preg_match( '/^(.+)_' . $tag . '_(\d+)$/i', $name, $m ) ) {
			foreach ( $this->cons['prefixes'] as $p ) {
				if ( 0 === strcasecmp( $m[1], $p . $suffix ) ) {
					$tmp = $this->target . $suffix . '_' . $tag . '_' . $m[2];
					if ( strlen( $tmp ) <= 64 && strlen( $this->cons['live'] . $suffix . '_' . $tag . '_' . $m[2] ) <= 64 ) {
						// RENAME TABLE renames <table>_ibfk_<n> / _chk_<n> along with the table.
						return $tmp;
					}
					break;
				}
			}
		}
		$orig = $name;
		$real = $this->cons['real'];
		if ( '' !== $real && $real !== $this->source && false !== stripos( $orig, $this->source ) ) {
			$fixed = str_ireplace( $this->source, $real, $orig );
			if ( strlen( $fixed ) <= 64 ) {
				$orig = $fixed;
			}
		}
		$tmp         = self::temp_constraint_name( $this->cons['salt'], $suffix, $name );
		$map[ $tmp ] = $orig;
		return $tmp;
	}

	/**
	 * Rename the schema-wide constraint names in a CREATE/ALTER definition:
	 * CONSTRAINT <name> FOREIGN KEY|CHECK, DROP FOREIGN KEY <name>, DROP CHECK <name>.
	 *
	 * @param string $def    Definition (after the table name).
	 * @param string $suffix Table name without prefix.
	 * @return array array( definition, map temp => original ).
	 */
	public function rename_constraints( $def, $suffix ) {
		$map = array();
		if ( null === $this->cons ) {
			return array( $def, $map );
		}
		$lx = self::lex( $def );
		if ( '' !== $lx['error'] ) {
			// The guard refuses the statement anyway.
			return array( $def, $map );
		}
		$t     = self::code( $lx['tokens'] );
		$n     = count( $t );
		$edits = array();
		$word  = function ( $k, $w ) use ( $t, $n ) {
			return $k < $n && 'word' === $t[ $k ][0] && strtoupper( $t[ $k ][1] ) === $w;
		};
		$named = function ( $k ) use ( $t, $n ) {
			return $k < $n && ( 'id' === $t[ $k ][0] || ( 'word' === $t[ $k ][0] && ! in_array( strtoupper( $t[ $k ][1] ), array( 'FOREIGN', 'CHECK', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'IF' ), true ) ) );
		};
		for ( $i = 0; $i < $n; $i++ ) {
			$k    = -1;
			$kind = '';
			$from = 0;
			if ( $word( $i, 'CONSTRAINT' ) && $named( $i + 1 ) ) {
				if ( $word( $i + 2, 'FOREIGN' ) ) {
					$kind = 'fk';
				} elseif ( $word( $i + 2, 'CHECK' ) ) {
					$kind = 'check';
				}
				$k    = $i + 1;
				$from = $t[ $i ][2];
			} elseif ( $word( $i, 'DROP' ) && $word( $i + 1, 'FOREIGN' ) && $word( $i + 2, 'KEY' ) ) {
				$k    = $word( $i + 3, 'IF' ) && $word( $i + 4, 'EXISTS' ) ? $i + 5 : $i + 3;
				$kind = 'fk';
			} elseif ( $word( $i, 'DROP' ) && $word( $i + 1, 'CHECK' ) ) {
				$k    = $i + 2;
				$kind = 'check';
			}
			if ( '' === $kind || ! $named( $k ) ) {
				continue;
			}
			$name = 'id' === $t[ $k ][0] ? str_replace( '``', '`', substr( $t[ $k ][1], 1, -1 ) ) : $t[ $k ][1];
			$new  = $this->constraint_name( $name, $suffix, $kind, $map );
			if ( false === $new && $from > 0 ) {
				// Up to the CHECK keyword, so no double blank is left.
				$edits[] = array( $from, $t[ $k + 1 ][2], '' );
			} elseif ( is_string( $new ) ) {
				$edits[] = array( $t[ $k ][2], $t[ $k ][3], '`' . $new . '`' );
			}
			$i = $k;
		}
		for ( $j = count( $edits ) - 1; $j >= 0; $j-- ) {
			$def = substr( $def, 0, $edits[ $j ][0] ) . $edits[ $j ][2] . substr( $def, $edits[ $j ][1] );
		}
		return array( $def, $map );
	}

	/**
	 * The definition of one named constraint in a SHOW CREATE TABLE text:
	 * everything after "CONSTRAINT `name`" up to the next comma or closing
	 * parenthesis at the same level (e.g. "FOREIGN KEY (`a`) REFERENCES `b`
	 * (`id`) ON DELETE CASCADE", "CHECK ((`n` > 0)) /*!80016 NOT ENFORCED *\/").
	 *
	 * @param string $create SHOW CREATE TABLE text.
	 * @param string $name   Constraint name (compared case-insensitively).
	 * @return string|null
	 */
	public static function constraint_clause( $create, $name ) {
		$lx = self::lex( $create );
		if ( '' !== $lx['error'] ) {
			return null;
		}
		$t     = $lx['tokens'];
		$n     = count( $t );
		$depth = 0;
		$start = -1;
		$level = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			$tok = $t[ $i ];
			if ( 'comment' === $tok[0] || 'vopen' === $tok[0] || 'vclose' === $tok[0] ) {
				continue;
			}
			if ( $start >= 0 ) {
				if ( 'sym' === $tok[0] && '(' === $tok[1] ) {
					++$depth;
				} elseif ( 'sym' === $tok[0] && ( ')' === $tok[1] || ',' === $tok[1] ) && $depth === $level ) {
					return trim( substr( $create, $start, $tok[2] - $start ) );
				} elseif ( 'sym' === $tok[0] && ')' === $tok[1] ) {
					--$depth;
				}
				continue;
			}
			if ( 'sym' === $tok[0] && '(' === $tok[1] ) {
				++$depth;
			} elseif ( 'sym' === $tok[0] && ')' === $tok[1] ) {
				--$depth;
			} elseif ( 'word' === $tok[0] && 'CONSTRAINT' === strtoupper( $tok[1] ) && $i + 2 < $n && in_array( $t[ $i + 1 ][0], array( 'id', 'word' ), true ) ) {
				$nt = $t[ $i + 1 ];
				$nm = 'id' === $nt[0] ? str_replace( '``', '`', substr( $nt[1], 1, -1 ) ) : $nt[1];
				if ( 0 === strcasecmp( $nm, $name ) ) {
					$start = $nt[3];
					$level = $depth;
					++$i;
				}
			}
		}
		return null;
	}

	/**
	 * Point the REFERENCES of a foreign key clause at another table.
	 *
	 * @param string $clause Clause from constraint_clause().
	 * @param string $table  New referenced table (valid identifier).
	 * @return string|null Null when the clause has no REFERENCES.
	 */
	public static function point_references( $clause, $table ) {
		$lx = self::lex( $clause );
		if ( '' !== $lx['error'] ) {
			return null;
		}
		$t = self::code( $lx['tokens'] );
		foreach ( $t as $i => $tok ) {
			if ( 'word' === $tok[0] && 'REFERENCES' === strtoupper( $tok[1] ) && isset( $t[ $i + 1 ] ) && in_array( $t[ $i + 1 ][0], array( 'id', 'word' ), true ) ) {
				return substr( $clause, 0, $t[ $i + 1 ][2] ) . '`' . str_replace( '`', '``', $table ) . '`' . substr( $clause, $t[ $i + 1 ][3] );
			}
		}
		return null;
	}

	/**
	 * Restore a prefix placeholder at the start of string values in INSERTs
	 * into the given tables (AIOWPM writes SERVMASK_PREFIX_ for the prefix at
	 * the start of option names and user meta keys).
	 *
	 * @param string $placeholder Placeholder, e.g. SERVMASK_PREFIX_.
	 * @param string $replacement Real prefix to put back.
	 * @param array  $tables      Table names without prefix.
	 */
	public function set_key_placeholder( $placeholder, $replacement, array $tables = array( 'options', 'usermeta' ) ) {
		$this->key_fix = null;
		if ( preg_match( '/^[A-Za-z0-9_$]+$/', (string) $placeholder ) && preg_match( '/^[A-Za-z0-9_$]*$/', (string) $replacement ) ) {
			$this->key_fix = array(
				'placeholder' => (string) $placeholder,
				'replacement' => (string) $replacement,
				'tables'      => array_fill_keys( array_map( 'strtolower', $tables ), true ),
			);
		}
	}

	/**
	 * Replace the key placeholder at the start of every quoted string literal.
	 *
	 * @param string $sql SQL fragment (VALUES part).
	 * @return string
	 */
	public function restore_keys( $sql ) {
		if ( null === $this->key_fix ) {
			return $sql;
		}
		$ph  = $this->key_fix['placeholder'];
		$rep = $this->key_fix['replacement'];
		$len = strlen( $ph );
		return self::outside_strings(
			$sql,
			function ( $part ) {
				return $part;
			},
			true,
			function ( $lit ) use ( $ph, $rep, $len ) {
				if ( '`' !== $lit[0] && substr( $lit, 1, $len ) === $ph ) {
					return $lit[0] . $rep . substr( $lit, 1 + $len );
				}
				return $lit;
			}
		);
	}

	/**
	 * Map a table name from the dump to the temp prefix, or null when it is not ours.
	 *
	 * @param string $token Name token (maybe backticked).
	 * @return string|null Backticked new name.
	 */
	public function map_table( $token ) {
		$name = $token;
		if ( '`' === substr( $name, 0, 1 ) ) {
			$name = str_replace( '``', '`', substr( $name, 1, -1 ) );
		}
		if ( '' === $this->source || 0 !== strpos( $name, $this->source ) ) {
			return null;
		}
		$new = $this->target . substr( $name, strlen( $this->source ) );
		if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', $new ) || strlen( $new ) > 64 ) {
			return null;
		}
		return '`' . $new . '`';
	}

	/**
	 * Apply $fn to the parts of $sql outside quoted strings and backticks.
	 *
	 * @param string        $sql   SQL.
	 * @param callable      $fn    fn( string ): string.
	 * @param bool          $ticks Also protect backticked identifiers.
	 * @param callable|null $lit   fn( string ): string applied to each quoted part (with its quotes).
	 * @return string
	 */
	public static function outside_strings( $sql, $fn, $ticks = false, $lit = null ) {
		$out   = '';
		$len   = strlen( $sql );
		$start = 0;
		$i     = 0;
		$stops = $ticks ? "'\"`" : "'\"";
		while ( $i < $len ) {
			$j = $i + strcspn( $sql, $stops, $i );
			if ( $j >= $len ) {
				break;
			}
			$q    = $sql[ $j ];
			$out .= call_user_func( $fn, substr( $sql, $start, $j - $start ) );
			$k    = $j + 1;
			while ( $k < $len ) {
				$c = $sql[ $k ];
				if ( '\\' === $c && '`' !== $q ) {
					$k += 2;
					continue;
				}
				if ( $c === $q ) {
					if ( $k + 1 < $len && $sql[ $k + 1 ] === $q ) {
						$k += 2;
						continue;
					}
					break;
				}
				++$k;
			}
			$k     = min( $len, $k + 1 );
			$out  .= null === $lit ? substr( $sql, $j, $k - $j ) : call_user_func( $lit, substr( $sql, $j, $k - $j ) );
			$start = $k;
			$i     = $k;
		}
		return $out . call_user_func( $fn, substr( $sql, $start ) );
	}

	/**
	 * Classify and rewrite one statement.
	 *
	 * @param string $sql Statement without delimiter.
	 * @return array array( action => exec|skip|names, sql, reason, charset ).
	 */
	public function rewrite( $sql ) {
		$kind  = '';
		$table = '';
		$skip  = function ( $reason ) use ( $sql, &$kind, &$table ) {
			return array(
				'action' => 'skip',
				'sql'    => $sql,
				'reason' => $reason,
				'kind'   => $kind,
				'table'  => $table,
			);
		};
		$trim = ltrim( $sql );
		$kind = preg_match( '/^([A-Za-z]{1,20})\b/', $trim, $w ) ? strtoupper( $w[1] ) : ( '/*' === substr( $trim, 0, 2 ) ? 'COMMENT' : 'OTHER' );
		if ( preg_match( '/^(?:\/\*!\d*\s*)?SET\s+NAMES\s+[\'"`]?([A-Za-z0-9_]+)[\'"`]?(?:\s+COLLATE\s+[\'"`]?[A-Za-z0-9_]+[\'"`]?)?\s*(?:\*\/)?\s*$/i', $trim, $m ) ) {
			$cs = strtolower( $m[1] );
			return array(
				'action'  => 'names',
				'sql'     => $sql,
				'reason'  => '',
				'charset' => self::charset_allowed( $cs ) ? $this->charset_fallback( $cs ) : $cs,
				'allowed' => self::charset_allowed( $cs ),
			);
		}
		if ( '/*' === substr( $trim, 0, 2 ) ) {
			return $skip( 'executable comment' );
		}
		$n = self::NAME;
		if ( preg_match( '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)' . $n . '(?!\s*\.)/i', $trim, $m ) ) {
			$kind = 'create';
		} elseif ( preg_match( '/^((?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+(?:INTO\s+)?)' . $n . '(?!\s*\.)/i', $trim, $m ) ) {
			$kind = 'insert';
		} elseif ( preg_match( '/^(ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+)' . $n . '(?!\s*\.)/i', $trim, $m ) ) {
			$kind = 'alter';
		} elseif ( preg_match( '/^(DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?)(.+?)\s*$/is', $trim, $m ) ) {
			$kind  = 'drop';
			$parts = preg_split( '/\s*,\s*/', trim( preg_replace( '/\s+(RESTRICT|CASCADE)$/i', '', $m[2] ) ) );
			$names = array();
			foreach ( $parts as $p ) {
				if ( ! preg_match( '/^' . $n . '$/', $p ) ) {
					return $skip( 'unsupported DROP TABLE' );
				}
				$table = self::display_name( $p );
				$new   = $this->map_table( $p );
				if ( null === $new ) {
					return $skip( 'table outside the source prefix' );
				}
				$names[] = $new;
			}
			return array(
				'action' => 'exec',
				'sql'    => $m[1] . implode( ', ', $names ),
				'reason' => '',
				'kind'   => $kind,
				'table'  => $table,
			);
		} else {
			return $skip( 'statement type not imported' );
		}

		$table = self::display_name( $m[2] );
		$new   = $this->map_table( $m[2] );
		if ( null === $new ) {
			return $skip( 'table outside the source prefix' );
		}
		$rest = (string) substr( $trim, strlen( $m[0] ) );
		if ( 'insert' === $kind && null !== $this->key_fix ) {
			$bare = trim( $new, '`' );
			if ( isset( $this->key_fix['tables'][ strtolower( substr( $bare, strlen( $this->target ) ) ) ] ) ) {
				$rest = $this->restore_keys( $rest );
			}
		}
		$plain = '';
		self::outside_strings(
			$rest,
			function ( $part ) use ( &$plain ) {
				$plain .= $part . ' ';
				return $part;
			},
			true
		);
		if ( 'alter' === $kind && preg_match( '/\bRENAME\b/i', $plain ) ) {
			return $skip( 'ALTER TABLE ... RENAME is not imported' );
		}
		if ( 'alter' === $kind && preg_match( '/\bEXCHANGE\b/i', $plain ) ) {
			return $skip( 'ALTER TABLE ... EXCHANGE PARTITION is not imported' );
		}
		if ( 'create' === $kind && preg_match( '/^\s*(LIKE\b|\(\s*LIKE\b|AS\b|SELECT\b)/i', $rest ) ) {
			return $skip( 'CREATE TABLE ... LIKE/SELECT is not imported' );
		}
		if ( 'insert' === $kind && preg_match( '/^\s*(\([^)]*\)\s*)?(SELECT|TABLE|WITH)\b/i', $rest ) ) {
			return $skip( 'INSERT ... SELECT is not imported' );
		}
		if ( 'create' === $kind || 'alter' === $kind ) {
			$bad  = false;
			$self = $this;
			$rest = self::outside_strings(
				$rest,
				function ( $part ) use ( $self, &$bad ) {
					return preg_replace_callback(
						'/\bREFERENCES\s+' . FSC_SQL_Rewriter::NAME . '/i',
						function ( $r ) use ( $self, &$bad ) {
							$t = $self->map_table( $r[1] );
							if ( null === $t ) {
								$bad = true;
								return $r[0];
							}
							return 'REFERENCES ' . $t;
						},
						$part
					);
				}
			);
			if ( $bad ) {
				return $skip( 'foreign key to a table outside the source prefix' );
			}
			$rest = $this->fix_definition( $rest, false );
			list( $rest, $cons ) = $this->rename_constraints( $rest, substr( trim( $new, '`' ), strlen( $this->target ) ) );
		}
		$why = self::guard( $kind, $rest );
		if ( '' !== $why ) {
			return $skip( $why );
		}
		return array(
			'action' => 'exec',
			'sql'    => $m[1] . $new . $rest,
			'reason' => '',
			'kind'   => $kind,
			'table'  => $table,
			'cons'   => isset( $cons ) ? $cons : array(),
		);
	}

	/**
	 * Table name for log lines: unquoted, printable, at most 64 characters.
	 *
	 * @param string $token Name token (maybe backticked).
	 * @return string
	 */
	public static function display_name( $token ) {
		$name = (string) $token;
		if ( '`' === substr( $name, 0, 1 ) ) {
			$name = str_replace( '``', '`', substr( $name, 1, -1 ) );
		}
		$name = preg_replace( '/[^\x21-\x7e]/', '?', $name );
		return strlen( $name ) > 64 ? substr( $name, 0, 64 ) . '...' : $name;
	}

	/**
	 * Split SQL into tokens the way the server reads it, calling
	 * $fn( type, start, end ) for each token (nothing is collected, so large
	 * INSERTs stay cheap). Strings ('..' and ".." with backslash and
	 * doubled-quote escapes) and `names` are single tokens; plain comments
	 * (/* *\/, -- , #) are "comment" tokens; versioned comments
	 * (/*!12345 .. *\/, /*M!.. *\/) are "vopen"/"vclose" around their content,
	 * which is lexed as code.
	 * Types: str, id, word, num, sym, comment, vopen, vclose.
	 *
	 * @param string   $sql SQL.
	 * @param callable $fn  fn( string $type, int $start, int $end ).
	 * @return string '' or the reason the text cannot be read safely.
	 */
	public static function lex_each( $sql, $fn ) {
		$sql   = (string) $sql;
		$len   = strlen( $sql );
		$i     = 0;
		$in_vc = false;
		$last  = '';
		$lend  = -1;
		$wchr  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$' . implode( '', array_map( 'chr', range( 128, 255 ) ) );
		while ( $i < $len ) {
			$i += strspn( $sql, " \t\n\r\x0b\x0c", $i );
			if ( $i >= $len ) {
				break;
			}
			$c     = $sql[ $i ];
			$start = $i;
			$type  = '';
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$stops = '`' === $c ? '`' : $c . '\\';
				$k     = $i + 1;
				while ( true ) {
					$k += strcspn( $sql, $stops, $k );
					if ( $k >= $len ) {
						return 'unterminated string or name';
					}
					if ( '\\' === $sql[ $k ] ) {
						$k += 2;
						continue;
					}
					if ( $k + 1 < $len && $sql[ $k + 1 ] === $c ) {
						$k += 2;
						continue;
					}
					break;
				}
				$type = '`' === $c ? 'id' : 'str';
				$i    = $k + 1;
			} elseif ( '/' === $c && $i + 1 < $len && '*' === $sql[ $i + 1 ] ) {
				if ( $in_vc ) {
					return 'nested comment';
				}
				if ( preg_match( '/\G\/\*M?!\d*/', $sql, $vm, 0, $i ) ) {
					$in_vc = true;
					$type  = 'vopen';
					$i    += strlen( $vm[0] );
				} else {
					$end = strpos( $sql, '*/', $i + 2 );
					if ( false === $end ) {
						return 'unterminated comment';
					}
					$type = 'comment';
					$i    = $end + 2;
				}
			} elseif ( $in_vc && '*' === $c && $i + 1 < $len && '/' === $sql[ $i + 1 ] ) {
				$in_vc = false;
				$type  = 'vclose';
				$i    += 2;
			} elseif ( '#' === $c || ( '-' === $c && $i + 1 < $len && '-' === $sql[ $i + 1 ] && ( $i + 2 >= $len || ord( $sql[ $i + 2 ] ) <= 32 ) ) ) {
				if ( $in_vc ) {
					return 'nested comment';
				}
				$end  = strpos( $sql, "\n", $i );
				$type = 'comment';
				$i    = false === $end ? $len : $end + 1;
			} else {
				$glued = $lend === $i && ( 'word' === $last || 'id' === $last || 'num' === $last );
				$w     = strspn( $sql, $wchr, $i );
				if ( $w > 0 ) {
					$type = 'word';
					$d    = strspn( $sql, '0123456789', $i );
					if ( $d === $w ) {
						$type = 'num';
						if ( preg_match( '/\G\d+\.\d*(?:[eE][-+]?\d+)?/', $sql, $nm, 0, $i ) ) {
							$w = strlen( $nm[0] );
						}
					}
					$i += $w;
				} elseif ( '.' === $c && ! $glued && preg_match( '/\G\.\d+(?:[eE][-+]?\d+)?/', $sql, $nm, 0, $i ) ) {
					$type = 'num';
					$i   += strlen( $nm[0] );
				} else {
					$type = 'sym';
					++$i;
				}
			}
			if ( 'comment' !== $type ) {
				$last = $type;
				$lend = $i;
			}
			$fn( $type, $start, $i );
		}
		return $in_vc ? 'unterminated comment' : '';
	}

	/**
	 * All tokens of $sql (for small statements and tests).
	 *
	 * @param string $sql SQL.
	 * @return array array( tokens => list of array( type, text, start, end ), error => string ).
	 */
	public static function lex( $sql ) {
		$out = array();
		$err = self::lex_each(
			$sql,
			function ( $type, $start, $end ) use ( &$out, $sql ) {
				$out[] = array( $type, substr( $sql, $start, $end - $start ), $start, $end );
			}
		);
		return array(
			'tokens' => $out,
			'error'  => $err,
		);
	}

	/**
	 * Code tokens only (comments and versioned-comment markers removed).
	 *
	 * @param array $tokens Tokens from lex().
	 * @return array
	 */
	private static function code( array $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			if ( 'comment' !== $t[0] && 'vopen' !== $t[0] && 'vclose' !== $t[0] ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Bare lowercase value of a word, `name` or string token.
	 *
	 * @param string $type Token type.
	 * @param string $v    Token text.
	 * @return string
	 */
	private static function bare( $type, $v ) {
		if ( 'id' === $type ) {
			$v = str_replace( '``', '`', substr( $v, 1, -1 ) );
		} elseif ( 'str' === $type ) {
			$v = stripcslashes( str_replace( $v[0] . $v[0], $v[0], substr( $v, 1, -1 ) ) );
		}
		return strtolower( trim( $v ) );
	}

	/**
	 * Final check of the part of a statement after its table name. Returns
	 * the reason to skip it, or '' when it may run.
	 *
	 * @param string $kind create, alter or insert.
	 * @param string $rest SQL after the table name (already rewritten).
	 * @return string
	 */
	public static function guard( $kind, $rest ) {
		$ddl    = 'create' === $kind || 'alter' === $kind;
		$why    = '';
		$p1     = '';
		$engine = 0;
		$err    = self::lex_each(
			$rest,
			function ( $type, $start, $end ) use ( $rest, $kind, $ddl, &$why, &$p1, &$engine ) {
				if ( '' !== $why || 'comment' === $type || 'vopen' === $type || 'vclose' === $type ) {
					return;
				}
				$first = $rest[ $start ];
				if ( 'sym' === $type && '@' === $first ) {
					$why = 'variable reference';
					return;
				}
				$dot = ( 'sym' === $type || 'num' === $type ) && '.' === $first;
				if ( $dot && ( 'word' === $p1 || 'id' === $p1 ) ) {
					$why = 'qualified name (other database or table)';
					return;
				}
				if ( $engine > 0 ) {
					if ( 1 === $engine && 'sym' === $type && '=' === $first ) {
						$engine = 2;
					} elseif ( 'word' === $type || 'id' === $type || 'str' === $type ) {
						$engine = 0;
						if ( ! in_array( self::bare( $type, substr( $rest, $start, $end - $start ) ), self::SAFE_ENGINES, true ) ) {
							$why = 'engine not allowed';
							return;
						}
					} else {
						$why = 'invalid ENGINE clause';
						return;
					}
				}
				$p1 = $type;
				if ( 'word' !== $type || $end - $start > 40 ) {
					return;
				}
				$u = strtoupper( substr( $rest, $start, $end - $start ) );
				if ( in_array( $u, self::DENY_WORDS, true ) ) {
					$why = 'forbidden keyword or function ' . $u;
				} elseif ( 'insert' === $kind && 'TABLE' === $u ) {
					$why = 'forbidden keyword or function TABLE';
				} elseif ( 'alter' === $kind && in_array( $u, array( 'RENAME', 'EXCHANGE', 'DISCARD', 'IMPORT' ), true ) ) {
					$why = 'ALTER TABLE ... ' . $u . ' is not imported';
				} elseif ( $ddl && in_array( $u, self::DENY_OPTIONS, true ) ) {
					$why = 'unsupported table option ' . $u;
				} elseif ( $ddl && 'DIRECTORY' === $u ) {
					$why = 'DATA/INDEX DIRECTORY is not imported';
				} elseif ( $ddl && 'ENGINE' === $u ) {
					$engine = 1;
				}
			}
		);
		if ( '' !== $err ) {
			return $err;
		}
		if ( '' === $why && $engine > 0 ) {
			$why = 'invalid ENGINE clause';
		}
		return $why;
	}

	/**
	 * Normalise every ENGINE clause (with or without "=", bare, `quoted` or
	 * 'string', also inside versioned comments) to ENGINE=<name>, keeping only
	 * allowlisted engines the server has; anything else becomes InnoDB.
	 *
	 * @param string $def   Definition.
	 * @param bool   $force Retry mode: only InnoDB and MyISAM are kept.
	 * @return string
	 */
	public function rewrite_engines( $def, $force ) {
		$lx = self::lex( $def );
		if ( '' !== $lx['error'] ) {
			return $def;
		}
		$t     = self::code( $lx['tokens'] );
		$n     = count( $t );
		$edits = array();
		foreach ( $t as $i => $tok ) {
			if ( 'word' !== $tok[0] || 'ENGINE' !== strtoupper( $tok[1] ) ) {
				continue;
			}
			$k = $i + 1;
			if ( $k < $n && 'sym' === $t[ $k ][0] && '=' === $t[ $k ][1] ) {
				++$k;
			}
			if ( $k >= $n || ! in_array( $t[ $k ][0], array( 'word', 'id', 'str' ), true ) ) {
				continue;
			}
			$e    = self::bare( $t[ $k ][0], $t[ $k ][1] );
			$keep = in_array( $e, self::SAFE_ENGINES, true ) && $this->has_engine( $e ) && ! ( $force && 'innodb' !== $e && 'myisam' !== $e );
			$name = 'word' === $t[ $k ][0] ? $t[ $k ][1] : $e;
			$edits[] = array( $tok[2], $t[ $k ][3], 'ENGINE=' . ( $keep ? $name : 'InnoDB' ) );
		}
		for ( $j = count( $edits ) - 1; $j >= 0; $j-- ) {
			$def = substr( $def, 0, $edits[ $j ][0] ) . $edits[ $j ][2] . substr( $def, $edits[ $j ][1] );
		}
		return $def;
	}

	/**
	 * Whether a connection charset is on the SAFE_CHARSETS allowlist, i.e. the
	 * server lexes statement text under it the way this plugin's lexer does.
	 * See SAFE_CHARSETS. Comparison is case-insensitive.
	 *
	 * @param string $cs Charset name.
	 * @return bool
	 */
	public static function charset_allowed( $cs ) {
		return is_string( $cs ) && in_array( strtolower( $cs ), self::SAFE_CHARSETS, true );
	}

	/**
	 * Charset to use when the server lacks the requested one.
	 *
	 * @param string $cs Charset.
	 * @return string
	 */
	public function charset_fallback( $cs ) {
		if ( 'utf8mb3' === $cs && null !== $this->charsets && ! isset( $this->charsets['utf8mb3'] ) ) {
			$cs = 'utf8';
		}
		if ( 'utf8mb4' === $cs && null !== $this->charsets && ! isset( $this->charsets['utf8mb4'] ) ) {
			$cs = 'utf8';
		}
		return $cs;
	}

	/**
	 * Whether a collation is supported (true when unknown).
	 *
	 * @param string $c Collation.
	 * @return bool
	 */
	private function has_collation( $c ) {
		return null === $this->collations || isset( $this->collations[ strtolower( $c ) ] );
	}

	/**
	 * Replacement for an unsupported (or, when forced, any modern) collation.
	 *
	 * @param string $c     Collation.
	 * @param bool   $force Downgrade even if supported (retry mode).
	 * @return string
	 */
	public function collation_fallback( $c, $force ) {
		$lc = strtolower( $c );
		if ( ! preg_match( '/^(utf8mb4|utf8mb3|utf8)_/', $lc, $m ) ) {
			return $c;
		}
		$cs     = $m[1];
		$modern = (bool) preg_match( '/(_0900_|uca1400)/', $lc );
		if ( $this->has_collation( $lc ) && ( ! $force || ! $modern ) ) {
			return $c;
		}
		$target = $this->charset_fallback( $cs );
		if ( $target !== $cs && ! $modern ) {
			$same = $target . substr( $lc, strlen( $cs ) );
			if ( $this->has_collation( $same ) ) {
				return $same;
			}
		}
		foreach ( array( '_unicode_520_ci', '_unicode_ci', '_general_ci' ) as $suffix ) {
			if ( $target . $suffix !== $lc && $this->has_collation( $target . $suffix ) ) {
				return $target . $suffix;
			}
		}
		return $target . '_general_ci';
	}

	/**
	 * Fix charsets, collations, engines and DEFINER in a CREATE/ALTER definition.
	 *
	 * @param string $def   Definition (after the table name).
	 * @param bool   $force Retry mode: downgrade modern collations and strip engine-specific options.
	 * @return string
	 */
	public function fix_definition( $def, $force ) {
		$self = $this;
		$def  = preg_replace( '/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)\s*@\s*(`[^`]*`|\'[^\']*\'|[^\s]+)/i', '', $def );
		$def  = self::strip_directories( $def );
		$def  = $this->rewrite_engines( $def, $force );
		return self::outside_strings(
			$def,
			function ( $part ) use ( $self, $force ) {
				$part = preg_replace_callback(
					'/\b(utf8mb[34]|utf8)_[A-Za-z0-9_]+\b/i',
					function ( $m ) use ( $self, $force ) {
						return $self->collation_fallback( $m[0], $force );
					},
					$part
				);
				$part = preg_replace_callback(
					'/\b(CHARSET|CHARACTER\s+SET)(\s*=\s*|\s+)(utf8mb4|utf8mb3)\b/i',
					function ( $m ) use ( $self ) {
						return $m[1] . $m[2] . $self->charset_fallback( strtolower( $m[3] ) );
					},
					$part
				);
				if ( $force ) {
					$part = preg_replace( '/\s+(PAGE_CHECKSUM|TRANSACTIONAL|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ENCRYPTED|ENCRYPTION_KEY_ID)\s*=\s*\S+/i', '', $part );
					$part = preg_replace( '/\bROW_FORMAT\s*=\s*PAGE\b/i', 'ROW_FORMAT=DYNAMIC', $part );
				}
				return $part;
			},
			true
		);
	}

	/**
	 * Remove DATA DIRECTORY / INDEX DIRECTORY clauses (table and partition
	 * options that place files anywhere the database server can write).
	 *
	 * @param string $def Definition.
	 * @return string
	 */
	public static function strip_directories( $def ) {
		$drop = false;
		return self::outside_strings(
			$def,
			function ( $part ) use ( &$drop ) {
				$out = preg_replace( '/\s*\b(?:DATA|INDEX)\s+DIRECTORY\s*=?\s*$/i', '', $part, 1, $n );
				if ( $n > 0 ) {
					$drop = true;
				}
				return $out;
			},
			true,
			function ( $lit ) use ( &$drop ) {
				if ( $drop && '`' !== $lit[0] ) {
					$drop = false;
					return '';
				}
				return $lit;
			}
		);
	}

	/**
	 * Whether an engine is supported (true when unknown).
	 *
	 * @param string $e Engine.
	 * @return bool
	 */
	public function has_engine( $e ) {
		return null === $this->engines || isset( $this->engines[ strtolower( $e ) ] );
	}

	/**
	 * Aggressive rewrite for a retry after a failed statement.
	 *
	 * @param string $sql Rewritten statement.
	 * @return string
	 */
	public function retry_rewrite( $sql ) {
		if ( ! preg_match( '/^\s*(CREATE|ALTER)\s/i', $sql ) ) {
			return $sql;
		}
		return $this->fix_definition( $sql, true );
	}
}
