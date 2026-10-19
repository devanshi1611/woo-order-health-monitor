<?php
/**
 * Shared display helpers.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formatting and label helpers for admin UI.
 */
class OHM_Helpers {

	/**
	 * Human-readable issue type label.
	 *
	 * @param string $type Issue type.
	 * @return string
	 */
	public static function issue_type_label( $type ) {
		$labels = array(
			'failed'     => __( 'Failed order', 'woo-order-health-monitor' ),
			'pending'    => __( 'Pending payment (aged)', 'woo-order-health-monitor' ),
			'processing' => __( 'Processing (aged)', 'woo-order-health-monitor' ),
			'on_hold'    => __( 'On hold (aged)', 'woo-order-health-monitor' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Human-readable issue state label.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function issue_state_label( $state ) {
		$labels = array(
			'open'      => __( 'Open', 'woo-order-health-monitor' ),
			'resolved'  => __( 'Resolved', 'woo-order-health-monitor' ),
			'dismissed' => __( 'Dismissed', 'woo-order-health-monitor' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * Format a GMT MySQL datetime for display in site timezone.
	 *
	 * @param string|null $gmt_datetime GMT datetime string.
	 * @return string
	 */
	public static function format_datetime( $gmt_datetime ) {
		if ( empty( $gmt_datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( $gmt_datetime . ' UTC' );

		if ( ! $timestamp ) {
			return '—';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Admin URL to the WooCommerce order edit screen.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function order_edit_url( $order_id ) {
		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			return '';
		}

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && is_callable( array( $order, 'get_edit_order_url' ) ) ) {
				return $order->get_edit_order_url();
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Issues list URL with filters.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function issues_url( $args = array() ) {
		$args = array_merge( array( 'page' => 'ohm-issues' ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Scan status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function scan_status_label( $status ) {
		$labels = array(
			'running'     => __( 'Running', 'woo-order-health-monitor' ),
			'completed'   => __( 'Completed', 'woo-order-health-monitor' ),
			'failed'      => __( 'Failed', 'woo-order-health-monitor' ),
			'interrupted' => __( 'Interrupted', 'woo-order-health-monitor' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
