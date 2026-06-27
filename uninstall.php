<?php
/**
 * Uninstall handler for ACF Revisions.
 *
 * Cleans up plugin data when the plugin is uninstalled.
 *
 * @package ACF_Revisions
 */

// Exit if not called from WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete field group snapshots.
delete_option( '_acfr_field_group_backups' );

// Delete integrity check transients.
delete_transient( 'acfr_result' );

// Clean up per-post snapshots.
global $wpdb;

$wpdb->delete(
	$wpdb->postmeta,
	array( 'meta_key' => '_acfr_before' ),
	array( '%s' )
);
