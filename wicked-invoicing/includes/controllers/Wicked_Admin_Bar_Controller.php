<?php
/**
 * Admin Bar shortcuts.
 *
 * @package wicked-invoicing
 */

namespace Wicked_Invoicing\Controllers;

use WP_Admin_Bar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Admin Bar shortcuts:
 * - Invoices dropdown (All Invoices, Create Invoice)
 * - "Edit this Invoice" on the public invoice page (slug/hash), if user can edit.
 */
class Wicked_Admin_Bar_Controller extends Wicked_Base_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_items' ), 80 );
	}

	/**
	 * Add admin bar items.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 * @return void
	 */
	public function add_admin_bar_items( WP_Admin_Bar $bar ) {
		if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
			return;
		}

		// Only users who can edit invoices get this menu.
		$can_edit_any = self::user_has_cap( 'edit_wicked_invoices' ) || current_user_can( 'edit_wicked_invoices' );
		if ( ! $can_edit_any ) {
			return;
		}

		// Top-level "Invoices" menu.
		$bar->add_node(
			array(
				'id'    => 'wkd-invoices',
				'title' => __( 'Invoices', 'wicked-invoicing' ),
				'href'  => admin_url( 'admin.php?page=wicked-invoicing-invoices' ),
				'meta'  => array( 'class' => 'wkd-invoices-root' ),
			)
		);

		// Child: All Invoices.
		$bar->add_node(
			array(
				'id'     => 'wkd-invoices-all',
				'parent' => 'wkd-invoices',
				'title'  => __( 'All Invoices', 'wicked-invoicing' ),
				'href'   => admin_url( 'admin.php?page=wicked-invoicing-invoices' ),
			)
		);

		// Child: Create New.
		$bar->add_node(
			array(
				'id'     => 'wkd-invoices-new',
				'parent' => 'wkd-invoices',
				'title'  => __( 'Create Invoice', 'wicked-invoicing' ),
				'href'   => admin_url( 'admin.php?page=wicked-invoicing-invoice-new' ),
				'meta'   => array( 'class' => 'ab-item--primary' ),
			)
		);

		// Also add under the core "+ New" menu for muscle memory (only if it exists).
		if ( $bar->get_node( 'new-content' ) ) {
			$bar->add_node(
				array(
					'id'     => 'wkd-new-invoice',
					'parent' => 'new-content',
					'title'  => __( 'Invoice', 'wicked-invoicing' ),
					'href'   => admin_url( 'admin.php?page=wicked-invoicing-invoice-new' ),
				)
			);
		}

		// On the public invoice view? Offer "Edit this Invoice".
		$invoice_id = $this->detect_invoice_id_from_request();
		if ( $invoice_id ) {
			$edit_url = $this->get_invoice_edit_url( $invoice_id );

			$bar->add_node(
				array(
					'id'     => 'wkd-edit-invoice',
					'parent' => 'wkd-invoices',
					'title'  => __( 'Edit This Invoice', 'wicked-invoicing' ),
					'href'   => $edit_url,
					'meta'   => array( 'class' => 'ab-item--highlight' ),
				)
			);
		}
	}

	/**
	 * Returns the correct admin edit URL for your SPA editor.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return string
	 */
	protected function get_invoice_edit_url( int $invoice_id ): string {
		return admin_url( 'admin.php?page=wicked-invoicing-invoices#/invoice-edit?invoice_id=' . $invoice_id );
	}

	/**
	 * Detect invoice ID on the front-end invoice view.
	 *
	 * @return int Invoice ID or 0.
	 */
	protected function detect_invoice_id_from_request(): int {
		// Only for front-end.
		if ( is_admin() ) {
			return 0;
		}

		$hash = (string) get_query_var( 'wi_invoice_hash', '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a public query arg; no state change.
		if ( '' === $hash && isset( $_GET['wi_invoice_hash'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a view param, not processing a form action.
			$hash = sanitize_text_field( wp_unslash( $_GET['wi_invoice_hash'] ) );
		}

		$hash = trim( (string) $hash );
		if ( '' === $hash ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'        => Wicked_Invoice_Controller::get_cpt_slug(),
				'post_status'      => array( 'temp', 'pending', 'deposit-required', 'deposit-paid', 'paid' ),
				'numberposts'      => 1,
				'meta_key'         => '_wi_hash',
				'meta_value'       => $hash,
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		$invoice_id = (int) $posts[0]->ID;

		// Ensure current user can edit THIS invoice.
		if ( ! current_user_can( 'edit_post', $invoice_id ) ) {
			return 0;
		}

		// Optional: also require your plugin's edit cap.
		if ( ! ( self::user_has_cap( 'edit_wicked_invoices' ) || current_user_can( 'edit_wicked_invoices' ) ) ) {
			return 0;
		}

		return $invoice_id;
	}
}
