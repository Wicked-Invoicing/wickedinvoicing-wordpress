<?php
namespace Wicked_Invoicing\Controllers;

use WP_Block_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wicked_Block_Controller extends Wicked_Base_Controller {

	private static $booted = false;

	public function __construct() {

		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( $this, 'register_blocks' ) );

		add_filter( 'block_categories_all', array( $this, 'add_block_category' ), 10, 2 );
		add_filter( 'allowed_block_types_all', array( $this, 'restrict_block_types' ), 10, 2 );
	}

	/*
	------------------------------------------------------------------------
	 * BLOCK REGISTRATION (FORCE OVERRIDE)
	 * --------------------------------------------------------------------- */

	private function starts_with( string $haystack, string $needle ): bool {
		return $needle === '' || strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
	}

	/**
	 * Force-register blocks in /blocks.
	 * If a render_<slug>_block method exists, we ALWAYS use it (even if block.json has "render").
	 */
	public function register_blocks(): void {
		$pattern = WICKED_INV_PLUGIN_PATH . 'blocks/*';
		$dirs    = glob( $pattern, GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) ) {
			$dirs = array();
		}

		$registry = WP_Block_Type_Registry::get_instance();

		// line-item* blocks use global render callbacks
		$force_map = array(
			'line-items'            => __NAMESPACE__ . '\\wicked_inv_render_line_items_block',
			'line-item-description' => __NAMESPACE__ . '\\wicked_inv_render_line_item_description_block',
			'line-item-quantity'    => __NAMESPACE__ . '\\wicked_inv_render_line_item_quantity_block',
			'line-item-rate'        => __NAMESPACE__ . '\\wicked_inv_render_line_item_rate_block',
			'line-item-discount'    => __NAMESPACE__ . '\\wicked_inv_render_line_item_discount_block',
			'line-item-tax'         => __NAMESPACE__ . '\\wicked_inv_render_line_item_tax_block',
			'line-item-total'       => __NAMESPACE__ . '\\wicked_inv_render_line_item_total_block',
		);

		foreach ( $dirs as $block_dir ) {
			$manifest = $block_dir . '/block.json';
			$slug     = basename( $block_dir );

			if ( ! file_exists( $manifest ) ) {
				continue;
			}

			$json = json_decode( (string) file_get_contents( $manifest ), true );

			$name = ! empty( $json['name'] )
				? (string) $json['name']
				: 'wicked-invoicing/' . $slug;

			// Decide callback
			$args = array();

			if ( isset( $force_map[ $slug ] ) && is_callable( $force_map[ $slug ] ) ) {
				$args['render_callback'] = $force_map[ $slug ];
			} else {
				$method = 'render_' . str_replace( '-', '_', $slug ) . '_block';
				if ( method_exists( $this, $method ) ) {
					$args['render_callback'] = array( $this, $method );
				}
			}

			// IMPORTANT: Unregister any existing registration for our namespace so override always applies.
			if ( $registry->is_registered( $name ) && $this->starts_with( $name, 'wicked-invoicing/' ) ) {
				if ( method_exists( $registry, 'unregister' ) ) {
					$registry->unregister( $name );
				} elseif ( function_exists( 'unregister_block_type' ) ) {
					unregister_block_type( $name );
				}
			}

			register_block_type( $block_dir, $args );
		}
	}

	/*
	------------------------------------------------------------------------
	 * HELPERS
	 * --------------------------------------------------------------------- */

	private function invoice_id_from_block( $block ): int {
		if ( is_object( $block ) && isset( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}
		return (int) get_the_ID();
	}

	private function meta( int $invoice_id, string $key, $default = '' ): string {
		$v = get_post_meta( $invoice_id, $key, true );
		if ( $v === '' || $v === null ) {
			return (string) $default;
		}
		return (string) $v;
	}

	private function money( $amount ): string {
		if ( function_exists( 'wicked_inv_format_money' ) ) {
			return (string) wicked_inv_format_money( $amount );
		}
		return number_format_i18n( (float) $amount, 2 );
	}

	/*
	------------------------------------------------------------------------
	 * RENDERERS (CANONICAL)
	 * --------------------------------------------------------------------- */

	public function render_billing_address_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_billing_address', '' );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-billing-address">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_shipping_address_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_shipping_address', '' );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-shipping-address">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_client_address_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_client_address', '' );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-client-address">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_client_email_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$email = sanitize_email( $this->meta( $invoice_id, '_wi_client_email', '' ) );
		if ( $email === '' ) {
			return '';
		}

		return '<div class="wi-client-email"><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></div>';
	}

	public function render_client_name_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$name = sanitize_text_field( $this->meta( $invoice_id, '_wi_client_name', '' ) );
		if ( $name === '' ) {
			return '';
		}

		return '<div class="wi-client-name">' . esc_html( $name ) . '</div>';
	}

	public function render_notes_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_notes', '' );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-notes">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_tos_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_terms_and_conditions', '' );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-tos">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_po_number_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = sanitize_text_field( $this->meta( $invoice_id, '_wi_po_number', '' ) );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-po-number">' . esc_html( $raw ) . '</div>';
	}

	public function render_ref_number_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = sanitize_text_field( $this->meta( $invoice_id, '_wi_reference_number', '' ) );
		if ( $raw === '' ) {
			return '';
		}

		return '<div class="wi-ref-number">' . esc_html( $raw ) . '</div>';
	}

	public function render_invoice_id_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}
		return '<div class="wi-invoice-id">' . esc_html( (string) $invoice_id ) . '</div>';
	}

	public function render_start_date_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		// Use meta first (your meta list shows _wi_start_date)
		$raw = $this->meta( $invoice_id, '_wi_start_date', '' );
		if ( $raw === '' ) {
			$raw = (string) get_post_field( 'post_date', $invoice_id );
		}

		$ts = $raw ? strtotime( $raw ) : false;
		if ( ! $ts ) {
			return '';
		}

		return '<div class="wi-start-date">' . esc_html( date_i18n( get_option( 'date_format' ), $ts ) ) . '</div>';
	}

	public function render_due_date_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$raw = $this->meta( $invoice_id, '_wi_due_date', '' );
		$ts  = $raw ? strtotime( $raw ) : false;
		if ( ! $ts ) {
			return '';
		}

		return '<div class="wi-due-date">' . esc_html( date_i18n( get_option( 'date_format' ), $ts ) ) . '</div>';
	}

	public function render_subtotal_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$val = (float) $this->meta( $invoice_id, '_wi_subtotal', 0 );
		return '<div class="wi-subtotal">' . esc_html( $this->money( $val ) ) . '</div>';
	}

	public function render_tax_amount_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$val = (float) $this->meta( $invoice_id, '_wi_tax_amount', 0 );
		return '<div class="wi-tax-amount">' . esc_html( $this->money( $val ) ) . '</div>';
	}

	public function render_total_discount_amount_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$val = (float) $this->meta( $invoice_id, '_wi_discount_amount', 0 );
		return '<div class="wi-total-discount">' . esc_html( $this->money( $val ) ) . '</div>';
	}

	public function render_total_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$val = (float) $this->meta( $invoice_id, '_wi_total', 0 );
		return '<div class="wi-total">' . esc_html( $this->money( $val ) ) . '</div>';
	}

	public function render_status_block( $attrs, $content = '', $block = null ) {
		$invoice_id = $this->invoice_id_from_block( $block );
		if ( ! $invoice_id ) {
			return '';
		}

		$status = get_post_status( $invoice_id );
		if ( ! $status ) {
			return '';
		}

		return '<div class="wi-status">' . esc_html( $status ) . '</div>';
	}

	/**
	 * Company blocks (settings)
	 */
	public function render_company_address_block( $attrs, $content = '', $block = null ) {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		$raw  = (string) ( $opts['company_address'] ?? '' );
		if ( $raw === '' ) {
			return '';
		}
		return '<div class="wi-company-address">' . nl2br( esc_html( $raw ) ) . '</div>';
	}

	public function render_company_phone_block( $attrs, $content = '', $block = null ) {
		$opts  = get_option( 'wicked_invoicing_settings', array() );
		$phone = sanitize_text_field( $opts['company_phone'] ?? '' );
		if ( $phone === '' ) {
			return '';
		}
		return '<div class="wi-company-phone">' . esc_html( $phone ) . '</div>';
	}

	public function render_company_email_block( $attrs, $content = '', $block = null ) {
		$opts  = get_option( 'wicked_invoicing_settings', array() );
		$email = sanitize_email( $opts['company_email'] ?? '' );
		if ( $email === '' ) {
			return '';
		}
		return '<div class="wi-company-email"><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></div>';
	}

	public function render_company_name_block( $attrs, $content = '', $block = null ) {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		$name = sanitize_text_field( $opts['company_name'] ?? '' );
		if ( $name === '' ) {
			return '';
		}
		return '<div class="wi-company-name">' . esc_html( $name ) . '</div>';
	}

	/*
	------------------------------------------------------------------------
	 * ALIASES (slug variations so the template still renders)
	 * --------------------------------------------------------------------- */

	// InvoiceID vs invoice-id
	public function render_invoiceid_block( $attrs, $content = '', $block = null ) {
		return $this->render_invoice_id_block( $attrs, $content, $block );
	}

	// Ref number variations
	public function render_reference_number_block( $attrs, $content = '', $block = null ) {
		return $this->render_ref_number_block( $attrs, $content, $block );
	}

	// TOS variations
	public function render_terms_and_conditions_block( $attrs, $content = '', $block = null ) {
		return $this->render_tos_block( $attrs, $content, $block );
	}

	// Tax label variations
	public function render_tax_block( $attrs, $content = '', $block = null ) {
		return $this->render_tax_amount_block( $attrs, $content, $block );
	}

	/*
	------------------------------------------------------------------------
	 * EDITOR CATEGORY + ALLOWED BLOCKS (unchanged)
	 * --------------------------------------------------------------------- */

	public function add_block_category( $categories, $editor_context ) {
		$template_id = $this->get_template_page_id();

		if ( isset( $editor_context->post ) && (int) $editor_context->post->ID === $template_id ) {
			$categories[] = array(
				'slug'  => 'wicked-invoices',
				'title' => __( 'Wicked Invoices', 'wicked-invoicing' ),
			);
		}

		return $categories;
	}

	public function restrict_block_types( $allowed, $editor_context ) {
		$template_id = $this->get_template_page_id();

		if ( isset( $editor_context->post ) && (int) $editor_context->post->ID === $template_id ) {
			return true;
		}

		if ( ! is_array( $allowed ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			$allowed  = array_keys( $registry->get_all_registered() );
		}

		$dirs = glob( WICKED_INV_PLUGIN_PATH . 'blocks/*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) ) {
			$dirs = array();
		}

		$our_blocks = array_map(
			static fn( $dir ) => 'wicked-invoicing/' . basename( $dir ),
			$dirs
		);

		return array_values( array_diff( $allowed, $our_blocks ) );
	}

	private function get_template_page_id(): int {
		$settings = get_option( 'wicked_invoicing_settings', array() );
		if ( ! empty( $settings['invoice_template_id'] ) ) {
			return absint( $settings['invoice_template_id'] );
		}

		$q = new \WP_Query(
			array(
				'post_type'      => 'page',
				'posts_per_page' => 1,
				'title'          => 'Wicked Invoice Template',
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
			)
		);

		$id = ! empty( $q->posts[0] ) ? (int) $q->posts[0] : 0;
		wp_reset_postdata();

		return $id;
	}

	public function enqueue_frontend_block_styles( $hash ): void {
		$css_file_url  = plugin_dir_url( WICKED_INV_PLUGIN_FILE ) . 'includes/assets/invoice-style.css';
		$css_file_path = WICKED_INV_PLUGIN_PATH . 'includes/assets/invoice-style.css';

		$version = file_exists( $css_file_path ) ? filemtime( $css_file_path ) : '1.0';

		wp_enqueue_style(
			'wicked-invoicing-invoice-style',
			$css_file_url,
			array(),
			$version
		);
	}
}
