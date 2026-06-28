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
		$bridge = acfr_get_bridge();

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
		$backups = get_option( '_acfr_field_group_backups', array() );

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

		$bridge = acfr_get_bridge();
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

	/**
	 * Restore ACF sections meta from a revision to its parent post.
	 *
	 * Copies all sections_% and _sections% meta from the revision post
	 * back to the parent post. Creates a backup of the current state
	 * first in wp_options (_acfr_restore_backup_{post_id}).
	 *
	 * ## OPTIONS
	 *
	 * <revision_id>
	 * : The revision post ID to restore from.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acf-revisions restore 6732
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$rev_id = (int) $args[0];
		$parent_id = wp_is_post_revision( $rev_id );

		if ( ! $parent_id ) {
			WP_CLI::error( "Post $rev_id is not a revision." );
		}

		$parent = get_post( $parent_id );
		WP_CLI::log( sprintf(
			'Restoring revision %d → post %d (%s)...',
			$rev_id,
			$parent_id,
			$parent->post_title
		) );

		// Show summary before restoring.
		$rev_meta_count = count( acfr_get_bridge()->get_acf_section_meta( $rev_id ) );
		$cur_meta_count = count( acfr_get_bridge()->get_acf_section_meta( $parent_id ) );

		WP_CLI::log( "Current ACF keys on post: $cur_meta_count" );
		WP_CLI::log( "ACF keys on revision:   $rev_meta_count" );

		// Confirm.
		WP_CLI::confirm( 'Proceed with restore?' );

		// Run restore.
		$copied = acfr_restore_revision( $rev_id );

		WP_CLI::success( sprintf(
			'Restored revision %d → post %d. Copied %d meta keys. Backup saved as _acfr_restore_backup_%d.',
			$rev_id,
			$parent_id,
			$copied,
			$parent_id
		) );
	}

	/**
	 * List recent revisions for a post with ACF sections diff summary.
	 *
	 * Shows the 5 most recent revisions, with key/value changes
	 * in the ACF sections meta for each revision compared to its
	 * previous version.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to list revisions for.
	 *
	 * [--limit=<limit>]
	 * : Number of revisions to show. Default: 5.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table. Options: table, json, csv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp acf-revisions revisions 34
	 *     wp acf-revisions revisions 34 --limit=10
	 *     wp acf-revisions revisions 34 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function revisions( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		$limit   = min( (int) ( $assoc_args['limit'] ?? 5 ), 50 );
		$format  = $assoc_args['format'] ?? 'table';

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post $post_id not found." );
		}

		$revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => $limit,
			'orderby'        => 'post_date',
			'order'          => 'DESC',
		) );

		if ( empty( $revisions ) ) {
			WP_CLI::warning( "No revisions found for post $post_id." );
			return;
		}

		$items = array();

		foreach ( $revisions as $rev ) {
			$sections = get_post_meta( $rev->ID, 'sections', true );
			if ( ! is_array( $sections ) ) {
				$sections = array();
			}

			$fields  = $this->acfr_get_field_values( $rev->ID );
			$changes = $this->acfr_diff_previous( $rev->ID, $post_id );
			$author  = get_userdata( $rev->post_author );

			$items[] = array(
				'revision_id'  => $rev->ID,
				'date'         => $rev->post_date,
				'author'       => $author ? $author->user_login : 'unknown',
				'layout_count' => count( $sections ),
				'layouts'      => implode( ', ', $sections ),
				'field_count'  => count( $fields ),
				'changes'      => $changes,
			);
		}

		if ( 'table' === $format || 'csv' === $format ) {
			$display = array();
			foreach ( $items as $item ) {
				$display[] = array(
					'Rev ID'  => $item['revision_id'],
					'Date'    => $item['date'],
					'Author'  => $item['author'],
					'Layouts' => $item['layout_count'],
					'Fields'  => $item['field_count'],
					'Changes' => $item['changes'],
				);
			}
			WP_CLI\Utils\format_items( $format, $display, array( 'Rev ID', 'Date', 'Author', 'Layouts', 'Fields', 'Changes' ) );
		} else {
			WP_CLI\Utils\format_items( $format, $items, array( 'revision_id', 'date', 'author', 'layout_count', 'layouts', 'field_count', 'changes' ) );
		}
	}

	/**
	 * Get ACF field values for a post/revision (non-ref keys).
	 *
	 * @param int $post_id Post or revision ID.
	 * @return array<string, mixed>
	 */
	private function acfr_get_field_values( int $post_id ): array {
		$meta  = get_post_meta( $post_id );
		$items = array();
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( $key, 'sections_' ) && ! str_starts_with( $key, '_' ) ) {
				$items[ $key ] = maybe_unserialize( end( $values ) );
			}
		}
		return $items;
	}

	/**
	 * Diff ACF sections meta between a revision and its immediate predecessor.
	 *
	 * @param int $rev_id  Current revision ID.
	 * @param int $post_id Parent post ID.
	 * @return string Human-readable diff summary.
	 */
	private function acfr_diff_previous( int $rev_id, int $post_id ): string {
		$rev = get_post( $rev_id );
		if ( ! $rev ) {
			return 'unknown';
		}

		global $wpdb;
		$prev_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM $wpdb->posts
			 WHERE post_type = 'revision'
			   AND post_parent = %d
			   AND post_date < %s
			   AND ID != %d
			 ORDER BY post_date DESC
			 LIMIT 1",
			$post_id,
			$rev->post_date,
			$rev_id
		) );

		if ( ! $prev_id ) {
			return '(first revision)';
		}

		$cur_sections  = array_filter( (array) get_post_meta( $rev_id, 'sections', true ) );
		$prev_sections = array_filter( (array) get_post_meta( $prev_id, 'sections', true ) );

		$parts = array();

		if ( $cur_sections !== $prev_sections ) {
			$added   = array_diff( $cur_sections, $prev_sections );
			$removed = array_diff( $prev_sections, $cur_sections );
			if ( ! empty( $added ) ) {
				$parts[] = '+' . implode( ',+', $added );
			}
			if ( ! empty( $removed ) ) {
				$parts[] = '-' . implode( ',-', $removed );
			}
		}

		$cur_fields  = $this->acfr_get_field_values( $rev_id );
		$prev_fields = $this->acfr_get_field_values( $prev_id );

		$changed = 0;
		foreach ( $cur_fields as $key => $val ) {
			if ( array_key_exists( $key, $prev_fields ) && $prev_fields[ $key ] !== $val ) {
				$changed++;
			}
		}
		$new_keys  = count( $cur_fields ) - count( array_intersect_key( $cur_fields, $prev_fields ) );
		$del_keys  = count( $prev_fields ) - count( array_intersect_key( $prev_fields, $cur_fields ) );

		if ( $new_keys > 0 ) {
			$parts[] = "+{$new_keys}f";
		}
		if ( $del_keys > 0 ) {
			$parts[] = "-{$del_keys}f";
		}
		if ( $changed > 0 ) {
			$parts[] = "{$changed}f changed";
		}

		return ! empty( $parts ) ? implode( ', ', $parts ) : '(no changes)';
	}
}

