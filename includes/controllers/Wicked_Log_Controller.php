<?php
namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Log_Controller extends Wicked_Base_Controller {

	private static $writing = false; // prevents re-entrancy recursion

	private const CACHE_GROUP      = 'wicked_invoicing';
	private const CACHE_VER_OPTION = 'wicked_inv_log_cache_ver';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		add_action( 'wicked_invoicing_info', array( $this, 'handle_info' ), 10, 2 );
		add_action( 'wicked_invoicing_error', array( $this, 'handle_error' ), 10, 2 );
		add_action( 'wicked_invoicing_debug', array( $this, 'handle_debug' ), 10, 2 );
	}

	public function handle_info( $message, $context = array() ) {
		self::log( 'info', (string) $message, (array) $context ); }
	public function handle_error( $message, $context = array() ) {
		self::log( 'error', (string) $message, (array) $context ); }
	public function handle_debug( $message, $context = array() ) {
		self::log( 'debug', (string) $message, (array) $context ); }

	/**
	 * Returns the internal log table name (validated identifier).
	 *
	 * Note: Identifiers (table/column names) cannot be parameterized with $wpdb->prepare().
	 */
	private static function table_name(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'wicked_invoice_logs';

		// Allow only valid identifier characters.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			$table = $wpdb->prefix . 'wicked_invoice_logs';
		}

		return $table;
	}

	private static function cache_ver(): int {
		$ver = (int) get_option( self::CACHE_VER_OPTION, 1 );
		return $ver > 0 ? $ver : 1;
	}

	private static function bump_cache_ver(): void {
		$ver = self::cache_ver();
		update_option( self::CACHE_VER_OPTION, $ver + 1, false );
	}

	public function register_routes() {
		$ns = 'wicked-invoicing/v1';

		register_rest_route(
			$ns,
			'/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_logs' ),
					'permission_callback' => fn() => self::user_has_cap( 'manage_wicked_invoicing' ) || current_user_can( 'manage_wicked_invoicing' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_logs' ),
					'permission_callback' => fn() => self::user_has_cap( 'manage_wicked_invoicing' ) || current_user_can( 'manage_wicked_invoicing' ),
				),
			)
		);
	}

	public function get_logs( WP_REST_Request $req ) {
		global $wpdb;

		$table = self::table_name();

		$page     = max( 1, absint( $req->get_param( 'page' ) ) );
		$per_page = absint( $req->get_param( 'per_page' ) );
		$per_page = $per_page > 0 ? min( 200, $per_page ) : 20;

		$offset = ( $page - 1 ) * $per_page;

		$ver = self::cache_ver();

		$logs_cache_key  = "logs:v{$ver}:p{$page}:pp{$per_page}";
		$total_cache_key = "logs_total:v{$ver}";

		$logs  = wp_cache_get( $logs_cache_key, self::CACHE_GROUP );
		$total = wp_cache_get( $total_cache_key, self::CACHE_GROUP );

		if ( false === $logs ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query required.
			$logs = $wpdb->get_results(
				$wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated/escaped internal table name; placeholders used for values.
					"SELECT * FROM {$table} ORDER BY log_date DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);

			if ( ! is_array( $logs ) ) {
				$logs = array();
			}

			wp_cache_set( $logs_cache_key, $logs, self::CACHE_GROUP, 60 );
		}

		if ( false === $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name is internal + validated; identifiers can't be prepared.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			wp_cache_set( $total_cache_key, $total, self::CACHE_GROUP, 60 );
		}

		return rest_ensure_response(
			array(
				'logs'     => $logs,
				'total'    => (int) $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	public function clear_logs() {
		global $wpdb;

		$table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table maintenance required.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated/escaped internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Bust cached pages/totals by bumping version.
		self::bump_cache_ver();

		return rest_ensure_response( array( 'cleared' => true ) );
	}

	public static function is_enabled(): bool {
		// Always read live so settings changes take effect immediately.
		$opts = get_option( 'wicked_invoicing_settings', array() );
		return ! empty( $opts['debug_enabled'] );
	}

	public static function log( string $level, string $message, array $data = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( self::$writing ) {
			return; // prevent recursion if DB insert triggers hooks elsewhere
		}

		$user     = Wicked_Base_Controller::get_current_user();
		$username = $user ? ( $user->user_login ?: 'unknown' ) : 'guest';

		$level = strtolower( sanitize_key( $level ) );
		if ( ! in_array( $level, array( 'error', 'info', 'debug' ), true ) ) {
			$level = 'info';
		}

		$prefix = sprintf(
			'[Wicked %s] (%s) %s',
			strtoupper( $level ),
			$username,
			$message
		);

		self::$writing = true;
		self::write_to_db( $level, $prefix, $data );
		self::$writing = false;
	}

	private static function write_to_db( string $level, string $prefix, array $data = array() ): void {
		global $wpdb;

		$table = self::table_name();
		$msg   = $prefix . ( $data ? ' | ' . wp_json_encode( $data ) : '' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert required.
		$wpdb->insert(
			$table,
			array(
				'log_date' => current_time( 'mysql', 1 ),
				'level'    => $level,
				'message'  => $msg,
			),
			array( '%s', '%s', '%s' )
		);

		// New logs mean cached pages/totals can be stale.
		self::bump_cache_ver();
	}
}
