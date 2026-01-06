<?php
/**
 * Uninstall Wicked Invoicing
 *
 * Deletes all plugin data when the user deletes the plugin
 * from the Plugins screen in WordPress.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Per-site cleanup.
 *
 * This is run for each site in a multisite network (if applicable).
 */
function wicked_invoicing_uninstall_site() {
	global $wpdb;

	// ---------------------------------------------------------------------
	// 1. Delete custom invoice posts (CPT: wicked_invoice)
	// ---------------------------------------------------------------------
	$post_type = 'wicked_invoice';

	$invoice_ids = get_posts(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'any',
			'numberposts'            => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( ! empty( $invoice_ids ) && is_array( $invoice_ids ) ) {
		foreach ( $invoice_ids as $invoice_id ) {
			wp_delete_post( $invoice_id, true );
		}
	}

	// ---------------------------------------------------------------------
	// 2. Delete the automatically-created "Wicked Invoice Template" page
	// ---------------------------------------------------------------------
	$settings = get_option( 'wicked_invoicing_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	if ( ! empty( $settings['invoice_template_id'] ) ) {
		$template_id = absint( $settings['invoice_template_id'] );
		if ( $template_id > 0 ) {
			wp_delete_post( $template_id, true );
		}
	}

	// ---------------------------------------------------------------------
	// 3. Delete plugin options / settings
	// ---------------------------------------------------------------------
	$option_names = array(
		'wicked_invoicing_settings',
		'wicked_invoicing_status_labels',
		'wicked_invoicing_invoice_slug',
		'wicked_invoicing_active_bundles',
		'wicked_invoicing_notifications',
		'wicked_invoicing_uninstall_behavior',
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
		delete_site_option( $option_name );
	}

	// ---------------------------------------------------------------------
	// 4. Clear scheduled cron jobs + transients for notifications
	// ---------------------------------------------------------------------
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'wicked_invoicing_notifications_cron' );
	}

	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( 'wicked_invoicing_notif_cron_lock' );
	}

	// ---------------------------------------------------------------------
	// 5. Drop custom database tables
	// ---------------------------------------------------------------------
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wicked_invoice_logs" );

	// ---------------------------------------------------------------------
	// 6. Remove custom roles + caps added by Wicked_Roles_Controller
	// ---------------------------------------------------------------------
	if ( function_exists( 'remove_role' ) ) {
		$roles_to_remove = array(
			'wicked_admin',
			'wicked_employee',
			'wicked_client',
		);

		foreach ( $roles_to_remove as $role ) {
			remove_role( $role );
		}
	}

	if ( function_exists( 'get_role' ) ) {
		$admin = get_role( 'administrator' );

		if ( $admin ) {
			$caps = array(
				'manage_wicked_invoicing',
				'edit_wicked_settings',
				'edit_wicked_invoices',
				'view_all_invoices',
				'view_own_invoices',
			);

			foreach ( $caps as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}

// -------------------------------------------------------------------------
// Multisite-aware uninstall: run cleanup on each site, if needed
// -------------------------------------------------------------------------
if ( is_multisite() ) {
	$wicked_invoicing_site_ids = get_sites( array( 'fields' => 'ids' ) );

	if ( ! empty( $wicked_invoicing_site_ids ) ) {
		foreach ( $wicked_invoicing_site_ids as $wicked_invoicing_site_id ) {
			switch_to_blog( (int) $wicked_invoicing_site_id );
			wicked_invoicing_uninstall_site();
			restore_current_blog();
		}
	}
} else {
	wicked_invoicing_uninstall_site();
}
