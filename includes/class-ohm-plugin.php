<?php
/**
 * Main plugin coordinator.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots runtime services used on admin and cron requests.
 */
class OHM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var OHM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return OHM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize hooks and services.
	 */
	public function init() {
		OHM_Installer::maybe_upgrade();

		$scheduler = new OHM_Scheduler();
		$scheduler->register_hooks();

		$scanner = new OHM_Scanner();
		$scanner->register_hooks();

		if ( self::is_woocommerce_active() ) {
			// Track status entry times for more accurate age thresholds.
			add_action( 'woocommerce_order_status_changed', array( $this, 'record_status_entry_time' ), 10, 4 );
		}

		if ( is_admin() ) {
			new OHM_Admin();
		}
	}

	/**
	 * Store when an order entered its current status.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Previous status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order      Order object.
	 */
	public function record_status_entry_time( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_ohm_entered_status_at', time() );
		$order->update_meta_data( '_ohm_entered_status', $new_status );
		$order->save();
	}

	/**
	 * Whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}
}
