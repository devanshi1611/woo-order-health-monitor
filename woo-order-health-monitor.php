<?php
/**
 * Plugin Name: WooCommerce Order Health Monitor
 * Description: Monitor WooCommerce orders for potential issues.
 * Version: 1.0.0
 * Author: Devanshi Mistry
 * Text Domain: woo-order-health-monitor
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'OHM_VERSION', '1.0.0' );
define( 'OHM_DB_VERSION', '1.1.0' );
define( 'OHM_PATH', plugin_dir_path( __FILE__ ) );
define( 'OHM_URL', plugin_dir_url( __FILE__ ) );
define( 'OHM_BASENAME', plugin_basename( __FILE__ ) );

require_once OHM_PATH . 'includes/class-ohm-autoloader.php';
OHM_Autoloader::register();

/**
 * Bootstrap the plugin after WordPress (and ideally WooCommerce) have loaded.
 */
function ohm_init() {
	OHM_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'ohm_init' );

/**
 * Declare HPOS compatibility before WooCommerce initializes feature flags.
 */
function ohm_declare_hpos_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		__FILE__,
		true
	);
}
add_action( 'before_woocommerce_init', 'ohm_declare_hpos_compatibility' );

register_activation_hook( __FILE__, array( 'OHM_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OHM_Installer', 'deactivate' ) );
