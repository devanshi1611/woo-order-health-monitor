<?php
/**
 * Activation, deactivation, and schema upgrades.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates plugin tables and manages lifecycle hooks.
 */
class OHM_Installer {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'ohm_db_version', OHM_DB_VERSION );

		if ( false === get_option( 'ohm_settings', false ) ) {
			add_option( 'ohm_settings', OHM_Settings::defaults(), '', false );
		}

		OHM_Scheduler::schedule_recurring();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Clears scheduled events but retains stored issues and settings.
	 */
	public static function deactivate() {
		OHM_Scheduler::clear_scheduled();
		OHM_Scanner::release_lock();
		delete_transient( 'ohm_scan_lock' );
	}

	/**
	 * Upgrade schema when the stored DB version lags behind the plugin.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'ohm_db_version', '' );

		if ( OHM_DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		update_option( 'ohm_db_version', OHM_DB_VERSION );

		if ( false === get_option( 'ohm_settings', false ) ) {
			add_option( 'ohm_settings', OHM_Settings::defaults(), '', false );
		}

		OHM_Scheduler::schedule_recurring();
	}

	/**
	 * Create or update plugin-owned tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$issues_table    = $wpdb->prefix . 'ohm_issues';
		$scans_table     = $wpdb->prefix . 'ohm_scan_history';

		$sql_issues = "CREATE TABLE {$issues_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			issue_key varchar(191) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			issue_type varchar(32) NOT NULL,
			description text NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'open',
			first_detected datetime NOT NULL,
			last_checked datetime NOT NULL,
			resolved_at datetime DEFAULT NULL,
			dismissed_at datetime DEFAULT NULL,
			meta longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY issue_key (issue_key),
			KEY order_id (order_id),
			KEY issue_type (issue_type),
			KEY state (state),
			KEY state_type (state, issue_type),
			KEY first_detected (first_detected),
			KEY dismissed_at (dismissed_at),
			KEY resolved_at (resolved_at)
		) {$charset_collate};";

		$sql_scans = "CREATE TABLE {$scans_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			trigger_source varchar(20) NOT NULL DEFAULT 'manual',
			orders_processed int(11) unsigned NOT NULL DEFAULT 0,
			issues_created int(11) unsigned NOT NULL DEFAULT 0,
			issues_updated int(11) unsigned NOT NULL DEFAULT 0,
			issues_resolved int(11) unsigned NOT NULL DEFAULT 0,
			errors_count int(11) unsigned NOT NULL DEFAULT 0,
			message text DEFAULT NULL,
			meta longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY started_at (started_at),
			KEY trigger_source (trigger_source),
			KEY status_started (status, started_at)
		) {$charset_collate};";

		dbDelta( $sql_issues );
		dbDelta( $sql_scans );
	}

	/**
	 * Issues table name.
	 *
	 * @return string
	 */
	public static function issues_table() {
		global $wpdb;
		return $wpdb->prefix . 'ohm_issues';
	}

	/**
	 * Scan history table name.
	 *
	 * @return string
	 */
	public static function scans_table() {
		global $wpdb;
		return $wpdb->prefix . 'ohm_scan_history';
	}
}
