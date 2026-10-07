<?php
/**
 * Cancelling and replacing a job (FSC_Plugin::cancel(), clear_previous())
 * while a step of that job is running.
 *
 * @package wp-free-site-cloner
 */

/**
 * Every test runs in its own process: the plugin class reads the storage
 * and wp-content paths from constants.
 *
 * @covers FSC_Plugin
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class JobControlTest extends FSC_Test_Case {

	const ID = '0123456789abcdef';

	/** Code of a step that passes the commit point ($job is the locked job). */
	const COMMIT = '$job->data["swapped"] = true; $job->data["phase"] = "finalize"; $job->save();';

	/** @var FSC_Storage */
	private $st;

	/** @var resource[] */
	private $procs = array();

	protected function setUp(): void {
		parent::setUp();
		define( 'WP_CONTENT_DIR', $this->dir . '/wp-content' );
		define( 'ABSPATH', $this->dir . '/abs/' );
		define( 'FSC_STORAGE_DIR', $this->dir . '/base' );
		mkdir( WP_CONTENT_DIR, 0755, true );
		mkdir( ABSPATH, 0755, true );
		$this->st = FSC_Storage::default_storage();
		$this->st->ensure();
	}

	protected function tearDown(): void {
		foreach ( $this->procs as $p ) {
			proc_close( $p );
		}
		parent::tearDown();
	}

	/**
	 * An import job in the files phase, with a staging folder.
	 *
	 * @param array $data Extra job data.
	 * @return FSC_Job
	 */
	private function import_job( array $data = array() ) {
		$stage = FSC_Importer::stage_dir( $this->st, self::ID );
		if ( ! is_dir( $stage . '/plugins/a' ) ) {
			mkdir( $stage . '/plugins/a', 0755, true );
		}
		file_put_contents( $stage . '/plugins/a/a.php', 'new a' );
		return FSC_Job::create(
			$this->st->job_path(),
			'import',
			array_merge(
				array(
					'id'    => self::ID,
					'phase' => 'files',
				),
				$data
			)
		);
	}

	/**
	 * Start a second process that holds the step lock for a moment, then
	 * changes the job like a step would, and wait until it has the lock.
	 *
	 * @param string $then PHP code run under the lock ($job is the job).
	 */
	private function step_in_another_process( $then ) {
		$script = $this->dir . '/step.php';
		$ready  = $this->dir . '/step.ready';
		file_put_contents(
			$script,
			'<?php require ' . var_export( dirname( __DIR__, 2 ) . '/includes/autoload.php', true ) . ';'
			. '$job = FSC_Job::load( ' . var_export( $this->st->job_path(), true ) . ' );'
			. 'if ( ! $job || ! $job->lock( 0 ) ) { exit( 2 ); }'
			. 'touch( ' . var_export( $ready, true ) . ' );'
			. 'usleep( 600000 );'
			. $then
			. '$job->unlock();'
		);
		$null = array( 'file', '/dev/null', 'r+' );
		$p    = proc_open( array( PHP_BINARY, $script ), array( $null, $null, $null ), $pipes );
		$this->assertIsResource( $p );
		$this->procs[] = $p;
		for ( $i = 0; $i < 200 && ! is_file( $ready ); $i++ ) {
			usleep( 50000 );
			clearstatcache( true, $ready );
		}
		$this->assertFileExists( $ready, 'the other process took the step lock' );
	}

	public function test_cancel_that_waited_for_the_committing_step_is_refused() {
		$this->import_job();
		$this->step_in_another_process( self::COMMIT );
		try {
			FSC_Plugin::instance()->cancel();
			$this->fail( 'cancel() must refuse an import that was switched while it waited' );
		} catch ( FSC_Exception $e ) {
			$this->assertSame( 409, $e->getCode() );
			$this->assertStringContainsString( 'already active', $e->getMessage() );
		}
		$job = FSC_Job::load( $this->st->job_path() );
		$this->assertNotNull( $job, 'the job stays so that finalize can run' );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertSame( 'finalize', $job->data['phase'] );
		$this->assertFileExists( FSC_Importer::stage_dir( $this->st, self::ID ) . '/plugins/a/a.php' );
		$this->assertTrue( $job->lock( 0 ), 'the lock was released' );
	}

	public function test_replace_that_waited_for_the_committing_step_is_refused() {
		$this->import_job();
		$this->step_in_another_process( self::COMMIT );
		try {
			FSC_Plugin::instance()->clear_previous( true );
			$this->fail( 'clear_previous() must refuse an import that was switched while it waited' );
		} catch ( FSC_Exception $e ) {
			$this->assertSame( 409, $e->getCode() );
			$this->assertStringContainsString( 'finishing', $e->getMessage() );
		}
		$job = FSC_Job::load( $this->st->job_path() );
		$this->assertNotNull( $job );
		$this->assertTrue( $job->data['swapped'] );
		$this->assertFileExists( FSC_Importer::stage_dir( $this->st, self::ID ) . '/plugins/a/a.php' );
		$this->assertTrue( $job->lock( 0 ), 'the lock was released' );
	}

	public function test_replace_without_force_sees_the_job_the_step_revived() {
		// Stale when the request arrives, touched by the step it then waits for.
		$job             = $this->import_job();
		$data            = $job->data;
		$data['updated'] = time() - FSC_Plugin::STALE_AFTER - 60;
		file_put_contents( $this->st->job_path(), json_encode( $data ) );
		$this->step_in_another_process( '$job->save();' );
		try {
			FSC_Plugin::instance()->clear_previous( false );
			$this->fail( 'clear_previous() must not replace a job that is active again' );
		} catch ( FSC_Exception $e ) {
			$this->assertSame( 409, $e->getCode() );
		}
		$this->assertNotNull( FSC_Job::load( $this->st->job_path() ) );
	}

	public function test_cancel_and_replace_of_a_job_that_ended_meanwhile() {
		$this->import_job();
		$this->step_in_another_process( '@unlink( $job->path() );' );
		$this->assertFalse( FSC_Plugin::instance()->cancel(), 'nothing left to cancel' );

		$this->import_job();
		@unlink( $this->dir . '/step.ready' );
		$this->step_in_another_process( '@unlink( $job->path() );' );
		FSC_Plugin::instance()->clear_previous( true );
		$this->assertNull( FSC_Job::load( $this->st->job_path() ) );
	}
}
