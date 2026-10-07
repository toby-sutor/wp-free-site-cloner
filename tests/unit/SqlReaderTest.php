<?php
/**
 * SQL statement splitter tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_SQL_Reader
 */
class SqlReaderTest extends FSC_Test_Case {

	private function split( $sql, $offset = 0 ) {
		$file = $this->dir . '/s.sql';
		file_put_contents( $file, $sql );
		$r   = new FSC_SQL_Reader( $file, $offset );
		$out = array();
		while ( null !== ( $s = $r->next() ) ) {
			$out[] = $s;
		}
		return $out;
	}

	public function test_basic_split_and_comments() {
		$sql = "-- header comment\n# hash comment\n/* block ; comment */\nSET NAMES utf8mb4;\n\n"
			. "CREATE TABLE `t` (\n  `id` int -- trailing comment; with semicolon\n) ENGINE=InnoDB;\n"
			. "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */;\n"
			. "SELECT 1-1;SELECT 2 --x\n;\n;;\n";
		$this->assertSame(
			array(
				'SET NAMES utf8mb4',
				"CREATE TABLE `t` (\n  `id` int \n) ENGINE=InnoDB",
				'/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */',
				'SELECT 1-1',
				'SELECT 2 --x',
			),
			$this->split( $sql )
		);
	}

	public function test_quotes_escapes_and_semicolons_in_strings() {
		$stmts = array(
			"INSERT INTO t VALUES ('a;b', 'it''s; fine', 'back\\\\slash', 'esc\\'aped; quote')",
			"INSERT INTO t VALUES (\"dq ; \\\" \"\"x\"\"\", '-- not a comment', '# nor this', '/* nor this */')",
			'INSERT INTO `we;ird``name` VALUES (1)',
			"INSERT INTO t VALUES ('multi\nline;\nvalue'),\n('second')",
			"INSERT INTO t VALUES ('ends with backslash\\\\')",
		);
		$this->assertSame( $stmts, $this->split( implode( ";\n", $stmts ) . ";\n" ) );
	}

	public function test_last_statement_without_delimiter() {
		$this->assertSame( array( 'SELECT 1', 'SELECT 2' ), $this->split( "SELECT 1;\nSELECT 2\n-- tail" ) );
	}

	public function test_delimiter_command() {
		$sql = "SELECT 1;\nDELIMITER ;;\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a=1; SET @b=2; END;;\nDELIMITER ;\nSELECT 3;\n";
		$this->assertSame(
			array( 'SELECT 1', 'CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a=1; SET @b=2; END', 'SELECT 3' ),
			$this->split( $sql )
		);
	}

	public function test_resume_at_offsets_matches_full_read() {
		$parts = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$parts[] = "INSERT INTO `wp_posts` VALUES ($i,'value; with \\'quote\\' and '' doubled $i','" . str_repeat( 'x', $i % 50 ) . "')";
			if ( 0 === $i % 97 ) {
				$parts[] = "/*!40000 ALTER TABLE `wp_posts` DISABLE KEYS */";
			}
		}
		$sql  = "-- dump\n" . implode( ";\n-- c $i\n", $parts ) . ";\n";
		$file = $this->dir . '/r.sql';
		file_put_contents( $file, $sql );

		$full = $this->split( $sql );
		$this->assertSame( $parts, $full );

		$got    = array();
		$offset = 0;
		$delim  = ';';
		$guard  = 0;
		while ( $guard++ < 100000 ) {
			$r = new FSC_SQL_Reader( $file, $offset, $delim );
			$n = 0;
			while ( $n < 7 && null !== ( $s = $r->next() ) ) {
				$got[] = $s;
				++$n;
			}
			$offset = $r->offset();
			$delim  = $r->delimiter();
			if ( $n < 7 ) {
				break;
			}
		}
		$this->assertSame( $parts, $got );
		$this->assertSame( strlen( $sql ), $offset );
	}

	public function test_statement_crossing_read_buffer_boundary() {
		// Literal content: abc;\\''x\'y  (escaped backslash, doubled quote, escaped quote).
		$big   = str_repeat( "abc;\\\\''x\\'y", 300000 );
		$stmt1 = "INSERT INTO t VALUES ('" . $big . "')";
		$stmt2 = 'SELECT 2';
		$got   = $this->split( $stmt1 . ";\n" . $stmt2 . ';' );
		$this->assertCount( 2, $got );
		$this->assertSame( strlen( $stmt1 ), strlen( $got[0] ) );
		$this->assertTrue( $stmt1 === $got[0] );
		$this->assertSame( $stmt2, $got[1] );
	}

	public function test_statement_offset_points_at_statement_start() {
		$file = $this->dir . '/o.sql';
		file_put_contents( $file, "SELECT 1;\n-- c\nSELECT 2;" );
		$r = new FSC_SQL_Reader( $file );
		$r->next();
		$this->assertSame( 9, $r->offset() );
		$r->next();
		$this->assertSame( 9, $r->statement_offset() );
		$this->assertNull( $r->next() );
	}

	public function test_size_limit() {
		$file = $this->dir . '/l.sql';
		file_put_contents( $file, 'SELECT ' . str_repeat( 'x', 3 * 1024 * 1024 ) . ';' );
		$r = new FSC_SQL_Reader( $file, 0, ';', 1024 * 1024 );
		$this->expectException( FSC_Exception::class );
		$r->next();
	}
}