/**
 * Register the WP-CLI commands.
 */
WP_CLI::add_command( 'acf-revisions', 'ACFR_CLI' );

/**
 * Restore ACF sections meta from a revision.
 *
 * @param int $rev_id Revision post ID.
 * @return int Parent post ID.
 */
function acfr_restore_revision( int $rev_id ): int {
	$parent_id = wp_is_post_revision( $rev_id );
	if ( ! $parent_id ) {
		throw new InvalidArgumentException( "Post $rev_id is not a revision." );
	}

	// 1. Backup current ACF meta.
	$current_backup = array();
	$meta = get_post_meta( $parent_id );
	foreach ( $meta as $key => $values ) {
		if ( str_starts_with( $key, 'sections_' ) || str_starts_with( $key, '_sections' ) ) {
			$current_backup[ $key ] = end( $values );
		}
	}
	update_option( '_acfr_restore_backup_' . $parent_id, $current_backup, false );

	// 2. Delete current ACF meta on parent.
	foreach ( array_keys( $current_backup ) as $key ) {
		delete_post_meta( $parent_id, $key );
	}

	// 3. Copy sections_% and _sections_% meta from revision to parent.
	$rev_meta = get_post_meta( $rev_id );
	$copied   = 0;
	foreach ( $rev_meta as $key => $values ) {
		if ( str_starts_with( $key, 'sections_' ) || str_starts_with( $key, '_sections' ) ) {
			$val = maybe_unserialize( end( $values ) );
			update_post_meta( $parent_id, $key, $val );
			$copied++;
		}
	}

	// 4. Ensure _sections ref key exists.
	if ( ! metadata_exists( 'post', $parent_id, '_sections' ) ) {
		$acf_key = get_post_meta( $rev_id, '_sections', true );
		if ( $acf_key ) {
			update_post_meta( $parent_id, '_sections', $acf_key );
			$copied++;
		}
	}

	// 5. Clear ACF field cache.
	if ( function_exists( 'acf_get_store' ) ) {
		$store = acf_get_store( 'fields' );
		if ( $store ) {
			$store->reset();
		}
		$store = acf_get_store( 'values' );
		if ( $store ) {
			$store->reset();
		}
	}

	return $copied;
}
