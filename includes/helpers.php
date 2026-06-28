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
function acfr_get_bridge(): ?ACFR_Bridge {
	$plugin = ACFR_Plugin::get_instance();
	return $plugin->bridge;
}

/**
 * Get the options bridge instance.
 *
 * @return ACFR_Options_Bridge|null
 */
function acfr_get_options(): ?ACFR_Options_Bridge {
	$plugin = ACFR_Plugin::get_instance();
	return $plugin->options;
}
