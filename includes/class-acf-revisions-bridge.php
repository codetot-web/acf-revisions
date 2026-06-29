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
	 * Cached list of detected flexible content fields.
	 *
	 * Populated by detect_flex_fields(). Each entry:
	 *   name      => string (field name, e.g. 'sections')
	 *   key       => string (ACF field key, e.g. 'field_...')
	 *   group_key => string (ACF field group key)
	 *
	 * @var array[]|null
	 */
	private $flex_fields = null;

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

		// Hook 6: Preventative guard — block save if sections data would be destroyed.
		add_action( 'acf/save_post', array( $this, 'guard_before_save' ), 1, 1 );

		// Hook 7: Auto-recovery — if field values dropped, restore from pre-save snapshot.
		add_action( 'acf/save_post', array( $this, 'auto_restore_if_data_loss' ), 20, 1 );
	}

	/**
	 * Detect all registered ACF flexible content fields.
	 *
	 * Scans all ACF field groups for fields of type 'flexible_content'.
	 * Results are cached in $this->flex_fields for the request lifetime.
	 *
	 * @return array[] Array of {name, key, group_key} entries.
	 */
	public function detect_flex_fields(): array {
		if ( null !== $this->flex_fields ) {
			return $this->flex_fields;
		}

		$this->flex_fields = array();

		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $this->flex_fields;
		}

		$field_groups = acf_get_field_groups();

		foreach ( $field_groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( empty( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( isset( $field['type'] ) && 'flexible_content' === $field['type'] ) {
					$this->flex_fields[ $field['name'] ] = array(
						'name'      => $field['name'],
						'key'       => $field['key'],
						'group_key' => $group['key'],
					);
				}
			}
		}

		return $this->flex_fields;
	}

	/**
	 * Cache of valid sub-field names per flex field (from ACF registration).
	 *
	 * @var array<string, string[]>|null
	 */
	private $valid_sub_field_names = null;

	/**
	 * Get valid sub-field names for all flexible content fields.
	 *
	 * Walks ACF field group definitions to build a set of all valid
	 * sub-field names across all layouts. This ensures we only track
	 * meta keys that correspond to actual ACF-registered fields,
	 * preventing accidental capture of non-ACF meta keys.
	 *
	 * Example output: [ 'sections' => [ 'css_class', 'title', 'description',
	 *                                   'image', 'items', 'items_0_title', ... ] ]
	 *
	 * @return array<string, string[]> Field name => sub-field names.
	 */
	public function get_valid_sub_field_names(): array {
		if ( null !== $this->valid_sub_field_names ) {
			return $this->valid_sub_field_names;
		}

		$this->valid_sub_field_names = array();
		$fields = $this->detect_flex_fields();

		foreach ( $fields as $name => $info ) {
			$valid = array();
			$field = function_exists( 'acf_get_field' ) ? acf_get_field( $info['key'] ) : null;
			if ( empty( $field['layouts'] ) ) {
				continue;
			}

			foreach ( $field['layouts'] as $layout ) {
				if ( empty( $layout['sub_fields'] ) ) {
					continue;
				}
				$this->walk_sub_fields( $layout['sub_fields'], '', $valid );
			}

			$this->valid_sub_field_names[ $name ] = array_unique( $valid );
		}

		return $this->valid_sub_field_names;
	}

	/**
	 * Recursively walk ACF sub-fields and build valid meta key names.
	 *
	 * For repeaters/flex within layouts, builds dotted paths like
	 * 'items_0_title' to match meta keys like 'sections_1_items_0_title'.
	 *
	 * @param array[] $sub_fields Array of ACF sub-field definitions.
	 * @param string  $prefix    Current prefix for nested fields.
	 * @param string[] &$result  Reference to result array.
	 */
	private function walk_sub_fields( array $sub_fields, string $prefix, array &$result ): void {
		foreach ( $sub_fields as $sub ) {
			$sub_name = $prefix . $sub['name'];

			if ( 'repeater' === ( $sub['type'] ?? '' ) || 'flexible_content' === ( $sub['type'] ?? '' ) ) {
				// Repeater: items → items_0_{sub_name}, items_1_{sub_name}, ...
				// Track the parent (e.g. 'items') as valid too.
				$result[] = $sub_name;
				if ( ! empty( $sub['sub_fields'] ) ) {
					$this->walk_sub_fields( $sub['sub_fields'], $sub_name . '_N_', $result );
				}
				if ( ! empty( $sub['layouts'] ) ) {
					foreach ( $sub['layouts'] as $layout ) {
						if ( ! empty( $layout['sub_fields'] ) ) {
							$this->walk_sub_fields( $layout['sub_fields'], $sub_name . '_N_', $result );
						}
					}
				}
			} else {
				$result[] = $sub_name;
			}
		}
	}

	/**
	 * Check if a meta key belongs to any detected flexible content field,
	 * validated against the actual ACF field registration.
	 *
	 * Matches patterns like:
	 *   {field_name}                     — layout array (e.g. 'sections')
	 *   _{field_name}                    — ref key (e.g. '_sections')
	 *   {field_name}_N_{sub_field}       — field values (e.g. 'sections_0_title')
	 *   _{field_name}_N_{sub_field}      — ref keys (e.g. '_sections_0_title')
	 *   {field_name}_N_{repeater}_M_{sf} — nested (e.g. 'sections_1_items_0_title')
	 *
	 * The N/M indices are dynamic (any number). The sub-field name must
	 * match a known ACF field from the field group registration.
	 *
	 * @param string $meta_key Meta key to check.
	 * @return bool
	 */
	public function is_flex_meta_key( string $meta_key ): bool {
		$fields = $this->detect_flex_fields();
		if ( empty( $fields ) ) {
			// No flex fields found. For backward compat, check the 'sections' pattern.
			return (bool) preg_match( '/^(sections_|_sections)/', $meta_key );
		}

		foreach ( $fields as $name => $info ) {
			// Exact match: the layout array key itself.
			if ( $meta_key === $name || $meta_key === "_$name" ) {
				return true;
			}

			// Value or ref key: must start with {field_name}_ or _{field_name}_.
			$prefix   = $name . '_';
			$ref_prefix = '_' . $name . '_';
			$is_value = str_starts_with( $meta_key, $prefix );
			$is_ref   = str_starts_with( $meta_key, $ref_prefix );

			if ( ! $is_value && ! $is_ref ) {
				continue;
			}

			// Extract the field name part after {field_name}_N_ or _{field_name}_N_.
			// e.g. 'sections_0_title' → 'title'
			//      '_sections_0_title' → 'title'
			//      'sections_1_items_0_title' → 'items_0_title'
			$stripped = $is_value ? substr( $meta_key, strlen( $prefix ) ) : substr( $meta_key, strlen( $ref_prefix ) );

			// Remove the numeric index prefix (e.g. '0_' or '1_') to get the field name.
			$field_name = preg_replace( '/^\d+_/', '', $stripped, 1 );

			// Normalize nested numeric indices to match ACF registration patterns.
			// e.g. 'items_0_title' → 'items_N_title' for lookup against valid names.
			$normalized = preg_replace( '/_\d+_/', '_N_', $field_name );

			// Validate against known ACF sub-field names.
			$valid_names = $this->get_valid_sub_field_names();
			if ( ! empty( $valid_names[ $name ] ) ) {
				if ( in_array( $normalized, $valid_names[ $name ], true ) ) {
					return true;
				}
			} else {
				// No registration loaded — accept by prefix as fallback.
				return true;
			}
		}

		return false;
	}

	/**
	 * Get field keys for all detected flexible content fields.
	 *
	 * @return array<string, string> Field name => ACF field key.
	 */
	public function get_flex_field_keys(): array {
		$fields = $this->detect_flex_fields();
		$keys   = array();
		foreach ( $fields as $name => $info ) {
			$keys[ $name ] = $info['key'];
		}
		return $keys;
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
		// Only snapshot field groups that contain flexible content fields.
		$has_flex = false;
		if ( function_exists( 'acf_get_fields' ) ) {
			$fields = acf_get_fields( $field_group );
			foreach ( $fields as $field ) {
				if ( isset( $field['type'] ) && 'flexible_content' === $field['type'] ) {
					$has_flex = true;
					break;
				}
			}
		}
		if ( ! $has_flex ) {
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
		if ( ! $this->is_flex_meta_key( $meta_key ) ) {
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
	public function snapshot_on_acf_save( $post_id ): void {
		if ( is_string( $post_id ) ) {
			// Options pages use string post_id — handled by ACFR_Options_Bridge.
			return;
		}

		if ( ! $this->is_tracked_post_type( (int) $post_id ) ) {
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
			if ( $this->is_flex_meta_key( $key ) ) {
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
			if ( $this->is_flex_meta_key( $key ) ) {
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
		$keys = $this->get_flex_field_keys();
		if ( ! empty( $keys ) ) {
			return reset( $keys );
		}

		// Fallback: iterate all field groups for a 'sections' flex field.
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return null;
		}
		$groups = acf_get_field_groups();
		foreach ( $groups as $group ) {
			$group_fields = acf_get_fields( $group );
			foreach ( $group_fields as $field ) {
				if ( 'flexible_content' === ( $field['type'] ?? '' ) && 'sections' === ( $field['name'] ?? '' ) ) {
					return $field['key'];
				}
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

	/**
	 * Hook 6: Preventative guard against data loss.
	 *
	 * Runs at acf/save_post priority 1 (before ACF processes data).
	 * If the current post has rich ACF sections data but the incoming
	 * POST data has empty/drastically reduced field values, block the
	 * save with an admin error message.
	 *
	 * This only activates for classic-editor form submissions (POST).
	 * Gutenberg/REST saves are handled by auto_restore_if_data_loss.
	 *
	 * @param string|int $post_id Post ID or options identifier.
	 */
	public function guard_before_save( $post_id ): void {
		if ( is_string( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( ! $this->is_tracked_post_type( $post_id ) ) {
			return;
		}

		// Only guard classic-editor form submissions with nonce.
		if ( empty( $_POST['acf'] ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		// Verify the nonce (uses standard WP post save nonce).
		$nonce = sanitize_key( $_POST['_wpnonce'] );
		if ( ! wp_verify_nonce( $nonce, 'update-post_' . $post_id ) ) {
			return;
		}

		// Get current sections layout and value count.
		$current_sections = get_post_meta( $post_id, 'sections', true );
		if ( ! is_array( $current_sections ) || count( $current_sections ) < 2 ) {
			return;
		}

		$current_value_count = $this->count_section_field_values( $post_id );
		if ( $current_value_count < 10 ) {
			return; // Already minimal data, nothing to guard.
		}

		// Check all flexible content field keys submitted via POST.
		// ACF flexible content uses the field key as the POST key.
		$flex_field_keys = $this->get_flex_field_keys();
		if ( empty( $flex_field_keys ) ) {
			return;
		}

		$total_submitted_rows = 0;
		$any_field_tracked    = false;

		foreach ( $flex_field_keys as $flex_name => $field_key ) {
			// Get current layout array for this field.
			$current_layouts = get_post_meta( $post_id, $flex_name, true );
			if ( ! is_array( $current_layouts ) || count( $current_layouts ) < 2 ) {
				continue;
			}
			$any_field_tracked = true;

			// Unsanitized raw data — ACF POST keys are dynamically-generated keys.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$submitted = isset( $_POST['acf'][ $field_key ] ) ? (array) wp_unslash( $_POST['acf'][ $field_key ] ) : array();

			// Count non-empty submitted rows.
			$non_empty_rows = 0;
			foreach ( $submitted as $row ) {
				if ( is_array( $row ) ) {
					$filled = 0;
					foreach ( $row as $sub_key => $sub_val ) {
						if ( 'acf_fc_layout' !== $sub_key && ! empty( $sub_val ) ) {
							$filled++;
						}
					}
					if ( $filled > 0 ) {
						$non_empty_rows++;
					}
				}
			}

			// If user deliberately cleared the page, allow it.
			if ( 0 === $non_empty_rows ) {
				continue;
			}

			// If submitted rows < half of current, flag it.
			if ( $non_empty_rows < count( $current_layouts ) / 2 ) {
				$total_submitted_rows   = count( $submitted );
				$count_current_sections = count( $current_layouts );
				break;
			}
		}

		if ( 0 === $total_submitted_rows || ! $any_field_tracked ) {
			return;
		}

		wp_die(
			wp_kses_post( sprintf(
				'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
				esc_html__( 'ACF Revisions: Save Blocked', 'acf-revisions' ),
				// translators: %1$d is the current section count, %2$d is the submitted row count.
				sprintf(
				// translators: %1$d is the current section count, %2$d is the submitted row count.
				esc_html__( 'Section field values dropped from %1$d sections to %2$d submitted rows. This looks like ACF field group key mismatch — saving would destroy existing content.', 'acf-revisions' ),
					$count_current_sections,
					$total_submitted_rows
				),
				esc_html__( 'The page content has been preserved. Please restore a revision before saving.', 'acf-revisions' ),
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'revision.php?revision=' . end( wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1 ) )->ID ) ) ),
					esc_html__( 'View recent revisions', 'acf-revisions' )
				)
			) ),
			esc_html__( 'Save Blocked — ACF Data Loss Detected', 'acf-revisions' ),
			array( 'back_link' => true, 'response' => 409 )
		);
		exit;
	}

	/**
	 * Hook 7: Auto-recovery after save if sections data was lost.
	 *
	 * Runs at acf/save_post priority 20 (after ACF has written data).
	 * If the sections layout array still exists but field values
	 * have been cleared, restores them from the pre-save snapshot
	 * captured by Hook 5.
	 *
	 * This catches cases that guard_before_save misses (REST API,
	 * Gutenberg, AJAX saves).
	 *
	 * @param string|int $post_id Post ID or options identifier.
	 */
	public function auto_restore_if_data_loss( $post_id ): void {
		if ( is_string( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( ! $this->is_tracked_post_type( $post_id ) ) {
			return;
		}

		// Check current sections state.
		$current_sections = get_post_meta( $post_id, 'sections', true );
		if ( ! is_array( $current_sections ) || count( $current_sections ) < 2 ) {
			return;
		}

		$value_count = $this->count_section_field_values( $post_id );
		if ( $value_count > 5 ) {
			return; // Data looks healthy.
		}

		// Data loss detected! Attempt auto-restore from pre-save snapshot.
		$snapshot = get_post_meta( $post_id, '_acfr_before', true );
		if ( empty( $snapshot ) ) {
			// No snapshot available — log and give up.
			update_option( '_acfr_auto_restore_failed', array(
				'time'    => time(),
				'post_id' => $post_id,
				'reason'  => 'No pre-save snapshot found',
			), false );
			return;
		}

		$restored = 0;
		foreach ( $snapshot as $key => $value ) {
			if ( str_starts_with( $key, 'sections_' ) || '_sections' === $key ) {
				update_post_meta( $post_id, $key, $value );
				$restored++;
			}
		}

		// Clear the ACF cache so it re-reads the restored data.
		$this->clear_acf_cache();

		// Log the auto-restore for debugging.
		update_option( '_acfr_auto_restore_log', array(
			'time'           => time(),
			'post_id'        => $post_id,
			'value_count_before' => $value_count,
			'restored_count' => $restored,
		), false );
	}

	/**
	 * Compare current post sections meta against the latest revision.
	 *
	 * Detects manual changes made outside the WordPress admin (e.g., by
	 * AI agents, WP-CLI, raw SQL). Returns a structured diff of keys:
	 *
	 *   - added:   keys present in current but not in latest revision
	 *   - removed: keys present in latest revision but not in current
	 *   - changed: keys in both but values differ
	 *
	 * @param int $post_id Post ID.
	 * @return array{added: array, removed: array, changed: array, revision_id: int|null}
	 */
	public function diff_against_latest_revision( int $post_id ): array {
		$revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => 1,
			'orderby'        => 'post_date',
			'order'          => 'DESC',
		) );

		if ( empty( $revisions ) ) {
			return array(
				'added'       => array(),
				'removed'     => array(),
				'changed'     => array(),
				'revision_id' => null,
			);
		}

		$rev = reset( $revisions );
		$rev_id = $rev->ID;

		$current = $this->get_acf_section_meta( $post_id );
		$revision = $this->get_acf_section_meta( $rev_id );

		$added   = array();
		$removed = array();
		$changed = array();

		// Find keys added or changed in current vs revision.
		foreach ( $current as $key => $value ) {
			if ( ! array_key_exists( $key, $revision ) ) {
				$added[ $key ] = $value;
			} elseif ( $revision[ $key ] !== $value ) {
				$changed[ $key ] = array(
					'from' => $revision[ $key ],
					'to'   => $value,
				);
			}
		}

		// Find keys removed (in revision but not in current).
		foreach ( $revision as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$removed[ $key ] = $value;
			}
		}

		return array(
			'added'       => $added,
			'removed'     => $removed,
			'changed'     => $changed,
			'revision_id' => $rev_id,
		);
	}

	/**
	 * Count field values (non-ref, non-layout-array) for sections.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of section field value keys.
	 */
	private function count_section_field_values( int $post_id ): int {
		$meta = get_post_meta( $post_id );
		$count = 0;
		foreach ( $meta as $key => $values ) {
			if ( preg_match( '/^sections_\d+_/', $key ) && ! str_starts_with( $key, '_' ) ) {
				$count++;
			}
		}
		return $count;
	}
}
