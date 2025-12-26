<?php
/**
 * Front-end Invoice Display Template
 *
 * Renders an invoice by public hash using the "Wicked Invoice Template" page content.
 *
 * @package wicked-invoicing
 */

namespace Wicked_Invoicing\Controllers;

use Wicked_Invoicing\Wicked_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the invoice display template.
 *
 * Wrapped in a function to avoid “global variable” PHPCS noise for templates.
 *
 * @return void
 */
function wicked_invoicing_render_invoice_display_template(): void {
	// -----------------------------------------------------------------------------
	// 1. Resolve invoice by hash (public + temp for privileged users)
	// -----------------------------------------------------------------------------
	$wi_hash = get_query_var( 'wi_invoice_hash' );
	$wi_hash = is_string( $wi_hash ) ? trim( $wi_hash ) : '';

	if ( '' === $wi_hash ) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	$invoice_cpt_slug = Wicked_Controller::get( 'invoice' )->get_cpt_slug();

	// Public statuses only.
	$wi_public_statuses = array( 'pending', 'deposit-paid', 'paid' );

	$wi_invoice_posts = get_posts(
		array(
			'post_type'   => $invoice_cpt_slug,
			'meta_key'    => '_wi_hash',
			'meta_value'  => $wi_hash,
			'post_status' => $wi_public_statuses,
			'numberposts' => 1,
		)
	);

	// If not found and user can view temp, allow temp lookup.
	if (
		empty( $wi_invoice_posts )
		&& ( current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' ) )
	) {
		$wi_invoice_posts = get_posts(
			array(
				'post_type'   => $invoice_cpt_slug,
				'meta_key'    => '_wi_hash',
				'meta_value'  => $wi_hash,
				'post_status' => array( 'temp' ),
				'numberposts' => 1,
			)
		);
	}

	if ( empty( $wi_invoice_posts ) ) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	$wi_invoice = $wi_invoice_posts[0];

	// Hard safety: never show temp publicly.
	if (
		'temp' === $wi_invoice->post_status
		&& ! ( current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' ) )
	) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	// -----------------------------------------------------------------------------
	// 2. Load template page (Wicked Invoice Template)
	// -----------------------------------------------------------------------------
	$wi_settings = get_option( 'wicked_invoicing_settings', array() );
	$wi_settings = is_array( $wi_settings ) ? $wi_settings : array();

	$wi_template_id   = absint( $wi_settings['invoice_template_id'] ?? 0 );
	$wi_template_page = $wi_template_id ? get_post( $wi_template_id ) : null;

	if ( ! $wi_template_page ) {
		$wi_template_q = new \WP_Query(
			array(
				'post_type'      => 'page',
				'title'          => 'Wicked Invoice Template',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
			)
		);

		$wi_template_page = $wi_template_q->have_posts() ? $wi_template_q->posts[0] : null;
		wp_reset_postdata();
	}

	if ( ! $wi_template_page ) {
		wp_die( esc_html__( 'Invoice template missing.', 'wicked-invoicing' ) );
	}

	$wi_max_width = get_post_meta( $wi_template_page->ID, '_wi_template_max_width', true );
	$wi_max_width = $wi_max_width ? $wi_max_width : ( $wi_settings['invoice_template_width'] ?? '100%' );

	$wi_hide_title = (bool) get_post_meta( $wi_template_page->ID, '_wi_hide_title', true );

	// -----------------------------------------------------------------------------
	// 3. Hijack global $post so blocks (and invoice field blocks) render correctly
	// -----------------------------------------------------------------------------
	global $post;
	$wi_old_post = $post;
	$post        = $wi_invoice;
	setup_postdata( $post );

	// -----------------------------------------------------------------------------
	// 4. Decide whether to use site header/footer
	// -----------------------------------------------------------------------------
	$wi_meta_wrapper = get_post_meta( $wi_template_page->ID, '_wi_use_theme_wrapper', true );

	if ( '' !== $wi_meta_wrapper ) {
		$wi_use_theme_wrapper = (bool) $wi_meta_wrapper;
	} else {
		$wi_use_theme_wrapper = ! empty( $wi_settings['use_theme_wrapper'] );
	}

	$wi_is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

	// -----------------------------------------------------------------------------
	// 5. Open wrapper (theme or bare)
	// -----------------------------------------------------------------------------
	if ( $wi_use_theme_wrapper ) {
		if ( $wi_is_block_theme ) {
			?>
			<!DOCTYPE html>
			<html <?php language_attributes(); ?>>
			<head>
				<meta charset="<?php bloginfo( 'charset' ); ?>">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<?php wp_head(); ?>
			</head>
			<body <?php body_class( 'wi-invoice-body' ); ?>>
			<?php
		} else {
			get_header();
		}
	} else {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'wi-invoice-body' ); ?>>
		<?php
	}

	?>
	<div class="wi-invoice-container" style="width:100%; max-width:<?php echo esc_attr( $wi_max_width ); ?>; margin:0 auto;">
		<?php if ( ! $wi_hide_title ) : ?>
			<h1 class="wi-template-title"><?php echo esc_html( $wi_template_page->post_title ); ?></h1>
		<?php endif; ?>

		<div class="wicked-invoice-content">
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP hook.
			echo wp_kses_post( apply_filters( 'the_content', $wi_template_page->post_content ) );
			?>
		</div>
	</div>
	<?php

	// -----------------------------------------------------------------------------
	// 6. Close wrapper & restore global $post
	// -----------------------------------------------------------------------------
	if ( $wi_use_theme_wrapper ) {
		if ( $wi_is_block_theme ) {
			wp_footer();
			?>
			</body>
			</html>
			<?php
		} else {
			get_footer();
		}
	} else {
		wp_footer();
		?>
		</body>
		</html>
		<?php
	}

	wp_reset_postdata();
	$post = $wi_old_post;
}

wicked_invoicing_render_invoice_display_template();
