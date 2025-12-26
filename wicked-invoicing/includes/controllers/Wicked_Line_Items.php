<?php
namespace Wicked_Invoicing\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve current invoice ID from context (single CPT page, template routing, or query vars).
 */
function wicked_inv_current_invoice_id(): int {
	global $post;

	if ( is_object( $post ) && isset( $post->post_type ) && 'wicked_invoice' === $post->post_type ) {
		return (int) $post->ID;
	}

	foreach ( array( 'wi_invoice_id', 'invoice_id', 'inv_id', 'invoice' ) as $qv ) {
		$val = get_query_var( $qv );
		if ( $val && (int) $val > 0 ) {
			return (int) $val;
		}
	}

	if ( ! empty( $GLOBALS['wicked_current_invoice_id'] ) ) {
		return (int) $GLOBALS['wicked_current_invoice_id'];
	}

	return 0;
}

/** Fetch line items array from post meta `_wi_line_items` */
function wicked_inv_get_line_items( int $invoice_id ): array {
	$items = get_post_meta( $invoice_id, '_wi_line_items', true );

	if ( is_string( $items ) ) {
		$decoded = json_decode( $items, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			$items = $decoded;
		}
	}

	return is_array( $items ) ? $items : array();
}

/** Money formatting (filterable) */
function wicked_inv_format_money( $amount ): string {
	$amount    = is_numeric( $amount ) ? (float) $amount : 0.0;
	$formatted = number_format_i18n( $amount, 2 );
	return apply_filters( 'wicked_inv_format_money', $formatted, $amount );
}

/** Compute derived amounts for a single item */
function wicked_inv_compute_line_totals( array $item ): array {
	// Qty: allow either `qty` or `quantity`
	$qty = isset( $item['qty'] )
		? (float) $item['qty']
		: (float) ( $item['quantity'] ?? 0.0 );

	$rate = isset( $item['rate'] ) ? (float) $item['rate'] : 0.0;

	// Discount as-is (no rounding), or 0 if not numeric.
	$discount = is_numeric( $item['discount'] ?? null )
		? (float) $item['discount']
		: 0.0;

	$amount = $qty * $rate;
	$after  = max( 0.0, $amount - $discount );

	// Treat tax as a percentage (tax_rate preferred; fallback to tax).
	if ( array_key_exists( 'tax_rate', $item ) ) {
		$tax_rate = (float) $item['tax_rate'];
	} else {
		$tax_rate = is_numeric( $item['tax'] ?? null ) ? (float) $item['tax'] : 0.0;
	}

	$tax   = $after * ( $tax_rate / 100.0 );
	$total = $after + $tax;

	return array(
		'amount'   => $amount,
		'discount' => $discount,
		'tax'      => $tax,
		'total'    => $total,
	);
}

/** Helper to fetch a key from current row context */
function wicked_inv_ctx_value( $block, $key, $default = '' ) {
	$item = is_object( $block ) ? ( $block->context['wicked-invoicing/lineItem'] ?? null ) : null;
	return is_array( $item ) && array_key_exists( $key, $item ) ? $item[ $key ] : $default;
}

/** Return the canonical cell block names we support */
function wicked_inv_line_item_cell_block_names(): array {
	return array(
		'wicked-invoicing/line-item-description',
		'wicked-invoicing/line-item-quantity',
		'wicked-invoicing/line-item-rate',
		'wicked-invoicing/line-item-discount',
		'wicked-invoicing/line-item-tax',
		'wicked-invoicing/line-item-total',
	);
}

/** Recursively collect only our cell blocks from a parsed innerBlocks tree */
function wicked_inv_collect_cells( array $nodes, array $allowed_names, array &$out ): void {
	foreach ( $nodes as $node ) {
		$name = $node['blockName'] ?? '';
		if ( $name && in_array( $name, $allowed_names, true ) ) {
			$out[] = $node;
		}
		if ( ! empty( $node['innerBlocks'] ) ) {
			wicked_inv_collect_cells( $node['innerBlocks'], $allowed_names, $out );
		}
	}
}

