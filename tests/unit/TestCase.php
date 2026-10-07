<?php
/**
 * Base test case with a per-test scratch directory.
 *
 * @package wp-free-site-cloner
 */

/**
 * Base class.
 */
abstract class FSC_Test_Case extends PHPUnit\Framework\TestCase {

	/** @var string */
	protected $dir;

	protected function setUp(): void {
		$base = getenv( 'FSC_TEST_TMP' );
		if ( ! $base ) {
			$base = sys_get_temp_dir();
		}
		$this->dir = rtrim( $base, '/' ) . '/fsc-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0755, true );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->dir );
	}

	protected static function rrmdir( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			@unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' !== $f && '..' !== $f ) {
				self::rrmdir( $dir . '/' . $f );
			}
		}
		@rmdir( $dir );
	}
}
