<?php

namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Notifications_Controller extends Wicked_Base_Controller {

	/** Option key where we store notification settings (free + pro-normalized). */
	const OPTION_KEY = 'wicked_invoicing_notifications';

	/** Cron hook + schedule slug */
	const CRON_HOOK     = 'wicked_invoicing_notifications_cron';
	const CRON_SCHEDULE = 'wkd_five_minutes';

	/** Meta prefix for “sent” markers to dedupe */
	const META_SENT_PREFIX = '_wi_notif_sent_';

	/** admin-post action for "Send test" */
	const ADMIN_POST_TEST = 'wicked_invoicing_send_test';

	/** REST namespace */
	const NS = 'wicked-invoicing/v1';

	public function __construct() {

		// set up rules on crons
		add_filter( 'cron_schedules', array( __CLASS__, 'add_five_minute_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron_scheduled' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_worker' ) );

		// Nudge cron when invoices change status
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_status' ), 10, 3 );

		// Nudge cron after settings save
		add_action( 'wicked_invoicing_notifications_settings_saved', array( __CLASS__, 'reschedule_now' ) );

		// Test send (admin-post)
		add_action( 'admin_post_' . self::ADMIN_POST_TEST, array( __CLASS__, 'handle_send_test' ) );

		// REST routes
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// admin action callback
		add_action( 'admin_post_wi_resend_notifications', array( __CLASS__, 'handle_resend_notifications' ) );

		add_action(
			'wicked_invoicing_Invoice_created',
			function ( $post_id ) {
				// Only nudge if initial status is not temp (optional sanity)
				$status = get_post_status( $post_id );
				if ( 'temp' !== $status ) {
					wp_schedule_single_event( time() + 30, \Wicked_Invoicing\Controllers\Wicked_Notifications_Controller::CRON_HOOK );
				}
			},
			10,
			1
		);

		add_action(
			'wicked_invoicing_status_set',
			function ( $post_id, $new_status ) {
				// Nudge after status moves to something rules might care about
				wp_schedule_single_event( time() + 30, \Wicked_Invoicing\Controllers\Wicked_Notifications_Controller::CRON_HOOK );
			},
			10,
			2
		);
	}

	/** Capability check for settings endpoints */
	public static function can_manage_notifications(): bool {
		if ( method_exists( __CLASS__, 'user_has_cap' ) ) {
			if ( self::user_has_cap( 'manage_wicked_invoicing' ) ) {
				return true;
			}
			if ( self::user_has_cap( 'edit_wicked_settings' ) ) {
				return true;
			}
		} else {
			if ( current_user_can( 'manage_wicked_invoicing' ) ) {
				return true;
			}
			if ( current_user_can( 'edit_wicked_settings' ) ) {
				return true;
			}
		}
		return is_super_admin();
	}

	/** Register /notifications/settings (GET/POST) */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/notifications/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage_notifications' ),
				'args'                => array(
					'rules' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/settings',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'rest_save_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage_notifications' ),
				'args'                => array(
					'rules' => array(
						'type'     => 'array',
						'required' => false,
					),
				),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// SETTINGS (Storage + REST)
	// ─────────────────────────────────────────────────────────────────────

	/** Raw settings array from DB */
	public static function get_settings(): array {
		$s = get_option( self::OPTION_KEY, array() );
		return is_array( $s ) ? $s : array();
	}

	/** License check → how many rules we allow */
	protected static function max_rules_by_license(): int {
		$has_license = (bool) apply_filters( 'wicked_invoicing_has_valid_license', false );
		return $has_license ? 100 : 2; // tweak cap as you wish
	}

	/** Default two built-in rules (Pending / Paid) if nothing is configured yet */
	protected static function default_rules(): array {
		return array(
			self::sanitize_rule(
				array(
					'id'       => 'free_sent',
					'enabled'  => true,
					'match'    => array(
						'type'   => 'status_equals_any',
						'values' => array( 'pending' ),
					),
					'advanced' => array(
						'enabled'         => false,
						'date_field'      => 'start_date',
						'op'              => '>',
						'days'            => 5,
						'require_deposit' => false,
					),
					'template' => array(
						'subject' => __( 'Invoice {{invoice_id}} sent', 'wicked-invoicing' ),
						'html'    => '<p>Invoice {{invoice_id}} has been sent. <a href="{{view_url}}">View</a></p>',
					),
				)
			),
			self::sanitize_rule(
				array(
					'id'       => 'free_paid',
					'enabled'  => true,
					'match'    => array(
						'type'   => 'status_equals_any',
						'values' => array( 'paid' ),
					),
					'advanced' => array(
						'enabled'         => false,
						'date_field'      => 'start_date',
						'op'              => '>',
						'days'            => 5,
						'require_deposit' => false,
					),
					'template' => array(
						'subject' => __( 'Invoice {{invoice_id}} marked paid', 'wicked-invoicing' ),
						'html'    => '<p>Invoice {{invoice_id}} is paid. Balance: ${{balance}}. <a href="{{view_url}}">View</a></p>',
					),
				)
			),
		);
	}

	public static function handle_resend_notifications() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'wicked-invoicing' ), 403 );
		}

		// Nonce check: expects ?_wpnonce=... in the request
		check_admin_referer( 'wi_resend_notifications' );

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wicked-invoicing-notifications' ) );
			exit;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wicked-invoicing-notifications' ) );
			exit;
		}

		if ( $post->post_type !== 'wicked_invoice' ) {
			wp_die( esc_html__( 'Invalid invoice.', 'wicked-invoicing' ), 400 );
		}

		self::clear_sent_flags_for_invoice( $post_id );
		wp_schedule_single_event( time() + 15, self::CRON_HOOK );

		$redirect = get_edit_post_link( $post_id, 'redirect' );
		wp_safe_redirect( $redirect ? $redirect : admin_url( 'edit.php?post_type=' . urlencode( $post->post_type ) ) );
		exit;
	}


	/** Legacy → rules[] fallback (supports your current UI while you move) */
	protected static function fallback_legacy_rules( array $s ): array {
		$rules = array();

		$rules[] = self::sanitize_rule(
			array(
				'id'       => 'free_sent',
				'enabled'  => ! empty( $s['notify_on_sent'] ),
				'match'    => array(
					'type'   => 'status_equals_any',
					'values' => array( sanitize_key( $s['sent_status'] ?? 'pending' ) ),
				),
				'advanced' => (array) ( $s['advanced']['sent'] ?? array() ),
				'template' => array(
					'subject' => (string) ( $s['templates']['sent']['subject'] ?? __( 'Invoice {{invoice_id}} sent', 'wicked-invoicing' ) ),
					'html'    => (string) ( $s['templates']['sent']['html'] ?? '<p>Invoice {{invoice_id}} has been sent. <a href="{{view_url}}">View</a></p>' ),
				),
			)
		);

		$rules[] = self::sanitize_rule(
			array(
				'id'       => 'free_paid',
				'enabled'  => ! empty( $s['notify_on_paid'] ),
				'match'    => array(
					'type'   => 'status_equals_any',
					'values' => array( sanitize_key( $s['paid_status'] ?? 'paid' ) ),
				),
				'advanced' => (array) ( $s['advanced']['paid'] ?? array() ),
				'template' => array(
					'subject' => (string) ( $s['templates']['paid']['subject'] ?? __( 'Invoice {{invoice_id}} marked paid', 'wicked-invoicing' ) ),
					'html'    => (string) ( $s['templates']['paid']['html'] ?? '<p>Invoice {{invoice_id}} is paid. Balance: ${{balance}}. <a href="{{view_url}}">View</a></p>' ),
				),
			)
		);

		return $rules;
	}

	/** GET /notifications/settings → return normalized settings with rules[] */
	public static function rest_get_settings( WP_REST_Request $r ) {
		$settings  = self::get_settings();
		$has_rules = isset( $settings['rules'] ) && is_array( $settings['rules'] );
		$max_rules = self::max_rules_by_license();

		if ( $has_rules ) {
			$rules = array_values( array_filter( array_map( array( __CLASS__, 'sanitize_rule' ), $settings['rules'] ) ) );
			if ( empty( $rules ) ) {
				$rules = self::default_rules();
			}
		} else {
			// Legacy → synth two rules so your existing UI still works
			$rules = self::fallback_legacy_rules( $settings );
		}

		// Enforce cap (also enforced on POST)
		if ( count( $rules ) > $max_rules ) {
			$rules = array_slice( $rules, 0, $max_rules );
		}

		return rest_ensure_response(
			array(
				'rules'     => $rules,
				'max_rules' => $max_rules,
			)
		);
	}

	/** POST /notifications/settings → accept { rules: [...] } */
	public static function rest_save_settings( WP_REST_Request $r ) {
		$payload = $r->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		// New model: rules[]
		$raw_rules = isset( $payload['rules'] ) && is_array( $payload['rules'] ) ? $payload['rules'] : array();

		// If someone posts legacy fields, migrate them
		if ( empty( $raw_rules ) && ( isset( $payload['notify_on_sent'] ) || isset( $payload['notify_on_paid'] ) ) ) {
			$raw_rules = self::fallback_legacy_rules( $payload );
		}

		$rules = array_values( array_filter( array_map( array( __CLASS__, 'sanitize_rule' ), $raw_rules ) ) );

		$max_rules = self::max_rules_by_license();
		if ( count( $rules ) > $max_rules ) {
			$rules = array_slice( $rules, 0, $max_rules );
		}

		// Persist only the new shape
		$settings          = self::get_settings();
		$settings['rules'] = $rules;

		update_option( self::OPTION_KEY, $settings, false );
		do_action( 'wicked_invoicing_notifications_settings_saved', $settings );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'rules'     => $rules,
				'max_rules' => $max_rules,
			)
		);
	}

	/** Sanitize/normalize a rule; return null to drop invalid rules */
	protected static function sanitize_rule( $r ): ?array {
		if ( ! is_array( $r ) ) {
			return null;
		}

		$id = sanitize_key( $r['id'] ?? '' );
		if ( ! $id ) {
			$id = 'rule_' . wp_generate_password( 8, false, false );
		}

		$enabled = ! empty( $r['enabled'] );

		$match = (array) ( $r['match'] ?? array() );
		if ( ( $match['type'] ?? '' ) !== 'status_equals_any' ) {
			return null;
		}

		$values = array_values(
			array_unique(
				array_map(
					fn( $s ) => sanitize_key( str_replace( '_', '-', (string) $s ) ),
					(array) ( $match['values'] ?? array() )
				)
			)
		);
		if ( empty( $values ) ) {
			return null;
		}

		$adv  = (array) ( $r['advanced'] ?? array() );
		$tmpl = (array) ( $r['template'] ?? array() );

		$subject = (string) ( $tmpl['subject'] ?? __( 'Invoice Update', 'wicked-invoicing' ) );
		$subject = wp_strip_all_tags( $subject );

		$html = (string) ( $tmpl['html'] ?? '<p>{{invoice_title}} status is {{status}}.</p>' );
		$html = wp_kses_post( $html );

		// Normalize advanced settings WITHOUT ever reading missing keys
		$date_field = (string) ( $adv['date_field'] ?? 'start_date' );
		if ( ! in_array( $date_field, array( 'start_date', 'due_date', 'post_date' ), true ) ) {
			$date_field = 'start_date';
		}

		$op = (string) ( $adv['op'] ?? '>' );
		if ( ! in_array( $op, array( '>', '<' ), true ) ) {
			$op = '>';
		}

		$days = max( 0, (int) ( $adv['days'] ?? 0 ) );

		return array(
			'id'       => $id,
			'enabled'  => $enabled,
			'match'    => array(
				'type'   => 'status_equals_any',
				'values' => $values,
			),
			'advanced' => array(
				'enabled'         => ! empty( $adv['enabled'] ),
				'date_field'      => $date_field,
				'op'              => $op,
				'days'            => $days,
				'require_deposit' => ! empty( $adv['require_deposit'] ),
			),
			'template' => array(
				'subject' => $subject,
				'html'    => $html,
			),
		);
	}


	// ─────────────────────────────────────────────────────────────────────
	// ADMIN-POST: Send Test
	// ─────────────────────────────────────────────────────────────────────

	/** Sends test to current user using selected rule (or legacy 'sent'/'paid') */
	public static function handle_send_test() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to send a test.', 'wicked-invoicing' ), 403 );
		}
		check_admin_referer( 'wkd_notif_test' );

		$rule_id    = sanitize_key( $_POST['rule_id'] ?? '' );
		$legacy     = sanitize_key( $_POST['rule'] ?? '' ); // 'sent'|'paid'
		$invoice_id = absint( $_POST['invoice_id'] ?? 0 );
		$redirect = '';
		if ( isset( $_POST['redirect_to'] ) ) {
			$redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
		}

		$user = wp_get_current_user();
		if ( ! $user || ! is_email( $user->user_email ) ) {
			wp_die( esc_html__( 'Your account has no valid email address.', 'wicked-invoicing' ), 400 );
		}

		// Load rules (new or legacy)
		$settings = self::get_settings();
		$rules    = isset( $settings['rules'] ) && is_array( $settings['rules'] )
			? array_values( array_filter( array_map( array( __CLASS__, 'sanitize_rule' ), $settings['rules'] ) ) )
			: self::fallback_legacy_rules( $settings );

		$rule = null;

		if ( $rule_id ) {
			foreach ( $rules as $r ) {
				if ( isset( $r['id'] ) && $r['id'] === $rule_id ) {
					$rule = $r;
					break; }
			}
		}

		if ( ! $rule && in_array( $legacy, array( 'sent', 'paid' ), true ) ) {
			// Pick first rule that has matching status
			$want = ( $legacy === 'paid' ) ? 'paid' : 'pending';
			foreach ( $rules as $r ) {
				$status = $r['match']['values'][0] ?? '';
				if ( $status === $want ) {
					$rule = $r;
					break; }
			}
		}

		// If nothing found, fall back to defaults
		if ( ! $rule ) {
			$rule = self::default_rules()[0];
		}

		// Use a recent invoice if not provided
		if ( ! $invoice_id ) {
			$q          = new \WP_Query(
				array(
					'post_type'      => 'wicked_invoice',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$invoice_id = (int) ( $q->posts[0] ?? 0 );
			wp_reset_postdata();
		}

		$tokens = $invoice_id ? self::build_tokens( $invoice_id ) : array(
			'invoice_id'    => '0',
			'invoice_title' => 'Test',
			'status'        => 'Test',
			'view_url'      => home_url( '/' ),
			'total'         => '0.00',
			'paid'          => '0.00',
			'balance'       => '0.00',
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url( '/' ),
			'date'          => wp_date( 'Y-m-d H:i' ),
		);

		$subject = self::render_template( (string) $rule['template']['subject'], $tokens );
		$html    = self::render_template( (string) $rule['template']['html'], $tokens );

		// Test mails go to current user (not the client)
		$ok = wp_mail( $user->user_email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

		// For local dev, allow override/force
		$ok = apply_filters( 'wicked_invoicing_force_test_ok', $ok, $user );

		if ( self::is_debug_mode() ) {
			if ( ! $ok ) {
				$ok = true;
			}

			$context = array(
				'ok'         => $ok,
				'user'       => $user->user_email,
				'rule'       => ( $rule['id'] ?? 'n/a' ),
				'invoice_id' => $invoice_id,
			);

			if ( $ok ) {
				do_action( 'wicked_invoicing_info', '[Notifications] Test send', $context );
			} else {
				do_action( 'wicked_invoicing_error', '[Notifications] Test send', $context );
			}
		}

		$default_back = admin_url( 'admin.php?page=wicked-invoicing' ) . '#/notifications';
		$back         = $redirect ?: wp_get_referer() ?: $default_back;
		$back         = add_query_arg(
			array(
				'wkd_test' => $ok ? '1' : '0',
				'rule'     => ( $rule['id'] ?? 'n/a' ),
			),
			$back
		);
		$back         = wp_sanitize_redirect( $back );
		wp_safe_redirect( $back );
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────
	// CRON SCHEDULE
	// ─────────────────────────────────────────────────────────────────────

	public static function add_five_minute_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * 60,
			'display'  => __( 'Every Five Minutes (Wicked Invoicing)', 'wicked-invoicing' ),
		);
		return $schedules;
	}

	public static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/** When settings change, kick a single run soon. */
	public static function reschedule_now() {
		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
	}

	public static function on_transition_status( $new_status, $old_status, $post ) {
		if ( $post->post_type !== 'wicked_invoice' || $new_status === $old_status ) {
			return;
		}
		wp_schedule_single_event( time() + 60, self::CRON_HOOK );
	}

	// In Wicked_Notifications_Controller
	public static function clear_sent_flags_for_invoice( $post_id ) {
		$settings = self::get_settings();

		// Pull raw stored rules without applying license caps.
		$stored_rules = array();
		if ( isset( $settings['rules'] ) && is_array( $settings['rules'] ) ) {
			$stored_rules = $settings['rules'];
		} else {
			// Legacy fallback: synth rules from legacy settings.
			$stored_rules = self::fallback_legacy_rules( $settings );
		}

		foreach ( $stored_rules as $rule ) {
			if ( empty( $rule['id'] ) ) {
				continue;
			}
			delete_post_meta( $post_id, self::META_SENT_PREFIX . sanitize_key( (string) $rule['id'] ) );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// RULES → Worker
	// ─────────────────────────────────────────────────────────────────────

	/** Return normalized rules from settings (with cap applied) */
	public static function get_rules(): array {
		$settings  = self::get_settings();
		$max_rules = self::max_rules_by_license();

		$rules = isset( $settings['rules'] ) && is_array( $settings['rules'] )
			? array_values( array_filter( array_map( array( __CLASS__, 'sanitize_rule' ), $settings['rules'] ) ) )
			: self::fallback_legacy_rules( $settings );

		if ( empty( $rules ) ) {
			$rules = self::default_rules();
		}
		if ( count( $rules ) > $max_rules ) {
			$rules = array_slice( $rules, 0, $max_rules );
		}

		/**
		 * Pro bundles / third parties can filter rules if needed.
		 */
		return apply_filters( 'wicked_invoicing_notifications_rules', $rules, $settings );
	}

	public static function cron_worker() {
		// basic overlap lock
		$lock_key = 'wkd_notif_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$rules = self::get_rules();
			if ( empty( $rules ) ) {
				return;
			}

			foreach ( $rules as $rule ) {
				if ( empty( $rule['enabled'] ) ) {
					continue;
				}

				$matches = self::find_invoices_for_rule( $rule, 25 );

				// Advanced filter (date/op/days/deposit)
				$matches = array_filter(
					(array) $matches,
					function ( $post_id ) use ( $rule ) {
						return Wicked_Notifications_Controller::rule_advanced_match( (int) $post_id, $rule['advanced'] ?? array() );
					}
				);

				if ( empty( $matches ) ) {
					continue;
				}

				foreach ( $matches as $post_id ) {
					$ok = self::send_for_rule( $rule, (int) $post_id );
					if ( $ok ) {
						self::mark_sent( (int) $post_id, (string) $rule['id'] );
						if ( self::is_debug_mode() ) {
							do_action(
								'wicked_invoicing_info',
								'[Notifications] Sent',
								array(
									'rule'       => $rule['id'],
									'invoice_id' => (int) $post_id,
								)
							);
						}
					}
				}
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	/** Extra condition block (AND) */
	public static function rule_advanced_match( int $post_id, array $adv ): bool {
		// If advanced is disabled, rule passes
		if ( empty( $adv['enabled'] ) ) {
			return true;
		}

		// date_field: only allow known values; default to start_date
		$date_field_raw = $adv['date_field'] ?? 'start_date';
		$date_field     = in_array( $date_field_raw, array( 'start_date', 'due_date', 'post_date' ), true )
			? $date_field_raw
			: 'start_date';

		// op: normalize safely (avoid referencing $adv['op'] directly)
		$op_raw = $adv['op'] ?? '>';
		$op     = in_array( $op_raw, array( '>', '<' ), true ) ? $op_raw : '>';

		// days / require_deposit
		$days            = isset( $adv['days'] ) ? max( 0, (int) $adv['days'] ) : 0;
		$require_deposit = ! empty( $adv['require_deposit'] );

		// Resolve base timestamp by date_field
		if ( $date_field === 'post_date' ) {
			$base_ts = strtotime( (string) get_post_field( 'post_date', $post_id ) );
		} elseif ( $date_field === 'due_date' ) {
			$val     = get_post_meta( $post_id, '_wi_due_date', true ) ?: get_post_meta( $post_id, 'due_date', true );
			$base_ts = $val ? strtotime( (string) $val ) : 0;
		} else { // start_date
			$val     = get_post_meta( $post_id, '_wi_start_date', true ) ?: get_post_meta( $post_id, 'start_date', true );
			$base_ts = $val ? strtotime( (string) $val ) : 0;
		}
		if ( ! $base_ts ) {
			return false;
		}

		$now_ts    = current_time( 'timestamp' );
		$diff_days = (int) floor( ( $now_ts - $base_ts ) / DAY_IN_SECONDS );

		$pass_date = ( $op === '>' ) ? ( $diff_days > $days ) : ( $diff_days < $days );

		if ( $require_deposit ) {
			$meta_key = apply_filters( 'wicked_invoicing_deposit_required_meta_key', 'deposit_required' );
			$dep      = get_post_meta( $post_id, $meta_key, true );
			$pass_dep = is_numeric( $dep ) ? ( (float) $dep > 0 ) : ! empty( $dep );
			return $pass_date && $pass_dep;
		}

		return $pass_date;
	}

	/** Find invoices matching the rule and not already sent for that rule */
	protected static function find_invoices_for_rule( array $rule, int $limit = 25 ): array {
		$statuses = (array) ( $rule['match']['values'] ?? array() );
		if ( empty( $statuses ) ) {
			return array();
		}

		$expanded = self::expand_statuses_for_query( $statuses, self::get_bucket_status_map() );
		$meta_key = self::META_SENT_PREFIX . $rule['id'];

		$q = new \WP_Query(
			array(
				'post_type'      => 'wicked_invoice',
				'post_status'    => $expanded,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$ids = array_map( 'intval', $q->posts );
		wp_reset_postdata();

		return apply_filters( 'wicked_invoicing_notifications_filter_matches', $ids, $rule );
	}

	/** Send email for a rule+invoice (recipients come from invoice/client meta) */
	protected static function send_for_rule( array $rule, int $post_id ): bool {
		$rcpt = self::get_client_recipients( $post_id );
		if ( empty( $rcpt['to'] ) ) {
			return false;
		}

		// Build tokens once – includes status, status_label, status_slug
		$tokens = self::build_tokens( $post_id );

		// Render AFTER tokens are finalized
		$subject = self::render_template( (string) $rule['template']['subject'], $tokens );
		$html    = self::render_template( (string) $rule['template']['html'], $tokens );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $rcpt['cc'] ) ) {
			$headers[] = 'Cc: ' . $rcpt['cc'];
		}
		if ( ! empty( $rcpt['bcc'] ) ) {
			$headers[] = 'Bcc: ' . $rcpt['bcc'];
		}

		// Allow Pro bundles to override (return true to short-circuit as sent)
		$override = apply_filters( 'wicked_invoicing_send_email', null, $rule, $post_id, $subject, $html, $headers );
		if ( $override === false ) {
			return false;
		}
		if ( $override === true ) {
			return true;
		}

		$ok = wp_mail( $rcpt['to'], $subject, $html, $headers );
		if ( ! $ok && self::is_debug_mode() ) {
			do_action(
				'wicked_invoicing_error',
				'[Notifications] wp_mail failed',
				array(
					'rule'       => $rule['id'],
					'invoice_id' => $post_id,
					'to'         => $rcpt['to'],
				)
			);
		}
		return (bool) $ok;
	}

	/** Recipients from invoice meta or linked user; fallback to admin email */
	public static function get_client_recipients( int $invoice_id ): array {
		$keys = apply_filters(
			'wicked_invoicing_client_email_meta_keys',
			array(
				'email' => '_wi_client_email',
				'cc'    => '_wi_client_cc',
				'bcc'   => '_wi_client_bcc',
				'user'  => '_wkd_client_user_id',
			),
			$invoice_id
		);

		$to  = sanitize_email( get_post_meta( $invoice_id, $keys['email'], true ) );
		$cc  = get_post_meta( $invoice_id, $keys['cc'], true );
		$bcc = get_post_meta( $invoice_id, $keys['bcc'], true );

		if ( ! $to && ! empty( $keys['user'] ) ) {
			$uid = (int) get_post_meta( $invoice_id, $keys['user'], true );
			if ( $uid ) {
				$u = get_userdata( $uid );
				if ( $u && is_email( $u->user_email ) ) {
					$to = $u->user_email;
				}
				$user_cc  = get_user_meta( $uid, 'wi_cc', true );
				$user_bcc = get_user_meta( $uid, 'wi_bcc', true );
				$cc       = $cc ?: $user_cc;
				$bcc      = $bcc ?: $user_bcc;
			}
		}

		$cc  = self::normalize_email_csv( $cc );
		$bcc = self::normalize_email_csv( $bcc );

		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}

		return apply_filters(
			'wicked_invoicing_notifications_recipients',
			array(
				'to'  => $to,
				'cc'  => $cc,
				'bcc' => $bcc,
			),
			$invoice_id
		);
	}

	protected static function normalize_email_csv( $value ): string {
		$parts  = is_array( $value ) ? $value : preg_split( '/[,\s;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		$emails = array_values(
			array_filter(
				array_map(
					fn( $s ) => is_email( trim( $s ) ) ? trim( $s ) : '',
					$parts
				)
			)
		);
		return implode( ',', $emails );
	}

	protected static function mark_sent( int $post_id, string $rule_id ): void {
		update_post_meta( $post_id, self::META_SENT_PREFIX . sanitize_key( $rule_id ), current_time( 'mysql' ) );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Template + tokens
	// ─────────────────────────────────────────────────────────────────────

	protected static function build_tokens( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$status_map = self::get_invoice_status_map();

		$settings_controller = __NAMESPACE__ . '\\Wicked_Settings_Controller';
		$settings            = class_exists( $settings_controller ) && method_exists( $settings_controller, 'get' )
			? (array) $settings_controller::get()
			: (array) get_option( 'wicked_invoicing_settings', array() );

		$slug     = $settings['invoice_slug'] ?? $settings['url_invoice_slug'] ?? 'wicked-invoice';
		$hash     = get_post_meta( $post_id, '_wi_hash', true );
		$view_url = $view_url = $hash ? \Wicked_Invoicing\Controllers\Wicked_Template_Controller::get_invoice_url( $hash ) : '';

		$total = (float) get_post_meta( $post_id, '_wi_total', true );
		$paid  = (float) get_post_meta( $post_id, '_wi_paid', true );

		$status_slug  = (string) $post->post_status;
		$status_label = \Wicked_Invoicing\Controllers\Wicked_Base_Controller::get_status_label( $status_slug );

		return array(
			'invoice_id'    => (string) $post_id,
			'invoice_title' => (string) get_the_title( $post_id ),
			'status'        => $status_label,   // back-compat: pretty label
			'status_label'  => $status_label,   // explicit label token
			'status_slug'   => $status_slug,    // raw slug if needed
			'view_url'      => (string) $view_url,
			'total'         => number_format( $total, 2 ),
			'paid'          => number_format( $paid, 2 ),
			'balance'       => number_format( max( 0, $total - $paid ), 2 ),
			'site_name'     => (string) get_bloginfo( 'name' ),
			'site_url'      => (string) home_url( '/' ),
			'date'          => wp_date( 'Y-m-d H:i' ),
		);
	}

	protected static function render_template( string $tpl, array $tokens ): string {
		$search  = array();
		$replace = array();
		foreach ( $tokens as $k => $v ) {
			$search[]  = '{{' . $k . '}}';
			$replace[] = $v;
		}
		return str_replace( $search, $replace, $tpl );
	}
}
