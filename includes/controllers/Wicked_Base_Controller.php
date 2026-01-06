<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Exception;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wicked_Base_Controller
 *
 * Provides shared helpers for the Wicked Invoicing plugin:
 * - Debug‐mode detection
 * - Centralized capability checks with super‐admin override
 * - Unified REST error throwing and logging
 */
class Wicked_Base_Controller {

	/**
	 * Check if debug logging is enabled in plugin settings.
	 *
	 * @return bool
	 */
	public static function is_debug_mode(): bool {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		// Note: settings key is "debug_enabled"
		return ! empty( $opts['debug_enabled'] );
	}

	/**
	 * Determine if the current user has a specific Wicked Invoicing capability.
	 *
	 * Checks:
	 *   1) Super‐admin override (single user ID in settings)
	 *   2) Role‐to‐cap matrix from settings
	 *
	 * Fires a `wicked_invoicing_error` log entry when debug‐mode is on.
	 *
	 * @param string $needed_cap
	 * @return bool
	 */
	public static function user_has_cap( string $needed_cap ): bool {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}

		$opts      = get_option( 'wicked_invoicing_settings', array() );
		$super_id  = absint( $opts['super_admin'] ?? 0 );
		$user_id   = absint( $user->ID );
		$role_caps = (array) ( $opts['role_caps'] ?? array() );

		// 1) Super‐admin override
		if ( $user_id === $super_id ) {
			if ( self::is_debug_mode() ) {
				self::rest_info(
					"[Wicked_Base_Controller] super_admin override for user {$user_id}",
					compact( 'user_id', 'needed_cap' )
				);
			}
			return true;
		}

		// 2) Check each role’s allowed caps
		foreach ( $user->roles as $role ) {
			$caps_for_role = (array) ( $role_caps[ $role ] ?? array() );
			if ( in_array( $needed_cap, $caps_for_role, true ) ) {
				if ( self::is_debug_mode() ) {
					do_action(
						'wicked_invoicing_error',
						"[Wicked_Base_Controller] user {$user_id} granted '{$needed_cap}' via role '{$role}'",
						compact( 'user_id', 'needed_cap', 'role' )
					);
				}
				return true;
			}
		}

		// 3) Denied
		if ( self::is_debug_mode() ) {
			do_action(
				'wicked_invoicing_error',
				"[Wicked_Base_Controller] user {$user_id} does NOT have '{$needed_cap}'",
				array(
					'user_id'    => $user_id,
					'needed_cap' => $needed_cap,
					'roles'      => $user->roles,
					'role_caps'  => $role_caps,
				)
			);
		}

