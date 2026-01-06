<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for listing payments.
 *
 * Endpoint:
 *   GET /wp-json/wicked-invoicing/v1/payments
 *     ?page=1
 *     &per_page=50
 *     &orderby=created_at
 *     &order=DESC
 *     &search=...
 *     &status=...
 *     &invoice_id=123
 */
class Wicked_Payments_REST_Controller extends Wicked_Base_Controller {

	const REST_NAMESPACE = 'wicked-invoicing/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Wire up /payments route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/payments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_list_payments' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	/**
	 * Capability gate for Payments tab.
	 */
	public function can_list_payments() {
		return current_user_can( 'manage_wicked_invoicing' )
			|| current_user_can( 'view_all_invoices' )
			|| current_user_can( 'edit_wicked_invoices' );
	}

	/**
	 * Define collection params for /payments list.
	 */
	public function get_collection_params() {
		return array(
			'page'       => array(
				'description'       => 'Page number.',
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'minimum'           => 1,
			),
			'per_page'   => array(
				'description'       => 'Items per page.',
				'type'              => 'integer',
				'default'           => 50,
				'sanitize_callback' => 'absint',
				'minimum'           => 1,
				'maximum'           => 100,
			),
			'orderby'    => array(
				'description'       => 'Field to order by.',
				'type'              => 'string',
				'default'           => 'created_at',
				// Keep in sync with Wicked_Payments_Controller::list_payments()
				'enum'              => array( 'created_at', 'amount', 'invoice_id', 'status', 'processor' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'order'      => array(
				'description'       => 'Sort direction.',
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => array( 'ASC', 'DESC' ),
				'sanitize_callback' => function ( $value ) {
					$value = strtoupper( (string) $value );
					return in_array( $value, array( 'ASC', 'DESC' ), true ) ? $value : 'DESC';
				},
			),
			'status'     => array(
				'description'       => 'Filter by payment status.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'invoice_id' => array(
				'description'       => 'Filter by invoice ID.',
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'minimum'           => 0,
			),
			'search'     => array(
				'description'       => 'Search string.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Handle GET /payments
	 */
	public function get_items( WP_REST_Request $request ) {
		if ( ! function_exists( 'wicked_invoicing_payments' ) ) {
			return new WP_Error(
				'wicked_invoicing_payments_missing',
				__( 'Payments controller is not available.', 'wicked-invoicing' ),
				array( 'status' => 500 )
			);
		}

		$payments_controller = wicked_invoicing_payments();

		if ( ! $payments_controller || ! method_exists( $payments_controller, 'list_payments' ) ) {
			return new WP_Error(
				'wicked_invoicing_payments_method_missing',
				__( 'Payments controller does not implement list_payments().', 'wicked-invoicing' ),
				array( 'status' => 500 )
			);
		}

		// IMPORTANT:
		// Use get_params() so REST arg sanitizers/validators apply.
		// And guarantee an array for list_payments() (prevents the null fatal).
		$args = $request->get_params();
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		// Force safe defaults (in case caller sends weird values)
		$args['page']     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$args['per_page'] = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		$allowed_orderby = array( 'created_at', 'amount', 'invoice_id', 'status', 'processor' );
		$orderby         = sanitize_key( (string) ( $args['orderby'] ?? 'created_at' ) );
		$args['orderby'] = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'created_at';

		$order         = strtoupper( (string) ( $args['order'] ?? 'DESC' ) );
		$args['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$args['status']     = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$args['search']     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$args['invoice_id'] = absint( $args['invoice_id'] ?? 0 );

		$result = $payments_controller->list_payments( $args );

		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) )
			? $result['items']
			: array();

		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		$response = array(
			'data' => $items,
			'meta' => array(
				'total'       => $total,
				'page'        => (int) $args['page'],
				'per_page'    => (int) $args['per_page'],
				'total_pages' => (int) max( 1, ceil( $total / max( 1, (int) $args['per_page'] ) ) ),
			),
		);

		return new WP_REST_Response( $response, 200 );
	}
}
