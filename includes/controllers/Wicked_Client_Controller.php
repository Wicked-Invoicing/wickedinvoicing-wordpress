<?php
/**
 * Wicked Client Controller
 * - Adds fields to Users -> Edit User
 * - Saves user meta
 * - Provides REST endpoints for client search/create/update
 * - Helper to create-or-get a Wicked Client user
 *
 * @package WickedInvoicing
 */
namespace Wicked_Invoicing\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Client_Controller extends Wicked_Base_Controller {

	const META_COMPANY_NAME  = '_wkd_company_name';
	const META_COMPANY_PHONE = '_wkd_company_phone';
	const META_CC            = '_wkd_cc';   // array of emails
	const META_BCC           = '_wkd_bcc';  // array of emails

	const REST_NS = 'wicked-invoicing/v1';

	// Nonce for profile save
	const PROFILE_NONCE_ACTION = 'wicked_invoicing_client_profile_save';
	const PROFILE_NONCE_NAME   = 'wicked_invoicing_client_profile_nonce';

	public function __construct() {
		// Profile fields
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_section' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_section' ) );

		// REST API for client search/create/update
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/*
	----------------------------
	 * Profile UI
	 * ---------------------------- */
	public function render_profile_section( \WP_User $user ) {
		$company = (string) get_user_meta( $user->ID, self::META_COMPANY_NAME, true );
		$phone   = (string) get_user_meta( $user->ID, self::META_COMPANY_PHONE, true );
		$cc      = (array) get_user_meta( $user->ID, self::META_CC, true );
		$bcc     = (array) get_user_meta( $user->ID, self::META_BCC, true );

		// Build raw strings; escape at output (PHPCS-friendly)
		$cc_str  = implode( ', ', array_filter( array_map( 'trim', array_map( 'strval', $cc ) ) ) );
		$bcc_str = implode( ', ', array_filter( array_map( 'trim', array_map( 'strval', $bcc ) ) ) );

		$invoices_url = admin_url( 'admin.php?page=wicked-invoicing-invoices&client=' . (int) $user->ID );
		?>
		<h2><?php esc_html_e( 'Wicked Invoicing', 'wicked-invoicing' ); ?></h2>

		<?php wp_nonce_field( self::PROFILE_NONCE_ACTION, self::PROFILE_NONCE_NAME ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wkd_company_name"><?php esc_html_e( 'Company Name', 'wicked-invoicing' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wkd_company_name" name="wkd_company_name"
							value="<?php echo esc_attr( $company ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="wkd_company_phone"><?php esc_html_e( 'Company Phone', 'wicked-invoicing' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wkd_company_phone" name="wkd_company_phone"
							value="<?php echo esc_attr( $phone ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="wkd_cc"><?php esc_html_e( 'CC (comma-separated emails)', 'wicked-invoicing' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wkd_cc" name="wkd_cc"
							placeholder="name@example.com, other@example.com"
							value="<?php echo esc_attr( $cc_str ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="wkd_bcc"><?php esc_html_e( 'BCC (comma-separated emails)', 'wicked-invoicing' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wkd_bcc" name="wkd_bcc"
							placeholder="name@example.com, other@example.com"
							value="<?php echo esc_attr( $bcc_str ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Invoices', 'wicked-invoicing' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( $invoices_url ); ?>">
						<?php esc_html_e( 'View invoices for this client', 'wicked-invoicing' ); ?>
					</a>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_section( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Nonce verification (fixes Missing nonce verification warnings)
		$nonce = '';
		if ( isset( $_POST[ self::PROFILE_NONCE_NAME ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::PROFILE_NONCE_NAME ] ) );
		}

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::PROFILE_NONCE_ACTION ) ) {
			return;
		}

		$company = isset( $_POST['wkd_company_name'] )
			? sanitize_text_field( wp_unslash( $_POST['wkd_company_name'] ) )
			: '';

		$phone = isset( $_POST['wkd_company_phone'] )
			? sanitize_text_field( wp_unslash( $_POST['wkd_company_phone'] ) )
			: '';

		// Sanitize the raw strings before parsing (fixes “InputNotSanitized” warnings)
		$cc_raw  = isset( $_POST['wkd_cc'] ) ? sanitize_text_field( wp_unslash( $_POST['wkd_cc'] ) ) : '';
		$bcc_raw = isset( $_POST['wkd_bcc'] ) ? sanitize_text_field( wp_unslash( $_POST['wkd_bcc'] ) ) : '';

		$cc  = $this->parse_email_list( $cc_raw );
		$bcc = $this->parse_email_list( $bcc_raw );

		update_user_meta( $user_id, self::META_COMPANY_NAME, $company );
		update_user_meta( $user_id, self::META_COMPANY_PHONE, $phone );
		update_user_meta( $user_id, self::META_CC, $cc );
		update_user_meta( $user_id, self::META_BCC, $bcc );

		// Ensure wicked_client role exists on the user (non-destructive)
		if ( $user = get_userdata( $user_id ) ) {
			if ( ! in_array( 'wicked_client', (array) $user->roles, true ) ) {
				$user->add_role( 'wicked_client' );
			}
		}
	}

	/**
	 * Accepts string "a@b.com, c@d.com" OR array ["a@b.com","c@d.com"].
	 */
	private function parse_email_list( $raw ): array {
		$parts = array();

		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[,\n]+/', (string) $raw );
		}

		$parts = array_map( 'trim', array_map( 'strval', $parts ) );
		$parts = array_filter(
			$parts,
			static function ( $email ) {
				return $email !== '' && is_email( $email );
			}
		);

		$parts = array_values( array_unique( $parts ) );
		return $parts;
	}

	/*
	----------------------------
	 * REST: /clients
	 * ---------------------------- */
	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/clients',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_search_clients' ),
					'permission_callback' => array( $this, 'can_manage_clients' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_create_client' ),
					'permission_callback' => array( $this, 'can_manage_clients' ),
					'args'                => $this->get_client_write_schema(),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/clients/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_client' ),
					'permission_callback' => array( $this, 'can_manage_clients' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE, // PATCH/PUT
					'callback'            => array( $this, 'rest_update_client' ),
					'permission_callback' => array( $this, 'can_manage_clients' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'type'              => 'integer',
								'required'          => true,
								'sanitize_callback' => 'absint',
							),
						),
						$this->get_client_write_schema( false ) // email not required on update
					),
				),
			)
		);
	}

	public function can_manage_clients() {
		return current_user_can( 'edit_wicked_invoices' ) || current_user_can( 'manage_wicked_invoicing' );
	}

	/**
	 * REST args schema for create/update.
	 * If $require_email is true, email is required (create).
	 */
	private function get_client_write_schema( bool $require_email = true ): array {
		return array(
			'email'         => array(
				'required'          => $require_email,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $value ) use ( $require_email ) {
					if ( ! $require_email && ( $value === null || $value === '' ) ) {
						return true;
					}
					return ( $value === '' || is_email( $value ) )
						? true
						: new \WP_Error(
							'invalid_email',
							__( 'Please provide a valid email address.', 'wicked-invoicing' ),
							array(
								'status'    => 400,
								'parameter' => 'email',
							)
						);
				},
			),
			'first_name'    => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'     => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company_name'  => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company_phone' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'cc'            => array(
				'required' => false,
				'type'     => array( 'array', 'string' ),
			),
			'bcc'           => array(
				'required' => false,
				'type'     => array( 'array', 'string' ),
			),
		);
	}

	public function rest_search_clients( \WP_REST_Request $req ) {
		$s        = (string) $req->get_param( 'search' );
		$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $req->get_param( 'page' ) );

		// Who counts as a "client" in your UI search
		$role_in = array( 'wicked_client', 'subscriber', 'customer', 'administrator', 'editor', 'author', 'contributor' );

		if ( '' === $s ) {
			$q = new \WP_User_Query(
				array(
					'number'   => $per_page,
					'offset'   => ( $page - 1 ) * $per_page,
					'fields'   => 'ID',
					'role__in' => $role_in,
				)
			);

			$ids   = (array) $q->get_results();
			$total = (int) $q->get_total();
		} else {
			$s = sanitize_text_field( $s );

			// A) match user fields (login, email, display_name)
			$q1   = new \WP_User_Query(
				array(
					'number'         => -1,
					'search'         => '*' . $s . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'role__in'       => $role_in,
				)
			);
			$ids1 = (array) $q1->get_results();

			// B) match company meta (avoid meta_query slow-query warning)
			$q2   = new \WP_User_Query(
				array(
					'number'       => -1,
					'fields'       => 'ID',
					'role__in'     => $role_in,
					'meta_key'     => self::META_COMPANY_NAME,
					'meta_value'   => $s,
					'meta_compare' => 'LIKE',
				)
			);
			$ids2 = (array) $q2->get_results();

			$merged = array_values( array_unique( array_merge( $ids1, $ids2 ) ) );
			$total  = count( $merged );
			$offset = ( $page - 1 ) * $per_page;
			$ids    = array_slice( $merged, $offset, $per_page );
		}

		$users = array_map( fn( $id ) => $this->client_payload( (int) $id ), $ids );

		return rest_ensure_response(
			array(
				'results'  => $users,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	public function rest_create_client( \WP_REST_Request $req ) {
		// IMPORTANT: use get_params() to ensure REST arg sanitizers run
		$params = (array) $req->get_params();

		$email   = sanitize_email( (string) ( $params['email'] ?? '' ) );
		$first   = sanitize_text_field( (string) ( $params['first_name'] ?? '' ) );
		$last    = sanitize_text_field( (string) ( $params['last_name'] ?? '' ) );
		$company = sanitize_text_field( (string) ( $params['company_name'] ?? '' ) );
		$phone   = sanitize_text_field( (string) ( $params['company_phone'] ?? '' ) );

		$cc  = $this->parse_email_list( $params['cc'] ?? array() );
		$bcc = $this->parse_email_list( $params['bcc'] ?? array() );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error(
				'invalid_email',
				__( 'A valid email is required to create a WordPress user.', 'wicked-invoicing' ),
				array( 'status' => 400 )
			);
		}

		$user = self::create_or_get_wicked_client(
			array(
				'email'         => $email,
				'first_name'    => $first,
				'last_name'     => $last,
				'company_name'  => $company,
				'company_phone' => $phone,
				'cc'            => $cc,
				'bcc'           => $bcc,
			)
		);

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return rest_ensure_response( $this->client_payload( (int) $user->ID ) );
	}

	public function rest_get_client( \WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! get_user_by( 'id', $id ) ) {
			return new \WP_Error( 'not_found', __( 'User not found', 'wicked-invoicing' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->client_payload( $id ) );
	}

	public function rest_update_client( \WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$u  = get_user_by( 'id', $id );

		if ( ! $u ) {
			return new \WP_Error( 'not_found', __( 'User not found', 'wicked-invoicing' ), array( 'status' => 404 ) );
		}

		// Use get_params() so sanitizers run
		$p = (array) $req->get_params();

		if ( array_key_exists( 'company_name', $p ) ) {
			update_user_meta( $id, self::META_COMPANY_NAME, sanitize_text_field( (string) $p['company_name'] ) );
		}
		if ( array_key_exists( 'company_phone', $p ) ) {
			update_user_meta( $id, self::META_COMPANY_PHONE, sanitize_text_field( (string) $p['company_phone'] ) );
		}
		if ( array_key_exists( 'cc', $p ) ) {
			update_user_meta( $id, self::META_CC, $this->parse_email_list( $p['cc'] ) );
		}
		if ( array_key_exists( 'bcc', $p ) ) {
			update_user_meta( $id, self::META_BCC, $this->parse_email_list( $p['bcc'] ) );
		}

		// Optional: allow updating email if provided (and valid)
		if ( array_key_exists( 'email', $p ) && $p['email'] !== '' && $p['email'] !== null ) {
			$new_email = sanitize_email( (string) $p['email'] );
			if ( $new_email && is_email( $new_email ) && $new_email !== $u->user_email ) {
				$exists = get_user_by( 'email', $new_email );
				if ( $exists && (int) $exists->ID !== (int) $id ) {
					return new \WP_Error(
						'email_in_use',
						__( 'That email address is already in use.', 'wicked-invoicing' ),
						array( 'status' => 400 )
					);
				}
				$res = wp_update_user(
					array(
						'ID'         => $id,
						'user_email' => $new_email,
					)
				);
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		}

		// Ensure role is present (non-destructive)
		if ( $user = get_userdata( $id ) ) {
			if ( ! in_array( 'wicked_client', (array) $user->roles, true ) ) {
				$user->add_role( 'wicked_client' );
			}
		}

		return rest_ensure_response( $this->client_payload( $id ) );
	}

	private function client_payload( int $user_id ): array {
		$u = get_user_by( 'id', $user_id );

		return array(
			'id'            => (int) $user_id,
			'display_name'  => $u ? (string) $u->display_name : '',
			'email'         => $u ? (string) $u->user_email : '',
			'company_name'  => (string) get_user_meta( $user_id, self::META_COMPANY_NAME, true ),
			'company_phone' => (string) get_user_meta( $user_id, self::META_COMPANY_PHONE, true ),
			'cc'            => (array) get_user_meta( $user_id, self::META_CC, true ),
			'bcc'           => (array) get_user_meta( $user_id, self::META_BCC, true ),
		);
	}

	/*
	----------------------------
	 * Helper: create-or-get client
	 * ---------------------------- */
	public static function create_or_get_wicked_client( array $args ) {
		$email   = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$first   = isset( $args['first_name'] ) ? sanitize_text_field( $args['first_name'] ) : '';
		$last    = isset( $args['last_name'] ) ? sanitize_text_field( $args['last_name'] ) : '';
		$company = isset( $args['company_name'] ) ? sanitize_text_field( $args['company_name'] ) : '';
		$phone   = isset( $args['company_phone'] ) ? sanitize_text_field( $args['company_phone'] ) : '';
		$cc      = isset( $args['cc'] ) ? (array) $args['cc'] : array();
		$bcc     = isset( $args['bcc'] ) ? (array) $args['bcc'] : array();

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'A valid email is required.', 'wicked-invoicing' ) );
		}

		if ( $existing = get_user_by( 'email', $email ) ) {
			$user_id = (int) $existing->ID;
		} else {
			$base = sanitize_user( current( explode( '@', $email ) ) );
			if ( empty( $base ) ) {
				$base = 'wicked_client';
			}

			$login = $base;
			$i     = 1;
			while ( username_exists( $login ) ) {
				$login = $base . '_' . $i++;
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 20, true ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ) ?: $login,
					'role'         => 'wicked_client',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		// Ensure role is added (non-destructive)
		if ( $user = get_userdata( $user_id ) ) {
			if ( ! in_array( 'wicked_client', (array) $user->roles, true ) ) {
				$user->add_role( 'wicked_client' );
			}
		}

		update_user_meta( $user_id, self::META_COMPANY_NAME, $company );
		update_user_meta( $user_id, self::META_COMPANY_PHONE, $phone );

		// sanitize email arrays
		$cc  = array_values( array_filter( array_unique( array_map( 'sanitize_email', $cc ) ), 'is_email' ) );
		$bcc = array_values( array_filter( array_unique( array_map( 'sanitize_email', $bcc ) ), 'is_email' ) );

		update_user_meta( $user_id, self::META_CC, $cc );
		update_user_meta( $user_id, self::META_BCC, $bcc );

		return get_user_by( 'id', $user_id );
	}
}
