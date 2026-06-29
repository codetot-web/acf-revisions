<?php
/**
 * Options page snapshot and restore for ACF Revisions.
 *
 * ACF Options Pages store data in wp_options (not wp_postmeta),
 * so WordPress post revisions do not apply. This class provides
 * snapshot-based backup/restore for ACF options page fields.
 *
 * Only snapshots option names that correspond to registered ACF
 * fields (identified by the _optionname ref key pattern). This
 * prevents accidentally capturing non-ACF WordPress options.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles snapshot and restore of ACF options page data.
 */
class ACFR_Options_Bridge {

	/**
	 * Option prefixes to track for ACF options pages.
	 *
	 * Default tracks the standard 'options' page prefix.
	 * Custom options pages (e.g. 'theme_settings', 'my_options')
	 * should be added via the acfr_options_prefixes filter.
	 *
	 * @var string[]
	 */
	private $prefixes = array( 'options_' );

	/**
	 * Maximum snapshots to keep.
	 *
	 * @var int
	 */
	private $max_snapshots = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Filter the option prefixes to track for ACF options pages.
		 *
		 * Only option names starting with these prefixes will be
		 * snapshotted on acf/save_post for options pages.
		 *
		 * @param string[] $prefixes Array of option name prefixes.
		 */
		$this->prefixes = apply_filters( 'acfr_options_prefixes', $this->prefixes );

		$this->register_hooks();
	}

	/**
	 * Register hooks for options page tracking.
	 */
	private function register_hooks(): void {
		// Snapshot before ACF writes to options (priority 5 = before ACF's save).
		add_action( 'acf/save_post', array( $this, 'snapshot_before_save' ), 5, 1 );
	}

	/**
	 * Snapshot ACF options before save.
	 *
	 * Fires on acf/save_post. When an options page is being saved
	 * ($post_id is a string), captures the current state of all
	 * ACF option names matching the tracked prefixes.
	 *
	 * Only option names with a valid ACF field reference key
	 * (_optionname) are included, ensuring non-ACF options
	 * are not captured.
	 *
	 * @param string|int $post_id Post ID or options page identifier.
	 */
	public function snapshot_before_save( $post_id ): void {
		// Only handle options pages (post_id is a string, not numeric).
		if ( is_numeric( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$snapshot = $this->get_tracked_option_values();
		if ( empty( $snapshot ) ) {
			return;
		}

		$backups = get_option( '_acfr_options_backups', array() );

		$backups[] = array(
			'time'     => time(),
			'date'     => current_time( 'mysql' ),
			'user_id'  => get_current_user_id(),
			'post_id'  => $post_id,
			'snapshot' => $snapshot,
		);

		// Keep only the N most recent.
		if ( count( $backups ) > $this->max_snapshots ) {
			$backups = array_slice( $backups, -$this->max_snapshots );
		}

		update_option( '_acfr_options_backups', $backups, false );
	}

	/**
	 * Get current values of tracked ACF options.
	 *
	 * Queries wp_options for names matching the tracked prefixes,
	 * then filters to only include option names that have a valid
	 * ACF field reference key (_optionname).
	 *
	 * @return array<string, string> Option name => value pairs.
	 */
	public function get_tracked_option_values(): array {
		global $wpdb;

		$values = array();

		foreach ( $this->prefixes as $prefix ) {
			// Get value rows (option_name starts with prefix) AND ref rows (starts with _prefix).
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value
				 FROM $wpdb->options
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s
				 ORDER BY option_name",
				$wpdb->esc_like( $prefix ) . '%',
				$wpdb->esc_like( '_' . $prefix ) . '%'
			) );

			if ( empty( $rows ) ) {
				continue;
			}

			// Collect candidate option names and their ref keys.
			$candidates = array();
			$ref_keys   = array();

			foreach ( $rows as $row ) {
				if ( str_starts_with( $row->option_name, '_' . $prefix ) ) {
					// This is a ref key (_options_fieldname).
					$field_name = substr( $row->option_name, strlen( '_' . $prefix ) );
					$ref_keys[ $field_name ] = $row->option_value;
				} elseif ( str_starts_with( $row->option_name, $prefix ) ) {
					// This is a value key (options_fieldname).
					$field_name = substr( $row->option_name, strlen( $prefix ) );
					$candidates[ $field_name ] = $row->option_name;
				}
			}

			// Only include options that have a valid ACF field ref key.
			// ACF field keys match pattern: field_[a-f0-9]{13} or layout_*
			foreach ( $candidates as $field_name => $option_name ) {
				if ( isset( $ref_keys[ $field_name ] ) ) {
					$ref_value = $ref_keys[ $field_name ];
					// Validate it looks like an ACF field key.
					if ( preg_match( '/^(field_|layout_|group_)/', $ref_value ) ) {
						$values[ $option_name ] = $wpdb->get_var( $wpdb->prepare(
							"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
							$option_name
						) );
					}
				}
			}
		}

		return $values;
	}

	/**
	 * List recent options snapshots with summary.
	 *
	 * @param int $limit Max snapshots to return.
	 * @return array{backups: array, option_keys: string[], tracked_prefixes: string[]}
	 */
	public function list_snapshots( int $limit = 10 ): array {
		$backups = get_option( '_acfr_options_backups', array() );

		// Get all unique option keys across all snapshots.
		$all_keys = array();
		foreach ( $backups as $b ) {
			if ( isset( $b['snapshot'] ) && is_array( $b['snapshot'] ) ) {
				$all_keys = array_merge( $all_keys, array_keys( $b['snapshot'] ) );
			}
		}
		$all_keys = array_unique( $all_keys );
		sort( $all_keys );

		return array(
			'backups'          => array_slice( $backups, -$limit ),
			'option_keys'      => $all_keys,
			'tracked_prefixes' => $this->prefixes,
		);
	}

	/**
	 * Restore options from a snapshot by index.
	 *
	 * @param int $index Snapshot index (0 = oldest, -1 = newest).
	 * @return int Number of options restored.
	 * @throws InvalidArgumentException If snapshot not found.
	 */
	public function restore_snapshot( int $index ): int {
		$backups = get_option( '_acfr_options_backups', array() );

		if ( empty( $backups ) ) {
			throw new InvalidArgumentException( 'No options snapshots available.' );
		}

		if ( $index < 0 ) {
			$index = count( $backups ) + $index;
		}

		if ( ! isset( $backups[ $index ] ) ) {
			throw new InvalidArgumentException( esc_html( "Snapshot index $index not found. Available: 0-" . ( count( $backups ) - 1 ) ) );
		}

		$snapshot = $backups[ $index ]['snapshot'];
		$restored = 0;

		foreach ( $snapshot as $option_name => $option_value ) {
			// Preserve the value as-is (already a string from the DB).
			update_option( $option_name, maybe_unserialize( $option_value ), false );
			$restored++;
		}

		return $restored;
	}

	/**
	 * Get current values for display.
	 *
	 * @return array<string, string> Option name => truncated value.
	 */
	public function get_current_values(): array {
		$values = $this->get_tracked_option_values();

		$display = array();
		foreach ( $values as $key => $value ) {
			$display[ $key ] = $value;
		}
		return $display;
	}

	/**
	 * Format a value for CLI display.
	 *
	 * @param string $value Raw option value.
	 * @return string Truncated display value.
	 */
	public static function format_value( string $value ): string {
		$unser = maybe_unserialize( $value );
		if ( is_array( $unser ) ) {
			return sprintf( '(%d items)', count( $unser ) );
		}
		if ( strlen( $value ) > 60 ) {
			return substr( $value, 0, 60 ) . '...';
		}
		return $value;
	}
}
