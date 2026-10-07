<?php
/**
 * Constraint renaming of the SQL rewriter (same-site restore: FOREIGN KEY
 * and MySQL CHECK names are unique per schema).
 *
 * @package wp-free-site-cloner
 */

/**
 * @covers FSC_SQL_Rewriter
 */
class ConstraintRewriteTest extends FSC_Test_Case {

	/**
	 * Rewriter as the importer sets it up for a native archive.
	 *
	 * @param bool   $check CHECK names schema-wide.
	 * @param string $src   Dump prefix.
	 * @param string $real  Real archive prefix.
	 * @param string $live  Live prefix.
	 * @return FSC_SQL_Rewriter
	 */
	private function rw( $check = true, $src = '{{FSC_PREFIX}}', $real = 'wp_', $live = 'wp_' ) {
		$rw = new FSC_SQL_Rewriter( $src, 'fsctmp_' );
		$rw->set_constraints( 'job1', array( $src, $real ), $real, $live, $check );
		return $rw;
	}

	public function test_auto_style_foreign_keys_follow_the_temp_table() {
		$sql = "CREATE TABLE `{{FSC_PREFIX}}pgmb_location_cache` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `group_id` bigint(20) unsigned NOT NULL,\n  `other` bigint(20) unsigned,\n  PRIMARY KEY (`id`),\n  KEY `group_id` (`group_id`),\n  KEY `wp_pgmb_location_cache_ibfk_2` (`other`),\n"
			. "  CONSTRAINT `wp_pgmb_location_cache_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `{{FSC_PREFIX}}pgmb_groups` (`id`) ON DELETE CASCADE,\n"
			. "  CONSTRAINT `wp_pgmb_location_cache_ibfk_2` FOREIGN KEY (`other`) REFERENCES `{{FSC_PREFIX}}pgmb_groups` (`id`)\n) ENGINE=InnoDB";
		$r   = $this->rw()->rewrite( $sql );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_pgmb_location_cache_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `fsctmp_pgmb_groups` (`id`) ON DELETE CASCADE,', $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_pgmb_location_cache_ibfk_2` FOREIGN KEY (`other`) REFERENCES `fsctmp_pgmb_groups` (`id`)', $r['sql'] );
		// Index names are per table: unchanged.
		$this->assertStringContainsString( 'KEY `wp_pgmb_location_cache_ibfk_2` (`other`)', $r['sql'] );
		$this->assertSame( array(), $r['cons'] );
	}

	public function test_auto_style_with_placeholder_or_other_case() {
		$rw = $this->rw( true, 'SERVMASK_PREFIX_', 'wp_' );
		$r  = $rw->rewrite( 'CREATE TABLE `SERVMASK_PREFIX_loc` (`a` int, CONSTRAINT `SERVMASK_PREFIX_loc_ibfk_1` FOREIGN KEY (`a`) REFERENCES `SERVMASK_PREFIX_loc` (`a`), CONSTRAINT WP_LOC_IBFK_2 FOREIGN KEY (`a`) REFERENCES SERVMASK_PREFIX_loc (`a`))' );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_loc_ibfk_1` FOREIGN KEY', $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_loc_ibfk_2` FOREIGN KEY', $r['sql'] );
		$this->assertStringContainsString( 'REFERENCES `fsctmp_loc` (`a`)', $r['sql'] );
	}

	public function test_custom_names_get_a_recorded_temp_name() {
		$rw  = $this->rw();
		$sql = 'CREATE TABLE `{{FSC_PREFIX}}loc` (`g` int, `h` int, CONSTRAINT `fk_group` FOREIGN KEY (`g`) REFERENCES `{{FSC_PREFIX}}grp` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION, CONSTRAINT fk_h FOREIGN KEY (`h`) REFERENCES `{{FSC_PREFIX}}grp` (`id`))';
		$r   = $rw->rewrite( $sql );
		$t1  = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'fk_group' );
		$t2  = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'fk_h' );
		$this->assertMatchesRegularExpression( '/^fsctmp_c[0-9a-f]{20}$/', $t1 );
		$this->assertNotSame( $t1, $t2 );
		$this->assertStringContainsString( 'CONSTRAINT `' . $t1 . '` FOREIGN KEY (`g`) REFERENCES `fsctmp_grp` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION', $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `' . $t2 . '` FOREIGN KEY (`h`)', $r['sql'] );
		$this->assertSame( array( $t1 => 'fk_group', $t2 => 'fk_h' ), $r['cons'] );
		// Deterministic for a replay, different for another import.
		$this->assertSame( $r['sql'], $rw->rewrite( $sql )['sql'] );
		$other = new FSC_SQL_Rewriter( '{{FSC_PREFIX}}', 'fsctmp_' );
		$other->set_constraints( 'job2', array( '{{FSC_PREFIX}}', 'wp_' ), 'wp_', 'wp_', true );
		$this->assertStringNotContainsString( $t1, $other->rewrite( $sql )['sql'] );
	}

	public function test_names_with_the_prefix_in_odd_places_are_custom() {
		$names = array( 'wp_wp_loc_ibfk_1', 'xwp_loc_ibfk_1', 'wp_loc_ibfk_1x', 'wp_loc_ibfk_', 'wp_other_ibfk_1', 'wp_loc_chk_1', 'fsctmp_loc_ibfk_1' );
		foreach ( $names as $name ) {
			$r   = $this->rw()->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}loc` (`a` int, CONSTRAINT `' . $name . '` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}loc` (`a`))' );
			$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', $name );
			$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` FOREIGN KEY', $r['sql'], $name );
			$this->assertSame( array( $tmp => $name ), $r['cons'], $name );
		}
	}

	public function test_backtick_names_and_text_that_only_looks_like_a_constraint() {
		$sql = "CREATE TABLE `{{FSC_PREFIX}}loc` (`a` int COMMENT 'CONSTRAINT `wp_x` FOREIGN KEY', `CONSTRAINT` int, CONSTRAINT `we``ird` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}loc` (`a`)) COMMENT='CONSTRAINT fk FOREIGN KEY'";
		$r   = $this->rw()->rewrite( $sql );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'we`ird' );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( "COMMENT 'CONSTRAINT `wp_x` FOREIGN KEY'", $r['sql'] );
		$this->assertStringContainsString( '`CONSTRAINT` int', $r['sql'] );
		$this->assertStringContainsString( "COMMENT='CONSTRAINT fk FOREIGN KEY'", $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` FOREIGN KEY', $r['sql'] );
		$this->assertSame( array( $tmp => 'we`ird' ), $r['cons'] );
	}

	public function test_versioned_comments_are_read_as_code() {
		$r = $this->rw()->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}loc` (`a` int /*!50001 , CONSTRAINT `wp_loc_ibfk_1` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}loc` (`a`) */)' );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( '/*!50001 , CONSTRAINT `fsctmp_loc_ibfk_1` FOREIGN KEY (`a`) REFERENCES `fsctmp_loc` (`a`) */', $r['sql'] );
		// Plain comments are not code.
		$r = $this->rw()->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}loc` (`a` int /* CONSTRAINT `fk_x` FOREIGN KEY */)' );
		$this->assertStringContainsString( '/* CONSTRAINT `fk_x` FOREIGN KEY */', $r['sql'] );
		$this->assertSame( array(), $r['cons'] );
	}

	public function test_check_constraints_on_mysql() {
		$sql = 'CREATE TABLE `{{FSC_PREFIX}}loc` (`n` int CHECK (`n` > 0), CONSTRAINT `chk_n` CHECK ((`n` >= 0)), CONSTRAINT `wp_loc_chk_1` CHECK ((`n` < 1000)) /*!80016 NOT ENFORCED */, CONSTRAINT `CONSTRAINT_1` CHECK (`n` <> 5), CHECK (`n` <> 6))';
		$r   = $this->rw( true )->rewrite( $sql );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'chk_n' );
		$this->assertSame( 'exec', $r['action'] );
		$this->assertStringContainsString( '`n` int CHECK (`n` > 0)', $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` CHECK ((`n` >= 0))', $r['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_loc_chk_1` CHECK ((`n` < 1000)) /*!80016 NOT ENFORCED */', $r['sql'] );
		// MariaDB's generated name is dropped: MySQL generates <table>_chk_<n>.
		$this->assertStringContainsString( ', CHECK (`n` <> 5)', $r['sql'] );
		$this->assertStringNotContainsString( 'CONSTRAINT_1', $r['sql'] );
		$this->assertStringContainsString( ', CHECK (`n` <> 6)', $r['sql'] );
		$this->assertSame( array( $tmp => 'chk_n' ), $r['cons'] );
	}

	public function test_check_constraints_on_mariadb_are_kept() {
		$sql = 'CREATE TABLE `{{FSC_PREFIX}}loc` (`n` int, CONSTRAINT `chk_n` CHECK (`n` >= 0), CONSTRAINT `CONSTRAINT_1` CHECK (`n` < 9), CONSTRAINT `fk_n` FOREIGN KEY (`n`) REFERENCES `{{FSC_PREFIX}}loc` (`n`))';
		$r   = $this->rw( false )->rewrite( $sql );
		$this->assertStringContainsString( 'CONSTRAINT `chk_n` CHECK (`n` >= 0), CONSTRAINT `CONSTRAINT_1` CHECK (`n` < 9)', $r['sql'] );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'fk_n' );
		$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` FOREIGN KEY', $r['sql'] );
		$this->assertSame( array( $tmp => 'fk_n' ), $r['cons'] );
	}

	public function test_self_reference_and_cycles_between_tables() {
		$rw = $this->rw();
		$a  = $rw->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}a` (`id` int PRIMARY KEY, `b` int, `p` int, CONSTRAINT `wp_a_ibfk_1` FOREIGN KEY (`b`) REFERENCES `{{FSC_PREFIX}}b` (`id`) ON DELETE CASCADE, CONSTRAINT `wp_a_ibfk_2` FOREIGN KEY (`p`) REFERENCES `{{FSC_PREFIX}}a` (`id`))' );
		$b  = $rw->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}b` (`id` int PRIMARY KEY, `a` int, CONSTRAINT `fk_b_a` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}a` (`id`) ON DELETE SET NULL)' );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_a_ibfk_1` FOREIGN KEY (`b`) REFERENCES `fsctmp_b` (`id`) ON DELETE CASCADE', $a['sql'] );
		$this->assertStringContainsString( 'CONSTRAINT `fsctmp_a_ibfk_2` FOREIGN KEY (`p`) REFERENCES `fsctmp_a` (`id`)', $a['sql'] );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'b', 'fk_b_a' );
		$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` FOREIGN KEY (`a`) REFERENCES `fsctmp_a` (`id`) ON DELETE SET NULL', $b['sql'] );
		// The same custom name on two tables gets two temp names.
		$this->assertNotSame( $tmp, FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'a', 'fk_b_a' ) );
	}

	public function test_alter_add_and_drop_use_the_same_names() {
		$rw  = $this->rw();
		$add = $rw->rewrite( 'ALTER TABLE `{{FSC_PREFIX}}loc` ADD CONSTRAINT `fk_g` FOREIGN KEY (`g`) REFERENCES `{{FSC_PREFIX}}grp` (`id`), ADD CONSTRAINT `wp_loc_ibfk_3` FOREIGN KEY (`h`) REFERENCES `{{FSC_PREFIX}}grp` (`id`)' );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'fk_g' );
		$this->assertSame( 'exec', $add['action'] );
		$this->assertSame( 'ALTER TABLE `fsctmp_loc` ADD CONSTRAINT `' . $tmp . '` FOREIGN KEY (`g`) REFERENCES `fsctmp_grp` (`id`), ADD CONSTRAINT `fsctmp_loc_ibfk_3` FOREIGN KEY (`h`) REFERENCES `fsctmp_grp` (`id`)', $add['sql'] );
		$drop = $rw->rewrite( 'ALTER TABLE `{{FSC_PREFIX}}loc` DROP FOREIGN KEY `FK_G`, DROP FOREIGN KEY IF EXISTS wp_loc_ibfk_3, DROP CHECK `chk_n`' );
		$this->assertSame( 'ALTER TABLE `fsctmp_loc` DROP FOREIGN KEY `' . $tmp . '`, DROP FOREIGN KEY IF EXISTS `fsctmp_loc_ibfk_3`, DROP CHECK `' . FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'loc', 'chk_n' ) . '`', $drop['sql'] );
		// PRIMARY/UNIQUE constraint names are per table.
		$u = $rw->rewrite( 'ALTER TABLE `{{FSC_PREFIX}}loc` ADD CONSTRAINT `uq` UNIQUE KEY (`g`)' );
		$this->assertSame( 'ALTER TABLE `fsctmp_loc` ADD CONSTRAINT `uq` UNIQUE KEY (`g`)', $u['sql'] );
	}

	public function test_long_names_take_the_custom_route() {
		$suffix = str_repeat( 'x', 52 );
		$name   = 'wp_' . $suffix . '_ibfk_1';
		$this->assertLessThanOrEqual( 64, strlen( $name ) );
		$r   = $this->rw()->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}' . $suffix . '` (`a` int, CONSTRAINT `' . $name . '` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}' . $suffix . '` (`a`))' );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', $suffix, $name );
		$this->assertStringContainsString( 'CONSTRAINT `' . $tmp . '` FOREIGN KEY', $r['sql'] );
		$this->assertSame( array( $tmp => $name ), $r['cons'] );
		// Also when only the final name (longer live prefix) would be too long.
		$rw = $this->rw( true, '{{FSC_PREFIX}}', 'wp_', 'my_long_prefix_' );
		$s2 = str_repeat( 'y', 45 );
		$r  = $rw->rewrite( 'CREATE TABLE `{{FSC_PREFIX}}' . $s2 . '` (`a` int, CONSTRAINT `wp_' . $s2 . '_ibfk_1` FOREIGN KEY (`a`) REFERENCES `{{FSC_PREFIX}}' . $s2 . '` (`a`))' );
		$this->assertStringNotContainsString( 'fsctmp_' . $s2 . '_ibfk_1', $r['sql'] );
		$this->assertCount( 1, $r['cons'] );
	}

	public function test_placeholder_inside_custom_names_is_put_back() {
		$rw  = $this->rw( true, 'SERVMASK_PREFIX_', 'shop_' );
		$r   = $rw->rewrite( 'CREATE TABLE `SERVMASK_PREFIX_wc_download_log` (`p` int, CONSTRAINT `fk_SERVMASK_PREFIX_wc_download_log_permission_id` FOREIGN KEY (`p`) REFERENCES `SERVMASK_PREFIX_wc_perm` (`id`) ON DELETE CASCADE)' );
		$tmp = FSC_SQL_Rewriter::temp_constraint_name( 'job1', 'wc_download_log', 'fk_SERVMASK_PREFIX_wc_download_log_permission_id' );
		$this->assertSame( array( $tmp => 'fk_shop_wc_download_log_permission_id' ), $r['cons'] );
	}

	public function test_without_constraint_setup_nothing_changes() {
		$rw  = new FSC_SQL_Rewriter( 'wp_', 'fsctmp_' );
		$sql = 'CREATE TABLE `wp_loc` (`a` int, CONSTRAINT `fk` FOREIGN KEY (`a`) REFERENCES `wp_loc` (`a`))';
		$r   = $rw->rewrite( $sql );
		$this->assertSame( 'CREATE TABLE `fsctmp_loc` (`a` int, CONSTRAINT `fk` FOREIGN KEY (`a`) REFERENCES `fsctmp_loc` (`a`))', $r['sql'] );
		$this->assertSame( array(), $r['cons'] );
	}

	public function test_constraint_clause_and_point_references() {
		$create = "CREATE TABLE `wp_loc` (\n  `id` int NOT NULL,\n  `s` varchar(9) DEFAULT 'x,)',\n  PRIMARY KEY (`id`),\n"
			. "  CONSTRAINT `Fk_Group` FOREIGN KEY (`group_id`, `x`) REFERENCES `fscold_grp` (`id`, `x`) ON DELETE CASCADE ON UPDATE SET NULL,\n"
			. "  CONSTRAINT `chk_s` CHECK (((`s` <> _utf8mb4'a,)b') and (`id` > 0))) /*!80016 NOT ENFORCED */,\n"
			. "  CONSTRAINT `last` CHECK (`id` < 10)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$fk     = FSC_SQL_Rewriter::constraint_clause( $create, 'fk_group' );
		$this->assertSame( 'FOREIGN KEY (`group_id`, `x`) REFERENCES `fscold_grp` (`id`, `x`) ON DELETE CASCADE ON UPDATE SET NULL', $fk );
		$this->assertSame( "CHECK (((`s` <> _utf8mb4'a,)b') and (`id` > 0))) /*!80016 NOT ENFORCED */", FSC_SQL_Rewriter::constraint_clause( $create, 'chk_s' ) );
		$this->assertSame( 'CHECK (`id` < 10)', FSC_SQL_Rewriter::constraint_clause( $create, 'last' ) );
		$this->assertNull( FSC_SQL_Rewriter::constraint_clause( $create, 'missing' ) );
		$this->assertNull( FSC_SQL_Rewriter::constraint_clause( $create, 'wp_loc' ) );
		$this->assertSame( 'FOREIGN KEY (`group_id`, `x`) REFERENCES `wp_grp` (`id`, `x`) ON DELETE CASCADE ON UPDATE SET NULL', FSC_SQL_Rewriter::point_references( $fk, 'wp_grp' ) );
		$this->assertNull( FSC_SQL_Rewriter::point_references( 'CHECK (`id` < 10)', 'wp_grp' ) );
	}

	public function test_database_name_is_kept_out_of_error_texts() {
		$this->assertSame( "Can't create table `fsctmp_pgmb_location_cache` (errno: 121 \"Duplicate key on write or update\")", FSC_DB::safe_error( "Can't create table `jvunhpqr_wp490`.`fsctmp_pgmb_location_cache` (errno: 121 \"Duplicate key on write or update\")", 'jvunhpqr_wp490' ) );
		$this->assertSame( "Failed to add the foreign key constraint 'fk_x' to system tables", FSC_DB::safe_error( "Failed to add the foreign key constraint 'wordpress/fk_x' to system tables", 'wordpress' ) );
		$this->assertSame( 'Cannot add or update a child row: a foreign key constraint fails (`wp_t`, CONSTRAINT `c`)', FSC_DB::safe_error( 'Cannot add or update a child row: a foreign key constraint fails (`wordpress`.`wp_t`, CONSTRAINT `c`)', 'wordpress' ) );
		$this->assertSame( 'Table wordpress_x is fine', FSC_DB::safe_error( 'Table wordpress_x is fine', 'wordpress' ) );
	}
}
