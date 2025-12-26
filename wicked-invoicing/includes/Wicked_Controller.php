<?php
namespace Wicked_Invoicing;

use Wicked_Invoicing\Controllers\Wicked_Settings_Controller;
use Wicked_Invoicing\Controllers\Wicked_Invoice_Controller;
use Wicked_Invoicing\Controllers\Wicked_Template_Controller;
use Wicked_Invoicing\Controllers\Wicked_Block_Controller;
use Wicked_Invoicing\Controllers\Wicked_Admin_Controller;
use Wicked_Invoicing\Controllers\Wicked_Notifications_Controller;
use Wicked_Invoicing\Controllers\Wicked_Dashboard_Controller;
use Wicked_Invoicing\Controllers\Wicked_Roles_Controller;
use Wicked_Invoicing\Controllers\Wicked_Log_Controller;
use Wicked_Invoicing\Controllers\Wicked_Client_Controller;
use Wicked_Invoicing\Controllers\Wicked_UI_Controller;
use Wicked_Invoicing\Controllers\Wicked_Admin_Bar_Controller;

use Wicked_Invoicing\Model\WI_Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main controller class for Wicked Invoicing.
 * Singleton pattern with lazy-loading access to all components.
 */
class Wicked_Controller {

	private static $instance;

	// Public component access
	public $settings;
	public $invoice;
	public $template;
	public $blocks;
	public $admin;
	public $notifications;
	public $dashboard;
	public $roles;
	public $logs;
	public $client;
	public $ui;
	public $adminbar;

	// Registry for models/helpers
	protected static $registry = array();

	/**
	 * Initialize statically loaded components
	 */
	public static function init() {
		self::$registry['invoice_model'] = new WI_Invoice();
	}

	/**
	 * Create & store singleton
	 */
	private function __construct() {
		$this->settings      = new Wicked_Settings_Controller();
		$this->invoice       = new Wicked_Invoice_Controller();
		$this->template      = new Wicked_Template_Controller();
		$this->blocks        = new Wicked_Block_Controller();
		$this->admin         = new Wicked_Admin_Controller();
		$this->notifications = new Wicked_Notifications_Controller();
		$this->dashboard     = new Wicked_Dashboard_Controller();
		$this->roles         = new Wicked_Roles_Controller();
		$this->logs          = new Wicked_Log_Controller();
		$this->client        = new Wicked_Client_Controller();
		$this->ui            = new Wicked_UI_Controller();
		$this->adminbar      = new Wicked_Admin_Bar_Controller();
	}

	/**
	 * Get singleton instance
	 */
	public static function instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Access a controller by key name
	 */
	public static function get( string $key ) {
		$instance = self::instance();
		return $instance->$key ?? null;
	}

	/**
	 * Access the invoice model
	 */
	public static function invoice(): WI_Invoice {
		return self::$registry['invoice_model'];
	}

	public static function setup_hooks() {
		// Activation: Create roles, grant caps, register routes, create page, etc.
		register_activation_hook(
			WICKED_INV_PLUGIN_FILE,
			function () {
				$controller = self::instance();

				// 1. Roles
				$controller->roles->activate();

				// 2. Invoice Template routes
				$controller->template->add_frontend_routes();

				// 3. Create default Invoice Template page
				$controller->template->maybe_create_invoice_template_page();

				// 4. Create logs table
				global $wpdb;
				$table   = $wpdb->prefix . 'wicked_invoice_logs';
				$charset = $wpdb->get_charset_collate();
				$sql     = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                log_date DATETIME NOT NULL,
                level VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                PRIMARY KEY (id)
            ) $charset;";
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $sql );

				// 5. Flush rewrite rules
				flush_rewrite_rules();
			}
		);

		register_deactivation_hook(
			WICKED_INV_PLUGIN_FILE,
			function () {
				$controller = self::instance();
				$controller->roles->deactivate();
				flush_rewrite_rules();
			}
		);
	}

	public static function setup() {
		// Instantiate everything
		self::instance();
	}

	public static function is_invoice_request(): bool {
		return '' !== get_query_var( 'wicked_invoice_id', '' );
	}

	/**
	 * Proxy to the Invoice controller’s list method.
	 */
	public static function list_invoices( array $args ): array {
		return self::get( 'invoice' )->list_invoices( $args );
	}
}

// Boot the model registry
Wicked_Controller::init();
