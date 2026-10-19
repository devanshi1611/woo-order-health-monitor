<?php
/**
 * WP-Cron scheduling for recurring scans.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the recurring scan schedule.
 *
 * Note: WP-Cron is traffic-dependent. Events run when someone visits the site
 * after the scheduled time, not necessarily at the exact scheduled second.
 */
class OHM_Scheduler {

	const HOOK = 'ohm_run_scheduled_scan';

	/**
	 * Register cron callback.
	 */
	public function register_hooks() {
		add_action( self::HOOK, array( $this, 'run_scheduled_scan' ) );
		add_action( 'update_option_ohm_settings', array( $this, 'reschedule_on_settings_change' ), 10, 2 );
	}

	/**
	 * Schedule recurring scans based on settings.
	 */
	public static function schedule_recurring() {
		self::clear_scheduled();

		$frequency = OHM_Settings::get( 'scan_frequency', 'hourly' );
		$allowed   = array( 'hourly', 'twicedaily', 'daily' );

		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'hourly';
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::HOOK );
		}
	}

	/**
	 * Clear all plugin cron events.
	 */
	public static function clear_scheduled() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( OHM_Scanner::BATCH_HOOK );
	}

	/**
	 * Reschedule when frequency changes.
	 *
	 * @param mixed $old_value Old option.
	 * @param mixed $new_value New option.
	 */
	public function reschedule_on_settings_change( $old_value, $new_value ) {
		$old_freq = is_array( $old_value ) && isset( $old_value['scan_frequency'] ) ? $old_value['scan_frequency'] : '';
		$new_freq = is_array( $new_value ) && isset( $new_value['scan_frequency'] ) ? $new_value['scan_frequency'] : '';

		if ( $old_freq !== $new_freq ) {
			self::schedule_recurring();
		}
	}

	/**
	 * Cron callback: run a scheduled scan when WooCommerce is available.
	 */
	public function run_scheduled_scan() {
		if ( ! OHM_Plugin::is_woocommerce_active() ) {
			return;
		}

		if ( OHM_Scanner::is_running() ) {
			return;
		}

		$scanner = new OHM_Scanner();
		$scanner->start( 'scheduled' );
	}

	/**
	 * Next scheduled scan timestamp (site timezone display uses WP helpers in UI).
	 *
	 * @return int|false
	 */
	public static function next_scheduled() {
		return wp_next_scheduled( self::HOOK );
	}
}
