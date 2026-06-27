<?php
/**
 * Plugin Name:       ACF Revisions
 * Plugin URI:        https://github.com/codetot-web/acf-revisions
 * Description:       Bridge hooks that save ACF Flexible Content field values and reference keys into WordPress post revisions, enabling recovery after accidental data loss.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            CODE TOT JSC
 * Author URI:        https://codetot.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acf-revisions
 * Domain Path:       /languages
 *
 * @package ACF_Revisions
 * @author  CODE TOT JSC
 *
 * Co-Author: Khôi Nguyễn (khoipro)
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'ACFR_VERSION', '1.0.0' );
define( 'ACFR_FILE', __FILE__ );
define( 'ACFR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACFR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize the plugin.
 */
function acfr_init(): void {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	require_once ACFR_DIR . 'includes/helpers.php';
	require_once ACFR_DIR . 'includes/class-acf-revisions.php';
	require_once ACFR_DIR . 'includes/class-acf-revisions-bridge.php';

	// Initialize the singleton.
	ACFR_Plugin::get_instance();

	/**
	 * Fires after ACF Revisions plugin is loaded.
	 */
	do_action( 'acfr_loaded' );
}

// Hook into WordPress.
add_action( 'plugins_loaded', 'acfr_init' );

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, function () {
	if ( ! function_exists( 'acf' ) || version_compare( acf()->settings['version'] ?? '0', '6.0', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'ACF Revisions requires ACF Pro 6.0 or later.', 'acf-revisions' ),
			'Plugin Activation Error',
			array( 'back_link' => true )
		);
	}

	// Ensure the post types using flexible content support revisions.
	$flexible_post_types = apply_filters( 'acfr_post_types', array( 'page', 'post' ) );
	foreach ( $flexible_post_types as $pt ) {
		$post_type_obj = get_post_type_object( $pt );
		if ( $post_type_obj && ! post_type_supports( $pt, 'revisions' ) ) {
			// Log a notice but don't block activation.
			trigger_error(
				esc_html(
					sprintf(
						// translators: %s is the post type name.
						__( 'ACF Revisions: Post type "%s" does not support revisions. Meta will not be revisioned.', 'acf-revisions' ),
						$pt
					)
				),
				E_USER_NOTICE
			);
		}
	}

	flush_rewrite_rules();
} );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
