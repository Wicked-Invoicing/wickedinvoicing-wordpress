<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Roles_Controller extends Wicked_Base_Controller {
	const REST_NS = 'wicked-invoicing/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/security-role-caps',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_role_caps' ),
				'permission_callback' => fn() => current_user_can( 'manage_wicked_invoicing' ),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/settings/security',
			array(
				'methods'             => WP_REST_Server::EDITABLE, // PATCH/PUT/POST
				'callback'            => array( $this, 'update_security_settings' ),
				'permission_callback' => fn() => current_user_can( 'manage_wicked_invoicing' ),
				'args'                => array(
					'super_admin' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'role_caps'   => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);
	}

	public function get_role_caps( WP_REST_Request $request ) {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();

		$role_caps = $opts['role_caps'] ?? array();
		$role_caps = is_array( $role_caps ) ? $role_caps : array();

		return rest_ensure_response(
			array(
				'super_admin' => absint( $opts['super_admin'] ?? 0 ),
				'role_caps'   => $role_caps,
			)
		);
	}

	public function update_security_settings( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params(); // avoids null
		$opts   = get_option( 'wicked_invoicing_settings', array() );
		$opts   = is_array( $opts ) ? $opts : array();

		if ( array_key_exists( 'super_admin', $params ) ) {
			$opts['super_admin'] = absint( $params['super_admin'] );
		}

		if ( isset( $params['role_caps'] ) && is_array( $params['role_caps'] ) ) {
			$clean_matrix = array();

			foreach ( $params['role_caps'] as $role => $caps ) {
				$role = sanitize_key( (string) $role );
				if ( $role === '' ) {
					continue;
				}

				$caps = is_array( $caps ) ? $caps : array();
				$caps = array_map( 'sanitize_key', $caps );
				$caps = array_values( array_filter( $caps, static fn( $c ) => $c !== '' ) );

				$clean_matrix[ $role ] = $caps;
			}

			$opts['role_caps'] = $clean_matrix;
		}

		update_option( 'wicked_invoicing_settings', $opts, false );

		if ( self::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Wicked_Roles_Controller] update_security_settings', array( 'opts' => $opts ) );
		}

		return rest_ensure_response(
			array(
				'super_admin' => absint( $opts['super_admin'] ?? 0 ),
				'role_caps'   => $opts['role_caps'] ?? array(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	public static function activate() {
		if ( apply_filters( 'wicked_invoicing_skip_role_capabilities', false ) ) {
			return;
		}

		self::register_roles();
		self::grant_admin_caps();

		$opts = get_option( 'wicked_invoicing_settings', array() );
		$opts = is_array( $opts ) ? $opts : array();

		if ( empty( $opts['super_admin'] ) ) {
			$opts['super_admin'] = get_current_user_id();
		}

		if ( empty( $opts['role_caps'] ) || ! is_array( $opts['role_caps'] ) ) {
			$opts['role_caps'] = array(
				'administrator'   => array( 'manage_wicked_invoicing', 'edit_wicked_settings', 'edit_wicked_invoices', 'view_all_invoices', 'view_own_invoices' ),
				'wicked_admin'    => array( 'manage_wicked_invoicing', 'edit_wicked_settings', 'edit_wicked_invoices', 'view_all_invoices', 'view_own_invoices' ),
				'wicked_employee' => array( 'edit_wicked_invoices', 'view_own_invoices' ),
				'wicked_client'   => array( 'view_own_invoices' ),
			);
		}

		update_option( 'wicked_invoicing_settings', $opts, false );

		$roles_and_caps = apply_filters(
			'wicked_invoicing_role_capabilities',
			array(
				'administrator'   => array( 'manage_wicked_invoicing', 'edit_wicked_settings', 'edit_wicked_invoices', 'view_all_invoices', 'view_own_invoices' ),
				'wicked_admin'    => array( 'manage_wicked_invoicing', 'edit_wicked_settings', 'edit_wicked_invoices', 'view_all_invoices', 'view_own_invoices' ),
				'wicked_employee' => array( 'edit_wicked_invoices', 'view_own_invoices' ),
				'wicked_client'   => array( 'view_own_invoices' ),
			)
		);

		foreach ( $roles_and_caps as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( (array) $caps as $cap ) {
				$cap = sanitize_key( (string) $cap );
				if ( $cap !== '' ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function deactivate() {
		// WP.org-friendly: do NOT remove roles/caps here.
		// If you want cleanup, do it in uninstall.php.
	}

	public static function register_roles() {
		if ( ! get_role( 'wicked_admin' ) ) {
			add_role(
				'wicked_admin',
				__( 'Wicked Admin', 'wicked-invoicing' ),
				array(
					'read'                    => true,
					'manage_wicked_invoicing' => true,
					'edit_wicked_settings'    => true,
					'edit_wicked_invoices'    => true,
					'view_all_invoices'       => true,
					'view_own_invoices'       => true,
				)
			);
		}

		if ( ! get_role( 'wicked_employee' ) ) {
			add_role(
				'wicked_employee',
				__( 'Wicked Employee', 'wicked-invoicing' ),
				array(
					'read'                 => true,
					'edit_wicked_invoices' => true,
					'view_own_invoices'    => true,
				)
			);
		}

		if ( ! get_role( 'wicked_client' ) ) {
			add_role(
				'wicked_client',
				__( 'Wicked Client', 'wicked-invoicing' ),
				array(
					'read'              => true,
					'view_own_invoices' => true,
				)
			);
		}
	}

	public static function grant_admin_caps() {
		$caps = array(
			'manage_wicked_invoicing',
			'edit_wicked_settings',
			'edit_wicked_invoices',
			'view_all_invoices',
			'view_own_invoices',
		);

		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		foreach ( $caps as $cap ) {
			$cap = sanitize_key( $cap );
			if ( $cap !== '' ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
