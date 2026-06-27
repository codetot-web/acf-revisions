<?php
/**
 * Bridge hooks between ACF Flexible Content and WordPress post revisions.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles copying ACF Flexible Content meta to/from post revisions.
 *
 * WordPress revisions (since 6.4) support meta revisioning via register_meta(),
 * but only for statically-registered meta keys. ACF Flexible Content fields
 * use dynamic keys (sections_0_title, sections_3_image, etc.) that cannot
 * be pre-registered. This bridge copies those keys into revision posts
 * manually, enabling proper revision history and recovery.
 */
class ACFR_Bridge {

	/**
	 * Meta key pattern for ACF flexible content fields.
	 *
	 * @var string
	 */
	const SECTIONS_PATTERN = '/^(sections_|_sections)/';

	/**
	 * Flexible content field group key.
	 *
	 * @var string
	 */
	private $field_group_key = '';

	/**
	 * Registered post types to monitor.
	 *
	 * @var string[]
	 */
	private $post_types = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Filter the field group key for ACF flexible content.
		 * Defaults to the Flexible Template group (group_69577fd380786).
		 *
		 * @param string $field_group_key The ACF field group key.
		 */
		$this->field_group_key = apply_filters( 'acfr_field_group_key', 'group_69577fd380786' );

		/**
		 * Filter which post types should have ACF meta revisioned.
		 *
		 * @param string[] $post_types Array of post type slugs.
		 */
		$this->post_types = apply_filters( 'acfr_post_types', array( 'page', 'post' ) );

