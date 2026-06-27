<?php
/**
 * Helper functions for ACF Revisions.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Get the bridge instance.
 *
 * @return ACFR_Bridge|null
 */
function acf_revisions_get_bridge(): ?ACFR_Bridge {
	$plugin = ACFR_Plugin::get_instance();
	return $plugin->bridge;
}
