<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores per-user UI prefs:
 * - Invoice Edit screen: section order + spans
 * - Tables: columns order + hidden map (per screen)
 */
class Wicked_UI_Controller extends Wicked_Base_Controller {
	const REST_NS = 'wicked-invoicing/v1';

	// Invoice Edit meta keys
	const META_ORDER = '_wi_layout_invoice_order';
	const META_SPANS = '_wi_layout_invoice_spans';

	// Per-screen meta keys for table columns
	const META_TABLE_ORDER_PREFIX  = '_wi_table_order_';
	const META_TABLE_HIDDEN_PREFIX = '_wi_table_hidden_';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes() {
		$ns = self::REST_NS;

		// Optional "ping" endpoint (safe alternative to "/")
		register_rest_route(
			$ns,
			'/ui',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'can_use' ),
				'callback'            => fn() => array(
					'ok'        => true,
					'endpoints' => array(
						'GET  /ui/layout',
						'POST /ui/layout',
						'GET  /ui/table-columns?screen=invoices_list',
						'POST /ui/table-columns',
					),
				),
			)
		);

		// ─────────────────────────────────────────────────────────────
		// Layout (invoice edit)
		// ─────────────────────────────────────────────────────────────
		register_rest_route(
			$ns,
			'/ui/layout',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_layout' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'screen' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ui/layout',
			array(
				'methods'             => WP_REST_Server::EDITABLE, // POST/PUT/PATCH
				'callback'            => array( $this, 'save_layout' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'order' => array(
						'type'     => 'array',
						'required' => true,
					),
					'spans' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		// ─────────────────────────────────────────────────────────────
		// Table columns (per screen)
		// ─────────────────────────────────────────────────────────────
		register_rest_route(
			$ns,
			'/ui/table-columns',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_table_columns' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'screen' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ui/table-columns',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'save_table_columns' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'screen' => array(
						'type'     => 'string',
						'required' => false,
					),
					'order'  => array(
						'type'     => 'array',
						'required' => true,
					),
					'hidden' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
	}

	public function can_use( WP_REST_Request $request ) {
		// anyone who can edit invoices (or manage plugin) can customize screens
		if ( self::user_has_cap( 'manage_wicked_invoicing' ) || self::user_has_cap( 'edit_wicked_invoices' ) ) {
			return true;
		}

		return new WP_Error(
			'wi_forbidden',
			__( 'You do not have permission to customize Wicked Invoicing UI.', 'wicked-invoicing' ),
			array( 'status' => 403 )
		);
	}

	// ───────────── Layout (invoice edit) ─────────────

	public function get_layout( WP_REST_Request $request ) {
		$uid   = get_current_user_id();
		$order = get_user_meta( $uid, self::META_ORDER, true );
		$spans = get_user_meta( $uid, self::META_SPANS, true );

		return rest_ensure_response(
			array(
				'order' => is_array( $order ) ? array_values( $order ) : array(),
				'spans' => is_array( $spans ) ? (array) $spans : (object) array(),
			)
		);
	}

	public function save_layout( WP_REST_Request $request ) {
		$uid = get_current_user_id();

		$allowed = array( 'identity', 'dates', 'purchase', 'client', 'addresses', 'items', 'totals', 'notes' );

		$incomingOrder = (array) $request->get_param( 'order' );

		// keep only allowed unique keys, preserve order
		$seen  = array();
		$order = array();
		foreach ( $incomingOrder as $k ) {
			$k = (string) $k;
			if ( in_array( $k, $allowed, true ) && ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = true;
				$order[]    = $k;
			}
		}

		// ensure all sections exist at least once (append missing)
		foreach ( $allowed as $k ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$order[] = $k;
			}
		}

		$incomingSpans = (array) $request->get_param( 'spans' );
		$spans         = array();
		foreach ( $incomingSpans as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$spans[ $k ] = ( (int) $v === 2 ) ? 2 : 1;
			}
		}

		update_user_meta( $uid, self::META_ORDER, $order );
		update_user_meta( $uid, self::META_SPANS, $spans );

		return rest_ensure_response(
			array(
				'order' => $order,
				'spans' => (object) $spans,
			)
		);
	}

	// ───────────── Table columns (per screen) ─────────────

	public function get_table_columns( WP_REST_Request $request ) {
		$uid    = get_current_user_id();
		$screen = sanitize_key( $request->get_param( 'screen' ) ?: 'invoices_list' );

		$order  = get_user_meta( $uid, self::META_TABLE_ORDER_PREFIX . $screen, true );
		$hidden = get_user_meta( $uid, self::META_TABLE_HIDDEN_PREFIX . $screen, true );

		return rest_ensure_response(
			array(
				'screen' => $screen,
				'order'  => is_array( $order ) ? array_values( $order ) : array(),
				'hidden' => is_array( $hidden ) ? array_values( $hidden ) : array(),
			)
		);
	}

	public function save_table_columns( WP_REST_Request $request ) {
		$uid    = get_current_user_id();
		$screen = sanitize_key( $request->get_param( 'screen' ) ?: 'invoices_list' );

		$incomingOrder  = (array) $request->get_param( 'order' );
		$incomingHidden = (array) $request->get_param( 'hidden' );

		$order = array();
		$seen  = array();
		foreach ( $incomingOrder as $k ) {
			$k = sanitize_key( (string) $k );
			if ( $k === '' || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$order[]    = $k;
		}

		$hidden = array();
		$seenH  = array();
		foreach ( $incomingHidden as $k ) {
			$k = sanitize_key( (string) $k );
			if ( $k === '' || isset( $seenH[ $k ] ) ) {
				continue;
			}
			$seenH[ $k ] = true;
			$hidden[]    = $k;
		}

		update_user_meta( $uid, self::META_TABLE_ORDER_PREFIX . $screen, $order );
		update_user_meta( $uid, self::META_TABLE_HIDDEN_PREFIX . $screen, $hidden );

		return rest_ensure_response(
			array(
				'screen' => $screen,
				'order'  => $order,
				'hidden' => $hidden,
			)
		);
	}
}
