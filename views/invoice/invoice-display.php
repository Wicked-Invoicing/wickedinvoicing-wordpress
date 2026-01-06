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
	$wicked_invoicing_hash = get_query_var( 'wicked_invoicing_invoice_hash' );
	$wicked_invoicing_hash = is_string( $wicked_invoicing_hash ) ? trim( $wicked_invoicing_hash ) : '';

	if ( '' === $wicked_invoicing_hash ) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	$invoice_cpt_slug = Wicked_Controller::get( 'invoice' )->get_cpt_slug();

	// Public statuses only.
	$wicked_invoicing_public_statuses = array( 'pending', 'deposit-paid', 'paid' );

	$wicked_invoicing_invoice_posts = get_posts(
		array(
			'post_type'   => $invoice_cpt_slug,
			'meta_key'    => '_wicked_invoicing_hash',
			'meta_value'  => $wicked_invoicing_hash,
			'post_status' => $wicked_invoicing_public_statuses,
			'numberposts' => 1,
		)
	);

	// If not found and user can view temp, allow temp lookup.
	if (
		empty( $wicked_invoicing_invoice_posts )
		&& ( current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' ) )
	) {
		$wicked_invoicing_invoice_posts = get_posts(
			array(
				'post_type'   => $invoice_cpt_slug,
				'meta_key'    => '_wicked_invoicing_hash',
				'meta_value'  => $wicked_invoicing_hash,
				'post_status' => array( 'temp' ),
				'numberposts' => 1,
			)
		);
	}

	if ( empty( $wicked_invoicing_invoice_posts ) ) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	$wicked_invoicing_invoice = $wicked_invoicing_invoice_posts[0];

	// Hard safety: never show temp publicly.
	if (
		'temp' === $wicked_invoicing_invoice->post_status
		&& ! ( current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' ) )
	) {
		status_header( 404 );
		echo esc_html__( 'Invoice not found.', 'wicked-invoicing' );
		return;
	}

	// -----------------------------------------------------------------------------
	// 2. Load template page (Wicked Invoice Template)
	// -----------------------------------------------------------------------------
	$wicked_invoicing_settings = get_option( 'wicked_invoicing_settings', array() );
	$wicked_invoicing_settings = is_array( $wicked_invoicing_settings ) ? $wicked_invoicing_settings : array();

	$wicked_invoicing_template_id   = absint( $wicked_invoicing_settings['invoice_template_id'] ?? 0 );
	$wicked_invoicing_template_page = $wicked_invoicing_template_id ? get_post( $wicked_invoicing_template_id ) : null;

	if ( ! $wicked_invoicing_template_page ) {
		$wicked_invoicing_template_q = new \WP_Query(
			array(
				'post_type'      => 'page',
				'title'          => 'Wicked Invoice Template',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
			)
		);

		$wicked_invoicing_template_page = $wicked_invoicing_template_q->have_posts() ? $wicked_invoicing_template_q->posts[0] : null;
		wp_reset_postdata();
	}

	if ( ! $wicked_invoicing_template_page ) {
		wp_die( esc_html__( 'Invoice template missing.', 'wicked-invoicing' ) );
	}

	$wicked_invoicing_max_width = get_post_meta( $wicked_invoicing_template_page->ID, '_wicked_invoicing_template_max_width', true );
	$wicked_invoicing_max_width = $wicked_invoicing_max_width ? $wicked_invoicing_max_width : ( $wicked_invoicing_settings['invoice_template_width'] ?? '100%' );

	$wicked_invoicing_hide_title = (bool) get_post_meta( $wicked_invoicing_template_page->ID, '_wicked_invoicing_hide_title', true );

	// -----------------------------------------------------------------------------
	// 3. Hijack global $post so blocks (and invoice field blocks) render correctly
	// -----------------------------------------------------------------------------
	global $post;
	$wicked_invoicing_old_post = $post;
	$post                      = $wicked_invoicing_invoice;
	setup_postdata( $post );

	// -----------------------------------------------------------------------------
	// 4. Decide whether to use site header/footer
	// -----------------------------------------------------------------------------
	$wicked_invoicing_meta_wrapper = get_post_meta( $wicked_invoicing_template_page->ID, '_wicked_invoicing_use_theme_wrapper', true );

	if ( '' !== $wicked_invoicing_meta_wrapper ) {
		$wicked_invoicing_use_theme_wrapper = (bool) $wicked_invoicing_meta_wrapper;
	} else {
		$wicked_invoicing_use_theme_wrapper = ! empty( $wicked_invoicing_settings['use_theme_wrapper'] );
	}

	$wicked_invoicing_is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

	// -----------------------------------------------------------------------------
	// 5. Open wrapper (theme or bare)
	// -----------------------------------------------------------------------------
	if ( $wicked_invoicing_use_theme_wrapper ) {
		if ( $wicked_invoicing_is_block_theme ) {
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
			if ( function_exists( 'wp_body_open' ) ) {
				wp_body_open();
			}

			// Render the theme's header template part in block themes.
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'header' );
			} elseif ( function_exists( 'wp_template_part' ) ) {
				wp_template_part( 'header' );
			}
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
			<meta name="theme-color" content="#000">
			<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'wi-invoice-body' ); ?>>
		<?php
		if ( function_exists( 'wp_body_open' ) ) {
			wp_body_open();
		}
	}

	?>
	<div class="wi-invoice-container" style="width:100%; max-width:<?php echo esc_attr( $wicked_invoicing_max_width ); ?>; margin:0 auto;">
		<?php if ( ! $wicked_invoicing_hide_title ) : ?>
			<h1 class="wi-template-title"><?php echo esc_html( $wicked_invoicing_template_page->post_title ); ?></h1>
		<?php endif; ?>

		<div class="wicked-invoice-content">
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP hook.
			echo wp_kses_post( apply_filters( 'the_content', $wicked_invoicing_template_page->post_content ) );
			?>
		</div>
	</div>
	<?php

	// -----------------------------------------------------------------------------
	// 6. Close wrapper & restore global $post
	// -----------------------------------------------------------------------------
	if ( $wicked_invoicing_use_theme_wrapper ) {
		if ( $wicked_invoicing_is_block_theme ) {
			// Render the theme's footer template part in block themes.
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'footer' );
			} elseif ( function_exists( 'wp_template_part' ) ) {
				wp_template_part( 'footer' );
			}

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
	$post = $wicked_invoicing_old_post;
}

wicked_invoicing_render_invoice_display_template();
