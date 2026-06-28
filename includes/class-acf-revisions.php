<?php
/**
 * Main plugin class for ACF Revisions.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class responsible for initializing all components.
 */
class ACFR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Bridge instance.
	 *
	 * @var ACFR_Bridge|null
	 */
	public $bridge = null;

	/**
	 * Options bridge instance.
	 *
	 * @var ACFR_Options_Bridge|null
	 */
	public $options = null;

	/**
	 * Admin instance.
	 *
	 * @var ACFR_Admin|null
	 */
	public $admin = null;

	/**
	 * CLI instance.
	 *
	 * @var ACFR_CLI|null
	 */
	public $cli = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_components();
	}

	/**
	 * Load dependent files.
	 */
	private function load_dependencies(): void {
		$files = array(
			'class-acf-revisions-bridge.php',
			'class-acf-revisions-options.php',
			'class-acf-revisions-admin.php',
			'class-acf-revisions-cli.php',
		);

		foreach ( $files as $file ) {
			$path = ACFR_DIR . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Initialize all components.
	 */
	private function init_components(): void {
		// Initialize bridge hooks.
		if ( class_exists( 'ACFR_Bridge' ) ) {
			$this->bridge = new ACFR_Bridge();
		}

		// Initialize options page bridge.
		if ( class_exists( 'ACFR_Options_Bridge' ) ) {
			$this->options = new ACFR_Options_Bridge();
		}

		// Initialize admin UI (admin only).
		if ( is_admin() && class_exists( 'ACFR_Admin' ) ) {
			$this->admin = new ACFR_Admin();
		}

		// Register WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'ACFR_CLI' ) ) {
			$this->cli = new ACFR_CLI();
		}

		// Load text domain for i18n.
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'acf-revisions',
			false,
			dirname( plugin_basename( ACFR_FILE ) ) . '/languages'
		);
	}

	/**
	 * Get post types registered for ACF flexible content revisioning.
	 *
	 * @return string[]
	 */
	public function get_post_types(): array {
		if ( $this->bridge ) {
			return $this->bridge->post_types;
		}
		return array( 'page', 'post' );
	}
}
