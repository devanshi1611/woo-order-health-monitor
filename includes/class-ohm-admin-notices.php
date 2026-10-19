<?php
/**
 * Admin notice helpers.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders flash notices from query args.
 */
class OHM_Admin_Notices {

	/**
	 * Render notice based on ohm_notice query parameter.
	 */
	public static function render_from_query() {
		if ( empty( $_GET['ohm_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['ohm_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['ohm_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['ohm_notice_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ) {
			$type = 'info';
		}

		$custom = '';
		if ( ! empty( $_GET['ohm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$custom = sanitize_text_field( rawurldecode( wp_unslash( $_GET['ohm_msg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$messages = array(
			'scan_started'        => __( 'Scan started successfully.', 'woo-order-health-monitor' ),
			'scan_failed'         => __( 'Scan could not be started.', 'woo-order-health-monitor' ),
			'settings_saved'      => __( 'Settings saved.', 'woo-order-health-monitor' ),
			'settings_reset'      => __( 'Settings reset to defaults.', 'woo-order-health-monitor' ),
			'settings_invalid'    => __( 'Settings could not be saved.', 'woo-order-health-monitor' ),
			'issue_dismissed'     => __( 'Issue dismissed.', 'woo-order-health-monitor' ),
			'issue_reopened'      => __( 'Issue reopened.', 'woo-order-health-monitor' ),
			'issue_action_failed' => __( 'The issue action could not be completed.', 'woo-order-health-monitor' ),
			'wc_inactive'         => __( 'WooCommerce is not active. Scanning is unavailable.', 'woo-order-health-monitor' ),
		);

		$text = $custom ? $custom : ( isset( $messages[ $code ] ) ? $messages[ $code ] : '' );

		if ( '' === $text ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}
}
