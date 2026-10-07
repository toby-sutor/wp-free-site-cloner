<?php
/**
 * Tools > Site Cloner admin page. Plain HTML + a handful of hidden
 * translatable string templates read by assets/admin.js (see
 * internal/API.md for the AJAX contract this view is built against).
 *
 * @package wp-free-site-cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fsc_admin_data = FSC_Plugin::instance()->script_data();
$fsc_multisite  = ! empty( $fsc_admin_data['multisite'] );
?>
<div class="wrap fsc-wrap" id="fsc-app">
	<h1><?php echo esc_html__( 'WP Free Site Cloner', 'wp-free-site-cloner' ); ?></h1>

	<div id="fsc-global-notice" class="notice notice-error" role="alert" hidden>
		<p id="fsc-global-notice-msg"></p>
	</div>

	<?php if ( $fsc_multisite ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html__( 'Multisite networks are not supported. Export and import are disabled on this site.', 'wp-free-site-cloner' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="fsc-main" <?php echo $fsc_multisite ? 'hidden' : ''; ?>>

	<div id="fsc-retention-notice" class="notice notice-warning fsc-retention-notice" role="status" hidden>
		<p id="fsc-retention-notice-msg"></p>
		<p>
			<button type="button" id="fsc-delete-all" class="button">
				<?php echo esc_html__( 'Delete all archives', 'wp-free-site-cloner' ); ?>
			</button>
		</p>
	</div>

	<!-- ===================== Export ===================== -->
	<div class="card fsc-card" id="fsc-export-card">
		<h2><?php echo esc_html__( 'Export', 'wp-free-site-cloner' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Save the database and wp-content into one archive that you can import on another WordPress site running this plugin.', 'wp-free-site-cloner' ); ?></p>

		<div id="fsc-export-idle">
			<p>
				<button type="button" id="fsc-export-start" class="button button-primary">
					<?php echo esc_html__( 'Start export', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-export-running" hidden>
			<?php FSC_Plugin::render_progress_panel( 'export' ); ?>
			<p>
				<button type="button" id="fsc-export-cancel" class="button">
					<?php echo esc_html__( 'Cancel', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
			<details class="fsc-log-details">
				<summary><?php echo esc_html__( 'Log', 'wp-free-site-cloner' ); ?></summary>
				<div id="fsc-export-log" class="fsc-log" tabindex="0"></div>
			</details>
		</div>

		<div id="fsc-export-error" class="notice notice-error inline fsc-msg-box" role="alert" hidden>
			<p id="fsc-export-error-msg"></p>
			<p class="fsc-msg-time" id="fsc-export-error-time"></p>
			<p>
				<button type="button" id="fsc-export-resume" class="button" hidden>
					<?php echo esc_html__( 'Resume', 'wp-free-site-cloner' ); ?>
				</button>
				<button type="button" id="fsc-export-dismiss" class="button">
					<?php echo esc_html__( 'Dismiss', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-export-done" class="notice notice-success inline" role="status" hidden>
			<p id="fsc-export-done-msg"></p>
			<div id="fsc-export-done-warnings" class="notice notice-warning inline" hidden>
				<p><strong><?php echo esc_html__( 'Warnings:', 'wp-free-site-cloner' ); ?></strong></p>
				<ul id="fsc-export-done-warnings-list"></ul>
			</div>
			<p>
				<button type="button" id="fsc-export-done-dismiss" class="button">
					<?php echo esc_html__( 'Dismiss', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>
	</div>

	<!-- ===================== Import ===================== -->
	<div class="card fsc-card" id="fsc-import-card">
		<h2><?php echo esc_html__( 'Import', 'wp-free-site-cloner' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Restore an archive on this site. This replaces the current database and wp-content.', 'wp-free-site-cloner' ); ?></p>

		<div id="fsc-import-orphan" class="notice notice-warning inline" hidden>
			<p id="fsc-import-orphan-msg"></p>
			<p>
				<button type="button" id="fsc-import-orphan-cancel" class="button" hidden>
					<?php echo esc_html__( 'Cancel running import', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-import-idle">
			<div id="fsc-dropzone" class="fsc-dropzone" tabindex="0" role="button"
				aria-label="<?php echo esc_attr__( 'Drop an archive file here, or activate to choose a file', 'wp-free-site-cloner' ); ?>">
				<p>
					<?php echo esc_html__( 'Drag and drop a backup archive (.tar, .wpress from All-in-One WP Migration, .zip or .daf from Duplicator) here, or', 'wp-free-site-cloner' ); ?>
					<label class="button" for="fsc-file-input"><?php echo esc_html__( 'Choose file', 'wp-free-site-cloner' ); ?></label>
				</p>
				<input type="file" id="fsc-file-input" class="screen-reader-text" accept=".tar,.wpress,.zip,.daf" />
			</div>
			<p class="description fsc-storage-hint">
				<?php echo esc_html__( 'Large archives can also be uploaded by FTP to this folder; they then appear in the Archives list below:', 'wp-free-site-cloner' ); ?>
				<br /><code id="fsc-storage-path"></code>
			</p>
		</div>

		<div id="fsc-import-checking" hidden>
			<p class="fsc-status-line">
				<span class="fsc-spinner" aria-hidden="true"></span>
				<span id="fsc-checking-text"></span>
			</p>
		</div>

		<div id="fsc-precheck-error" class="notice notice-error inline fsc-msg-box" role="alert" hidden>
			<p id="fsc-precheck-error-msg"></p>
			<p class="fsc-msg-time" id="fsc-precheck-error-time"></p>
			<p>
				<button type="button" id="fsc-precheck-choose-another" class="button">
					<?php echo esc_html__( 'Choose another file', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-import-uploading" hidden>
			<div id="fsc-upload-progress-wrap">
				<div class="fsc-progress-row">
					<progress id="fsc-upload-progress" class="fsc-progress" max="100" value="0"
						role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
						aria-label="<?php echo esc_attr__( 'Upload progress', 'wp-free-site-cloner' ); ?>"></progress>
					<span id="fsc-upload-percent" class="fsc-percent">0%</span>
				</div>
				<p id="fsc-upload-status" class="fsc-status-line" aria-live="polite"></p>
				<p>
					<button type="button" id="fsc-upload-cancel" class="button">
						<?php echo esc_html__( 'Cancel upload', 'wp-free-site-cloner' ); ?>
					</button>
				</p>
			</div>
			<div id="fsc-upload-inspecting-wrap" hidden>
				<p class="fsc-status-line">
					<span class="fsc-spinner" aria-hidden="true"></span>
					<span id="fsc-upload-inspecting-text"></span>
				</p>
				<p>
					<button type="button" id="fsc-upload-inspecting-cancel" class="button">
						<?php echo esc_html__( 'Cancel', 'wp-free-site-cloner' ); ?>
					</button>
				</p>
			</div>
		</div>

		<div id="fsc-upload-error" class="notice notice-error inline fsc-msg-box" role="alert" hidden>
			<p id="fsc-upload-error-msg"></p>
			<p class="fsc-msg-time" id="fsc-upload-error-time"></p>
			<p>
				<button type="button" id="fsc-upload-dismiss" class="button">
					<?php echo esc_html__( 'Dismiss', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-inspect-error" class="notice notice-error inline fsc-msg-box" role="alert" hidden>
			<p id="fsc-inspect-error-msg"></p>
			<p class="fsc-msg-time" id="fsc-inspect-error-time"></p>
			<p>
				<button type="button" id="fsc-inspect-choose-another" class="button">
					<?php echo esc_html__( 'Choose another file', 'wp-free-site-cloner' ); ?>
				</button>
				<button type="button" id="fsc-inspect-delete-upload" class="button">
					<?php echo esc_html__( 'Delete this upload', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-import-inspect" hidden>
			<h3 id="fsc-inspect-heading" tabindex="-1"><?php echo esc_html__( 'Archive details', 'wp-free-site-cloner' ); ?></h3>
			<table class="widefat fsc-inspect-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Archive', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-insp-name"></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Source URL', 'wp-free-site-cloner' ); ?></th>
						<td><span id="fsc-insp-home"></span> &rarr; <span id="fsc-insp-target-home"></span></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Source format', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-insp-format"></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'WordPress version', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-insp-wpver"></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Size', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-insp-size"></td>
					</tr>
				</tbody>
			</table>

			<div id="fsc-insp-warnings" class="notice notice-warning inline" hidden>
				<p><strong><?php echo esc_html__( 'Warnings:', 'wp-free-site-cloner' ); ?></strong></p>
				<ul id="fsc-insp-warnings-list"></ul>
			</div>

			<div class="notice notice-error inline fsc-confirm-notice">
				<p>
					<strong>
						<?php echo esc_html__( 'This replaces the whole current site\'s database and wp-content. You will be logged out and must log in with the old site\'s credentials.', 'wp-free-site-cloner' ); ?>
					</strong>
				</p>
				<p id="fsc-confirm-trust">
					<?php echo esc_html__( 'Only import archives you created or fully trust: an archive contains PHP code (plugins, themes, mu-plugins) and user accounts that become active on this site.', 'wp-free-site-cloner' ); ?>
				</p>
			</div>

			<label class="fsc-confirm-check">
				<input type="checkbox" id="fsc-confirm-checkbox" />
				<?php echo esc_html__( 'I understand this replaces the current site and cannot be undone.', 'wp-free-site-cloner' ); ?>
			</label>

			<p>
				<button type="button" id="fsc-import-start" class="button button-primary" disabled>
					<?php echo esc_html__( 'Start import', 'wp-free-site-cloner' ); ?>
				</button>
				<button type="button" id="fsc-import-inspect-back" class="button">
					<?php echo esc_html__( 'Back', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-import-running" hidden>
			<?php FSC_Plugin::render_progress_panel( 'import' ); ?>
			<p>
				<button type="button" id="fsc-import-cancel" class="button">
					<?php echo esc_html__( 'Cancel', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
			<details class="fsc-log-details">
				<summary><?php echo esc_html__( 'Log', 'wp-free-site-cloner' ); ?></summary>
				<div id="fsc-import-log" class="fsc-log" tabindex="0"></div>
			</details>
		</div>

		<div id="fsc-import-error" class="notice notice-error inline fsc-msg-box" role="alert" hidden>
			<p id="fsc-import-error-msg"></p>
			<p class="fsc-msg-time" id="fsc-import-error-time"></p>
			<p>
				<button type="button" id="fsc-import-resume" class="button" hidden>
					<?php echo esc_html__( 'Resume', 'wp-free-site-cloner' ); ?>
				</button>
				<button type="button" id="fsc-import-dismiss" class="button">
					<?php echo esc_html__( 'Dismiss', 'wp-free-site-cloner' ); ?>
				</button>
			</p>
		</div>

		<div id="fsc-import-done" class="notice notice-success inline" role="status" hidden>
			<p><?php echo esc_html__( 'Import complete. Log in with the user accounts of the imported site.', 'wp-free-site-cloner' ); ?></p>
			<div id="fsc-import-done-warnings" class="notice notice-warning inline" hidden>
				<p><strong><?php echo esc_html__( 'Warnings:', 'wp-free-site-cloner' ); ?></strong></p>
				<ul id="fsc-import-done-warnings-list"></ul>
			</div>
			<p>
				<a id="fsc-import-login-link" class="button button-primary" href="<?php echo esc_url( $fsc_admin_data['loginUrl'] ); ?>">
					<?php echo esc_html__( 'Go to login', 'wp-free-site-cloner' ); ?>
				</a>
			</p>
		</div>
	</div>

	<!-- ===================== Archives ===================== -->
	<div class="card fsc-card" id="fsc-archives-card">
		<h2><?php echo esc_html__( 'Archives', 'wp-free-site-cloner' ); ?></h2>

		<div id="fsc-archives-notice" class="notice notice-error inline" role="alert" hidden>
			<p id="fsc-archives-notice-msg"></p>
		</div>

		<p class="fsc-archives-risk" id="fsc-archives-risk">
			<strong><?php echo esc_html__( 'Archives are not encrypted.', 'wp-free-site-cloner' ); ?></strong>
			<?php echo esc_html__( 'Each one is a full copy of the site: the database with password hashes, email addresses and API keys, plus all files. Keep downloaded copies somewhere safe and delete archives here once a migration is done.', 'wp-free-site-cloner' ); ?>
		</p>

		<table class="widefat striped" id="fsc-archives-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Name', 'wp-free-site-cloner' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Size', 'wp-free-site-cloner' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Date', 'wp-free-site-cloner' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Format', 'wp-free-site-cloner' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'wp-free-site-cloner' ); ?></th>
				</tr>
			</thead>
			<tbody id="fsc-archives-tbody"></tbody>
		</table>
		<p id="fsc-archives-empty" class="description" hidden>
			<?php echo esc_html__( 'No archives yet. Export this site, or upload an archive by FTP.', 'wp-free-site-cloner' ); ?>
		</p>
		<p id="fsc-archives-ftp-hint" class="description"></p>
		<p id="fsc-archives-freespace" class="description"></p>

		<div id="fsc-storage-info" class="fsc-storage-info" hidden>
			<h3><?php echo esc_html__( 'Storage', 'wp-free-site-cloner' ); ?></h3>
			<div id="fsc-storage-error" class="notice notice-error inline" role="alert" hidden>
				<p id="fsc-storage-error-msg"></p>
			</div>
			<table class="widefat fsc-inspect-table fsc-storage-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Location', 'wp-free-site-cloner' ); ?></th>
						<td><code id="fsc-storage-location"></code> <span id="fsc-storage-source" class="description"></span></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Permissions', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-storage-modes"></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatic deletion', 'wp-free-site-cloner' ); ?></th>
						<td id="fsc-storage-retention"></td>
					</tr>
				</tbody>
			</table>
			<div id="fsc-storage-warnings" class="notice notice-warning inline" hidden>
				<ul id="fsc-storage-warnings-list"></ul>
			</div>
			<ul id="fsc-storage-notes" class="fsc-storage-notes description"></ul>
		</div>
	</div>

	</div><!-- #fsc-main -->
</div>

<!-- Translatable message templates read by assets/admin.js. Never rendered. -->
<div id="fsc-i18n" hidden>
	<span data-key="err_prefix"><?php echo esc_html__( 'Error: %s', 'wp-free-site-cloner' ); ?></span>
	<span data-key="reload_needed"><?php echo esc_html__( 'Your session expired. Reload the page.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: attempt number, 2: max attempts, 3: seconds until the next retry. */ ?>
	<span data-key="retrying"><?php echo esc_html__( 'The server did not respond (attempt %s of %s). Retrying in %ss…', 'wp-free-site-cloner' ); ?></span>
	<span data-key="connection_lost"><?php echo esc_html__( 'Lost connection to the server after several attempts. The job may still be running on the server.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="waiting_busy"><?php echo esc_html__( 'Waiting for the current step to finish…', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: bytes uploaded so far, 2: total size, 3: upload speed. */ ?>
	<span data-key="upload_progress"><?php echo esc_html__( '%s of %s uploaded (%s/s)', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: chunk number sent so far, 2: total chunk count. */ ?>
	<span data-key="upload_chunk_info"><?php echo esc_html__( 'Chunk %s of %s', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: estimated time remaining, formatted mm:ss. */ ?>
	<span data-key="upload_eta"><?php echo esc_html__( 'about %s remaining', 'wp-free-site-cloner' ); ?></span>
	<span data-key="upload_preparing"><?php echo esc_html__( 'Preparing upload…', 'wp-free-site-cloner' ); ?></span>
	<span data-key="upload_cancelled"><?php echo esc_html__( 'Upload cancelled.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: current attempt number, 2: max attempts. */ ?>
	<span data-key="upload_retry"><?php echo esc_html__( 'Upload problem, retrying… (attempt %s of %s)', 'wp-free-site-cloner' ); ?></span>
	<span data-key="upload_invalid_type"><?php echo esc_html__( 'That file type is not supported. Use a .tar, .wpress, .zip or .daf archive.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="checking_file"><?php echo esc_html__( 'Checking file…', 'wp-free-site-cloner' ); ?></span>
	<span data-key="checking_archive"><?php echo esc_html__( 'Checking archive…', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: current file size formatted with thousands separators, 2: expected size, 3: percent complete. */ ?>
	<?php // fmt() in admin.js only replaces literal "%s" tokens (no printf %% escaping) - a single "%" here renders as one literal percent sign. ?>
	<span data-key="tar_incomplete_pct"><?php echo esc_html__( 'This file is incomplete: %s of %s bytes (%s%). The download was probably cut off. Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="tar_never_finished"><?php echo esc_html__( 'This archive was never finished on the source site. Export again.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: current file size formatted with thousands separators, 2: expected size. */ ?>
	<span data-key="tar_wrong_size"><?php echo esc_html__( 'This file is larger than expected: %s of %s bytes. It may not be the right archive, or extra data was added to it. Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="file_incomplete_generic"><?php echo esc_html__( 'This file is incomplete. The download was probably cut off. Download it again, or copy it by FTP/SFTP.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="upload_complete_checking"><?php echo esc_html__( 'Upload complete. Checking the archive…', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: elapsed seconds. */ ?>
	<span data-key="elapsed_suffix"><?php echo esc_html__( '(%ss elapsed)', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: timeout in seconds. */ ?>
	<span data-key="inspect_timeout"><?php echo esc_html__( 'The server did not respond to the archive check within %ss. It may still be processing a large file; try again in a moment.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: seconds since the last server response. */ ?>
	<span data-key="heartbeat"><?php echo esc_html__( 'Last response from server: %ss ago', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: seconds with no progress. */ ?>
	<span data-key="stall_warning"><?php echo esc_html__( 'No progress for %ss. The server is still responding; a large file or table may be in progress.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: elapsed time, formatted mm:ss. */ ?>
	<span data-key="elapsed_total"><?php echo esc_html__( 'Total elapsed: %s', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: elapsed time in the current step, formatted mm:ss. */ ?>
	<span data-key="elapsed_step"><?php echo esc_html__( 'This step: %s', 'wp-free-site-cloner' ); ?></span>
	<span data-key="step_pending"><?php echo esc_html__( 'pending', 'wp-free-site-cloner' ); ?></span>
	<span data-key="step_active"><?php echo esc_html__( 'in progress', 'wp-free-site-cloner' ); ?></span>
	<span data-key="step_done"><?php echo esc_html__( 'done', 'wp-free-site-cloner' ); ?></span>
	<span data-key="step_skipped"><?php echo esc_html__( 'skipped', 'wp-free-site-cloner' ); ?></span>
	<span data-key="step_failed"><?php echo esc_html__( 'failed', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: number done so far, 2: total. */ ?>
	<span data-key="step_counter_bytes"><?php echo esc_html__( '%s of %s', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: number done so far, 2: total, 3: unit name (rows, files, tables, statements). */ ?>
	<span data-key="step_counter_generic"><?php echo esc_html__( '%s of %s %s', 'wp-free-site-cloner' ); ?></span>
	<span data-key="unit_rows"><?php echo esc_html__( 'rows', 'wp-free-site-cloner' ); ?></span>
	<span data-key="unit_files"><?php echo esc_html__( 'files', 'wp-free-site-cloner' ); ?></span>
	<span data-key="unit_tables"><?php echo esc_html__( 'tables', 'wp-free-site-cloner' ); ?></span>
	<span data-key="unit_statements"><?php echo esc_html__( 'statements', 'wp-free-site-cloner' ); ?></span>
	<span data-key="choose_another_file"><?php echo esc_html__( 'Choose another file', 'wp-free-site-cloner' ); ?></span>
	<span data-key="confirm_delete"><?php echo esc_html__( 'Delete %s? This cannot be undone.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="confirm_cancel"><?php echo esc_html__( 'Cancel the running job? Progress made so far will be discarded.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="cancel_conflict_hint"><?php echo esc_html__( 'It can be cancelled once it has been idle for 10 minutes.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="confirm_replace_job"><?php echo esc_html__( 'Another export or import is already running. Replace it?', 'wp-free-site-cloner' ); ?></span>
	<span data-key="export_done"><?php echo esc_html__( 'Export complete: %s (%s).', 'wp-free-site-cloner' ); ?></span>
	<span data-key="leave_warning"><?php echo esc_html__( 'A job is still running. If you leave, it keeps running on the server but this page will stop showing progress.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="import_orphan_running"><?php echo esc_html__( 'An import is running, started from another browser tab or session. Progress cannot be shown here.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="import_orphan_swapped"><?php echo esc_html__( 'An import is finishing on the server. Log in again shortly.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="not_found_refresh"><?php echo esc_html__( 'That job or archive no longer exists. The lists were refreshed.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="format_label_tar"><?php echo esc_html__( 'WP Free Site Cloner', 'wp-free-site-cloner' ); ?></span>
	<span data-key="format_label_wpress"><?php echo esc_html__( 'All-in-One WP Migration', 'wp-free-site-cloner' ); ?></span>
	<span data-key="format_label_duplicator"><?php echo esc_html__( 'Duplicator', 'wp-free-site-cloner' ); ?></span>
	<span data-key="download"><?php echo esc_html__( 'Download', 'wp-free-site-cloner' ); ?></span>
	<span data-key="delete"><?php echo esc_html__( 'Delete', 'wp-free-site-cloner' ); ?></span>
	<span data-key="delete_this_upload"><?php echo esc_html__( 'Delete this upload', 'wp-free-site-cloner' ); ?></span>
	<span data-key="use_for_import"><?php echo esc_html__( 'Use for import', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: free disk space, e.g. 1.6 TB. */ ?>
	<span data-key="free_space"><?php echo esc_html__( 'Free space: %s', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: exact byte count with thousands separators. */ ?>
	<span data-key="bytes_exact"><?php echo esc_html__( '%s bytes', 'wp-free-site-cloner' ); ?></span>
	<span data-key="checksum_available"><?php echo esc_html__( 'checksums available (verified on import)', 'wp-free-site-cloner' ); ?></span>
	<span data-key="storage_custom"><?php echo esc_html__( '(set by FSC_STORAGE_DIR)', 'wp-free-site-cloner' ); ?></span>
	<span data-key="storage_default"><?php echo esc_html__( '(default, inside wp-content)', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: directory mode, 2: file mode (octal, e.g. 0700). */ ?>
	<span data-key="storage_modes"><?php echo esc_html__( 'folders %s, files %s', 'wp-free-site-cloner' ); ?></span>
	<span data-key="storage_modes_wp"><?php echo esc_html__( '(from FS_CHMOD_DIR/FS_CHMOD_FILE)', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: number of days. */ ?>
	<span data-key="retention_on"><?php echo esc_html__( 'Archives older than %s days are deleted once a day.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="retention_off"><?php echo esc_html__( 'Off: archives are kept until you delete them. To delete them automatically after a number of days, define FSC_ARCHIVE_RETENTION_DAYS in wp-config.php or use the fsc_archive_retention_days filter.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="retention_stale"><?php echo esc_html__( 'Unfinished uploads and temporary files older than 24 hours are always deleted.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: 1: number of archives, 2: number of days, 3: total size. */ ?>
	<span data-key="retention_notice"><?php echo esc_html__( '%s archive(s) in storage are older than %s days (%s in total). Archives are full, unencrypted copies of a site; delete them once you no longer need them.', 'wp-free-site-cloner' ); ?></span>
	<span data-key="confirm_delete_all"><?php echo esc_html__( 'Delete ALL archives in storage? This cannot be undone. An archive used by a running job is kept.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: number of archives deleted. */ ?>
	<span data-key="deleted_all"><?php echo esc_html__( '%s archive(s) deleted.', 'wp-free-site-cloner' ); ?></span>
	<?php /* translators: %s: the storage directory's absolute path. */ ?>
	<span data-key="ftp_hint"><?php echo esc_html__( 'Large archives: download by FTP/SFTP from %s to avoid browser or server timeouts. Compare the file size with the size shown here.', 'wp-free-site-cloner' ); ?></span>
</div>
