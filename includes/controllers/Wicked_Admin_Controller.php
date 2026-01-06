<?php
namespace Wicked_Invoicing\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wicked_Admin_Controller
 *
 * Handles admin menu registration, asset enqueueing, and bootstrapping the SPA.
 */
class Wicked_Admin_Controller extends Wicked_Base_Controller {

	const ADMIN_POST_TEST = 'wicked_invoicing_send_test';
	const WICKED_CSS_VERSION = '1.0';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function add_admin_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && strpos( (string) $screen->id, 'wicked-invoicing' ) !== false ) {
			$classes .= ' wicked-invoicing-admin';
		}
		return $classes;
	}

	public function add_plugin_menu() {
		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Wicked Admin] Adding plugin menu and submenus' );
		}

		add_menu_page(
			esc_html__( 'Wicked Invoices', 'wicked-invoicing' ),
			esc_html__( 'Wicked Invoices', 'wicked-invoicing' ),
			'edit_wicked_invoices',
			'wicked-invoicing-invoices',
			array( $this, 'render_invoices_page' ),
			'dashicons-media-spreadsheet',
			26
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			__( 'All Invoices', 'wicked-invoicing' ),
			__( 'All Invoices', 'wicked-invoicing' ),
			'edit_wicked_invoices',
			'wicked-invoicing-invoices',
			array( $this, 'render_invoices_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Payments', 'wicked-invoicing' ),
			esc_html__( 'Payments', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-payments',
			array( $this, 'render_payments_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			__( 'Add New', 'wicked-invoicing' ),
			__( 'Add New', 'wicked-invoicing' ),
			'edit_wicked_invoices',
			'wicked-invoicing-invoice-new',
			array( $this, 'render_invoice_new_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			__( 'Edit Invoice', 'wicked-invoicing' ),
			__( 'Edit Invoice', 'wicked-invoicing' ),
			'edit_wicked_invoices',
			'wicked-invoicing-invoice-edit',
			array( $this, 'render_invoice_edit_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			__( 'Dashboard', 'wicked-invoicing' ),
			__( 'Dashboard', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Settings', 'wicked-invoicing' ),
			esc_html__( 'Settings', 'wicked-invoicing' ),
			'edit_wicked_settings',
			'wicked-invoicing-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Notifications', 'wicked-invoicing' ),
			esc_html__( 'Notifications', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-notifications',
			array( $this, 'render_notifications_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Security', 'wicked-invoicing' ),
			esc_html__( 'Security', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-security',
			array( $this, 'render_security_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Support', 'wicked-invoicing' ),
			esc_html__( 'Support', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-support',
			array( $this, 'render_support_page' )
		);

		add_submenu_page(
			'wicked-invoicing-invoices',
			esc_html__( 'Logs', 'wicked-invoicing' ),
			esc_html__( 'Logs', 'wicked-invoicing' ),
			'manage_wicked_invoicing',
			'wicked-invoicing-logs',
			array( $this, 'render_logs_page' )
		);

		add_action(
			'admin_head',
			function () {
				remove_submenu_page( 'wicked-invoicing-invoices', 'wicked-invoicing-invoice-edit' );
			},
			99
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( (string) $hook, 'wicked-invoicing' ) === false ) {
			return;
		}

		$page = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; no state change.
		if ( isset( $_GET['page'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
			$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		}

		$allowed = array(
			'wicked-invoicing-invoices',
			'wicked-invoicing-payments',
			'wicked-invoicing-invoice-new',
			'wicked-invoicing-invoice-edit',
			'wicked-invoicing-dashboard',
			'wicked-invoicing-settings',
			'wicked-invoicing-notifications',
			'wicked-invoicing-processors',
			'wicked-invoicing-addons',
			'wicked-invoicing-security',
			'wicked-invoicing-support',
			'wicked-invoicing-logs',
		);

		if ( ! in_array( $page, $allowed, true ) ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );

		$rest_root_query  = add_query_arg( 'rest_route', '/', home_url( '/' ) );
		$rest_root_pretty = home_url( '/wp-json' );

		$rest_nonce = wp_create_nonce( 'wp_rest' );

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'try{wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(%s));wp.apiFetch.use(wp.apiFetch.createRootURLMiddleware(%s));}catch(e){}',
				wp_json_encode( $rest_nonce ),
				wp_json_encode( untrailingslashit( $rest_root_query ) . '/' )
			),
			'after'
		);

		wp_add_inline_script(
			'wp-api-fetch',
			'(function(){
				const pretty = ' . wp_json_encode( rtrim( $rest_root_pretty, '/' ) ) . ';
				const queryR = ' . wp_json_encode( rtrim( $rest_root_query, '/' ) ) . ';

				function setRoot(root){
					try {
						const normalized = (root || "").replace(/\/$/, "");
						if (window.wp && wp.apiFetch && wp.apiFetch.createRootURLMiddleware) {
							wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware(normalized + "/") );
						}
						if (window.wickedAdminData) {
							window.wickedAdminData.restUrl = normalized;
							window.wickedAdminData.__restRootSet = true;
						}
					} catch(e) {}
				}

				(async function(){
					try {
						const res = await fetch(pretty + "/", { method:"GET", credentials:"same-origin", redirect:"manual" });
						const ct  = (res && res.headers) ? (res.headers.get("content-type") || "") : "";
						const okJson = !!res && res.status === 200 && ct.toLowerCase().includes("application/json");
						setRoot(okJson ? pretty : queryR);
					} catch(e) {
						setRoot(queryR);
					}
				})();
			})();',
			'after'
		);

		wp_enqueue_editor();
		wp_enqueue_media();

		$rel = 'includes/assets/admin-style.css';
		$abs = plugin_dir_path( WICKED_INV_PLUGIN_FILE ) . $rel;
		$url = plugins_url( $rel, WICKED_INV_PLUGIN_FILE );

		wp_enqueue_style(
			'wicked-invoicing-admin',
			$url,
			array(),
			file_exists( $abs ) ? filemtime( $abs ) : null
		);

		$build_dir = WICKED_INV_PLUGIN_PATH . 'views/admin/build/';
		$build_url = plugin_dir_url( WICKED_INV_PLUGIN_FILE ) . 'views/admin/build/';
		$manifest  = $build_dir . 'asset-manifest.json';

		if ( ! is_readable( $manifest ) ) {
			return;
		}

		$data = json_decode( file_get_contents( $manifest ), true );
		if ( empty( $data ) ) {
			return;
		}
		
		$main_css = $data['files']['main.css'] ?? '';
		$main_css = is_string( $main_css ) ? ltrim( $main_css, '/' ) : '';

		if ( '' !== $main_css ) {
			$css_path = $build_dir . $main_css;
			$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : self::WICKED_CSS_VERSION;

			wp_enqueue_style(
				'wicked-invoicing-admin-react-style',
				$build_url . $main_css,
				array(),
				$css_ver
			);
		}

		$entries = array();
		if ( ! empty( $data['entrypoints'] ) && is_array( $data['entrypoints'] ) ) {
			$entries = array_values(
				array_filter(
					$data['entrypoints'],
					function ( $p ) {
						return is_string( $p ) && str_ends_with( $p, '.js' );
					}
				)
			);
		} elseif ( ! empty( $data['files'] ) && is_array( $data['files'] ) ) {
			foreach ( $data['files'] as $path ) {
				if ( is_string( $path ) && preg_match( '#^/static/js/.+\.js$#', $path ) ) {
					$entries[] = $path;
				}
			}
		}

		$primary_handle = 'wicked-invoicing-admin-react';
		$last_handle    = null;

		foreach ( $entries as $i => $reljs ) {
			$reljs_clean = is_string( $reljs ) ? ltrim( $reljs, '/' ) : '';
			if ( '' === $reljs_clean ) {
				continue;
			}

			$src     = $build_url . $reljs_clean;
			$is_main = ( str_contains( $reljs_clean, 'main.' ) || $i === count( $entries ) - 1 );
			$handle  = $is_main ? $primary_handle : 'wicked-invoicing-admin-chunk-' . $i;

			$js_path = $build_dir . $reljs_clean;
			$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : self::WICKED_CSS_VERSION;

			wp_enqueue_script( $handle, $src, array( 'wp-api-fetch' ), $js_ver, true );
			$last_handle = $handle;
		}

		if ( $last_handle ) {
			$settings        = get_option( 'wicked_invoicing_settings', array() );
			$invoice_slug    = sanitize_key( $settings['invoice_slug'] ?? 'wicked-invoicing' );
			$caps_list       = array( 'manage_wicked_invoicing', 'edit_wicked_settings', 'edit_wicked_invoices', 'view_all_invoices', 'view_own_invoices' );
			// Build the front-end capabilities list from the Wicked role matrix
			// (user_has_cap) instead of raw WP role caps (current_user_can).
			$user_caps       = array_values(
				array_filter(
					array_map(
						function ( $c ) {
							return self::user_has_cap( $c ) ? $c : false;
						},
						$caps_list
					)
				)
			);

			$current_user_id = get_current_user_id();
			$super_admin_id  = absint( $settings['super_admin'] ?? 0 );
			$statuses_map    = \Wicked_Invoicing\Controllers\Wicked_Base_Controller::get_invoice_status_map();

			$is_pro_active = (bool) (
				( defined( 'WICKED_INV_PRO' ) && WICKED_INV_PRO ) ||
				( defined( 'WICKED_INV_PRO_ACTIVE' ) && WICKED_INV_PRO_ACTIVE ) ||
				apply_filters( 'wicked_invoicing_pro_active', false )
			);

			wp_localize_script(
				$primary_handle,
				'wickedAdminData',
				array(
					'restUrl'           => esc_url_raw( untrailingslashit( $rest_root_query ) ),
					'namespace'         => 'wicked-invoicing/v1',
					'nonce'             => $rest_nonce,
					'invoiceSlug'       => $invoice_slug,
					'debugEnabled'      => (bool) ( $settings['debug_enabled'] ?? false ),
					'userCapabilities'  => $user_caps,
					'currentUserId'     => $current_user_id,
					'currentSuperAdmin' => $super_admin_id,
					'statuses'          => $statuses_map,
					'notificationsUrl'  => admin_url( 'admin.php?page=wicked-invoicing-notifications' ),
					'adminPostUrl'      => admin_url( 'admin-post.php' ),
					'notifTestNonce'    => wp_create_nonce( 'wicked_invoicing_notif_test' ),
					'notifTestAction'   => \Wicked_Invoicing\Controllers\Wicked_Notifications_Controller::ADMIN_POST_TEST,
					'proUpsellUrl'      => $is_pro_active ? '' : 'https://wickedinvoicing.com/features',
					'hasLicense'        => (bool) apply_filters( 'wicked_invoicing_has_valid_license', ( defined( 'WICKED_INVOICING_PRO' ) && WICKED_INVOICING_PRO ) || class_exists( \Wicked_Invoicing_Pro::class ) ),
					'activeBundles'     => get_option( 'wicked_invoicing_active_bundles', array() ),
				)
			);

			wp_add_inline_script(
				$primary_handle,
				'(function(){
					try {
						if (window.wp && wp.apiFetch && wp.apiFetch.createRootURLMiddleware) {
							if (!window.wickedAdminData || !window.wickedAdminData.__restRootSet) {
								var root=' . wp_json_encode( untrailingslashit( $rest_root_query ) ) . ';
								root = (root || "").replace(/\/$/, "");
								wp.apiFetch.use(wp.apiFetch.createRootURLMiddleware(root + "/"));
								if (window.wickedAdminData) window.wickedAdminData.restUrl = root;
							}
						}
					} catch(e){}
				})();',
				'before'
			);
		}
	}

	public function render_invoice_edit_page() {
		if ( ! current_user_can( 'edit_wicked_invoices' ) ) {
			do_action( 'wicked_invoicing_error', '[Wicked Admin] render_invoice_edit_page: access denied', array( 'user' => get_current_user_id() ) );
			wp_die(
				esc_html__( 'You do not have permission to edit invoices.', 'wicked-invoicing' ),
				esc_html__( 'Access Denied', 'wicked-invoicing' ),
				array( 'response' => 403 )
			);
		}

		$invoice_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; no state change.
		if ( isset( $_GET['invoice_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
			$invoice_id = absint( wp_unslash( $_GET['invoice_id'] ) );
		}

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Wicked Admin] render_invoice_edit_page: invoice_id=' . $invoice_id, array() );
		}

		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin" data-active-tab="invoice-edit" data-invoice-id="' . esc_attr( $invoice_id ) . '"></div>';

		$desired = ( $invoice_id > 0 ) ? '#/invoice-edit?invoice_id=' . $invoice_id : '#/invoices';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(!location.hash||location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_payments_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'location.hash = "#/payments";',
			'after'
		);
	}

	public function render_dashboard_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/dashboard';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_invoices_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$hash = '#/invoices';

		$action     = '';
		$invoice_id = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; no state change.
		if ( isset( $_GET['action'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value; no state change.
		if ( isset( $_GET['invoice_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
			$invoice_id = absint( wp_unslash( $_GET['invoice_id'] ) );
		}

		if ( 'edit' === $action && $invoice_id > 0 ) {
			$hash = '#/invoice-edit?invoice_id=' . $invoice_id;
		}

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $hash ) . ';if(!location.hash){location.hash=desired;return;}if(desired.indexOf("#/invoice-edit")===0&&location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_invoice_new_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/invoice-new';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'before'
		);
	}
	
	public function render_settings_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/settings';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_notifications_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/notifications';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_addons_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/add-ons';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_security_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/security';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}


	public function render_support_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/support';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}

	public function render_logs_page() {
		echo '<div id="wicked-invoicing-admin-app" class="wicked-invoicing-admin"></div>';

		$desired = '#/logs';

		wp_add_inline_script(
			'wicked-invoicing-admin-react',
			'(function(){var desired=' . wp_json_encode( $desired ) . ';if(location.hash!==desired){location.hash=desired;}})();',
			'after'
		);
	}
}
