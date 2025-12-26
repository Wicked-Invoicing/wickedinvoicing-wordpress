<?php

namespace Wicked_Invoicing\Controllers;

use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wicked_Dashboard_Controller
 *
 * - GET /dashboard/metrics
 * - GET /dashboard/activity
 */
class Wicked_Dashboard_Controller extends Wicked_Base_Controller {

	const NS  = 'wicked-invoicing/v1';
	const CPT = 'wicked_invoice';

	private const DATE_META  = '_wi_start_date';
	private const META_TOTAL = '_wi_total';
	private const META_PAID  = '_wi_paid';
	private const META_DUE   = '_wi_due_date';

	public function __construct() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_status' ), 10, 3 );
	}

	public static function can_view_dashboard(): bool {
		if ( self::user_has_cap( 'manage_wicked_invoicing' ) ) {
			return true;
		}
		if ( self::user_has_cap( 'edit_wicked_invoices' ) ) {
			return true;
		}
		if ( self::user_has_cap( 'view_all_invoices' ) ) {
			return true;
		}
		if ( self::user_has_cap( 'view_own_invoices' ) ) {
			return true;
		}

		if ( current_user_can( 'manage_wicked_invoicing' ) ) {
			return true;
		}
		if ( current_user_can( 'edit_wicked_invoices' ) ) {
			return true;
		}
		if ( current_user_can( 'view_all_invoices' ) ) {
			return true;
		}
		if ( current_user_can( 'view_own_invoices' ) ) {
			return true;
		}

		if ( is_super_admin() ) {
			return true;
		}

		return false;
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/dashboard/metrics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_metrics' ),
				'permission_callback' => array( __CLASS__, 'can_view_dashboard' ),
				'args'                => array(
					'range' => array(
						'type'    => 'string',
						'default' => '30d',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/dashboard/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_activity' ),
				'permission_callback' => array( __CLASS__, 'can_view_dashboard' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
	}

	public static function get_metrics( WP_REST_Request $request ) {
		$range     = sanitize_text_field( (string) $request->get_param( 'range' ) );
		$dates     = self::resolve_range_to_dates( $range );
		$statuses  = self::get_invoice_status_slugs();
		$bucketMap = self::get_bucket_status_map();

		$counts_all = self::counts_by_status( null, $statuses, $bucketMap );
		$sums_all   = self::get_amount_sums( null, $statuses, $bucketMap );

		$counts_rng = self::counts_by_status( $dates, $statuses, $bucketMap );
		$sums_rng   = self::get_amount_sums( $dates, $statuses, $bucketMap );

		$overdue = self::count_overdue();

		$counts_all_out          = self::with_status_aliases( $counts_all['by_status'] );
		$counts_all_out['total'] = array_sum( $counts_all['__raw_counts'] );

		$counts_rng_out          = self::with_status_aliases( $counts_rng['by_status'] );
		$counts_rng_out['total'] = array_sum( $counts_rng['__raw_counts'] );

		list( $depRequiredSet, $depPaidSet ) = self::get_deposit_status_sets();

		$dep_req_all  = 0;
		$dep_req_rng  = 0;
		$dep_paid_all = 0;
		$dep_paid_rng = 0;
		foreach ( $depRequiredSet as $s ) {
			$u            = str_replace( '-', '_', $s );
			$dep_req_all += (int) ( $counts_all_out[ $s ] ?? $counts_all_out[ $u ] ?? 0 );
			$dep_req_rng += (int) ( $counts_rng_out[ $s ] ?? $counts_rng_out[ $u ] ?? 0 );
		}
		foreach ( $depPaidSet as $s ) {
			$u             = str_replace( '-', '_', $s );
			$dep_paid_all += (int) ( $counts_all_out[ $s ] ?? $counts_all_out[ $u ] ?? 0 );
			$dep_paid_rng += (int) ( $counts_rng_out[ $s ] ?? $counts_rng_out[ $u ] ?? 0 );
		}

		$dep_collected_all = $depPaidSet ? self::sum_paid_for_statuses( $depPaidSet, null, $bucketMap ) : 0.0;
		$dep_collected_rng = $depPaidSet ? self::sum_paid_for_statuses( $depPaidSet, $dates, $bucketMap ) : 0.0;

		$deposit_required_meta_key = apply_filters( 'wicked_invoicing_deposit_required_meta_key', 'deposit_required' );
		$dep_outstanding_all       = $depRequiredSet ? self::sum_meta_for_statuses( $deposit_required_meta_key, $depRequiredSet, null, $bucketMap ) : 0.0;
		$dep_outstanding_rng       = $depRequiredSet ? self::sum_meta_for_statuses( $deposit_required_meta_key, $depRequiredSet, $dates, $bucketMap ) : 0.0;

		$response = array(
			'range'           => $range,
			'date_range'      => $dates,
			'counts'          => $counts_all_out,
			'counts_in_range' => $counts_rng_out,
			'sums'            => $sums_all,
			'sums_in_range'   => $sums_rng,
			'overdue'         => $overdue,
			'issued_in_range' => $counts_rng_out['total'],

			'deposits'        => array(
				'count_required'              => $dep_req_all,
				'count_required_in_range'     => $dep_req_rng,
				'count_paid'                  => $dep_paid_all,
				'count_paid_in_range'         => $dep_paid_rng,

				'amount_collected'            => $dep_collected_all,
				'amount_collected_in_range'   => $dep_collected_rng,
				'amount_outstanding'          => $dep_outstanding_all,
				'amount_outstanding_in_range' => $dep_outstanding_rng,

				'count'                       => $dep_paid_all,
				'count_in_range'              => $dep_paid_rng,
				'amount'                      => $dep_collected_all,
				'amount_in_range'             => $dep_collected_rng,
			),
		);

		$response = apply_filters( 'wicked_invoicing_dashboard_metrics', $response, $dates, $range );
		return rest_ensure_response( $response );
	}

	private static function counts_by_status( ?array $dates, array $logicalStatuses, array $bucketMap ): array {
		$by_status = array();
		$raw       = array();

		foreach ( $logicalStatuses as $status ) {
			$query_statuses = self::expand_status_for_query( $status, $bucketMap );

			$args = array(
				'post_type'              => self::CPT,
				'post_status'            => $query_statuses,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( $dates ) {
				$args['meta_query'] = array(
					array(
						'key'     => self::DATE_META,
						'value'   => array( $dates['from'], $dates['to'] ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				);
			}

			$q     = new \WP_Query( $args );
			$count = (int) $q->found_posts;
			wp_reset_postdata();

			if ( $dates ) {
				$args2  = array(
					'post_type'              => self::CPT,
					'post_status'            => $query_statuses,
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => self::DATE_META,
							'compare' => 'NOT EXISTS',
						),
					),
					'date_query'             => array(
						array(
							'after'     => $dates['from'] . ' 00:00:00',
							'before'    => $dates['to'] . ' 23:59:59',
							'inclusive' => true,
							'column'    => 'post_date',
						),
					),
				);
				$q2     = new \WP_Query( $args2 );
				$count += (int) $q2->found_posts;
				wp_reset_postdata();
			}

			$by_status[ $status ] = $count;
			$raw[]                = $count;
		}

		return array(
			'by_status'    => $by_status,
			'__raw_counts' => $raw,
		);
	}

	private static function get_amount_sums( ?array $dates, array $logicalStatuses, array $bucketMap ): array {
		$total_sum = (float) self::sum_meta_for_statuses( self::META_TOTAL, $logicalStatuses, $dates, $bucketMap );
		$paid_sum  = (float) self::sum_meta_for_statuses( self::META_PAID, $logicalStatuses, $dates, $bucketMap );
		$unpaid    = max( 0, $total_sum - $paid_sum );

		return array(
			'total'  => round( $total_sum, 2 ),
			'paid'   => round( $paid_sum, 2 ),
			'unpaid' => round( $unpaid, 2 ),
		);
	}

	private static function sum_paid_for_statuses( array $logicalStatuses, ?array $dates, array $bucketMap ): float {
		return self::sum_meta_for_statuses( self::META_PAID, $logicalStatuses, $dates, $bucketMap );
	}

	/**
	 * Sum an arbitrary meta across given logical statuses; range-aware with fallback.
	 * Avoids direct SQL to satisfy Plugin Check / PHPCS PreparedSQL sniffs.
	 */
	private static function sum_meta_for_statuses( string $meta_key, array $logicalStatuses, ?array $dates, array $bucketMap ): float {
		$expanded = self::expand_statuses_for_query( $logicalStatuses, $bucketMap );
		if ( empty( $expanded ) ) {
			return 0.0;
		}

		$expanded = array_values( array_filter( array_map( 'sanitize_key', $expanded ) ) );
		if ( empty( $expanded ) ) {
			return 0.0;
		}

		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key ) {
			return 0.0;
		}

		$ids = array();

		if ( $dates ) {
			$q1 = new \WP_Query(
				array(
					'post_type'              => self::CPT,
					'post_status'            => $expanded,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => self::DATE_META,
							'value'   => array( $dates['from'], $dates['to'] ),
							'compare' => 'BETWEEN',
							'type'    => 'DATE',
						),
					),
				)
			);
			$ids = array_merge( $ids, $q1->posts );

			$q2 = new \WP_Query(
				array(
					'post_type'              => self::CPT,
					'post_status'            => $expanded,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => self::DATE_META,
							'compare' => 'NOT EXISTS',
						),
					),
					'date_query'             => array(
						array(
							'after'     => $dates['from'] . ' 00:00:00',
							'before'    => $dates['to'] . ' 23:59:59',
							'inclusive' => true,
							'column'    => 'post_date',
						),
					),
				)
			);
			$ids = array_merge( $ids, $q2->posts );
		} else {
			$q = new \WP_Query(
				array(
					'post_type'              => self::CPT,
					'post_status'            => $expanded,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$ids = $q->posts;
		}

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ( $ids as $post_id ) {
			$raw = get_post_meta( $post_id, $meta_key, true );
			$sum += (float) $raw;
		}

		return round( $sum, 2 );
	}

	public static function get_activity( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$items = array();

		$activity_statuses = self::expand_statuses_for_query(
			self::get_invoice_status_slugs(),
			self::get_bucket_status_map()
		);

		$recent = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => $activity_statuses,
				'numberposts' => 25,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		foreach ( $recent as $p ) {
			$created_ts = strtotime( $p->post_date_gmt ?: $p->post_date );

			$items[] = array(
				'type'       => 'invoice_created',
				'invoice_id' => $p->ID,
				'title'      => get_the_title( $p ),
				'status'     => $p->post_status,
				'date'       => wp_date( 'c', $created_ts ),
				'ts'         => $created_ts,
				'meta'       => array(
					'total' => get_post_meta( $p->ID, self::META_TOTAL, true ),
					'paid'  => get_post_meta( $p->ID, self::META_PAID, true ),
				),
			);

			$modified_ts = strtotime( $p->post_modified_gmt ?: $p->post_modified );
			if ( $modified_ts > $created_ts + 60 ) {
				$items[] = array(
					'type'       => 'invoice_updated',
					'invoice_id' => $p->ID,
					'title'      => get_the_title( $p ),
					'status'     => $p->post_status,
					'date'       => wp_date( 'c', $modified_ts ),
					'ts'         => $modified_ts,
				);
			}
		}

		if ( function_exists( 'rest_do_request' ) ) {
			$req = new \WP_REST_Request( 'GET', '/' . self::NS . '/logs' );
			$res = rest_do_request( $req );

			if ( ! is_wp_error( $res ) && method_exists( $res, 'get_data' ) ) {
				$rows = $res->get_data();
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$ts      = isset( $r['log_date'] ) ? strtotime( (string) $r['log_date'] ) : (int) ( $r['ts'] ?? 0 );
						$items[] = array(
							'type'    => 'log',
							'level'   => strtolower( (string) ( $r['level'] ?? 'info' ) ),
							'message' => $r['message'] ?? '',
							'date'    => isset( $r['log_date'] ) ? wp_date( 'c', $ts ) : ( $r['date'] ?? wp_date( 'c', $ts ) ),
							'ts'      => $ts,
							'data'    => $r['data'] ?? null,
						);
					}
				}
			}
		}

		usort( $items, fn( $a, $b ) => ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ) );

		$total  = count( $items );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $items, $offset, $per_page );

		$out = array(
			'items'    => array_values( $paged ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);

		$out = apply_filters( 'wicked_invoicing_dashboard_activity', $out );
		return rest_ensure_response( $out );
	}

	public static function on_transition_status( $new_status, $old_status, $post ) {
		if ( ! $post || $post->post_type !== self::CPT || $new_status === $old_status ) {
			return;
		}

		$title = get_the_title( $post );
		$msg   = sprintf( 'Invoice #%d (%s) status changed: %s → %s', $post->ID, $title, $old_status, $new_status );

		if ( self::is_debug_mode() ) {
			$u = wp_get_current_user();
			do_action(
				'wicked_invoicing_info',
				$msg,
				array(
					'type'       => 'status_changed',
					'invoice_id' => $post->ID,
					'from'       => $old_status,
					'to'         => $new_status,
					'by'         => ( $u && $u->exists() ) ? $u->user_login : 'system',
				)
			);
		}
	}

	private static function resolve_range_to_dates( string $range ): array {
		$now = (int) current_time( 'timestamp' ); // site TZ timestamp

		$to = wp_date( 'Y-m-d', $now );

		switch ( $range ) {
			case 'today':
				$from = $to;
				break;

			case '7d':
				$from = wp_date( 'Y-m-d', $now - ( 6 * DAY_IN_SECONDS ) );
				break;

			case 'this_month':
				$from = wp_date( 'Y-m-01', $now );
				break;

			case 'this_quarter':
				$m           = (int) wp_date( 'n', $now );
				$qStartMonth = (int) ( floor( ( $m - 1 ) / 3 ) * 3 ) + 1;
				$from        = sprintf( '%s-%02d-01', wp_date( 'Y', $now ), $qStartMonth );
				break;

			case 'this_year':
				$from = wp_date( 'Y-01-01', $now );
				break;

			case 'all':
				$from = '1970-01-01';
				break;

			case '30d':
			default:
				$from = wp_date( 'Y-m-d', $now - ( 29 * DAY_IN_SECONDS ) );
				break;
		}

		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	private static function count_overdue(): int {
		$today = current_time( 'Y-m-d' );

		$logical  = self::get_overdue_statuses();
		$bucket   = self::get_bucket_status_map();
		$statuses = self::expand_statuses_for_query( $logical, $bucket );

		$q = new \WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_DUE,
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				),
			)
		);

		$count = (int) $q->found_posts;
		wp_reset_postdata();

		return $count;
	}

	private static function count_created_between( string $fromYmd, string $toYmd ): int {
		$after  = $fromYmd . ' 00:00:00';
		$before = $toYmd . ' 23:59:59';

		$statuses = self::expand_statuses_for_query(
			self::get_invoice_status_slugs(),
			self::get_bucket_status_map()
		);

		$q = new \WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => $statuses,
				'date_query'     => array(
					array(
						'after'     => $after,
						'before'    => $before,
						'inclusive' => true,
						'column'    => 'post_date',
					),
				),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$count = (int) $q->found_posts;
		wp_reset_postdata();

		return $count;
	}
}
