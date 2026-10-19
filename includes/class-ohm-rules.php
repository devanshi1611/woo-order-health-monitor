<?php
/**
 * Order health detection rules.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates WooCommerce orders against configured detection rules.
 *
 * Timestamp strategy:
 * - failed: no age check; current status must be failed.
 * - pending: age measured from order date_created (pending typically begins at creation).
 * - processing / on_hold: prefer `_ohm_entered_status_at` meta written on status change;
 *   fall back to date_modified when meta is missing. date_modified can reset when the
 *   order is edited, so age may under-report until a status transition is recorded.
 */
class OHM_Rules {

	/**
	 * Map issue types to WooCommerce statuses.
	 *
	 * @return array<string,string>
	 */
	public static function type_status_map() {
		return array(
			'failed'     => 'failed',
			'pending'    => 'pending',
			'processing' => 'processing',
			'on_hold'    => 'on-hold',
		);
	}

	/**
	 * WC statuses that should be queried for the current settings.
	 *
	 * @return array
	 */
	public function get_statuses_to_scan() {
		$map      = self::type_status_map();
		$statuses = array();

		foreach ( $map as $type => $status ) {
			if ( OHM_Settings::is_rule_enabled( $type ) ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Evaluate a single order and return matching issue payloads.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int,array{type:string,description:string,meta:array}>
	 */
	public function evaluate_order( $order ) {
		$matches = array();

		if ( ! $order instanceof WC_Order ) {
			return $matches;
		}

		$status = $order->get_status(); // Without wc- prefix.

		if ( OHM_Settings::is_rule_enabled( 'failed' ) && 'failed' === $status ) {
			$matches[] = array(
				'type'        => 'failed',
				'description' => sprintf(
					/* translators: %d: order ID */
					__( 'Order #%d is in failed status and needs attention.', 'woo-order-health-monitor' ),
					$order->get_id()
				),
				'meta'        => array(
					'status' => $status,
				),
			);
		}

		if ( OHM_Settings::is_rule_enabled( 'pending' ) && 'pending' === $status ) {
			$age = $this->get_pending_age_seconds( $order );
			$threshold = OHM_Settings::get_threshold_hours( 'pending' ) * HOUR_IN_SECONDS;

			if ( null !== $age && $age >= $threshold ) {
				$matches[] = array(
					'type'        => 'pending',
					'description' => sprintf(
						/* translators: 1: order ID, 2: hours */
						__( 'Order #%1$d has remained pending payment for approximately %2$d hours.', 'woo-order-health-monitor' ),
						$order->get_id(),
						(int) floor( $age / HOUR_IN_SECONDS )
					),
					'meta'        => array(
						'status'           => $status,
						'age_seconds'      => $age,
						'threshold_hours'  => OHM_Settings::get_threshold_hours( 'pending' ),
						'timestamp_source' => 'date_created',
					),
				);
			}
		}

		if ( OHM_Settings::is_rule_enabled( 'processing' ) && 'processing' === $status ) {
			$info = $this->get_status_age( $order, 'processing' );
			$threshold = OHM_Settings::get_threshold_hours( 'processing' ) * HOUR_IN_SECONDS;

			if ( null !== $info['age'] && $info['age'] >= $threshold ) {
				$matches[] = array(
					'type'        => 'processing',
					'description' => sprintf(
						/* translators: 1: order ID, 2: hours */
						__( 'Order #%1$d has remained in processing for approximately %2$d hours.', 'woo-order-health-monitor' ),
						$order->get_id(),
						(int) floor( $info['age'] / HOUR_IN_SECONDS )
					),
					'meta'        => array(
						'status'           => $status,
						'age_seconds'      => $info['age'],
						'threshold_hours'  => OHM_Settings::get_threshold_hours( 'processing' ),
						'timestamp_source' => $info['source'],
					),
				);
			}
		}

		if ( OHM_Settings::is_rule_enabled( 'on_hold' ) && 'on-hold' === $status ) {
			$info = $this->get_status_age( $order, 'on-hold' );
			$threshold = OHM_Settings::get_threshold_hours( 'on_hold' ) * HOUR_IN_SECONDS;

			if ( null !== $info['age'] && $info['age'] >= $threshold ) {
				$matches[] = array(
					'type'        => 'on_hold',
					'description' => sprintf(
						/* translators: 1: order ID, 2: hours */
						__( 'Order #%1$d has been on hold for approximately %2$d hours and may need review.', 'woo-order-health-monitor' ),
						$order->get_id(),
						(int) floor( $info['age'] / HOUR_IN_SECONDS )
					),
					'meta'        => array(
						'status'           => $status,
						'age_seconds'      => $info['age'],
						'threshold_hours'  => OHM_Settings::get_threshold_hours( 'on_hold' ),
						'timestamp_source' => $info['source'],
					),
				);
			}
		}

		return $matches;
	}

	/**
	 * Types that could apply to a given WC status (for resolve passes).
	 *
	 * @param string $status Order status without wc- prefix.
	 * @return array
	 */
	public function types_for_status( $status ) {
		$map = array(
			'failed'     => array( 'failed' ),
			'pending'    => array( 'pending' ),
			'processing' => array( 'processing' ),
			'on-hold'    => array( 'on_hold' ),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : array();
	}

	/**
	 * All rule types.
	 *
	 * @return array
	 */
	public static function all_types() {
		return array_keys( self::type_status_map() );
	}

	/**
	 * Age for pending orders from creation time.
	 *
	 * @param WC_Order $order Order.
	 * @return int|null Seconds or null.
	 */
	private function get_pending_age_seconds( $order ) {
		$created = $order->get_date_created();

		if ( ! $created ) {
			return null;
		}

		return max( 0, time() - $created->getTimestamp() );
	}

	/**
	 * Age since entering the current status.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Expected status slug.
	 * @return array{age:int|null,source:string}
	 */
	private function get_status_age( $order, $status ) {
		$entered_at = (int) $order->get_meta( '_ohm_entered_status_at', true );
		$entered_st = (string) $order->get_meta( '_ohm_entered_status', true );

		if ( $entered_at > 0 && $entered_st === $status ) {
			return array(
				'age'    => max( 0, time() - $entered_at ),
				'source' => 'ohm_status_meta',
			);
		}

		$modified = $order->get_date_modified();
		if ( $modified ) {
			return array(
				'age'    => max( 0, time() - $modified->getTimestamp() ),
				'source' => 'date_modified',
			);
		}

		$created = $order->get_date_created();
		if ( $created ) {
			return array(
				'age'    => max( 0, time() - $created->getTimestamp() ),
				'source' => 'date_created',
			);
		}

		return array(
			'age'    => null,
			'source' => 'unavailable',
		);
	}
}
