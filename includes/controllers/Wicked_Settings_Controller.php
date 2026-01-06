<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wicked_Settings_Controller
 *
 * Handles plugin settings for Wicked Invoicing:
 * - Ensures default settings exist on init
 * - Exposes REST endpoints to get and update settings
 * - Flushes rewrite rules when invoice_slug changes
 */
class Wicked_Settings_Controller extends Wicked_Base_Controller {

	const OPTION_KEY       = 'wicked_invoicing_settings';
	const STATUS_LABEL_OPT = 'wicked_invoicing_status_labels';

	/**
	 * Hook into init and rest_api_init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_register_defaults' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Static getter used by other controllers.
	 */
	public static function get(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * On init, create the settings option with defaults if it doesn't exist.
	 */
	public function maybe_register_defaults() {
		$defaults = array(
			'company_name'      => 'Wicked Invoicing',
			'company_address'   => '123 Wicked St, Suite 100',
			'currency_icon'     => '$',
			'currency_format'   => '20,000.00',
			'invoice_slug'      => 'wicked-invoicing',
			'debug_enabled'     => false,
			'use_theme_wrapper' => true,
		);

		$current = get_option( self::OPTION_KEY );

		if ( false === $current ) {
			add_option( self::OPTION_KEY, $defaults );
		} else {
			$current = is_array( $current ) ? $current : array();
			$merged  = wp_parse_args( $current, $defaults );

			if ( $merged !== $current ) {
				update_option( self::OPTION_KEY, $merged, false );
			}
		}

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action(
				'wicked_invoicing_info',
				'[Settings] Defaults ensured',
				array(
					'final_settings' => get_option( self::OPTION_KEY ),
				)
			);
		}
	}

	/**
	 * Register REST API routes for GET/POST /settings.
	 */
	public function register_routes() {
		register_rest_route(
			'wicked-invoicing/v1',
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => fn() => current_user_can( 'edit_wicked_invoices' ),
					'args'                => $this->get_args_schema(),
				),
			)
		);

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Settings] REST routes registered', array() );
		}
	}

	/**
	 * GET /settings
	 */
	public function get_settings( WP_REST_Request $request ) {
		$settings = self::get();

		// Stored custom labels (if any)
		$settings['status_labels'] = get_option( self::STATUS_LABEL_OPT, array() );
		// Canonical defaults (always available)
		$settings['status_defaults'] = Wicked_Base_Controller::get_invoice_status_map();

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Settings] Current settings', $settings );
		}

		return rest_ensure_response( $settings );
	}

	/**
	 * POST /settings
	 */
	public function update_settings( WP_REST_Request $request ) {
		// IMPORTANT:
		// Use get_params() so REST arg sanitizers/validators apply.
		$params  = (array) $request->get_params();
		$current = self::get();

		// Capture previous & incoming slugs (sanitize to be safe)
		$prev_slug = sanitize_title( $current['invoice_slug'] ?? 'wicked-invoicing' );

		$incoming_slug = array_key_exists( 'invoice_slug', $params )
			? sanitize_title( (string) $params['invoice_slug'] )
			: $prev_slug;

		// Normalize empty slug back to previous/default
		$new_slug = $incoming_slug !== '' ? $incoming_slug : $prev_slug;

		// Merge settings (but force the sanitized slug)
		$updated                 = wp_parse_args( $params, $current );
		$updated['invoice_slug'] = $new_slug;

		// Save status_labels separately (and only allow known statuses)
		if ( isset( $params['status_labels'] ) && is_array( $params['status_labels'] ) ) {
			$defaults = Wicked_Base_Controller::get_invoice_status_map();
			$clean    = array();

			foreach ( $defaults as $slug => $_label ) {
				if ( isset( $params['status_labels'][ $slug ] ) ) {
					$v = trim( wp_strip_all_tags( (string) $params['status_labels'][ $slug ] ) );
					if ( $v !== '' && strlen( $v ) <= 40 ) {
						$clean[ $slug ] = $v;
					}
				}
			}

			update_option( self::STATUS_LABEL_OPT, $clean, false );
		}

		// Persist main settings
		update_option( self::OPTION_KEY, $updated, false );

		// If the slug changed, flush rewrite rules (deferred to shutdown)
		if ( $new_slug !== $prev_slug ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action(
					'wicked_invoicing_info',
					'[Settings] invoice_slug changed → flushing rewrites',
					array(
						'prev_slug' => $prev_slug,
						'new_slug'  => $new_slug,
					)
				);
			}

			add_action(
				'shutdown',
				function () {
					flush_rewrite_rules( false );
				}
			);
		}

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action(
				'wicked_invoicing_info',
				'[Settings] update_settings',
				array(
					'prev_slug' => $prev_slug,
					'new_slug'  => $new_slug,
					'updated'   => $updated,
				)
			);
		}

		return rest_ensure_response( $updated );
	}

	/**
	 * Schema for validating and sanitizing settings fields.
	 */
	private function get_args_schema(): array {
		return array(
			'company_name'      => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'company_address'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'currency_icon'     => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'currency_format'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// Use sanitize_title for permalink-y slugs
			'invoice_slug'      => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'default'           => 'wicked-invoicing',
			),
			'debug_enabled'     => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'use_theme_wrapper' => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			// Allow status_labels to be posted (we still sanitize again in update_settings)
			'status_labels'     => array(
				'required' => false,
				'type'     => 'object',
			),
		);
	}
}
