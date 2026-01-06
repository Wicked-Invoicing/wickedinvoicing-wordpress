<?php
namespace Wicked_Invoicing\Model;

use Wicked_Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WI_Invoice
 *
 * Encapsulates invoice business logic:
 * - Creation with secure hash
 * - Lookup by ID/hash
 * - Status/metadata access
 * - Updates
 */
class WI_Invoice {
	/** Custom Post Type slug */
	const CPT = 'wicked_invoice';
	/** Meta key for the public hash */
	const META_HASH = '_wicked_invoicing_hash';

	public function get_by_hash( string $hash ): ?\WP_Post {
		$hash  = sanitize_text_field( $hash );
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'meta_key'    => self::META_HASH,
				'meta_value'  => $hash,
				'post_status' => array( 'temp', 'pending', 'deposit-paid', 'paid' ),
				'numberposts' => 1,
			)
		);

		if ( empty( $posts ) ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action( 'wicked_invoicing_error', '[WI_Invoice] get_by_hash: no invoice found', compact( 'hash' ) );
			}
			return null;
		}

		return $posts[0];
	}

	public function get_by_id( int $id ): ?\WP_Post {
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== self::CPT ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action( 'wicked_invoicing_error', '[WI_Invoice] get_by_id: invalid ID', compact( 'id' ) );
			}
			return null;
		}

		return $post;
	}

	public function is_invoice_request(): bool {
		$hash = get_query_var( 'wicked_invoicing_invoice_hash' );
		return is_string( $hash ) && strlen( trim( $hash ) ) === 32;
	}

	public function get_hash( int $id ): ?string {
		$h = get_post_meta( $id, self::META_HASH, true );
		return $h ?: null;
	}

	public function get_status( int $id ): string {
		return get_post_status( $id ) ?: '';
	}

	public function create( string $title ): ?array {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => sanitize_text_field( $title ),
				'post_status' => 'temp',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action(
					'wicked_invoicing_error',
					'[WI_Invoice] create: wp_insert_post failed',
					array(
						'error' => $post_id->get_error_message(),
					)
				);
			}
			return null;
		}

		try {
			$hash = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			$hash = wp_generate_uuid4();
		}

		if ( ! update_post_meta( $post_id, self::META_HASH, $hash ) ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action( 'wicked_invoicing_error', '[WI_Invoice] create: failed to save hash', compact( 'post_id', 'hash' ) );
			}
		}

		do_action( 'wicked_invoicing_Invoice_created', $post_id );

		return array(
			'id'   => $post_id,
			'hash' => $hash,
		);
	}

	public function list( array $args = array() ): array {
		$page     = absint( $args['page'] ?? 1 );
		$per_page = absint( $args['per_page'] ?? 10 );
		$status   = isset( $args['status'] )
					? explode( ',', sanitize_text_field( $args['status'] ) )
					: array( 'temp', 'pending', 'deposit-paid', 'paid' );
		$search   = sanitize_text_field( $args['search'] ?? '' );
		$orderby  = sanitize_key( $args['orderby'] ?? 'date' );
		$order    = strtoupper( sanitize_key( $args['order'] ?? 'DESC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'post_status'    => $status,
				's'              => $search,
				'orderby'        => $orderby,
				'order'          => $order,
			)
		);

		$invoices = array_map(
			function ( $p ) {
				return array(
					'id'     => $p->ID,
					'title'  => $p->post_title,
					'status' => $p->post_status,
					'date'   => $p->post_date,
					'hash'   => $this->get_hash( $p->ID ),
				);
			},
			$query->posts
		);

		return array(
			'invoices' => $invoices,
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function update( int $id, array $fields ): ?array {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action( 'wicked_invoicing_error', '[WI_Invoice] update: invalid ID', compact( 'id', 'fields' ) );
			}
			return null;
		}

		$update = array( 'ID' => $id );
		if ( isset( $fields['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $fields['title'] );
		}
		if ( isset( $fields['status'] ) ) {
			$update['post_status'] = sanitize_key( $fields['status'] );
			do_action( 'wicked_invoicing_status_set', $post_id, $new_status );
		}

		if ( count( $update ) === 1 ) {
			return array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'status' => get_post_status( $id ),
			);
		}

		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) {
			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action(
					'wicked_invoicing_error',
					'[WI_Invoice] update: failed',
					array(
						'error' => $res->get_error_message(),
					)
				);
			}
			return null;
		}

		return array(
			'id'     => $id,
			'title'  => get_the_title( $id ),
			'status' => get_post_status( $id ),
		);
	}
}
