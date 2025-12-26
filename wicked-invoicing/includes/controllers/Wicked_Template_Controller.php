<?php
namespace Wicked_Invoicing\Controllers;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wicked_Template_Controller
 *
 * Handles front-end invoice URL routing, template loading, front-end block style enqueuing,
 * and meta boxes/options for the "Wicked Invoice Template" page.
 *
 * Public access rules:
 * - Public can view: pending | deposit-paid | paid
 * - temp is restricted to users who can edit invoices (admins/edit permission)
 */
class Wicked_Template_Controller extends Wicked_Base_Controller {

	public function __construct() {
		add_action( 'init', array( $this, 'log_constructor' ) );
		add_action( 'init', array( $this, 'add_frontend_routes' ), 10 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		add_action( 'init', array( $this, 'register_invoice_pattern_category' ), 5 );
		add_action( 'init', array( $this, 'register_invoice_block_patterns' ), 10 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_page', array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'add_invoice_body_class' ) );

		if ( is_admin() ) {
			add_filter( 'default_content', array( $this, 'maybe_load_invoice_skeleton' ), 10, 2 );
			add_filter( 'rest_prepare_page', array( $this, 'override_rest_page_content' ), 10, 3 );

			add_action( 'add_meta_boxes_page', array( $this, 'wicked_invoicing_add_invoice_template_metabox' ) );
			add_action( 'save_post_page', array( $this, 'wicked_invoicing_save_invoice_template_metabox' ), 10, 2 );
		}

		if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			add_filter( 'template_include', array( $this, 'template_router' ), 99 );

			add_filter( 'redirect_canonical', array( $this, 'maybe_skip_invoice_canonical' ), 10, 2 );
			add_action( 'parse_request', array( $this, 'maybe_parse_invoice_request' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'wicked_inv_enqueue_frontend_block_styles' ) );
		}
	}

	private function get_public_invoice_statuses(): array {
		return array( 'pending', 'deposit-paid', 'paid' );
	}

	private function current_user_can_view_temp(): bool {
		return current_user_can( 'manage_wicked_invoicing' ) || current_user_can( 'edit_wicked_invoices' );
	}

	public function register_invoice_pattern_category() {
		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				'wicked-invoices',
				array( 'label' => __( 'Wicked Invoices', 'wicked-invoicing' ) )
			);
		}
	}

	public function register_invoice_block_patterns() {
		$free = WICKED_INV_PLUGIN_PATH . 'views/invoice/template-skeleton-free.html';
		if ( file_exists( $free ) && function_exists( 'register_block_pattern' ) ) {
			register_block_pattern(
				'wicked-invoicing/default-invoice-free',
				array(
					'title'      => __( 'Default Invoice (Free)', 'wicked-invoicing' ),
					'categories' => array( 'wicked-invoices' ),
					'content'    => file_get_contents( $free ),
				)
			);
		}

		if ( defined( 'WICKED_INV_PRO' ) ) {
			$pro = WICKED_INV_PLUGIN_PATH . 'views/invoice/template-skeleton-pro.html';
			if ( file_exists( $pro ) && function_exists( 'register_block_pattern' ) ) {
				register_block_pattern(
					'wicked-invoicing/default-invoice-pro',
					array(
						'title'      => __( 'Default Invoice (Pro)', 'wicked-invoicing' ),
						'categories' => array( 'wicked-invoices' ),
						'content'    => file_get_contents( $pro ),
					)
				);
			}
		}
	}

	public function maybe_skip_invoice_canonical( $redirect, $requested ) {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $redirect;
		}

		$opts = get_option( 'wicked_invoicing_settings', array() );
		$slug = isset( $opts['invoice_slug'] ) ? sanitize_title( (string) $opts['invoice_slug'] ) : 'wicked-invoicing';

		$path = wp_parse_url( $requested, PHP_URL_PATH );
		if ( is_string( $path ) && preg_match( '#^/' . preg_quote( $slug, '#' ) . '/[^/]+/?$#', $path ) ) {
			return false;
		}

		return $redirect;
	}

	/**
	 * Add a meta box only on the Wicked Invoice Template page.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function wicked_invoicing_add_invoice_template_metabox( $post ) {
		$settings = get_option( 'wicked_invoicing_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$template_id = isset( $settings['invoice_template_id'] ) ? absint( $settings['invoice_template_id'] ) : 0;

		if ( ! $template_id || $post->ID !== $template_id ) {
			return;
		}

		add_meta_box(
			'wicked_invoicing_template_options',
			__( 'Wicked Invoice Template Options', 'wicked-invoicing' ),
			array( $this, 'wicked_invoicing_render_invoice_template_metabox' ),
			'page',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function wicked_invoicing_render_invoice_template_metabox( $post ) {
		wp_nonce_field( 'wicked_invoicing_template_options', 'wicked_invoicing_template_options_nonce' );

		$use_theme_wrapper = (bool) get_post_meta( $post->ID, '_wi_use_theme_wrapper', true );
		?>
		<p>
			<label>
				<input type="checkbox" name="wi_use_theme_wrapper" value="1" <?php checked( $use_theme_wrapper ); ?> />
				<?php esc_html_e( 'Display site header and footer for invoices', 'wicked-invoicing' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'When checked, invoices will use your active theme header and footer. Leave unchecked to render invoices without the theme wrapper.', 'wicked-invoicing' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the invoice template options meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function wicked_invoicing_save_invoice_template_metabox( $post_id, $post ) {
		$nonce = isset( $_POST['wicked_invoicing_template_options_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['wicked_invoicing_template_options_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wicked_invoicing_template_options' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$settings    = get_option( 'wicked_invoicing_settings', array() );
		$settings    = is_array( $settings ) ? $settings : array();
		$template_id = isset( $settings['invoice_template_id'] ) ? absint( $settings['invoice_template_id'] ) : 0;

		if ( $post_id !== $template_id ) {
			return;
		}

		$use_wrapper = ! empty( $_POST['wi_use_theme_wrapper'] ) ? '1' : '0';
		update_post_meta( $post_id, '_wi_use_theme_wrapper', $use_wrapper );
	}

	public function maybe_parse_invoice_request( $wp ) {
		if ( ! ( $wp instanceof \WP ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$uri = $this->get_request_uri();
		if ( '' === $uri ) {
			return;
		}

		if ( strpos( $uri, '/wp-json/' ) !== false ) {
			return;
		}

		if ( get_query_var( 'wi_invoice_hash' ) ) {
			return;
		}

		$opts = get_option( 'wicked_invoicing_settings', array() );
		$slug = isset( $opts['invoice_slug'] ) ? sanitize_title( (string) $opts['invoice_slug'] ) : 'wicked-invoicing';

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return;
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) && '' !== $home_path ? $home_path : '/';

		if ( $home_path !== '/' && function_exists( 'str_starts_with' ) && str_starts_with( $path, $home_path ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}

		if ( preg_match( '#^/' . preg_quote( $slug, '#' ) . '/([^/]+)/?$#', $path, $m ) ) {
			$hash                              = $m[1];
			$wp->query_vars['wi_invoice_hash'] = $hash;
			$wp->matched_rule                  = "^{$slug}/([^/]+)/?$";
			$wp->matched_query                 = "wi_invoice_hash={$hash}";
			add_filter( 'redirect_canonical', '__return_false', 99 );
		}
	}

	public function maybe_load_invoice_skeleton( string $content, WP_Post $post ): string {
		$tmpl_id = $this->get_template_page_id();
		if ( 'page' !== $post->post_type || $post->ID !== $tmpl_id ) {
			return $content;
		}

		$file = WICKED_INV_PLUGIN_PATH
			. ( defined( 'WICKED_INV_PRO' )
				? 'views/invoice/template-skeleton-pro.html'
				: 'views/invoice/template-skeleton-free.html'
			);

		if ( file_exists( $file ) ) {
			return file_get_contents( $file );
		}

		return $content;
	}

	public function override_rest_page_content( $response, $post, $request ) {
		$tmpl_id = $this->get_template_page_id();
		if ( ! $post || (int) $post->ID !== (int) $tmpl_id ) {
			return $response;
		}

		$content = (string) get_post_field( 'post_content', $tmpl_id );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using core 'the_content' filter intentionally.
		$rendered = apply_filters( 'the_content', $content );

		$response->data['content']['raw']      = $content;
		$response->data['content']['rendered'] = $rendered;
		return $response;
	}

	protected function get_template_page_id(): int {
		$settings = get_option( 'wicked_invoicing_settings', array() );
		return absint( $settings['invoice_template_id'] ?? 0 );
	}

	public function add_invoice_body_class( $classes ) {
		if ( get_query_var( 'wi_invoice_hash' ) ) {
			$classes[] = 'wi-invoice-container';
		}
		return $classes;
	}

	public function log_constructor() {
		if ( Wicked_Base_Controller::is_debug_mode() ) {
			do_action( 'wicked_invoicing_info', '[Wicked_Template_Controller] hooked init & filters', array() );
		}
	}

	public static function get_invoice_url( string $hash ): string {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		$slug = isset( $opts['invoice_slug'] ) ? sanitize_title( (string) $opts['invoice_slug'] ) : 'wicked-invoicing';

		if ( get_option( 'permalink_structure' ) ) {
			return home_url( trailingslashit( $slug ) . rawurlencode( $hash ) );
		}

		return add_query_arg( 'wi_invoice_hash', rawurlencode( $hash ), home_url( '/' ) );
	}

	public function add_frontend_routes() {
		$opts = get_option( 'wicked_invoicing_settings', array() );
		$slug = isset( $opts['invoice_slug'] ) ? sanitize_title( (string) $opts['invoice_slug'] ) : 'wicked-invoicing';

		add_rewrite_tag( '%wi_invoice_hash%', '([^/]+)' );
		add_rewrite_rule(
			"^{$slug}/([^/]+)/?$",
			'index.php?wi_invoice_hash=$matches[1]',
			'top'
		);
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'wi_invoice_hash';
		return $vars;
	}

	public function template_router( $template ) {
		$hash = (string) get_query_var( 'wi_invoice_hash', '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
		if ( '' === $hash && isset( $_GET['wi_invoice_hash'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing param, not processing a form action.
			$hash = sanitize_text_field( wp_unslash( $_GET['wi_invoice_hash'] ) );
		}

		$hash = trim( $hash );
		if ( '' === $hash ) {
			return $template;
		}

		if ( Wicked_Base_Controller::is_debug_mode() ) {
			$req_uri = $this->get_request_uri();
			do_action(
				'wicked_invoicing_info',
				'[Template] invoice hash route hit',
				array(
					'hash' => $hash,
					'uri'  => $req_uri,
				)
			);
		}

		$invoice = get_posts(
			array(
				'post_type'   => \Wicked_Invoicing\Wicked_Controller::get( 'invoice' )->get_cpt_slug(),
				'meta_key'    => '_wi_hash',
				'meta_value'  => $hash,
				'post_status' => $this->get_public_invoice_statuses(),
				'numberposts' => 1,
			)
		);

		if ( empty( $invoice ) && $this->current_user_can_view_temp() ) {
			$invoice = get_posts(
				array(
					'post_type'   => \Wicked_Invoicing\Wicked_Controller::get( 'invoice' )->get_cpt_slug(),
					'meta_key'    => '_wi_hash',
					'meta_value'  => $hash,
					'post_status' => array( 'temp' ),
					'numberposts' => 1,
				)
			);
		}

		if ( empty( $invoice ) ) {
			status_header( 404 );
			return get_404_template();
		}

		$invoice = $invoice[0];

		if ( $invoice->post_status === 'temp' && ! $this->current_user_can_view_temp() ) {
			status_header( 404 );
			return get_404_template();
		}

		status_header( 200 );
		return WICKED_INV_PLUGIN_PATH . 'views/invoice/invoice-display.php';
	}

	public function wicked_inv_enqueue_frontend_block_styles() {
		if ( is_admin() ) {
			return;
		}

		$css_file_url  = plugin_dir_url( WICKED_INV_PLUGIN_FILE ) . 'includes/assets/invoice-style.css';
		$css_file_path = WICKED_INV_PLUGIN_PATH . 'includes/assets/invoice-style.css';
		$version       = file_exists( $css_file_path ) ? filemtime( $css_file_path ) : '1.0';

		wp_enqueue_style(
			'wicked-invoicing-invoice-style',
			$css_file_url,
			array(),
			$version
		);
	}

	public function add_meta_box() {
		$settings    = get_option( 'wicked_invoicing_settings', array() );
		$template_id = absint( $settings['invoice_template_id'] ?? 0 );

		if ( ! $template_id ) {
			$pages       = get_posts(
				array(
					'title'       => 'Wicked Invoice Template',
					'post_type'   => 'page',
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$template_id = $pages[0] ?? 0;
		}

		if ( ! $template_id ) {
			return;
		}

		add_meta_box(
			'wi_template_options',
			__( 'Invoice Template Options', 'wicked-invoicing' ),
			array( $this, 'render_meta_box' ),
			'page',
			'side',
			'default',
			array( 'page_id' => $template_id )
		);
	}

	public function render_meta_box( $post, $args ) {
		if ( $post->ID !== $args['args']['page_id'] ) {
			return;
		}

		wp_nonce_field( 'wi_template_options', 'wi_template_options_nonce' );

		$hide_title = (bool) get_post_meta( $post->ID, '_wi_hide_title', true );
		$max_width  = get_post_meta( $post->ID, '_wi_template_max_width', true );
		?>
		<p>
			<label>
				<input type="checkbox" name="wi_hide_title" value="1" <?php checked( $hide_title, true ); ?> />
				<?php esc_html_e( 'Hide Invoice Template Title', 'wicked-invoicing' ); ?>
			</label>
		</p>

		<p>
			<label for="wi_template_max_width">
				<?php esc_html_e( 'Invoice Max-Width (e.g. 800px or 75%)', 'wicked-invoicing' ); ?>
			</label><br/>
			<input
				type="text"
				id="wi_template_max_width"
				name="wi_template_max_width"
				value="<?php echo esc_attr( $max_width ?: '100%' ); ?>"
				placeholder="100%"
				style="width:6em"
			/>
		</p>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$nonce = isset( $_POST['wi_template_options_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['wi_template_options_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wi_template_options' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$hide = ! empty( $_POST['wi_hide_title'] );
		if ( $hide ) {
			update_post_meta( $post_id, '_wi_hide_title', '1' );
		} else {
			delete_post_meta( $post_id, '_wi_hide_title' );
		}

		if ( isset( $_POST['wi_template_max_width'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['wi_template_max_width'] ) );
			if ( preg_match( '/^\d+(?:\.\d+)?\s*(px|em|rem|%|vw|vh)$/i', $val ) ) {
				update_post_meta( $post_id, '_wi_template_max_width', $val );
			} else {
				update_post_meta( $post_id, '_wi_template_max_width', '100%' );
			}
		}
	}

	/**
	 * Safe request URI for parsing/logging.
	 */
	private function get_request_uri(): string {
		$uri = '';

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		}

		$uri = is_string( $uri ) ? $uri : '';

		// Strip control chars (defense-in-depth for logs/parsing).
		$uri = preg_replace( '/[\x00-\x1F\x7F]/u', '', $uri );

		return sanitize_text_field( $uri );
	}

	public function maybe_create_invoice_template_page() {
		$found = false;
		$q     = new \WP_Query(
			array(
				'post_type'      => 'page',
				'posts_per_page' => 1,
				'title'          => 'Wicked Invoice Template',
				'post_status'    => 'publish',
			)
		);
		if ( $q->have_posts() ) {
			$found = true;
		}
		wp_reset_postdata();

		if ( ! $found ) {
			$skeleton_file = WICKED_INV_PLUGIN_PATH . 'views/invoice/template-skeleton-free.html';
			$content       = file_exists( $skeleton_file ) ? file_get_contents( $skeleton_file ) : '';

			$page_id = wp_insert_post(
				array(
					'post_title'   => 'Wicked Invoice Template',
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				true
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				add_post_meta( $page_id, '_wi_hide_title', '1', true );
				add_post_meta( $page_id, '_wi_template_max_width', '75%', true );

				$opts                           = (array) get_option( 'wicked_invoicing_settings', array() );
				$opts['invoice_template_id']    = $page_id;
				$opts['invoice_template_width'] = '75%';
				update_option( 'wicked_invoicing_settings', $opts );
			}
		}
	}
}
