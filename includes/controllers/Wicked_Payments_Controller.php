<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Payments_Controller extends Wicked_Base_Controller {

	const META_KEY = '_wicked_invoicing_payments';

	public function __construct() {
		$this->boot();
	}

	public function boot() {
		// Expose invoice-payments endpoints via REST
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes() {
		register_rest_route(
			'wicked-invoicing/v1',
			'/invoices/(?P<id>\d+)/payments',
			array(
				// GET: list payments for an invoice
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_invoice_payments' ),
					'permission_callback' => array( $this, 'rest_can_view_invoice_payments' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				// POST: add a manual payment
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_create_invoice_payment' ),
					'permission_callback' => array( $this, 'rest_can_edit_invoice_payments' ),
					'args'                => array(
						'id'        => array(
							'type'     => 'integer',
							'required' => true,
						),
						'amount'    => array(
							'required' => true,
							'type'     => 'number',
						),
						'currency'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'processor' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
	}

	/*
	--------------------------------------------------------------------
	 * CORE API
	 * ------------------------------------------------------------------ */

	/**
	 * Record a payment against an invoice (stored in invoice meta _wicked_invoicing_payments).
	 *
	 * @param array $data
	 * @return array
	 */
	public function record_payment( array $data ): array {
		$invoice_id = isset( $data['invoice_id'] ) ? (int) $data['invoice_id'] : 0;
		if ( $invoice_id <= 0 ) {
			return array(
				'ok'      => false,
				'error'   => 'invalid_invoice',
				'message' => 'Missing or invalid invoice_id.',
			);
		}

		$amount   = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
		$currency = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : 'USD';
		$status   = isset( $data['status'] ) ? (string) $data['status'] : 'succeeded';

		$processor             = isset( $data['processor'] ) ? (string) $data['processor'] : '';
		$processor_payment_id  = isset( $data['processor_payment_id'] ) ? (string) $data['processor_payment_id'] : '';
		$processor_charge_id   = isset( $data['processor_charge_id'] ) ? (string) $data['processor_charge_id'] : '';
		$processor_customer_id = isset( $data['processor_customer_id'] ) ? (string) $data['processor_customer_id'] : '';
		$note                  = isset( $data['note'] ) ? (string) $data['note'] : '';

		$created_at = ! empty( $data['created_at'] )
			? (string) $data['created_at']
			: current_time( 'mysql' );

		$row = array(
			'amount'                => $amount,
			'currency'              => $currency,
			'status'                => $status,
			'processor'             => $processor,
			'processor_payment_id'  => $processor_payment_id,
			'processor_charge_id'   => $processor_charge_id,
			'processor_customer_id' => $processor_customer_id,
			'note'                  => $note,
			'created_at'            => $created_at,
		);

		if ( empty( $row['id'] ) ) {
			$row['id'] = uniqid( 'pay_', true );
		}

		$this->append_payment_to_invoice_meta( $invoice_id, $row );
		$this->recalculate_invoice_paid_total( $invoice_id );

		return array(
			'ok'                    => true,
			'invoice_id'            => $invoice_id,
			'id'                    => $row['id'],
			'amount'                => $amount,
			'currency'              => $currency,
			'status'                => $status,
			'processor'             => $processor,
			'processor_payment_id'  => $processor_payment_id,
			'processor_charge_id'   => $processor_charge_id,
			'processor_customer_id' => $processor_customer_id,
			'note'                  => $note,
			'created_at'            => $created_at,
		);
	}

	public function get_payments_for_invoice( int $invoice_id ): array {
		if ( $invoice_id <= 0 ) {
			return array();
		}
		$payments = get_post_meta( $invoice_id, self::META_KEY, true );
		return is_array( $payments ) ? $payments : array();
	}

	private function append_payment_to_invoice_meta( int $invoice_id, array $payment_row ): void {
		if ( $invoice_id <= 0 ) {
			return;
		}

		$existing = get_post_meta( $invoice_id, self::META_KEY, true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$normalized = array(
			'id'                    => isset( $payment_row['id'] ) ? (string) $payment_row['id'] : uniqid( 'pay_', true ),
			'amount'                => isset( $payment_row['amount'] ) ? (float) $payment_row['amount'] : 0.0,
			'currency'              => isset( $payment_row['currency'] ) ? strtoupper( (string) $payment_row['currency'] ) : 'USD',
			'processor'             => isset( $payment_row['processor'] ) ? (string) $payment_row['processor'] : '',
			'processor_payment_id'  => isset( $payment_row['processor_payment_id'] ) ? (string) $payment_row['processor_payment_id'] : '',
			'processor_charge_id'   => isset( $payment_row['processor_charge_id'] ) ? (string) $payment_row['processor_charge_id'] : '',
			'processor_customer_id' => isset( $payment_row['processor_customer_id'] ) ? (string) $payment_row['processor_customer_id'] : '',
			'status'                => isset( $payment_row['status'] ) ? (string) $payment_row['status'] : 'succeeded',
			'note'                  => isset( $payment_row['note'] ) ? (string) $payment_row['note'] : '',
			'created_at'            => isset( $payment_row['created_at'] ) ? (string) $payment_row['created_at'] : current_time( 'mysql' ),
		);

		$existing[] = $normalized;
		update_post_meta( $invoice_id, self::META_KEY, $existing );
	}

	/**
	 * Updates invoice meta _wicked_invoicing_paid based on succeeded/refunded rows.
	 * Also bumps status to paid when fully paid.
	 *
	 * NOTE: Your statuses do NOT include "partial", so we do not set it.
	 */
	private function recalculate_invoice_paid_total( int $invoice_id ): void {
		if ( $invoice_id <= 0 ) {
			return;
		}

		$payments = get_post_meta( $invoice_id, self::META_KEY, true );
		if ( ! is_array( $payments ) ) {
			$payments = array();
		}

		$paid = 0.0;

		foreach ( $payments as $payment ) {
			$amount = isset( $payment['amount'] ) ? (float) $payment['amount'] : 0.0;
			$status = isset( $payment['status'] ) ? strtolower( (string) $payment['status'] ) : '';

			if ( $status === 'succeeded' ) {
				$paid += $amount;
			} elseif ( $status === 'refunded' ) {
				$paid -= $amount;
			}
		}

		update_post_meta( $invoice_id, '_wicked_invoicing_paid', $paid );

		$total_raw = get_post_meta( $invoice_id, '_wicked_invoicing_total', true );
		$total     = is_numeric( $total_raw ) ? (float) $total_raw : 0.0;

		if ( $total > 0 ) {
			$epsilon        = 0.01;
			$current_status = get_post_status( $invoice_id );

			if ( $paid >= ( $total - $epsilon ) && $current_status !== 'paid' ) {
				wp_update_post(
					array(
						'ID'          => $invoice_id,
						'post_status' => 'paid',
					)
				);
			}
			// If you want a "partial" status, add it to your invoice status map first.
		}
	}

	/*
	--------------------------------------------------------------------
	 * REST permission helpers
	 * ------------------------------------------------------------------ */

	private function user_can_access_invoice( int $invoice_id, string $mode = 'view' ) {
		$invoice = get_post( $invoice_id );
		if ( ! $invoice || $invoice->post_type !== Wicked_Invoice_Controller::get_cpt_slug() ) {
			return new WP_Error(
				'wicked_invoicing_invoice_not_found',
				__( 'Invoice not found.', 'wicked-invoicing' ),
				array( 'status' => 404 )
			);
		}

		// Managers/editors can do everything.
		if ( current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' ) ) {
			return true;
		}

		// View-only users: allow if they own the invoice.
		if ( $mode === 'view' && current_user_can( 'view_own_invoices' ) ) {
			return ( (int) $invoice->post_author === get_current_user_id() )
				? true
				: new WP_Error( 'wicked_invoicing_payments_forbidden', __( 'You do not have permission to view payments.', 'wicked-invoicing' ), array( 'status' => 403 ) );
		}

		return new WP_Error(
			'wicked_invoicing_payments_forbidden',
			( $mode === 'edit' )
				? __( 'You do not have permission to modify payments.', 'wicked-invoicing' )
				: __( 'You do not have permission to view payments.', 'wicked-invoicing' ),
			array( 'status' => 403 )
		);
	}

	public function rest_can_view_invoice_payments( WP_REST_Request $request ) {
		return $this->user_can_access_invoice( (int) $request['id'], 'view' );
	}

	public function rest_can_edit_invoice_payments( WP_REST_Request $request ) {
		return $this->user_can_access_invoice( (int) $request['id'], 'edit' );
	}

	/*
	--------------------------------------------------------------------
	 * REST callbacks
	 * ------------------------------------------------------------------ */

	public function rest_get_invoice_payments( WP_REST_Request $request ) {
		$invoice_id = (int) $request['id'];

		$invoice = get_post( $invoice_id );
		if ( ! $invoice || $invoice->post_type !== Wicked_Invoice_Controller::get_cpt_slug() ) {
			return new WP_Error(
				'wicked_invoicing_invoice_not_found',
				__( 'Invoice not found.', 'wicked-invoicing' ),
				array( 'status' => 404 )
			);
		}

		$payments = $this->get_payments_for_invoice( $invoice_id );

		return new WP_REST_Response(
			array( 'data' => $payments ),
			200
		);
	}

	public function rest_create_invoice_payment( WP_REST_Request $request ) {
		$invoice_id = (int) $request['id'];

		$invoice = get_post( $invoice_id );
		if ( ! $invoice || $invoice->post_type !== Wicked_Invoice_Controller::get_cpt_slug() ) {
			return new WP_Error(
				'wicked_invoicing_invoice_not_found',
				__( 'Invoice not found.', 'wicked-invoicing' ),
				array( 'status' => 404 )
			);
		}

		$amount = (float) $request->get_param( 'amount' );
		if ( $amount <= 0 ) {
			return new WP_Error(
				'wicked_invoicing_payment_invalid_amount',
				__( 'Payment amount must be greater than zero.', 'wicked-invoicing' ),
				array( 'status' => 400 )
			);
		}

		$currency  = strtoupper( (string) ( $request->get_param( 'currency' ) ?: 'USD' ) );
		$processor = (string) ( $request->get_param( 'processor' ) ?: 'manual' );
		$note      = (string) ( $request->get_param( 'note' ) ?? '' );

		$this->record_payment(
			array(
				'invoice_id'            => $invoice_id,
				'amount'                => $amount,
				'currency'              => $currency,
				'processor'             => $processor,
				'processor_payment_id'  => '',
				'processor_charge_id'   => '',
				'processor_customer_id' => '',
				'status'                => 'succeeded',
				'note'                  => $note,
			)
		);

		$payments = $this->get_payments_for_invoice( $invoice_id );

		return new WP_REST_Response(
			array( 'data' => $payments ),
			201
		);
	}

	/*
	--------------------------------------------------------------------
	 * Cross-invoice listing for Payments tab
	 * ------------------------------------------------------------------ */

	public function list_payments( $args = array() ): array {
		$args = is_array( $args ) ? $args : array();

		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page   = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$order_by   = sanitize_key( $args['orderby'] ?? 'created_at' );
		$order      = strtoupper( (string) ( $args['order'] ?? 'DESC' ) );
		$status     = trim( (string) ( $args['status'] ?? '' ) );
		$search     = trim( (string) ( $args['search'] ?? '' ) );
		$invoice_id = (int) ( $args['invoice_id'] ?? 0 );

		$invoice_cpt = Wicked_Invoice_Controller::get_cpt_slug();

		$query_args = array(
			'post_type'      => $invoice_cpt,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( $invoice_id > 0 ) {
			$query_args['post__in'] = array( $invoice_id );
		} else {
			$query_args['meta_query'] = array(
				array(
					'key'     => self::META_KEY,
					'compare' => 'EXISTS',
				),
			);
		}

		$invoice_ids = get_posts( $query_args );

		$payments = array();

		foreach ( $invoice_ids as $inv_id ) {
			$inv_id           = (int) $inv_id;
			$invoice_title    = (string) get_the_title( $inv_id );
			$invoice_hash     = (string) get_post_meta( $inv_id, '_wicked_invoicing_hash', true );
			$invoice_view_url = $invoice_hash
				? Wicked_Template_Controller::get_invoice_url( $invoice_hash )
				: '';

			$stored = get_post_meta( $inv_id, self::META_KEY, true );
			if ( ! is_array( $stored ) ) {
				continue;
			}

			foreach ( $stored as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$payments[] = $this->normalize_payment_row(
					$row,
					$inv_id,
					$invoice_title,
					$invoice_view_url
				);
			}
		}

		if ( $status !== '' ) {
			$status_lower = strtolower( $status );
			$payments     = array_values(
				array_filter(
					$payments,
					static function ( $payment ) use ( $status_lower ) {
						return strtolower( (string) ( $payment['status'] ?? '' ) ) === $status_lower;
					}
				)
			);
		}

		if ( $search !== '' ) {
			$needle   = mb_strtolower( $search );
			$payments = array_values(
				array_filter(
					$payments,
					static function ( $payment ) use ( $needle ) {
						$haystack = implode(
							' ',
							array(
								(string) ( $payment['invoice_title'] ?? '' ),
								(string) ( $payment['processor'] ?? '' ),
								(string) ( $payment['processor_payment_id'] ?? '' ),
								(string) ( $payment['processor_customer_id'] ?? '' ),
								(string) ( $payment['processor_charge_id'] ?? '' ),
								(string) ( $payment['note'] ?? '' ),
							)
						);
						return mb_stripos( mb_strtolower( $haystack ), $needle ) !== false;
					}
				)
			);
		}

		$allowed_orderby = array( 'created_at', 'amount', 'invoice_id', 'status', 'processor' );
		if ( ! in_array( $order_by, $allowed_orderby, true ) ) {
			$order_by = 'created_at';
		}
		$order_is_desc = ( $order === 'DESC' );

		usort(
			$payments,
			static function ( $a, $b ) use ( $order_by, $order_is_desc ) {
				$va = $a[ $order_by ] ?? null;
				$vb = $b[ $order_by ] ?? null;

				if ( $order_by === 'created_at' ) {
					$ta  = is_string( $va ) ? strtotime( $va ) : (int) $va;
					$tb  = is_string( $vb ) ? strtotime( $vb ) : (int) $vb;
					$cmp = $ta <=> $tb;
				} elseif ( $order_by === 'amount' ) {
					$cmp = (float) $va <=> (float) $vb;
				} else {
					$cmp = (string) $va <=> (string) $vb;
				}

				return $order_is_desc ? -$cmp : $cmp;
			}
		);

		$total  = count( $payments );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $payments, $offset, $per_page );

		return array(
			'items' => array_values( $items ),
			'total' => (int) $total,
		);
	}

	private function normalize_payment_row( array $row, int $invoice_id, string $invoice_title, string $invoice_view_url ): array {
		$payment_id = ! empty( $row['id'] ) ? (string) $row['id'] : uniqid( 'pay_', true );

		$amount   = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
		$currency = isset( $row['currency'] ) ? strtoupper( (string) $row['currency'] ) : 'USD';

		$status    = isset( $row['status'] ) ? (string) $row['status'] : 'succeeded';
		$processor = isset( $row['processor'] ) ? (string) $row['processor'] : '';

		$created_at = ! empty( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' );

		return array(
			'id'                    => $payment_id,
			'invoice_id'            => $invoice_id,

			'invoice_title'         => $invoice_title,
			'invoice_view_url'      => $invoice_view_url,

			'amount'                => $amount,
			'currency'              => $currency,

			'status'                => $status,
			'processor'             => $processor,
			'processor_payment_id'  => isset( $row['processor_payment_id'] ) ? (string) $row['processor_payment_id'] : '',
			'processor_charge_id'   => isset( $row['processor_charge_id'] ) ? (string) $row['processor_charge_id'] : '',
			'processor_customer_id' => isset( $row['processor_customer_id'] ) ? (string) $row['processor_customer_id'] : '',

			'created_at'            => $created_at,
			'note'                  => isset( $row['note'] ) ? (string) $row['note'] : '',
		);
	}
}
