<?php
namespace Wicked_Invoicing\Controllers;

use Wicked_Invoicing\Wicked_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wicked_Invoice_Controller
 *
 * Registers the `wicked_invoice` CPT and exposes it via a REST API.
 * All errors are funneled through Wicked_Base_Controller::rest_error() which
 * logs via Wicked_Log_Controller (wicked_invoicing_error) and throws a WP_REST_Exception.
 */
class Wicked_Invoice_Controller extends Wicked_Base_Controller {

		/** CPT slug */
	const CPT = 'wicked_invoice';
	/** REST namespace */
	const REST_NS = 'wicked-invoicing/v1';

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_custom_statuses' ), 0 );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the hidden `wicked_invoice` CPT for REST-only use.
	 */
	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Invoices', 'wicked-invoicing' ),
					'singular_name' => __( 'Invoice', 'wicked-invoicing' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => true,
				'rest_base'    => 'invoices',
				'supports'     => array( 'title', 'editor' ),
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * Register our custom invoice statuses.
	 */
	public function register_custom_statuses() {
		$labels_map = Wicked_Base_Controller::get_invoice_status_map(); // ['temp'=>'Temp', ...]

		foreach ( $labels_map as $slug => $label ) {
			register_post_status(
				$slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => true,
					'show_in_rest'              => true, // critical for REST saves

				/* translators: 1: Invoice, 2: Invoices. */
					'label_count'               => _n_noop(
						'Invoice <span class="count">(%s)</span>',
						'Invoices <span class="count">(%s)</span>',
						'wicked-invoicing'
					),
				)
			);
		}
	}

	/** Canonical list of invoice statuses (slug => label). */
	public static function get_statuses(): array {
		return array(
			'temp'             => __( 'Temp', 'wicked-invoicing' ),
			'pending'          => __( 'Pending', 'wicked-invoicing' ),
			'deposit-required' => __( 'Deposit Required', 'wicked-invoicing' ),
			'deposit-paid'     => __( 'Deposit Paid', 'wicked-invoicing' ),
			'paid'             => __( 'Paid', 'wicked-invoicing' ),
		);
	}

