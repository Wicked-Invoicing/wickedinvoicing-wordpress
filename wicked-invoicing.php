<?php

/**
 * Plugin Name: Wicked Invoicing
 * Plugin URI: https://wickedinvoicing.com
 * Description: Simple invoicing and billing for WordPress.
 * Version: 1.1.3
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Wicked Invoicing
 * Text Domain: wicked-invoicing
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------
if ( ! defined( 'WICKED_INV_PLUGIN_PATH' ) ) {
	define( 'WICKED_INV_PLUGIN_PATH', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'WICKED_INV_PLUGIN_FILE' ) ) {
	define( 'WICKED_INV_PLUGIN_FILE', __FILE__ ); }

// ---------------------------------------------------------------------
// Autoload Classes
// ---------------------------------------------------------------------

require_once WICKED_INV_PLUGIN_PATH . 'includes/class-autoloader.php';
\Wicked_Invoicing\Autoloader::register();

require_once WICKED_INV_PLUGIN_PATH . 'includes/Wicked_Controller.php';
\Wicked_Invoicing\Wicked_Controller::setup_hooks();

require_once WICKED_INV_PLUGIN_PATH . 'includes/functions.php';

require_once WICKED_INV_PLUGIN_PATH . 'includes/controllers/Wicked_Line_Items.php';

add_action(
	'init',
	function () {
		// Logging first so other controllers' do_action('wicked_invoicing_*') gets captured
		new \Wicked_Invoicing\Controllers\Wicked_Log_Controller();

		// Core
		new \Wicked_Invoicing\Controllers\Wicked_Roles_Controller();
		new \Wicked_Invoicing\Controllers\Wicked_Settings_Controller();
		new \Wicked_Invoicing\Controllers\Wicked_Invoice_Controller();

		// Front-end invoice routing + template skeleton + invoice CSS enqueue
		new \Wicked_Invoicing\Controllers\Wicked_Template_Controller();

		// Dynamic blocks registration
		new \Wicked_Invoicing\Controllers\Wicked_Block_Controller();

		// UI prefs + payments
		new \Wicked_Invoicing\Controllers\Wicked_UI_Controller();
		new \Wicked_Invoicing\Controllers\Wicked_Payments_Controller();

		// Optional controllers (instantiate if you use these endpoints/features)
		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Payments_REST_Controller' ) ) {
			new \Wicked_Invoicing\Controllers\Wicked_Payments_REST_Controller();
		}

		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Dashboard_Controller' ) ) {
			new \Wicked_Invoicing\Controllers\Wicked_Dashboard_Controller();
		}

		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Client_Controller' ) ) {
			new \Wicked_Invoicing\Controllers\Wicked_Client_Controller();
		}

		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Admin_Bar_Controller' ) ) {
			new \Wicked_Invoicing\Controllers\Wicked_Admin_Bar_Controller();
		}

		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Notifications_Controller' ) ) {
			new \Wicked_Invoicing\Controllers\Wicked_Notifications_Controller();
		}
	},
	1
);


// ---------------------------------------------------------------------
// Load textdomain
// ---------------------------------------------------------------------
add_action(
	'plugins_loaded',
	function () {
		// If your base does additional setup:
		if ( method_exists( '\Wicked_Invoicing\Wicked_Controller', 'setup' ) ) {
			\Wicked_Invoicing\Wicked_Controller::setup();
		}
	}
);



// ---------------------------------------------------------------------
// activation (rewrite flush only)
// ---------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	function () {
		// Roles/caps + settings seed
		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Roles_Controller' ) ) {
			\Wicked_Invoicing\Controllers\Wicked_Roles_Controller::activate();
		}

		// Ensure routes exist, then flush
		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Template_Controller' ) ) {
			( new \Wicked_Invoicing\Controllers\Wicked_Template_Controller() )->add_frontend_routes();
		}

		flush_rewrite_rules();
	}
);

// ---------------------------------------------------------------------
// Deactivation (rewrite flush only)
// ---------------------------------------------------------------------
register_deactivation_hook(
	WICKED_INV_PLUGIN_FILE,
	function () {
		flush_rewrite_rules();

		// clears out scheduled notification hook on deactivation
		if ( class_exists( '\Wicked_Invoicing\Controllers\Wicked_Notifications_Controller' ) ) {
			wp_clear_scheduled_hook( \Wicked_Invoicing\Controllers\Wicked_Notifications_Controller::CRON_HOOK );
		}
	}
);

// ---------------------------------------------------------------------
// Signal plugin is loaded
// ---------------------------------------------------------------------
do_action( 'wicked_invoicing_loaded' );