		return false;
	}

	/**
	 * Log an error via Wicked_Log_Controller (wicked_invoicing_error) and throw a REST exception.
	 *
	 * @param string $code     Machine‐readable error code (e.g. 'missing_title').
	 * @param string $message  Human‐readable error message.
	 * @param int    $status   HTTP status code to return.
	 * @param array  $context  Optional context data to include in the log.
	 *
	 * @throws WP_REST_Exception
	 */
	public static function rest_error( $code, $message, $status = 400, $data = array() ) {
		if ( self::is_debug_mode() ) {
				do_action(
					'wicked_invoicing_error',
					"[REST ERROR] $code: $message",
					array(
						'status' => $status,
						'data'   => $data,
					)
				);
		}
		// Always return a WP_Error; WP REST will convert it to JSON with status
		return new \WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => (int) $status ), (array) $data )
		);
	}

	/**
	 * Log an info‐level REST message (does not throw).
	 *
	 * @param string $message Human‐readable message.
	 * @param array  $context Optional context data.
	 */
	protected static function rest_info( string $message, array $context = array() ) {
		if ( self::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', $message, $context );
		}
	}

	/**
	 * Log a debug‐level REST message (does not throw).
	 *
	 * @param string $message Human‐readable message.
	 * @param array  $context Optional context data.
	 */
	protected static function rest_debug( string $message, array $context = array() ): void {
		if ( self::is_debug_mode() ) {
			do_action( 'wicked_invoicing_debug', "[Wicked DEBUG] {$message}", $context );
		}
	}

	/**
	 * Shortcut for throwing a 404 REST exception.
	 *
	 * @param string $message Optional custom message.
	 * @throws WP_REST_Exception
	 */
	protected static function rest_not_found( string $message = 'Not Found' ) {
		return self::rest_error( 'not_found', $message, 404 );
	}


	/**
	 * Proxy for is_user_logged_in()
	 *
	 * @return bool
	 */
	public static function is_user_logged_in(): bool {
		return \function_exists( 'is_user_logged_in' ) && \is_user_logged_in();
	}

	/**
	 * Proxy for wp_get_current_user()
	 *
	 * @return \WP_User|null
	 */
	public static function get_current_user(): ?\WP_User {
		if ( self::is_user_logged_in() && \function_exists( 'wp_get_current_user' ) ) {
			return \wp_get_current_user();
		}
		return null;
	}

	/**
	 * Canonical invoice statuses from Wicked_Invoice_Controller.
	 * Returns array: [ 'slug' => 'Label', ... ]
	 */
	public static function get_invoice_status_map(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$map = array();
		$cls = '\\Wicked_Invoicing\\Controllers\\Wicked_Invoice_Controller';
		if ( class_exists( $cls ) ) {
			if ( is_callable( array( $cls, 'get_statuses' ) ) ) {
				$map = (array) $cls::get_statuses();
			} elseif ( is_callable( array( $cls, 'Get_Statuses' ) ) ) {
				$map = (array) $cls::Get_Statuses();
			}
		}

		// Normalize keys
		$norm = array();
		foreach ( $map as $slug => $label ) {
			$s          = sanitize_key( str_replace( '_', '-', (string) $slug ) );
			$norm[ $s ] = (string) $label;
		}
		if ( ! $norm ) {
			$norm = array(
				'temp'             => __( 'Temp', 'wicked-invoicing' ),
				'pending'          => __( 'Pending', 'wicked-invoicing' ),
				'deposit-required' => __( 'Deposit Required', 'wicked-invoicing' ),
				'deposit-paid'     => __( 'Deposit Paid', 'wicked-invoicing' ),
				'paid'             => __( 'Paid', 'wicked-invoicing' ),
			);
		}

		// ⬇⬇⬇ APPLY USER OVERRIDES
		$over = get_option( 'wicked_invoicing_status_labels', array() );
		if ( is_array( $over ) ) {
			foreach ( $over as $slug => $custom ) {
				$k = sanitize_key( str_replace( '_', '-', (string) $slug ) );
				$v = is_string( $custom ) ? trim( wp_strip_all_tags( $custom ) ) : '';
				if ( $v !== '' && isset( $norm[ $k ] ) ) {
					$norm[ $k ] = $v;
				}
			}
		}

		$norm  = apply_filters( 'wicked_invoicing_status_map', $norm );
		$cache = $norm;
		return $cache;
	}

	/**
	 * Map a status slug → display label (respects Settings overrides).
	 */
	public static function get_status_label( string $slug ): string {
		$map  = self::get_invoice_status_map(); // already merges overrides
		$slug = sanitize_key( str_replace( '_', '-', $slug ) );
		return $map[ $slug ] ?? $slug;
	}


	/** Convenience: just the status slugs (array of strings). */
	public static function get_invoice_status_slugs(): array {
		return array_keys( self::get_invoice_status_map() );
	}

	/**
	 * Bucket map: logical status => concrete post_status[] for queries.
	 * By default, "temp" also includes WordPress drafts while editing.
	 * Always includes identity mapping for every known slug.
	 */
	public static function get_bucket_status_map(): array {
		$slugs = self::get_invoice_status_slugs();

		$map = array(
			// temp bucket should surface new/draft invoices in metrics/activity
			'temp' => array_values( array_unique( array_filter( array( 'temp', 'draft', 'auto-draft' ) ) ) ),
		);

		// Ensure identity mapping for all known slugs
		foreach ( $slugs as $s ) {
			if ( ! isset( $map[ $s ] ) ) {
				$map[ $s ] = array( $s );
			}
		}

		/**
		 * Filter the bucket map.
		 *
		 * @param array $map   e.g. ['temp' => ['temp','draft','auto-draft'], 'pending' => ['pending'], ...]
		 * @param array $slugs all known status slugs
		 */
		return apply_filters( 'wicked_invoicing_bucket_status_map', $map, $slugs );
	}

	/** Expand a single logical status to concrete post_status[] for WP_Query/SQL. */
	public static function expand_status_for_query( string $status, ?array $bucketMap = null ): array {
		$bucketMap = $bucketMap ?? self::get_bucket_status_map();
		return $bucketMap[ $status ] ?? array( $status );
	}

	/** Expand many logical statuses to unique concrete post_status[] for WP_Query/SQL. */
	public static function expand_statuses_for_query( array $statuses, ?array $bucketMap = null ): array {
		$bucketMap = $bucketMap ?? self::get_bucket_status_map();
		$out       = array();
		foreach ( $statuses as $s ) {
			foreach ( $bucketMap[ $s ] ?? array( $s ) as $x ) {
				$out[] = $x;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Which statuses should count as "overdue-eligible" (i.e., not fully paid)?
	 * Default: every known status except 'paid'.
	 */
	public static function get_overdue_statuses(): array {
		$slugs = self::get_invoice_status_slugs();
		$def   = array_values( array_diff( $slugs, array( 'paid' ) ) );
		return apply_filters( 'wicked_invoicing_overdue_statuses', $def, $slugs );
	}

	/**
	 * Identify deposit-related statuses.
	 * Returns array [ requiredStatuses[], paidStatuses[] ].
	 * Defaults are detected by slug names but are filterable.
	 */
	public static function get_deposit_status_sets(): array {
		$slugs = self::get_invoice_status_slugs();

		// Naive detection by conventional slugs; sites can override via filters.
		$required = array_values( array_intersect( $slugs, array( 'deposit-required' ) ) );
		$paid     = array_values( array_intersect( $slugs, array( 'deposit-paid' ) ) );

		$required = apply_filters( 'wicked_invoicing_deposit_required_statuses', $required, $slugs );
		$paid     = apply_filters( 'wicked_invoicing_deposit_paid_statuses', $paid, $slugs );

		return array( $required, $paid );
	}

	/**
	 * Given an array keyed by status (e.g., counts), also add underscore aliases
	 * so 'deposit-paid' is available as 'deposit_paid' for front-end convenience.
	 */
	public static function with_status_aliases( array $by_status ): array {
		$out = $by_status;
		foreach ( $by_status as $k => $v ) {
			if ( strpos( $k, '-' ) !== false ) {
				$out[ str_replace( '-', '_', $k ) ] = $v;
			}
		}
		return $out;
	}
}

add_filter(
	'rest_post_dispatch',
	function ( $served_response, $server, $request ) {
		/** @var WP_REST_Response|\WP_Error $served_response */
		// We only care about errors:
		$status = is_wp_error( $served_response )
		? $served_response->get_error_data()['status'] ?? 500
		: $served_response->get_status();

		if ( $status === 403 ) {
				$route  = $request->get_route();                      // e.g. /wicked-invoicing/v1/invoices/12
				$method = $request->get_method();                    // GET, POST, PATCH, …
				$params = $request->get_params();                    // includes path + query + body
				$body   = $request->get_body();                      // raw body
				$msg    = sprintf(
					'[REST 403] %s %s → params=%s body=%s',
					$method,
					$route,
					wp_json_encode( $params ),
					$body
				);
				do_action( 'wicked_invoicing_error', $msg );
		}

		return $served_response;
	},
	10,
	3
);