		/**
		 * Register all invoice meta for REST with sanitization + validation.
		 */
	public function register_meta() {
		// String-ish fields (single line)
		$text_fields = array(
			'_wicked_invoicing_client_name',
			'_wicked_invoicing_payment_terms',
			'_wicked_invoicing_po_number',
			'_wicked_invoicing_reference_number',
		);
		foreach ( $text_fields as $meta_key ) {
			register_post_meta(
				self::CPT,
				$meta_key,
				array(
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( $value ) => is_string( $value ),
					'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);
		}

		// Multiline fields (addresses, notes, terms)
		$textarea_fields = array(
			'_wicked_invoicing_client_address',
			'_wicked_invoicing_billing_address',
			'_wicked_invoicing_shipping_address',
			'_wicked_invoicing_notes',
			'_wicked_invoicing_terms_and_conditions',
			'_wicked_invoicing_footer_text',
		);
		foreach ( $textarea_fields as $meta_key ) {
			register_post_meta(
				self::CPT,
				$meta_key,
				array(
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( $value ) => is_string( $value ),
					'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);
		}

		// Client email
		register_post_meta(
			self::CPT,
			'_wicked_invoicing_client_email',
			array(
				'single'            => true,
				'show_in_rest'      => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $value ) {
					$value = (string) $value;
					return $value === '' || is_email( $value );
				},
				'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
			)
		);

		// Subscriptions meta
		$subs_enabled = apply_filters( 'wicked_invoicing_subscriptions_enabled', true );
		if ( $subs_enabled ) {
			register_post_meta(
				self::CPT,
				'_wicked_invoicing_sub_enabled',
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'type'          => 'boolean',
					'auth_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);

			register_post_meta(
				self::CPT,
				'_wicked_invoicing_sub_mode',
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'type'          => 'string',
					'auth_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);

			register_post_meta(
				self::CPT,
				'_wicked_invoicing_sub_anchor_dom',
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'type'          => 'integer',
					'auth_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);

			register_post_meta(
				self::CPT,
				'_wicked_invoicing_sub_days',
				array(
					'single'        => true,
					'show_in_rest'  => true,
					'type'          => 'integer',
					'auth_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);

			register_post_meta(
				self::CPT,
				'_wicked_invoicing_sub_next_run',
				array(
					'single'            => true,
					'show_in_rest'      => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $value ) {
						if ( $value === '' || $value === null ) {
							return true;
						}
						return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
					},
					'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);
		}

		// Client user id
		register_post_meta(
			self::CPT,
			'_wicked_invoicing_client_user_id',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'integer',
				'auth_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
			)
		);

		// Date fields (yyyy-mm-dd)
		$date_fields = array( '_wicked_invoicing_start_date', '_wicked_invoicing_due_date' );
		foreach ( $date_fields as $meta_key ) {
			register_post_meta(
				self::CPT,
				$meta_key,
				array(
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => fn( $value ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ),
					'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);
		}

		// Numeric fields
		$number_fields = array(
			'_wicked_invoicing_subtotal',
			'_wicked_invoicing_tax_amount',
			'_wicked_invoicing_discount_amount',
			'_wicked_invoicing_total',
			'_wicked_invoicing_paid',
		);
		foreach ( $number_fields as $meta_key ) {
			register_post_meta(
				self::CPT,
				$meta_key,
				array(
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'wicked_inv_sanitize_number',
					'validate_callback' => fn( $value ) => is_numeric( $value ),
					'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
				)
			);
		}

		// Line items
		register_post_meta(
			self::CPT,
			'_wicked_invoicing_line_items',
			array(
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'description' => array( 'type' => 'string' ),
								'quantity'    => array( 'type' => 'integer' ),
								'rate'        => array( 'type' => 'number' ),
								'discount'    => array( 'type' => 'number' ),
								'tax'         => array( 'type' => 'number' ),
							),
							'required'   => array( 'description', 'quantity', 'rate' ),
						),
					),
				),
				'sanitize_callback' => function ( $value ) {
					if ( ! is_array( $value ) ) {
						return array();
					}
					return array_map(
						function ( $item ) {
							return array(
								'description' => sanitize_text_field( $item['description'] ?? '' ),
								'quantity'    => intval( $item['quantity'] ?? 0 ),
								'rate'        => floatval( $item['rate'] ?? 0 ),
								'discount'    => floatval( $item['discount'] ?? 0 ),
								'tax'         => floatval( $item['tax'] ?? 0 ),
							);
						},
						$value
					);
				},
				'validate_callback' => fn( $value ) => is_array( $value ),
				'auth_callback'     => fn() => current_user_can( 'edit_wicked_invoices' ),
			)
		);
	}

	/**
	 * Define REST routes.
	 */
	public function register_rest_routes() {
		$ns = self::REST_NS;

		// ─── Collection: GET /invoices, POST /invoices ────────────────────────
		register_rest_route(
			$ns,
			'/invoices',
			array(
				// GET /invoices
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_invoices' ),
					'permission_callback' => array( $this, 'can_list_invoices' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 10,
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
							'maximum'           => 100,
						),
						'status'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => array( __CLASS__, 'sanitize_status_slug' ),
							'validate_callback' => function ( $value ) {
								if ( $value === '' || $value === null ) {
									return true;
								}
								return self::validate_status_slug( $value );
							},
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'  => array(
							'type'              => 'string',
							'default'           => 'date',
							'sanitize_callback' => 'sanitize_key',
							'enum'              => array( 'title', 'date', 'status', 'start_date', 'due_date', 'total', 'paid' ),
						),
						'order'    => array(
							'type'              => 'string',
							'default'           => 'DESC',
							'sanitize_callback' => function ( $value ) {
								$v = strtoupper( (string) $value );
								return in_array( $v, array( 'ASC', 'DESC' ), true ) ? $v : 'DESC';
							},
							'enum'              => array( 'ASC', 'DESC' ),
						),
					),
				),
				// POST /invoices
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_invoice' ),
					'permission_callback' => array( $this, 'can_edit_invoices' ),
					'args'                => array(
						'title'                   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'                  => array(
							'type'              => 'string',
							'sanitize_callback' => array( __CLASS__, 'sanitize_status_slug' ),
							'validate_callback' => array( __CLASS__, 'validate_status_slug' ),
							'default'           => 'temp',
						),
						'client_name'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'client_email'            => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => fn( $v ) => $v === '' || is_email( $v ),
						),
						'client_address'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'billing_address'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'shipping_address'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'start_date'              => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value ) {
								return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value )
									? true
									: new \WP_Error(
										'invalid_start_date',
										__( 'Please enter a valid start date (YYYY-MM-DD)', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => 'start_date',
										)
									);
							},
						),
						'due_date'                => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value, \WP_REST_Request $request, $param ) {
								if ( $value === '' || $value === null ) {
									return true; // allow omitted; server will default
								}
								if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) {
									return new \WP_Error(
										'invalid_due_date',
										__( 'Please enter a valid due date (YYYY-MM-DD).', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => $param,
										)
									);
								}
								$start = $request->get_param( 'start_date' ) ?: current_time( 'Y-m-d' );
								if ( strtotime( (string) $value ) <= strtotime( (string) $start ) ) {
									return new \WP_Error(
										'invalid_due_date_order',
										__( 'Due Date must be after the Invoice Date.', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => $param,
										)
									);
								}
								return true;
							},
						),
						'payment_terms'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'po_number'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reference_number'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'line_items'              => array(
							'type'              => 'array',
							'validate_callback' => fn( $v ) => is_array( $v ),
						),
						'subtotal'                => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'tax_amount'              => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'discount_amount'         => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'total'                   => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'paid'                    => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'notes'                   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'terms_and_conditions'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'subscription_enabled'    => array( 'type' => 'boolean' ),
						'subscription_days'       => array( 'type' => 'integer' ),
						'subscription_next_run'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => fn( $v ) => $v === null || $v === '' || preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ),
						),
						'subscription_mode'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $v ) {
								return in_array( $v, array( 'days', 'anchor' ), true ) ? true : new \WP_Error(
									'invalid_subscription_mode',
									__( 'subscription_mode must be "days" or "anchor".', 'wicked-invoicing' ),
									array(
										'status'    => 400,
										'parameter' => 'subscription_mode',
									)
								);
							},
						),
						'subscription_anchor_dom' => array(
							'type'              => 'integer',
							'validate_callback' => function ( $v ) {
								if ( $v === null || $v === '' ) {
									return true;
								}
								$n = (int) $v;
								return ( $n >= 1 && $n <= 28 ) ? true : new \WP_Error(
									'invalid_subscription_anchor_dom',
									__( 'subscription_anchor_dom must be between 1 and 28.', 'wicked-invoicing' ),
									array(
										'status'    => 400,
										'parameter' => 'subscription_anchor_dom',
									)
								);
							},
						),
					),
				),
			)
		);

		// ─── Item: GET /invoices/{id}, PATCH /invoices/{id} ────────────────
		register_rest_route(
			$ns,
			'/invoices/(?P<id>\d+)',
			array(
				// GET /invoices/{id}
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_invoice' ),
					'permission_callback' => array( $this, 'can_view_invoice' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				// DELETE /invoices/{id}
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_invoice' ),
					'permission_callback' => array( $this, 'can_delete_invoice' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				// PATCH /invoices/{id}
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_invoice' ),
					'permission_callback' => array( $this, 'can_edit_invoices' ),
					'args'                => array(
						'id'                      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'title'                   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'                  => array(
							'type'              => 'string',
							'sanitize_callback' => array( __CLASS__, 'sanitize_status_slug' ),
							'validate_callback' => array( __CLASS__, 'validate_status_slug' ),
						),
						'client_name'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'client_email'            => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => fn( $v ) => $v === '' || is_email( $v ),
						),
						'client_address'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'billing_address'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'shipping_address'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'start_date'              => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value ) {
								if ( $value === '' || $value === null ) {
									return true; // allow omitted
								}
								return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value )
									? true
									: new \WP_Error(
										'invalid_start_date',
										__( 'Please enter a valid start date (YYYY-MM-DD)', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => 'start_date',
										)
									);
							},
						),
						'due_date'                => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value, \WP_REST_Request $request, $param ) {
								if ( $value === '' || $value === null ) {
									return true; // allow omitted
								}
								if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) {
									return new \WP_Error(
										'invalid_due_date',
										__( 'Please enter a valid due date (YYYY-MM-DD).', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => $param,
										)
									);
								}
								$start = $request->get_param( 'start_date' ) ?: current_time( 'Y-m-d' );
								if ( strtotime( (string) $value ) <= strtotime( (string) $start ) ) {
									return new \WP_Error(
										'invalid_due_date_order',
										__( 'Due Date must be after the Invoice Date.', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => $param,
										)
									);
								}
								return true;
							},
						),
						'payment_terms'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'po_number'               => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reference_number'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'line_items'              => array(
							'type'              => 'array',
							'validate_callback' => fn( $v ) => is_array( $v ),
						),
						'subtotal'                => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'tax_amount'              => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'discount_amount'         => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'total'                   => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'paid'                    => array(
							'type'              => 'number',
							'sanitize_callback' => fn( $v ) => floatval( $v ),
						),
						'notes'                   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'terms_and_conditions'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'subscription_enabled'    => array( 'type' => 'boolean' ),
						'subscription_days'       => array( 'type' => 'integer' ),
						'subscription_next_run'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => fn( $v ) => $v === null || $v === '' || preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ),
						),
						'subscription_mode'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $v ) {
								return $v === null || $v === '' || in_array( $v, array( 'days', 'anchor' ), true )
									? true
									: new \WP_Error(
										'invalid_subscription_mode',
										__( 'subscription_mode must be "days" or "anchor".', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => 'subscription_mode',
										)
									);
							},
						),
						'subscription_anchor_dom' => array(
							'type'              => 'integer',
							'validate_callback' => function ( $v ) {
								if ( $v === null || $v === '' ) {
									return true;
								}
								$n = (int) $v;
								return ( $n >= 1 && $n <= 28 )
									? true
									: new \WP_Error(
										'invalid_subscription_anchor_dom',
										__( 'subscription_anchor_dom must be between 1 and 28.', 'wicked-invoicing' ),
										array(
											'status'    => 400,
											'parameter' => 'subscription_anchor_dom',
										)
									);
							},
						),
					),
				),
			)
		);

		// ─── Action: POST /invoices/{id}/duplicate ───────────────────────────
		register_rest_route(
			$ns,
			'/invoices/(?P<id>\d+)/duplicate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'duplicate_invoice' ),
					'permission_callback' => array( $this, 'can_edit_invoices' ),
					'args'                => array(
						'id'         => array(
							'type'     => 'integer',
							'required' => true,
						),
						'title'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => array( __CLASS__, 'sanitize_status_slug' ),
							'validate_callback' => array( __CLASS__, 'validate_status_slug' ),
						),
						'reset_paid' => array(
							'type'              => 'boolean',
							'required'          => false,
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'start_date' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => fn( $v ) => $v === null || $v === '' || preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ),
						),
						'due_date'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => fn( $v ) => $v === null || $v === '' || preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ),
						),
					),
				),
			)
		);
	}

	/** Normalize slugs: underscores → hyphens, sanitize_key, lowercase. */
	public static function sanitize_status_slug( $value ) {
		if ( $value === null ) {
			return null;
		}
		return sanitize_key( str_replace( '_', '-', (string) $value ) );
	}

	/** Validate against dynamic statuses from Base. */
	public static function validate_status_slug( $value ) {
		$slug    = self::sanitize_status_slug( $value );
		$allowed = \Wicked_Invoicing\Controllers\Wicked_Base_Controller::get_invoice_status_slugs();

		if ( in_array( $slug, $allowed, true ) ) {
			return true;
		}

		return new \WP_Error(
			'invalid_status',
			/* translators: 1: invalid invoice status slug, 2: comma-separated allowed statuses */
			sprintf( __( 'Invalid invoice status "%1$s". Allowed: %2$s', 'wicked-invoicing' ), $slug, implode( ', ', $allowed ) ),
			array( 'status' => 400 )
		);
	}

	public function list_invoices( array $args ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 10 ) );

		$orderby = sanitize_key( $args['orderby'] ?? 'date' );
		$order   = ( strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ) ? 'ASC' : 'DESC';

		$query = array(
			'post_type'      => self::CPT,
			'post_status'    => array( 'temp', 'pending', 'deposit-paid', 'paid' ),
			'paged'          => $page,
			'posts_per_page' => $per_page,
		);

		// - Users with view_all_invoices see every invoice.
		// - Users with only view_own_invoices see only invoices they authored.
		$can_view_all = self::user_has_cap( 'view_all_invoices' );
		$can_view_own = self::user_has_cap( 'view_own_invoices' );

		if ( ! $can_view_all && $can_view_own ) {
			// Limit the query to invoices where this user is the post_author.
			$query['author'] = get_current_user_id();
		}

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$query['post_status'] = sanitize_key( $args['status'] );
		}

		switch ( $orderby ) {
			case 'title':
			case 'date':
			case 'status':
				$query['orderby'] = $orderby;
				$query['order']   = $order;
				break;

			case 'start_date':
				$query['orderby']    = 'meta_value';
				$query['order']      = $order;
				$query['meta_key']   = '_wicked_invoicing_start_date';
				$query['meta_type']  = 'DATE';
				$query['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => '_wicked_invoicing_start_date',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_wicked_invoicing_start_date',
						'compare' => 'NOT EXISTS',
					),
				);
				break;

			case 'due_date':
				$query['orderby']    = 'meta_value';
				$query['order']      = $order;
				$query['meta_key']   = '_wicked_invoicing_due_date';
				$query['meta_type']  = 'DATE';
				$query['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => '_wicked_invoicing_due_date',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_wicked_invoicing_due_date',
						'compare' => 'NOT EXISTS',
					),
				);
				break;

			case 'total':
			case 'paid':
				$key                 = ( $orderby === 'total' ) ? '_wicked_invoicing_total' : '_wicked_invoicing_paid';
				$query['orderby']    = 'meta_value_num';
				$query['order']      = $order;
				$query['meta_key']   = $key;
				$query['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
				);
				break;

			default:
				$query['orderby'] = 'date';
				$query['order']   = $order;
		}

		$q = new \WP_Query( $query );

		$items = array_map(
			function ( $post ) {
				$id   = $post->ID;
				$hash = (string) get_post_meta( $id, '_wicked_invoicing_hash', true );

				$subtotal   = (float) get_post_meta( $id, '_wicked_invoicing_subtotal', true );
				$tax_amount = (float) get_post_meta( $id, '_wicked_invoicing_tax_amount', true );
				$discount   = (float) get_post_meta( $id, '_wicked_invoicing_discount_amount', true );
				$total      = (float) get_post_meta( $id, '_wicked_invoicing_total', true );
				$paid       = (float) get_post_meta( $id, '_wicked_invoicing_paid', true );

				$client_name    = (string) get_post_meta( $id, '_wicked_invoicing_client_name', true );
				$client_user_id = (int) get_post_meta( $id, '_wicked_invoicing_client_user_id', true );
				$company_name   = '';

				if ( $client_user_id ) {
						$company_name = get_user_meta( $client_user_id, 'company_name', true );
					if ( '' === $company_name ) {
						$company_name = get_user_meta( $client_user_id, '_wicked_invoicing_company_name', true );
					}
				}

				$start_date = (string) ( get_post_meta( $id, '_wicked_invoicing_start_date', true ) ?: get_the_date( 'Y-m-d', $id ) );
				$due_date   = (string) get_post_meta( $id, '_wicked_invoicing_due_date', true );
				$po_number  = (string) get_post_meta( $id, '_wicked_invoicing_po_number', true );
				$ref_number = (string) get_post_meta( $id, '_wicked_invoicing_reference_number', true );
				$terms      = (string) get_post_meta( $id, '_wicked_invoicing_payment_terms', true );

				$sub_enabled  = (bool) get_post_meta( $id, '_wicked_invoicing_sub_enabled', true );
				$sub_days     = (int) get_post_meta( $id, '_wicked_invoicing_sub_days', true );
				$sub_next_run = (string) get_post_meta( $id, '_wicked_invoicing_sub_next_run', true );
				$is_sub       = $sub_enabled || $sub_days > 0 || ! empty( $sub_next_run );
				$mode         = (string) get_post_meta( $id, '_wicked_invoicing_sub_mode', true );
				$anchor       = get_post_meta( $id, '_wicked_invoicing_sub_anchor_dom', true );

				$view_url = $hash ? \Wicked_Invoicing\Controllers\Wicked_Template_Controller::get_invoice_url( $hash ) : '';

				return array(
					'id'                      => $id,
					'title'                   => $post->post_title,
					'status'                  => $post->post_status,
					'date'                    => $post->post_date,

					'hash'                    => $hash,
					'view_url'                => $view_url,

					'subtotal'                => $subtotal,
					'tax_amount'              => $tax_amount,
					'discount_amount'         => $discount,
					'total'                   => $total,
					'paid'                    => $paid,
					'balance'                 => max( 0, $total - $paid ),

					'client_name'             => $client_name,
					'client_user_id'          => $client_user_id,
					'client_company'          => $company_name,

					'start_date'              => $start_date,
					'due_date'                => $due_date,
					'po_number'               => $po_number,
					'reference_number'        => $ref_number,
					'payment_terms'           => $terms,

					'subscription_enabled'    => $sub_enabled,
					'subscription_days'       => $sub_days,
					'subscription_next_run'   => $sub_next_run,
					'is_subscription'         => $is_sub,
					'subscription_mode'       => ( $mode === 'anchor' ) ? 'anchor' : 'days',
					'subscription_anchor_dom' => ( $mode === 'anchor' && $anchor ) ? intval( $anchor ) : null,
				);
			},
			$q->posts
		);

		return array(
			'data' => $items,
			'meta' => array(
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => (int) $q->found_posts,
			),
		);
	}

	/**
	 * GET /invoices/{id}
	 */
	public function get_invoice( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return self::rest_error( 'missing_id', __( 'Invoice ID is required', 'wicked-invoicing' ), 400 );
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			return self::rest_error( 'not_found', __( 'Invoice not found', 'wicked-invoicing' ), 404 );
		}

		$hash     = get_post_meta( $id, '_wicked_invoicing_hash', true );
		$view_url = $hash ? \Wicked_Invoicing\Controllers\Wicked_Template_Controller::get_invoice_url( $hash ) : '';

		$data = array(
			'id'                    => $id,
			'title'                 => $post->post_title,
			'status'                => $post->post_status,
			'available_statuses'    => Wicked_Base_Controller::get_invoice_status_map(),
			'hash'                  => $hash,
			'view_url'              => $view_url,

			'client_name'           => get_post_meta( $id, '_wicked_invoicing_client_name', true ),
			'client_user_id'        => (int) get_post_meta( $id, '_wicked_invoicing_client_user_id', true ),
			'client_email'          => get_post_meta( $id, '_wicked_invoicing_client_email', true ),
			'client_address'        => get_post_meta( $id, '_wicked_invoicing_client_address', true ),
			'billing_address'       => get_post_meta( $id, '_wicked_invoicing_billing_address', true ),
			'shipping_address'      => get_post_meta( $id, '_wicked_invoicing_shipping_address', true ),

			'start_date'            => get_post_meta( $id, '_wicked_invoicing_start_date', true ) ?: get_the_date( 'Y-m-d', $id ),
			'due_date'              => get_post_meta( $id, '_wicked_invoicing_due_date', true ),
			'payment_terms'         => get_post_meta( $id, '_wicked_invoicing_payment_terms', true ),
			'po_number'             => get_post_meta( $id, '_wicked_invoicing_po_number', true ),
			'reference_number'      => get_post_meta( $id, '_wicked_invoicing_reference_number', true ),

			'line_items'            => get_post_meta( $id, '_wicked_invoicing_line_items', true ),
			'subtotal'              => (float) get_post_meta( $id, '_wicked_invoicing_subtotal', true ),
			'tax_amount'            => (float) get_post_meta( $id, '_wicked_invoicing_tax_amount', true ),
			'discount_amount'       => (float) get_post_meta( $id, '_wicked_invoicing_discount_amount', true ),
			'total'                 => (float) get_post_meta( $id, '_wicked_invoicing_total', true ),
			'paid'                  => (float) get_post_meta( $id, '_wicked_invoicing_paid', true ),

			'notes'                 => get_post_meta( $id, '_wicked_invoicing_notes', true ),
			'terms_and_conditions'  => get_post_meta( $id, '_wicked_invoicing_terms_and_conditions', true ),
			'footer_text'           => get_post_meta( $id, '_wicked_invoicing_footer_text', true ),

			'subscription_enabled'  => (bool) get_post_meta( $id, '_wicked_invoicing_sub_enabled', true ),
			'subscription_days'     => (int) get_post_meta( $id, '_wicked_invoicing_sub_days', true ),
			'subscription_next_run' => (string) get_post_meta( $id, '_wicked_invoicing_sub_next_run', true ),
		);

		$mode   = (string) get_post_meta( $id, '_wicked_invoicing_sub_mode', true );
		$anchor = get_post_meta( $id, '_wicked_invoicing_sub_anchor_dom', true );

		$data['subscription_mode']       = ( $mode === 'anchor' ) ? 'anchor' : 'days';
		$data['subscription_anchor_dom'] = ( $data['subscription_mode'] === 'anchor' && $anchor ) ? intval( $anchor ) : null;

		return rest_ensure_response( $data );
	}

	/**
	 * POST /invoices
	 */
	public function create_invoice( WP_REST_Request $request ) {
		// Use get_params() so route arg sanitizers/validators apply.
		$params = (array) $request->get_params();

		$title = (string) ( $params['title'] ?? '' );
		if ( $title === '' ) {
			return self::rest_error( 'missing_title', __( 'Invoice ID / Name is required field', 'wicked-invoicing' ), 400 );
		}

		$res = \Wicked_Invoicing\Wicked_Controller::invoice()->create( $title );
		if ( is_wp_error( $res ) ) {
			$status = ( $res->get_error_data()['status'] ?? 400 );
			return self::rest_error( $res->get_error_code(), $res->get_error_message(), $status );
		}

		if ( is_array( $res ) ) {
			$post_id = absint( $res['id'] ?? 0 );
			$hash    = sanitize_text_field( $res['hash'] ?? '' );
		} elseif ( is_object( $res ) ) {
			$post_id = absint( $res->id ?? 0 );
			$hash    = sanitize_text_field( $res->hash ?? '' );
		} else {
			$post_id = absint( $res );
			$hash    = '';
		}

		if ( ! $post_id ) {
			return self::rest_error( 'create_failed', __( 'Failed to create invoice.', 'wicked-invoicing' ), 500 );
		}

		// Status (already sanitized by route args)
		$status = (string) ( $params['status'] ?? 'temp' );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status ?: 'temp',
			)
		);

		// Dates (defaults enforced)
		list( $start, $due ) = $this->normalize_dates_for_save( $request );
		update_post_meta( $post_id, '_wicked_invoicing_start_date', $start );
		update_post_meta( $post_id, '_wicked_invoicing_due_date', $due );

		// Subscription (explicit writes; do NOT include these in the generic map to avoid double-writing)
		$sub_enabled = (bool) ( $params['subscription_enabled'] ?? false );
		$sub_days    = (int) ( $params['subscription_days'] ?? 0 );
		$sub_next    = isset( $params['subscription_next_run'] ) ? (string) $params['subscription_next_run'] : '';

		if ( $sub_enabled && $sub_days <= 0 ) {
			$sub_days = 30;
		}
		if ( $sub_enabled && $sub_next === '' ) {
			$sub_next = $due ?: gmdate( 'Y-m-d', strtotime( $start . ' +30 days' ) );
		}

		update_post_meta( $post_id, '_wicked_invoicing_sub_enabled', $sub_enabled ? 1 : 0 );
		update_post_meta( $post_id, '_wicked_invoicing_sub_days', $sub_days );
		update_post_meta( $post_id, '_wicked_invoicing_sub_next_run', $sub_next );

		$mode = isset( $params['subscription_mode'] ) ? (string) $params['subscription_mode'] : 'days';
		$mode = in_array( $mode, array( 'days', 'anchor' ), true ) ? $mode : 'days';
		update_post_meta( $post_id, '_wicked_invoicing_sub_mode', $mode );

		$anchor = $params['subscription_anchor_dom'] ?? null;
		$anchor = is_null( $anchor ) ? null : max( 1, min( 28, intval( $anchor ) ) );
		if ( $mode === 'anchor' && $anchor ) {
			update_post_meta( $post_id, '_wicked_invoicing_sub_anchor_dom', $anchor );
		} else {
			delete_post_meta( $post_id, '_wicked_invoicing_sub_anchor_dom' );
		}

		// Generic meta map (everything else)
		$map = array(
			'client_name'          => '_wicked_invoicing_client_name',
			'client_email'         => '_wicked_invoicing_client_email',
			'client_user_id'       => '_wicked_invoicing_client_user_id',
			'client_address'       => '_wicked_invoicing_client_address',
			'billing_address'      => '_wicked_invoicing_billing_address',
			'shipping_address'     => '_wicked_invoicing_shipping_address',
			'payment_terms'        => '_wicked_invoicing_payment_terms',
			'po_number'            => '_wicked_invoicing_po_number',
			'reference_number'     => '_wicked_invoicing_reference_number',
			'line_items'           => '_wicked_invoicing_line_items',
			'subtotal'             => '_wicked_invoicing_subtotal',
			'tax_amount'           => '_wicked_invoicing_tax_amount',
			'discount_amount'      => '_wicked_invoicing_discount_amount',
			'total'                => '_wicked_invoicing_total',
			'paid'                 => '_wicked_invoicing_paid',
			'notes'                => '_wicked_invoicing_notes',
			'terms_and_conditions' => '_wicked_invoicing_terms_and_conditions',
		);

		foreach ( $map as $param => $meta_key ) {
			if ( array_key_exists( $param, $params ) ) {
				update_post_meta( $post_id, $meta_key, $params[ $param ] );
			}
		}

		$view_url = $hash ? \Wicked_Invoicing\Controllers\Wicked_Template_Controller::get_invoice_url( $hash ) : '';

		return rest_ensure_response(
			array(
				'id'       => $post_id,
				'hash'     => $hash,
				'view_url' => $view_url,
			)
		);
	}

	public function get_invoices( WP_REST_Request $request ) {
		if ( self::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Wicked INFO] get_invoices called', $request->get_params() );
		}
		$result = $this->list_invoices( $request->get_params() );
		return rest_ensure_response( $result );
	}

	/**
	 * Permission: can trash an invoice (Full Access only).
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function can_delete_invoice( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return false;
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			return false;
		}

		// Full Access only (your Security tab checkbox).
		if ( ! Wicked_Base_Controller::user_has_cap( 'manage_wicked_invoicing' ) ) {
			return false;
		}

		// WordPress native capability check as defense-in-depth.
		return current_user_can( 'delete_post', $id );
	}

	/**
	 * Trash an invoice.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function delete_invoice( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new \WP_Error(
				'invalid_invoice_id',
				__( 'Invalid invoice ID.', 'wicked-invoicing' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			return new \WP_Error(
				'invalid_invoice',
				__( 'Invoice not found.', 'wicked-invoicing' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to delete this invoice.', 'wicked-invoicing' ),
				array( 'status' => 403 )
			);
		}

		$trashed = wp_trash_post( $id );
		if ( ! $trashed ) {
			return new \WP_Error(
				'trash_failed',
				__( 'Unable to move invoice to Trash.', 'wicked-invoicing' ),
				array( 'status' => 500 )
			);
		}

		do_action(
			'wicked_invoicing_info',
			'[Wicked Invoice] delete_invoice trashed',
			array(
				'invoice_id' => $id,
				'user_id'    => get_current_user_id(),
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => 'trash',
			)
		);
	}

	/**
	 * PATCH /invoices/{id}
	 */
	public function update_invoice( WP_REST_Request $request ) {
		// Use get_params() so route arg sanitizers/validators apply.
		$params = (array) $request->get_params();

		$id = absint( $params['id'] ?? 0 );
		if ( ! $id ) {
			return self::rest_error( 'missing_id', __( 'Invoice ID is required.', 'wicked-invoicing' ), 400 );
		}

		$invoice = get_post( $id );
		if ( ! $invoice || $invoice->post_type !== self::CPT ) {
			return self::rest_error( 'not_found', __( 'Invoice not found.', 'wicked-invoicing' ), 404 );
		}

		// Title
		if ( array_key_exists( 'title', $params ) && $params['title'] !== null ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => (string) $params['title'],
				)
			);
		}

		// Status
		if ( array_key_exists( 'status', $params ) && $params['status'] !== null ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => (string) $params['status'],
				)
			);
		}

		// Dates (never empty)
		$existing_start      = get_post_meta( $id, '_wicked_invoicing_start_date', true );
		list( $start, $due ) = $this->normalize_dates_for_save( $request, $existing_start );
		update_post_meta( $id, '_wicked_invoicing_start_date', $start );
		update_post_meta( $id, '_wicked_invoicing_due_date', $due );

		// Subscription defaults on update
		$sub_enabled = array_key_exists( 'subscription_enabled', $params )
			? (bool) $params['subscription_enabled']
			: (bool) get_post_meta( $id, '_wicked_invoicing_sub_enabled', true );

		$sub_days = array_key_exists( 'subscription_days', $params )
			? (int) $params['subscription_days']
			: (int) get_post_meta( $id, '_wicked_invoicing_sub_days', true );

		$sub_next = array_key_exists( 'subscription_next_run', $params )
			? (string) $params['subscription_next_run']
			: (string) get_post_meta( $id, '_wicked_invoicing_sub_next_run', true );

		if ( $sub_enabled && $sub_days <= 0 ) {
			$sub_days = 30;
		}
		if ( $sub_enabled && ( $sub_next === '' || $sub_next === null ) ) {
			$sub_next = $due ?: gmdate( 'Y-m-d', strtotime( $start . ' +30 days' ) );
		}

		update_post_meta( $id, '_wicked_invoicing_sub_enabled', $sub_enabled ? 1 : 0 );
		update_post_meta( $id, '_wicked_invoicing_sub_days', $sub_days );
		update_post_meta( $id, '_wicked_invoicing_sub_next_run', $sub_next );

		// subscription mode + anchor
		$mode = null;
		if ( array_key_exists( 'subscription_mode', $params ) ) {
			$mode = (string) $params['subscription_mode'];
			$mode = in_array( $mode, array( 'days', 'anchor' ), true ) ? $mode : 'days';
			update_post_meta( $id, '_wicked_invoicing_sub_mode', $mode );
		}

		$anchor = $params['subscription_anchor_dom'] ?? null;
		if ( $anchor !== null || ( $mode ?? '' ) === 'anchor' ) {
			if ( $anchor !== null ) {
				$anchor = max( 1, min( 28, intval( $anchor ) ) );
			} else {
				$anchor = intval( get_post_meta( $id, '_wicked_invoicing_sub_anchor_dom', true ) );
				$anchor = ( $anchor >= 1 && $anchor <= 28 ) ? $anchor : null;
			}

			$effective_mode = $mode ?? (string) get_post_meta( $id, '_wicked_invoicing_sub_mode', true );
			if ( $effective_mode === 'anchor' && $anchor ) {
				update_post_meta( $id, '_wicked_invoicing_sub_anchor_dom', $anchor );
			} else {
				delete_post_meta( $id, '_wicked_invoicing_sub_anchor_dom' );
			}
		}

		// Generic meta map (exclude subscription_* to avoid double-writing)
		$map = array(
			'client_name'          => '_wicked_invoicing_client_name',
			'client_email'         => '_wicked_invoicing_client_email',
			'client_user_id'       => '_wicked_invoicing_client_user_id',
			'client_address'       => '_wicked_invoicing_client_address',
			'billing_address'      => '_wicked_invoicing_billing_address',
			'shipping_address'     => '_wicked_invoicing_shipping_address',
			'payment_terms'        => '_wicked_invoicing_payment_terms',
			'po_number'            => '_wicked_invoicing_po_number',
			'reference_number'     => '_wicked_invoicing_reference_number',
			'line_items'           => '_wicked_invoicing_line_items',
			'subtotal'             => '_wicked_invoicing_subtotal',
			'tax_amount'           => '_wicked_invoicing_tax_amount',
			'discount_amount'      => '_wicked_invoicing_discount_amount',
			'total'                => '_wicked_invoicing_total',
			'paid'                 => '_wicked_invoicing_paid',
			'notes'                => '_wicked_invoicing_notes',
			'terms_and_conditions' => '_wicked_invoicing_terms_and_conditions',
		);

		foreach ( $map as $param => $meta_key ) {
			if ( array_key_exists( $param, $params ) ) {
				update_post_meta( $id, $meta_key, $params[ $param ] );
			}
		}

		$invoice = get_post( $id );
		return rest_ensure_response(
			array(
				'id'     => $invoice->ID,
				'title'  => $invoice->post_title,
				'status' => $invoice->post_status,
				'date'   => $invoice->post_date,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Duplicate endpoint (your routes register it; these methods must exist)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * POST /invoices/{id}/duplicate
	 * Returns: { id, hash, view_url }
	 */
	public function duplicate_invoice( WP_REST_Request $request ) {
		$params = (array) $request->get_params();

		$src_id = (int) ( $params['id'] ?? 0 );
		if ( ! $src_id || ! get_post( $src_id ) ) {
			return self::rest_error( 'not_found', __( 'Source invoice not found.', 'wicked-invoicing' ), 404 );
		}

		$overrides = array(
			'title'      => $params['title'] ?? null,
			'status'     => $params['status'] ?? null,
			'start_date' => $params['start_date'] ?? null,
			'due_date'   => $params['due_date'] ?? null,
			'reset_paid' => array_key_exists( 'reset_paid', $params ) ? (bool) $params['reset_paid'] : true,
		);

		$res = $this->maybe_clone_invoice( $src_id, $overrides );
		if ( is_wp_error( $res ) ) {
			$status = ( $res->get_error_data()['status'] ?? 400 );
			return self::rest_error( $res->get_error_code(), $res->get_error_message(), $status );
		}

		return rest_ensure_response( $res );
	}

	/**
	 * Create a new invoice cloned from a source invoice.
	 *
	 * @param int   $source_id
	 * @param array $overrides
	 * @return array|\WP_Error { id, hash, view_url }
	 */
	public function maybe_clone_invoice( int $source_id, array $overrides = array() ) {
		$src = get_post( $source_id );
		if ( ! $src || $src->post_type !== self::CPT ) {
			return new \WP_Error( 'invalid_source', __( 'Invalid source invoice.', 'wicked-invoicing' ), array( 'status' => 404 ) );
		}

		$src_title  = (string) $src->post_title;
		$src_author = (int) $src->post_author;

		$src_meta = array(
			'client_name'          => get_post_meta( $source_id, '_wicked_invoicing_client_name', true ),
			'client_email'         => get_post_meta( $source_id, '_wicked_invoicing_client_email', true ),
			'client_user_id'       => get_post_meta( $source_id, '_wicked_invoicing_client_user_id', true ),
			'client_address'       => get_post_meta( $source_id, '_wicked_invoicing_client_address', true ),
			'billing_address'      => get_post_meta( $source_id, '_wicked_invoicing_billing_address', true ),
			'shipping_address'     => get_post_meta( $source_id, '_wicked_invoicing_shipping_address', true ),
			'start_date'           => get_post_meta( $source_id, '_wicked_invoicing_start_date', true ) ?: get_the_date( 'Y-m-d', $source_id ),
			'due_date'             => get_post_meta( $source_id, '_wicked_invoicing_due_date', true ),
			'payment_terms'        => get_post_meta( $source_id, '_wicked_invoicing_payment_terms', true ),
			'po_number'            => get_post_meta( $source_id, '_wicked_invoicing_po_number', true ),
			'reference_number'     => get_post_meta( $source_id, '_wicked_invoicing_reference_number', true ),
			'line_items'           => get_post_meta( $source_id, '_wicked_invoicing_line_items', true ),
			'subtotal'             => (float) get_post_meta( $source_id, '_wicked_invoicing_subtotal', true ),
			'tax_amount'           => (float) get_post_meta( $source_id, '_wicked_invoicing_tax_amount', true ),
			'discount_amount'      => (float) get_post_meta( $source_id, '_wicked_invoicing_discount_amount', true ),
			'total'                => (float) get_post_meta( $source_id, '_wicked_invoicing_total', true ),
			'paid'                 => (float) get_post_meta( $source_id, '_wicked_invoicing_paid', true ),
			'notes'                => get_post_meta( $source_id, '_wicked_invoicing_notes', true ),
			'terms_and_conditions' => get_post_meta( $source_id, '_wicked_invoicing_terms_and_conditions', true ),

			'sub_enabled'          => (bool) get_post_meta( $source_id, '_wicked_invoicing_sub_enabled', true ),
			'sub_days'             => (int) get_post_meta( $source_id, '_wicked_invoicing_sub_days', true ),
			'sub_next_run'         => (string) get_post_meta( $source_id, '_wicked_invoicing_sub_next_run', true ),
			'sub_mode'             => (string) get_post_meta( $source_id, '_wicked_invoicing_sub_mode', true ),
			'sub_anchor_dom'       => get_post_meta( $source_id, '_wicked_invoicing_sub_anchor_dom', true ),
		);

		$new_title = ( isset( $overrides['title'] ) && $overrides['title'] !== null )
			? sanitize_text_field( (string) $overrides['title'] )
			: ( $src_title !== '' ? $src_title . ' (Copy)' : 'Invoice (Copy)' );

		$new_status = ( isset( $overrides['status'] ) && $overrides['status'] !== null )
			? self::sanitize_status_slug( $overrides['status'] )
			: 'temp';

		// Validate status defensively (route already validates, but this method may be called internally)
		$valid = self::validate_status_slug( $new_status );
		if ( is_wp_error( $valid ) ) {
			$new_status = 'temp';
		}

		$reset_paid = array_key_exists( 'reset_paid', $overrides ) ? (bool) $overrides['reset_paid'] : true;

		$new_start = ( isset( $overrides['start_date'] ) && $overrides['start_date'] )
			? sanitize_text_field( (string) $overrides['start_date'] )
			: ( $src_meta['start_date'] ?: current_time( 'Y-m-d' ) );

		$new_due = ( isset( $overrides['due_date'] ) && $overrides['due_date'] )
			? sanitize_text_field( (string) $overrides['due_date'] )
			: ( $src_meta['due_date'] ?: gmdate( 'Y-m-d', strtotime( $new_start . ' +30 days' ) ) );

		// Create base invoice (your system sets unique hash)
		$res = \Wicked_Invoicing\Wicked_Controller::invoice()->create( $new_title );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( is_array( $res ) ) {
			$new_id = absint( $res['id'] ?? 0 );
			$hash   = sanitize_text_field( $res['hash'] ?? '' );
		} elseif ( is_object( $res ) ) {
			$new_id = absint( $res->id ?? 0 );
			$hash   = sanitize_text_field( $res->hash ?? '' );
		} else {
			$new_id = absint( $res );
			$hash   = '';
		}

		if ( ! $new_id ) {
			return new \WP_Error( 'clone_failed', __( 'Failed to create cloned invoice.', 'wicked-invoicing' ), array( 'status' => 500 ) );
		}

		wp_update_post(
			array(
				'ID'          => $new_id,
				'post_status' => $new_status ?: 'temp',
				'post_author' => $src_author ?: get_current_user_id(),
			)
		);

		update_post_meta( $new_id, '_wicked_invoicing_start_date', $new_start );
		update_post_meta( $new_id, '_wicked_invoicing_due_date', $new_due );

		// Copy meta
		$meta_map = array(
			'client_name'          => '_wicked_invoicing_client_name',
			'client_email'         => '_wicked_invoicing_client_email',
			'client_user_id'       => '_wicked_invoicing_client_user_id',
			'client_address'       => '_wicked_invoicing_client_address',
			'billing_address'      => '_wicked_invoicing_billing_address',
			'shipping_address'     => '_wicked_invoicing_shipping_address',
			'payment_terms'        => '_wicked_invoicing_payment_terms',
			'po_number'            => '_wicked_invoicing_po_number',
			'reference_number'     => '_wicked_invoicing_reference_number',
			'line_items'           => '_wicked_invoicing_line_items',
			'subtotal'             => '_wicked_invoicing_subtotal',
			'tax_amount'           => '_wicked_invoicing_tax_amount',
			'discount_amount'      => '_wicked_invoicing_discount_amount',
			'total'                => '_wicked_invoicing_total',
			'paid'                 => '_wicked_invoicing_paid',
			'notes'                => '_wicked_invoicing_notes',
			'terms_and_conditions' => '_wicked_invoicing_terms_and_conditions',

			'sub_enabled'          => '_wicked_invoicing_sub_enabled',
			'sub_days'             => '_wicked_invoicing_sub_days',
			'sub_next_run'         => '_wicked_invoicing_sub_next_run',
			'sub_mode'             => '_wicked_invoicing_sub_mode',
			'sub_anchor_dom'       => '_wicked_invoicing_sub_anchor_dom',
		);

		foreach ( $meta_map as $src_key => $meta_key ) {
			$val = $src_meta[ $src_key ] ?? '';

			if ( $src_key === 'paid' && $reset_paid ) {
				$val = 0.0;
			}

			// For clones, clear next_run so server logic/UI can compute a fresh date if needed
			if ( $src_key === 'sub_next_run' ) {
				$val = '';
			}

			if ( $src_key === 'sub_anchor_dom' ) {
				// only keep anchor if mode is anchor
				$mode = (string) ( $src_meta['sub_mode'] ?? '' );
				if ( $mode !== 'anchor' ) {
					delete_post_meta( $new_id, $meta_key );
					continue;
				}
			}

			update_post_meta( $new_id, $meta_key, $val );
		}

		$view_url = $hash ? \Wicked_Invoicing\Controllers\Wicked_Template_Controller::get_invoice_url( $hash ) : '';

		return array(
			'id'       => $new_id,
			'hash'     => $hash,
			'view_url' => $view_url,
		);
	}

	/**
	 * Permission: can list
	 */
	public function can_list_invoices( WP_REST_Request $request ) {
		return Wicked_Base_Controller::user_has_cap( 'view_all_invoices' )
			|| Wicked_Base_Controller::user_has_cap( 'view_own_invoices' );
	}

	/**
	 * Permission: can view single
	 */
	public function can_view_invoice( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( Wicked_Base_Controller::user_has_cap( 'view_all_invoices' )
			|| Wicked_Base_Controller::user_has_cap( 'edit_wicked_invoices' ) ) {
			return true;
		}

		if ( Wicked_Base_Controller::user_has_cap( 'view_own_invoices' ) ) {
			$post = get_post( $id );
			return $post && (int) $post->post_author === get_current_user_id();
		}

		do_action( 'wicked_invoicing_error', '[TRACE] can_view_invoice: DENIED', compact( 'id' ) );
		return false;
	}

	/**
	 * Who can create/update invoices? Also guards duplicate.
	 */
	public function can_edit_invoices( WP_REST_Request $request ) {
		if ( ! Wicked_Base_Controller::user_has_cap( 'edit_wicked_invoices' ) ) {
			return false;
		}

		$id = absint( $request->get_param( 'id' ) );
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== self::CPT ) {
				return false;
			}

			if ( Wicked_Base_Controller::user_has_cap( 'manage_wicked_invoicing' ) ) {
				return true;
			}

			return (int) $post->post_author === get_current_user_id();
		}

		return true;
	}

	private function normalize_dates_for_save( WP_REST_Request $req, $existing_start = '' ): array {
		$start = $req->get_param( 'start_date' );
		if ( empty( $start ) ) {
			$start = $existing_start ?: current_time( 'Y-m-d' );
		}

		$due = $req->get_param( 'due_date' );
		if ( empty( $due ) ) {
			$due = gmdate( 'Y-m-d', strtotime( $start . ' +30 days' ) );
		}

		return array( $start, $due );
	}

	public static function get_cpt_slug() {
		return self::CPT;
	}

	private function resolve_invoice_slug(): string {
		$slug = '';

		if ( class_exists( __NAMESPACE__ . '\\Wicked_Settings_Controller' )
			&& method_exists( Wicked_Settings_Controller::class, 'get' ) ) {
			$settings = (array) Wicked_Settings_Controller::get();
			$slug     = $settings['invoice_slug'] ?? $settings['url_invoice_slug'] ?? '';
		}

		if ( $slug === '' ) {
			$settings = get_option( 'wicked_invoicing_settings', array() );
			if ( is_array( $settings ) || is_object( $settings ) ) {
				$settings = (array) $settings;
				$slug     = $settings['invoice_slug'] ?? $settings['url_invoice_slug'] ?? '';
			}
		}

		if ( $slug === '' ) {
			$slug = get_option( 'wicked_invoicing_invoice_slug', 'invoice' );
		}

		return trim( (string) $slug, '/' );
	}
}