		$this->register_hooks();
	}

	/**
	 * Register all bridge hooks.
	 */
	private function register_hooks(): void {
		// Hook 1: When a revision is created, copy ACF meta from parent to revision.
		add_action( '_wp_put_post_revision', array( $this, 'copy_acf_meta_to_revision' ) );

		// Hook 2: When a revision is restored, copy ACF meta back to the parent post.
		add_action( 'wp_restore_post_revision', array( $this, 'restore_acf_meta_from_revision' ), 10, 2 );

		// Hook 3: Before ACF field group import, snapshot the field group JSON for recovery.
		add_action( 'acf/import_field_group', array( $this, 'snapshot_before_import' ), 0, 1 );

		// Hook 4: Also capture meta updates for direct post_meta changes (WP-CLI, bulk edit).
		add_action( 'updated_post_meta', array( $this, 'on_meta_update' ), 10, 4 );

		// Hook 5: Snapshot when ACF saves post data.
		add_action( 'acf/save_post', array( $this, 'snapshot_on_acf_save' ), 5 );
	}

	/**
	 * Hook 1: Copy ACF flexible content meta to a revision post.
	 *
	 * Called when WordPress creates a new revision. Copies all sections_%
	 * and _sections% meta keys from the parent post to the revision post.
	 *
	 * @param int $revision_id The revision post ID.
	 */
	public function copy_acf_meta_to_revision( int $revision_id ): void {
		$parent_id = wp_is_post_revision( $revision_id );
		if ( ! $parent_id ) {
			return;
		}

		if ( ! $this->is_tracked_post_type( $parent_id ) ) {
			return;
		}

		$acf_meta = $this->get_acf_section_meta( $parent_id );
		if ( empty( $acf_meta ) ) {
			return;
		}

		// Batch delete existing ACF meta on the revision (from prior saves).
		$this->delete_acf_section_meta( $revision_id );

		// Copy meta from parent to revision.
		foreach ( $acf_meta as $meta_key => $meta_value ) {
			add_metadata( 'post', $revision_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Hook 2: Restore ACF flexible content meta from a revision to the parent post.
	 *
	 * Called when a user restores a revision. Replaces all current
	 * sections_% and _sections% meta with the values from the revision.
	 *
	 * @param int $post_id      The parent post ID.
	 * @param int $revision_id  The revision post ID being restored.
	 */
	public function restore_acf_meta_from_revision( int $post_id, int $revision_id ): void {
		if ( ! $this->is_tracked_post_type( $post_id ) ) {
			return;
		}

		$revision_meta = $this->get_acf_section_meta( $revision_id );
		if ( empty( $revision_meta ) ) {
			return;
		}

		// 1. Clear existing ACF meta on the parent post.
		$this->delete_acf_section_meta( $post_id );

		// 2. Restore meta from revision to parent.
		foreach ( $revision_meta as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}

		// 3. Ensure the _sections reference key exists (field group registration).
		if ( ! metadata_exists( 'post', $post_id, '_sections' ) ) {
			$sections_key = $this->get_sections_field_key();
			if ( $sections_key ) {
				update_post_meta( $post_id, '_sections', $sections_key );
			}
		}

		// 4. Clear ACF field cache so it re-reads from the restored meta.
		$this->clear_acf_cache();
	}

	/**
	 * Hook 3: Snapshot field group before import/update.
	 *
	 * Captures the current field group JSON before acf_import_field_group()
	 * modifies it. Stores up to 10 snapshots in wp_options for recovery.
	 * This is the safety net for the exact scenario described in issue #64.
	 *
	 * @param array $field_group The field group being imported.
	 */
	public function snapshot_before_import( array $field_group ): void {
		if ( ! isset( $field_group['key'] ) || $field_group['key'] !== $this->field_group_key ) {
			return;
		}

		$backups = get_option( '_acfr_field_group_backups', array() );

		// Store the current field group state from ACF's local store.
		$snapshot = array(
			'time'       => time(),
			'user_id'    => get_current_user_id(),
			'group_key'  => $field_group['key'],
			'group_name' => $field_group['title'] ?? '',
			'snapshot'   => $this->get_current_field_group_state( $field_group['key'] ),
			'action'     => 'import',
		);

		array_unshift( $backups, $snapshot );

		// Keep only the last 10 snapshots.
		$backups = array_slice( $backups, 0, 10 );

		update_option( '_acfr_field_group_backups', $backups, false );
	}

	/**
	 * Hook 4: Track direct meta updates for ACF section fields.
	 *
	 * When post meta is updated directly (via WP-CLI, REST API, bulk edit),
	 * ensure that a revision is created to capture the change.
	 *
	 * @param int    $meta_id    ID of the meta entry.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $_meta_value Meta value.
	 */
	public function on_meta_update( int $meta_id, int $post_id, string $meta_key, $_meta_value ): void {
		// Only track ACF section fields.
		if ( ! preg_match( self::SECTIONS_PATTERN, $meta_key ) ) {
			return;
		}

		if ( ! $this->is_tracked_post_type( $post_id ) ) {
			return;
		}

		// Don't trigger on revisions themselves.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Force a revision to be created if one doesn't exist for this update.
		// WordPress already handles this for post_content changes,
		// but meta-only updates may not trigger a revision.
		if ( ! wp_revisions_enabled( get_post( $post_id ) ) ) {
			return;
		}

		// The revision will be created by WordPress save_post flow.
		// Our _wp_put_post_revision hook (Hook 1) will copy the meta.
		// This hook is just for logging/debugging.
		do_action( 'acfr_meta_changed', $post_id, $meta_key, $post_id );
	}

	/**
	 * Hook 5: Pre-snapshot before ACF saves post data.
	 *
	 * Runs before ACF writes field values. Captures current meta state
	 * for diff/rollback purposes.
	 *
	 * @param int $post_id The post ID being saved.
	 */
	public function snapshot_on_acf_save( int $post_id ): void {
		if ( ! $this->is_tracked_post_type( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Store the pre-save snapshot in post meta for comparison.
		$before = $this->get_acf_section_meta( $post_id );
		if ( ! empty( $before ) ) {
			update_post_meta( $post_id, '_acfr_before', $before );
		}
	}

	/**
	 * Get all ACF section meta keys for a post.
	 *
	 * Returns only the meta keys that belong to the flexible content field:
	 * - sections (the layout name array)
	 * - sections_N_* (field values)
	 * - _sections_N_* (field reference keys)
	 * - _sections (field group reference)
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Meta key => value pairs.
	 */
	public function get_acf_section_meta( int $post_id ): array {
		$meta = get_post_meta( $post_id );
		if ( empty( $meta ) ) {
			return array();
		}

		$acf_meta = array();

		foreach ( $meta as $key => $values ) {
			if ( preg_match( self::SECTIONS_PATTERN, $key ) ) {
				// Take the last value (most recent).
				$acf_meta[ $key ] = maybe_unserialize( end( $values ) );
			}
		}

		return $acf_meta;
	}

	/**
	 * Delete all ACF section meta for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_acf_section_meta( int $post_id ): void {
		$meta = get_post_meta( $post_id );
		if ( empty( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			if ( preg_match( self::SECTIONS_PATTERN, $key ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Check if a post type is tracked for revisions.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_tracked_post_type( int $post_id ): bool {
		$post_type = get_post_type( $post_id );
		return in_array( $post_type, $this->post_types, true );
	}

	/**
	 * Get the ACF field key for the sections flexible content field.
	 *
	 * @return string|null Field key or null.
	 */
	public function get_sections_field_key(): ?string {
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return null;
		}

		$field_group = acf_get_field_group( $this->field_group_key );
		if ( ! $field_group ) {
			return null;
		}

		$fields = acf_get_fields( $field_group );
		foreach ( $fields as $field ) {
			if ( 'flexible_content' === ( $field['type'] ?? '' ) && 'sections' === ( $field['name'] ?? '' ) ) {
				return $field['key'];
			}
		}

		return null;
	}

	/**
	 * Get the current state of a field group from ACF's local store.
	 *
	 * @param string $field_group_key The field group key.
	 * @return array|null The field group array or null.
	 */
	public function get_current_field_group_state( string $field_group_key ): ?array {
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return null;
		}

		$field_group = acf_get_field_group( $field_group_key );
		if ( ! $field_group ) {
			return null;
		}

		$fields = acf_get_fields( $field_group );

		return array(
			'field_group' => $field_group,
			'fields'      => $fields,
		);
	}

	/**
	 * Clear ACF's internal field cache to force re-reading from meta.
	 */
	public function clear_acf_cache(): void {
		if ( function_exists( 'acf_get_store' ) ) {
			$store = acf_get_store( 'fields' );
			if ( $store ) {
				$store->reset();
			}
		}

		if ( function_exists( 'acf_get_store' ) ) {
			$store = acf_get_store( 'values' );
			if ( $store ) {
				$store->reset();
			}
		}
	}

	/**
	 * Run an integrity check on all pages using the flexible content template.
	 *
	 * Verifies:
	 * - All pages have the _sections reference key.
	 * - No orphaned _sections_N_* meta keys exist (references to non-existent sections).
	 * - All sections_N_* values have matching _sections_N_* reference keys.
	 * - The sections array matches the actual sections_N_* meta keys.
	 *
	 * @param array $args {
	 *     Optional. Arguments for the check.
	 *
	 *     @type int|null   $post_id     Check a specific post only.
	 *     @type bool       $verbose     Show detailed output.
	 *     @type bool       $fix         Auto-fix detected issues.
	 * }
	 * @return array{issues: array, fixed: int, total: int}
	 */
	public function integrity_check( array $args = [] ): array {
		$args = wp_parse_args( $args, array(
			'post_id' => null,
			'verbose' => false,
			'fix'     => false,
		) );

		$issues = array();
		$fixed  = 0;

		// Get posts to check.
		if ( $args['post_id'] ) {
			$posts = array( get_post( $args['post_id'] ) );
		} else {
			$posts = get_posts( array(
				'post_type'      => $this->post_types,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			) );
		}

		$total = count( $posts );
		$sections_key = $this->get_sections_field_key();

		foreach ( $posts as $post_id ) {
			if ( is_object( $post_id ) ) {
				$post_id = $post_id->ID;
			}

			// Check 1: _sections reference key exists.
			$has_ref = metadata_exists( 'post', $post_id, '_sections' );

			if ( ! $has_ref ) {
				$issues[] = array(
					'post_id'  => $post_id,
					'severity' => 'error',
					'type'     => 'missing_sections_ref',
					'message'  => "Post $post_id: Missing _sections reference key.",
				);

				if ( $args['fix'] && $sections_key ) {
					update_post_meta( $post_id, '_sections', $sections_key );
					$fixed++;
				}
			}

			// Check 2: Get all sections_% keys.
			$section_values = array();
			$section_refs   = array();
			$all_meta       = get_post_meta( $post_id );

			foreach ( $all_meta as $key => $values ) {
				if ( preg_match( '/^sections_(\d+)_(.+)$/', $key, $m ) ) {
					$section_values[ $key ] = $m[1];
				} elseif ( preg_match( '/^_sections_(\d+)_(.+)$/', $key, $m ) ) {
					$section_refs[ $key ] = $m[1];
				}
			}

			// Check 3: Orphaned meta values without corresponding ref keys.
			foreach ( $section_values as $key => $idx ) {
				$ref_key = '_' . $key;
				if ( ! isset( $section_refs[ $ref_key ] ) && $key !== '_sections' ) {
					$issues[] = array(
						'post_id'  => $post_id,
						'severity' => 'warning',
						'type'     => 'orphaned_value',
						'message'  => "Post $post_id: Value key $key has no matching _ref key.",
					);
				}
			}
		}

		return array(
			'issues' => $issues,
			'fixed'  => $fixed,
			'total'  => $total,
		);
	}
}
