<?php
namespace Wicked_Invoicing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load_class' ) );
	}

	public static function load_class( $class ) {
		if ( strpos( $class, __NAMESPACE__ . '\\Bundles\\' ) === 0 ) {
			return;
		}

		$prefix = __NAMESPACE__ . '\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) ); // e.g. 'Controllers\Wicked_Settings_Controller'
		$path     = str_replace( '\\', '/', $relative );

		$candidates = array();

		// Ignore bundles completely – they bootstrap themselves.
		$norm = ltrim( $path, '/' );
		if ( strpos( $path, 'Bundles/' ) === 0 ) {
			return;
		}

		// Map Controllers\Foo → includes/controllers/Foo.php (lowercase folder)
		if ( strpos( $path, 'Controllers/' ) === 0 ) {
			$tail         = substr( $path, strlen( 'Controllers/' ) );
			$candidates[] = WICKED_INV_PLUGIN_PATH . 'includes/controllers/' . $tail . '.php';
			// Back-compat fallbacks (only if some files still exist here):
			$candidates[] = WICKED_INV_PLUGIN_PATH . 'includes/Controllers/' . $tail . '.php';
			$candidates[] = WICKED_INV_PLUGIN_PATH . 'includes/blocks/' . $tail . '.php';
			$candidates[] = WICKED_INV_PLUGIN_PATH . 'controllers/' . $tail . '.php';
		} else {
			// Generic includes (e.g., Wicked_Controller, other top-level)
			$candidates[] = WICKED_INV_PLUGIN_PATH . 'includes/' . $path . '.php';
		}

		// Optional lowercase fallback (case-sensitive FS safety)
		$candidates[] = WICKED_INV_PLUGIN_PATH . 'includes/controllers/' . strtolower( basename( $path ) ) . '.php';

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return; }
		}

		// Uncomment to debug misses:
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional debug logging gated by constant.
			error_log( '[Wicked] Autoload miss: ' . $class . ' → ' . implode( ' | ', $candidates ) );
		}
	}
}
