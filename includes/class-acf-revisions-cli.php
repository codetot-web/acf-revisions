<?php
/**
 * WP-CLI commands for ACF Revisions.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage ACF Flexible Content revisions and integrity.
 *
 * ## EXAMPLES
 *
 *     # Run integrity check on all pages.
 *     $ wp acf-revisions check
 *     Success: Checked 28 posts, 0 issues found.
 *
 *     # Check and auto-fix missing reference keys.
 *     $ wp acf-revisions check --fix
 *     Success: Checked 28 posts, 3 issues found, 3 fixed.
 *
 *     # Check a specific post.
 *     $ wp acf-revisions check --post_id=123
 *
 *     # List field group snapshots.
 *     $ wp acf-revisions snapshots
 *
 *     # Test bridge hooks on a post.
 *     $ wp acf-revisions test-bridge --post_id=123
 */
class ACFR_CLI extends WP_CLI_Command {

	/**
	 * Run integrity check on ACF flexible content pages.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Auto-fix detected issues (add missing _sections reference keys).
	 *
	 * [--post_id=<post_id>]
	 * : Check a specific post only.
	 *
	 * [--verbose]
	 * : Show detailed output for each issue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acf-revisions check
	 *     wp acf-revisions check --fix
	 *     wp acf-revisions check --post_id=123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		$bridge = acf_revisions_get_bridge();

		if ( ! $bridge ) {
			WP_CLI::error( 'ACF Revisions bridge not initialized. Is ACF Pro active?' );
		}

		$post_id = isset( $assoc_args['post_id'] ) ? (int) $assoc_args['post_id'] : null;
		$fix     = isset( $assoc_args['fix'] ) && $assoc_args['fix'];
		$verbose = isset( $assoc_args['verbose'] ) && $assoc_args['verbose'];

		$progress = WP_CLI\Utils\make_progress_bar(
			'Running integrity check...',
			$post_id ? 1 : 10
		);

		$result = $bridge->integrity_check( array(
			'post_id' => $post_id,
			'fix'     => $fix,
			'verbose' => $verbose,
		) );

		$progress->finish();

		if ( empty( $result['issues'] ) ) {
			WP_CLI::success(
				sprintf(
					'Checked %d posts, 0 issues found.',
					$result['total']
				)
			);
			return;
		}

		WP_CLI::warning(
			sprintf(
				'Checked %d posts, %d issues found%s.',
				$result['total'],
				count( $result['issues'] ),
				$result['fixed'] > 0 ? ", {$result['fixed']} fixed" : ''
			)
		);

		if ( $verbose ) {
			$table = array();
			foreach ( $result['issues'] as $issue ) {
				$table[] = array(
					'Post ID'  => $issue['post_id'],
					'Severity' => $issue['severity'],
					'Type'     => $issue['type'],
					'Message'  => $issue['message'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $table, array( 'Post ID', 'Severity', 'Type', 'Message' ) );
		}
	}

	/**
	 * List field group snapshots.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acf-revisions snapshots
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function snapshots( array $args, array $assoc_args ): void {
		$backups = get_option( '_acf_revisions_field_group_backups', array() );

		if ( empty( $backups ) ) {
			WP_CLI::warning( 'No field group snapshots found.' );
			return;
		}

		$table = array();
		foreach ( $backups as $i => $snapshot ) {
			$table[] = array(
				'#'         => $i + 1,
				'Time'      => gmdate( 'Y-m-d H:i:s', $snapshot['time'] ),
				'Group Key' => $snapshot['group_key'],
				'Action'    => $snapshot['action'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $table, array( '#', 'Time', 'Group Key', 'Action' ) );
	}

	/**
	 * Test the bridge hooks on a specific post.
	 *
	 * Creates a test revision, verifies ACF meta was copied,
	 * then cleans up.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to test.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acf-revisions test-bridge 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test_bridge( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			WP_CLI::error( "Post $post_id not found." );
		}

		$bridge = acf_revisions_get_bridge();
		if ( ! $bridge ) {
			WP_CLI::error( 'Bridge not initialized.' );
		}

		WP_CLI::log( "Testing bridge hooks on post $post_id ({$post->post_title})..." );

		// 1. Get current ACF meta.
		$acf_meta = $bridge->get_acf_section_meta( $post_id );
		WP_CLI::log( sprintf( 'Current ACF section meta keys: %d', count( $acf_meta ) ) );

		if ( empty( $acf_meta ) ) {
			WP_CLI::warning( 'No ACF section meta found. This post may not use flexible content.' );
		}

		// Show a few keys as sample.
		$sample = array_slice( array_keys( $acf_meta ), 0, 5 );
		foreach ( $sample as $key ) {
			$val = is_string( $acf_meta[ $key ] ) ? $acf_meta[ $key ] : '(complex)';
			WP_CLI::log( "  $key => " . substr( $val, 0, 60 ) );
		}

		// 2. Verify _sections reference key.
		$has_ref = metadata_exists( 'post', $post_id, '_sections' );
		WP_CLI::log( sprintf( '_sections reference key: %s', $has_ref ? '✅ present' : '❌ missing' ) );

		// 3. Check integrity for this post.
		$result = $bridge->integrity_check( array(
			'post_id' => $post_id,
		) );

		if ( empty( $result['issues'] ) ) {
			WP_CLI::success( "Post $post_id integrity check passed." );
		} else {
			WP_CLI::warning( sprintf( '%d issues found for post %d.', count( $result['issues'] ), $post_id ) );
			foreach ( $result['issues'] as $issue ) {
				WP_CLI::log( "  [{$issue['severity']}] {$issue['message']}" );
			}
		}

		WP_CLI::success( 'Bridge test completed.' );
	}
}

/**
 * Register the WP-CLI commands.
 */
WP_CLI::add_command( 'acf-revisions', 'ACFR_CLI' );