/** Build a minimal parsed cell node when no cells are saved in the template */
function wicked_inv_parsed_cell( string $block_name ): array {
	return array(
		'blockName'    => $block_name,
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
}

function wicked_inv_compute_invoice_totals( int $invoice_id ): array {
	$items = wicked_inv_get_line_items( $invoice_id );

	$subtotal = 0.0; // after discount, before tax
	$discount = 0.0;
	$tax      = 0.0;
	$total    = 0.0;

	foreach ( $items as $item ) {
		$t = wicked_inv_compute_line_totals( (array) $item );

		// line totals() returns:
		// amount (qty*rate), discount, tax, total (after discount + tax)
		$subtotal += max( 0.0, (float) $t['amount'] - (float) $t['discount'] );
		$discount += (float) $t['discount'];
		$tax      += (float) $t['tax'];
		$total    += (float) $t['total'];
	}

	$paid = (float) get_post_meta( $invoice_id, 'paid', true );
	$due  = max( 0.0, $total - $paid );

	return array(
		'subtotal' => round( $subtotal, 2 ),
		'discount' => round( $discount, 2 ),
		'tax'      => round( $tax, 2 ),
		'total'    => round( $total, 2 ),
		'paid'     => round( $paid, 2 ),
		'due'      => round( $due, 2 ),
	);
}


/** Fallback HTML if a child block isn’t registered */
function wicked_inv_render_cell_fallback_html( string $block_name, array $item ): string {
	$qty = isset( $item['qty'] )
		? (float) $item['qty']
		: (float) ( $item['quantity'] ?? 0 );

	$rate = isset( $item['rate'] ) ? (float) $item['rate'] : 0.0;

	$desc   = isset( $item['description'] ) ? wp_kses_post( $item['description'] ) : '';
	$totals = wicked_inv_compute_line_totals( $item );

	$discount = $totals['discount'];
	$tax      = $totals['tax'];
	$total    = $totals['total'];

	$money = static function ( $v ) {
		return esc_html( wicked_inv_format_money( $v ) );
	};

	$num = static function ( $v ) {
		$v   = is_numeric( $v ) ? (float) $v : 0.0;
		$out = number_format_i18n( $v, 2 );
		// Trim trailing zeros and trailing decimal separator.
		$out = rtrim( rtrim( $out, '0' ), '.' );
		return esc_html( $out );
	};

	switch ( $block_name ) {
		case 'wicked-invoicing/line-item-description':
			return '<div class="wi-line-items__cell wi-line-items__cell--description">' . $desc . '</div>';

		case 'wicked-invoicing/line-item-quantity':
			return '<div class="wi-line-items__cell wi-line-items__cell--qty">' . $num( $qty ) . '</div>';

		case 'wicked-invoicing/line-item-rate':
			return '<div class="wi-line-items__cell wi-line-items__cell--rate">' . $money( $rate ) . '</div>';

		case 'wicked-invoicing/line-item-discount':
			return '<div class="wi-line-items__cell wi-line-items__cell--discount">' . $money( $discount ) . '</div>';

		case 'wicked-invoicing/line-item-tax':
			return '<div class="wi-line-items__cell wi-line-items__cell--tax">' . $money( $tax ) . '</div>';

		case 'wicked-invoicing/line-item-total':
			return '<div class="wi-line-items__cell wi-line-items__cell--total">' . $money( $total ) . '</div>';

		default:
			return '';
	}
}

/** Parent loop renderer */
function wicked_inv_render_line_items_block( $attributes, $content, $block ): string {
	if ( Wicked_Base_Controller::is_debug_mode() ) {
		do_action(
			'wicked_invoicing_info',
			'[LineItems] start',
			array(
				'ctx_post_id' => $block->context['postId'] ?? null,
				'post_type'   => ! empty( $block->context['postId'] ) ? get_post_type( (int) $block->context['postId'] ) : null,
			)
		);
	}

	// Resolve invoice ID
	$invoice_id = 0;

	if ( is_object( $block ) && ! empty( $block->context['postId'] ) ) {
		$pid = (int) $block->context['postId'];
		if ( 'wicked_invoice' === get_post_type( $pid ) ) {
			$invoice_id = $pid;
		}
	}

	if ( ! $invoice_id ) {
		$invoice_id = wicked_inv_current_invoice_id();
	}

	if ( Wicked_Base_Controller::is_debug_mode() ) {
		do_action( 'wicked_invoicing_info', '[LineItems] resolved invoice', array( 'invoice_id' => $invoice_id ) );
	}

	if ( ! $invoice_id ) {
		return '<div class="wi-line-items wi-line-items--no-invoice" aria-hidden="true"></div>';
	}

	// Load items (with template fallback)
	$items = wicked_inv_get_line_items( $invoice_id );

	if ( Wicked_Base_Controller::is_debug_mode() ) {
		do_action(
			'wicked_invoicing_info',
			'[LineItems] items for invoice',
			array(
				'invoice_id' => $invoice_id,
				'count'      => is_array( $items ) ? count( $items ) : 0,
				'first'      => is_array( $items ) && $items ? $items[0] : null,
			)
		);
	}

	if ( ! $items ) {
		$settings = get_option( 'wicked_invoicing_settings', array() );
		$tpl_id   = absint( $settings['invoice_template_id'] ?? 0 );

		if ( $tpl_id ) {
			$tpl_items = wicked_inv_get_line_items( $tpl_id );

			if ( Wicked_Base_Controller::is_debug_mode() ) {
				do_action(
					'wicked_invoicing_info',
					'[LineItems] fallback to template',
					array(
						'template_id' => $tpl_id,
						'count'       => is_array( $tpl_items ) ? count( $tpl_items ) : 0,
						'first'       => is_array( $tpl_items ) && $tpl_items ? $tpl_items[0] : null,
					)
				);
			}

			if ( $tpl_items ) {
				$items = $tpl_items;
			}
		}
	}

	if ( ! $items ) {
		return '<div class="wi-line-items wi-line-items--empty" aria-hidden="true"></div>';
	}

	// Collect ONLY our six cell blocks from the saved innerBlocks tree (any depth).
	$cell_names = wicked_inv_line_item_cell_block_names();
	$saved_tree = isset( $block->parsed_block['innerBlocks'] ) ? $block->parsed_block['innerBlocks'] : array();
	$cells      = array();

	if ( ! empty( $saved_tree ) ) {
		wicked_inv_collect_cells( $saved_tree, $cell_names, $cells );
	}

	// If none saved, synthesize defaults in canonical order.
	if ( empty( $cells ) ) {
		$cells = array_map( __NAMESPACE__ . '\\wicked_inv_parsed_cell', $cell_names );
	}

	$registry = \WP_Block_Type_Registry::get_instance();

	$rows = '';
	foreach ( $items as $i => $item ) {
		$row_ctx = array_merge(
			is_array( $block->context ) ? $block->context : array(),
			array(
				'wicked-invoicing/lineItem'      => $item,
				'wicked-invoicing/lineItemIndex' => $i,
			)
		);

		$row_inner = '';
		foreach ( $cells as $cell_parsed ) {
			$name = $cell_parsed['blockName'] ?? '';
			if ( $name && $registry->is_registered( $name ) ) {
				$row_inner .= ( new \WP_Block( $cell_parsed, $row_ctx ) )->render();
			} else {
				$row_inner .= wicked_inv_render_cell_fallback_html( (string) $name, (array) $item );
			}
		}

		$rows .= sprintf(
			'<div class="wi-line-items__row" data-index="%d">%s</div>',
			(int) $i,
			$row_inner
		);
	}

	$default_labels = array(
		'wicked-invoicing/line-item-description' => __( 'Description', 'wicked-invoicing' ),
		'wicked-invoicing/line-item-quantity'    => __( 'Qty', 'wicked-invoicing' ),
		'wicked-invoicing/line-item-rate'        => __( 'Rate', 'wicked-invoicing' ),
		'wicked-invoicing/line-item-discount'    => __( 'Discount', 'wicked-invoicing' ),
		'wicked-invoicing/line-item-tax'         => __( 'Tax', 'wicked-invoicing' ),
		'wicked-invoicing/line-item-total'       => __( 'Total', 'wicked-invoicing' ),
	);

	$labels = is_array( $attributes['headers'] ?? null ) ? $attributes['headers'] : array();

	$header_cells = '';
	foreach ( $cells as $cell_parsed ) {
		$name = $cell_parsed['blockName'] ?? '';
		if ( ! $name ) {
			continue;
		}

		$key  = str_replace( 'wicked-invoicing/line-item-', '', $name );
		$text = ( isset( $labels[ $name ] ) && $labels[ $name ] !== '' )
			? $labels[ $name ]
			: ( $default_labels[ $name ] ?? '' );

		$header_cells .= sprintf(
			'<div class="wi-line-items__cell wi-line-items__cell--%s">%s</div>',
			esc_attr( $key ),
			esc_html( $text )
		);
	}

	$header_html   = sprintf( '<div class="wi-line-items__header">%s</div>', $header_cells );
	$wrapper_attrs = get_block_wrapper_attributes( array( 'class' => 'wi-line-items' ) );

	return sprintf( '<div %s>%s%s</div>', $wrapper_attrs, $header_html, $rows );
}

/*
-------------------------------------------------------------------------
 * CHILD CELL RENDERERS (proper WP dynamic block callback signatures)
 * ---------------------------------------------------------------------- */

function wicked_inv_render_line_item_description_block( $attributes, $content, $block ): string {
	$val = wp_kses_post( wicked_inv_ctx_value( $block, 'description', '' ) );
	return '<div class="wi-line-items__cell wi-line-items__cell--description">' . $val . '</div>';
}

function wicked_inv_render_line_item_quantity_block( $attributes, $content, $block ): string {
	$qty = wicked_inv_ctx_value( $block, 'qty', null );
	if ( $qty === null || $qty === '' ) {
		$qty = wicked_inv_ctx_value( $block, 'quantity', 0 );
	}

	$qty  = is_numeric( $qty ) ? (float) $qty : 0.0;
	$disp = number_format_i18n( $qty, 2 );
	$disp = rtrim( rtrim( $disp, '0' ), '.' );

	return '<div class="wi-line-items__cell wi-line-items__cell--qty">' . esc_html( $disp ) . '</div>';
}

function wicked_inv_render_line_item_rate_block( $attributes, $content, $block ): string {
	$rate = wicked_inv_ctx_value( $block, 'rate', 0 );
	return '<div class="wi-line-items__cell wi-line-items__cell--rate">' . esc_html( wicked_inv_format_money( $rate ) ) . '</div>';
}

function wicked_inv_render_line_item_discount_block( $attributes, $content, $block ): string {
	$discount = wicked_inv_ctx_value( $block, 'discount', 0 );
	return '<div class="wi-line-items__cell wi-line-items__cell--discount">' . esc_html( wicked_inv_format_money( $discount ) ) . '</div>';
}

function wicked_inv_render_line_item_tax_block( $attributes, $content, $block ): string {
	$totals = wicked_inv_compute_line_totals( (array) ( $block->context['wicked-invoicing/lineItem'] ?? array() ) );
	return '<div class="wi-line-items__cell wi-line-items__cell--tax">' . esc_html( wicked_inv_format_money( $totals['tax'] ) ) . '</div>';
}

function wicked_inv_render_line_item_total_block( $attributes, $content, $block ): string {
	$totals = wicked_inv_compute_line_totals( (array) ( $block->context['wicked-invoicing/lineItem'] ?? array() ) );
	return '<div class="wi-line-items__cell wi-line-items__cell--total">' . esc_html( wicked_inv_format_money( $totals['total'] ) ) . '</div>';
}
