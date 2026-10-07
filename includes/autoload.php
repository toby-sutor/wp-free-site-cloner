<?php
/**
 * Class map autoloader. Has no WordPress dependency so the unit tests can use it.
 *
 * @package wp-free-site-cloner
 */

if ( ! function_exists( 'fsc_autoload' ) ) {
	/**
	 * Load an FSC_ class from the includes directory.
	 *
	 * @param string $class Class name.
	 */
	function fsc_autoload( $class ) {
		static $map = array(
			'FSC_Plugin'            => 'class-fsc-plugin.php',
			'FSC_Storage'           => 'class-fsc-storage.php',
			'FSC_Job'               => 'class-fsc-job.php',
			'FSC_Tar'               => 'class-fsc-tar.php',
			'FSC_Tar_Writer'        => 'class-fsc-tar.php',
			'FSC_Tar_Reader'        => 'class-fsc-tar.php',
			'FSC_SQL_Reader'        => 'class-fsc-sql-reader.php',
			'FSC_Search_Replace'    => 'class-fsc-search-replace.php',
			'FSC_SR_Journal'        => 'class-fsc-sr-journal.php',
			'FSC_File_Scanner'      => 'class-fsc-file-scanner.php',
			'FSC_Extractor'         => 'class-fsc-extractor.php',
			'FSC_DB'                => 'class-fsc-db.php',
			'FSC_DB_Export'         => 'class-fsc-db-export.php',
			'FSC_DB_Import'         => 'class-fsc-db-import.php',
			'FSC_SQL_Rewriter'      => 'class-fsc-sql-rewriter.php',
			'FSC_Exporter'          => 'class-fsc-exporter.php',
			'FSC_Importer'          => 'class-fsc-importer.php',
			'FSC_Recovery'          => 'class-fsc-recovery.php',
			'FSC_Exception'         => 'class-fsc-job.php',
			'FSC_Integrity'         => 'class-fsc-integrity.php',
			'FSC_Source'            => 'sources/interface-fsc-source.php',
			'FSC_Source_Native'     => 'sources/class-fsc-source-native.php',
			'FSC_Source_Wpress'     => 'sources/class-fsc-source-wpress.php',
			'FSC_Source_Duplicator' => 'sources/class-fsc-source-duplicator.php',
			'FSC_Source_Util'       => 'sources/class-fsc-source-util.php',
			'FSC_Daf_Reader'        => 'sources/class-fsc-daf-reader.php',
		);
		if ( isset( $map[ $class ] ) ) {
			$file = __DIR__ . '/' . $map[ $class ];
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}
	spl_autoload_register( 'fsc_autoload' );
}
