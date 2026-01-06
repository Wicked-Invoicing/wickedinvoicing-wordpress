<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


if ( ! function_exists( 'wicked_invoicing_payments' ) ) {
	function wicked_invoicing_payments() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new \Wicked_Invoicing\Controllers\Wicked_Payments_Controller();

			if ( method_exists( $instance, 'boot' ) ) {
				$instance->boot();
			}
		}

		return $instance;
	}
}

if ( ! function_exists( 'wicked_inv_sanitize_number' ) ) {
	/**
	 * Sanitize numeric invoice meta.
	 * Accepts the 4 arguments WP will pass to a meta sanitizer.
	 *
	 * @param mixed       $value       Raw meta value.
	 * @param string      $meta_key    Meta key (unused).
	 * @param string      $object_type Object type (unused).
	 * @param string|null $meta_type   Meta type (unused).
	 * @return float
	 */
	function wicked_inv_sanitize_number( $value, $meta_key, $object_type, $meta_type ) {
		return floatval( $value );
	}
}


add_action(
	'parse_query',
	function ( $q ) {
		if ( isset( $q->query_vars['wicked_invoicing_invoice_hash'] ) && $q->query_vars['wicked_invoicing_invoice_hash'] ) {
			$q->is_404  = false;
			$q->is_home = false;
		}
	}
);

add_filter(
	'pre_get_document_title',
	function ( $title ) {
		if ( get_query_var( 'wicked_invoicing_invoice_hash' ) ) {
			// You can use invoice title or a generic label.
			return __( 'Invoice', 'wicked-invoicing' );
		}
		return $title;
	}
);

add_filter(
	'redirect_canonical',
	function ( $redirect_url, $requested_url ) {
		// If path contains /wp-json/ just don't canonicalize.
		if ( strpos( $requested_url, '/wp-json/' ) !== false ) {
			return false;
		}
		// Also handle REST requests that might not have /wp-json/ style.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return $redirect_url;
	},
	10,
	2
);

function wicked_invoicing_payments() {
	static $controller = null;
	if ( null === $controller ) {
		$controller = new \Wicked_Invoicing\Controllers\Wicked_Payments_Controller();
	}
	return $controller;
}
