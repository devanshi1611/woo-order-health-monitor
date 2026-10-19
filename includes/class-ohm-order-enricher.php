<?php
/**
 * Enrich issue rows with permitted WooCommerce order fields.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads order details via WooCommerce CRUD APIs only.
 */
class OHM_Order_Enricher {

	/**
	 * Load orders keyed by ID for a list of issue rows.
	 *
	 * @param array $issues Issue row objects.
	 * @return array<int,WC_Order>
	 */
	public function load_orders_for_issues( $issues ) {
		$map = array();

		if ( empty( $issues ) || ! OHM_Plugin::is_woocommerce_active() ) {
			return $map;
		}

		$ids = array();
		foreach ( $issues as $issue ) {
			$ids[] = absint( $issue->order_id );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$found = wc_get_orders(
				array(
					'include' => $chunk,
					'limit'   => count( $chunk ),
					'return'  => 'objects',
					'type'    => 'shop_order',
					// Include completed/cancelled/etc.; do not limit to “active” statuses.
					'status'  => 'any',
				)
			);

			if ( empty( $found ) ) {
				continue;
			}

			foreach ( $found as $order ) {
				if ( $order instanceof WC_Order ) {
					$map[ (int) $order->get_id() ] = $order;
				}
			}
		}

		return $map;
	}

	/**
	 * Build a safe detail array for one issue + optional order.
	 *
	 * @param object        $issue Issue row.
	 * @param WC_Order|null $order Order or null if deleted/unavailable.
	 * @return array<string,string>
	 */
	public function enrich_issue( $issue, $order = null ) {
		$row = array(
			'issue_id'            => (string) (int) $issue->id,
			'order_id'            => (string) (int) $issue->order_id,
			'order_number'        => '',
			'order_status'        => '',
			'order_available'     => 'no',
			'issue_type'          => (string) $issue->issue_type,
			'issue_type_label'    => OHM_Helpers::issue_type_label( $issue->issue_type ),
			'description'         => (string) $issue->description,
			'state'               => (string) $issue->state,
			'state_label'         => OHM_Helpers::issue_state_label( $issue->state ),
			'first_detected'      => (string) $issue->first_detected,
			'last_checked'        => (string) $issue->last_checked,
			'resolved_at'         => (string) $issue->resolved_at,
			'dismissed_at'        => (string) $issue->dismissed_at,
			'order_total'         => '',
			'order_currency'      => '',
			'products'            => '',
			'payment_method'      => '',
			'order_created'       => '',
			'time_in_status'      => '',
			'time_in_status_note' => '',
		);

		if ( ! $order instanceof WC_Order ) {
			$row['order_available'] = 'no';
			$row['order_status']    = __( 'Order unavailable', 'woo-order-health-monitor' );
			return $row;
		}

		$row['order_available'] = 'yes';
		$row['order_number']    = (string) $order->get_order_number();
		$row['order_status']    = (string) $order->get_status();
		$row['order_total']     = (string) $order->get_total();
		$row['order_currency']  = (string) $order->get_currency();
		$row['payment_method']  = (string) $order->get_payment_method_title();
		$row['products']        = $this->format_line_items( $order );

		$created = $order->get_date_created();
		if ( $created ) {
			$row['order_created'] = gmdate( 'Y-m-d H:i:s', $created->getTimestamp() );
		}

		$entered_at = (int) $order->get_meta( '_ohm_entered_status_at', true );
		$entered_st = (string) $order->get_meta( '_ohm_entered_status', true );
		$status     = $order->get_status();

		if ( $entered_at > 0 && $entered_st === $status ) {
			$seconds                 = max( 0, time() - $entered_at );
			$row['time_in_status']   = $this->format_duration( $seconds );
			$row['time_in_status_note'] = 'ohm_status_meta';
		} else {
			$row['time_in_status']      = '';
			$row['time_in_status_note'] = 'unavailable';
		}

		return $row;
	}

	/**
	 * Format product name / SKU / qty list without customer PII.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function format_line_items( $order ) {
		$parts = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( array( $item, 'get_name' ) ) ) {
				continue;
			}

			$name = $item->get_name();
			$qty  = is_callable( array( $item, 'get_quantity' ) ) ? (int) $item->get_quantity() : 0;
			$sku  = '';

			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product && is_callable( array( $product, 'get_sku' ) ) ) {
				$sku = (string) $product->get_sku();
			}

			if ( $sku ) {
				$parts[] = sprintf( '%s (SKU: %s) × %d', $name, $sku, $qty );
			} else {
				$parts[] = sprintf( '%s × %d', $name, $qty );
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * Human-readable duration.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private function format_duration( $seconds ) {
		$hours = (int) floor( $seconds / HOUR_IN_SECONDS );
		$mins  = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

		if ( $hours > 0 ) {
			return sprintf(
				/* translators: 1: hours, 2: minutes */
				__( '%1$d hours, %2$d minutes', 'woo-order-health-monitor' ),
				$hours,
				$mins
			);
		}

		return sprintf(
			/* translators: %d: minutes */
			__( '%d minutes', 'woo-order-health-monitor' ),
			max( 1, $mins )
		);
	}
}
