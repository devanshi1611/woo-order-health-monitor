<?php
/**
 * Sanitize and normalize Reports page filters.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses request filters for scan and issue reports.
 */
class OHM_Report_Filters {

	/**
	 * Default lookback days when no date range is provided.
	 */
	const DEFAULT_DAYS = 30;

	/**
	 * Parse shared period dates from the request (site-local Y-m-d).
	 *
	 * @return array{date_from_local:string,date_to_local:string,date_from_gmt:string,date_to_gmt:string,valid:bool,error:string}
	 */
	public static function parse_period_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$from = isset( $_GET['ohm_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ohm_date_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to = isset( $_GET['ohm_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ohm_date_to'] ) ) : '';

		$error = '';

		if ( '' === $from && '' === $to ) {
			$to   = wp_date( 'Y-m-d' );
			$from = wp_date( 'Y-m-d', time() - ( self::DEFAULT_DAYS * DAY_IN_SECONDS ) );
		}

		if ( ( $from && ! self::is_valid_ymd( $from ) ) || ( $to && ! self::is_valid_ymd( $to ) ) ) {
			$error = __( 'Invalid date range. Use YYYY-MM-DD dates.', 'woo-order-health-monitor' );
			$to    = wp_date( 'Y-m-d' );
			$from  = wp_date( 'Y-m-d', time() - ( self::DEFAULT_DAYS * DAY_IN_SECONDS ) );
		}

		if ( $from && $to && $from > $to ) {
			$error = __( 'The start date cannot be after the end date. Dates were swapped.', 'woo-order-health-monitor' );
			$tmp   = $from;
			$from  = $to;
			$to    = $tmp;
		}

		return array(
			'date_from_local' => $from,
			'date_to_local'   => $to,
			'date_from_gmt'   => self::local_ymd_to_gmt( $from, true ),
			'date_to_gmt'     => self::local_ymd_to_gmt( $to, false ),
			'valid'           => ( '' === $error ),
			'error'           => $error,
		);
	}

	/**
	 * Scan-history filters for queries and exports.
	 *
	 * @param array $period Period from parse_period_from_request().
	 * @return array
	 */
	public static function scan_args_from_request( $period ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['ohm_scan_status'] ) ? sanitize_key( wp_unslash( $_GET['ohm_scan_status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$trigger = isset( $_GET['ohm_scan_trigger'] ) ? sanitize_key( wp_unslash( $_GET['ohm_scan_trigger'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['ohm_scan_paged'] ) ? max( 1, absint( $_GET['ohm_scan_paged'] ) ) : 1;

		if ( $status && ! in_array( $status, OHM_Scan_Repository::statuses(), true ) ) {
			$status = '';
		}
		if ( $trigger && ! in_array( $trigger, OHM_Scan_Repository::triggers(), true ) ) {
			$trigger = '';
		}

		return array(
			'status'         => $status,
			'trigger_source' => $trigger,
			'date_from'      => $period['date_from_gmt'],
			'date_to'        => $period['date_to_gmt'],
			'per_page'       => 20,
			'page'           => $page,
			'orderby'        => 'started_at',
			'order'          => 'DESC',
		);
	}

	/**
	 * Issue-report filters for queries and exports.
	 *
	 * @param array $period Period from parse_period_from_request().
	 * @return array
	 */
	public static function issue_args_from_request( $period ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['ohm_issue_state'] ) ? sanitize_key( wp_unslash( $_GET['ohm_issue_state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['ohm_issue_type'] ) ? sanitize_key( wp_unslash( $_GET['ohm_issue_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['ohm_order_id'] ) ? absint( $_GET['ohm_order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['ohm_issue_paged'] ) ? max( 1, absint( $_GET['ohm_issue_paged'] ) ) : 1;

		if ( $state && ! in_array( $state, OHM_Issue_Repository::STATES, true ) ) {
			$state = '';
		}
		if ( $type && ! in_array( $type, OHM_Issue_Repository::TYPES, true ) ) {
			$type = '';
		}

		return array(
			'state'      => $state,
			'issue_type' => $type,
			'order_id'   => $order_id,
			'date_from'  => $period['date_from_gmt'],
			'date_to'    => $period['date_to_gmt'],
			'date_field' => 'first_detected',
			'per_page'   => 20,
			'page'       => $page,
			'orderby'    => 'first_detected',
			'order'      => 'DESC',
		);
	}

	/**
	 * Scan filters only (no pagination) for queries that must match exports.
	 *
	 * @param array $scan_args From scan_args_from_request().
	 * @return array
	 */
	public static function scan_filter_args( $scan_args ) {
		return array(
			'status'         => isset( $scan_args['status'] ) ? $scan_args['status'] : '',
			'trigger_source' => isset( $scan_args['trigger_source'] ) ? $scan_args['trigger_source'] : '',
			'date_from'      => isset( $scan_args['date_from'] ) ? $scan_args['date_from'] : '',
			'date_to'        => isset( $scan_args['date_to'] ) ? $scan_args['date_to'] : '',
		);
	}

	/**
	 * Issue filters only (no pagination) for queries that must match exports.
	 *
	 * @param array $issue_args From issue_args_from_request().
	 * @return array
	 */
	public static function issue_filter_args( $issue_args ) {
		return array(
			'state'      => isset( $issue_args['state'] ) ? $issue_args['state'] : '',
			'issue_type' => isset( $issue_args['issue_type'] ) ? $issue_args['issue_type'] : '',
			'order_id'   => isset( $issue_args['order_id'] ) ? absint( $issue_args['order_id'] ) : 0,
			'date_from'  => isset( $issue_args['date_from'] ) ? $issue_args['date_from'] : '',
			'date_to'    => isset( $issue_args['date_to'] ) ? $issue_args['date_to'] : '',
			'date_field' => isset( $issue_args['date_field'] ) ? $issue_args['date_field'] : 'first_detected',
		);
	}

	/**
	 * Query args preserved in filter forms and export links (local dates + UI filters).
	 *
	 * @param array $period     Period array.
	 * @param array $scan_args  Parsed scan args.
	 * @param array $issue_args Parsed issue args.
	 * @return array
	 */
	public static function request_query_args( $period, $scan_args, $issue_args ) {
		$args = array(
			'page'          => 'ohm-reports',
			'ohm_date_from' => $period['date_from_local'],
			'ohm_date_to'   => $period['date_to_local'],
		);

		if ( ! empty( $scan_args['status'] ) ) {
			$args['ohm_scan_status'] = $scan_args['status'];
		}
		if ( ! empty( $scan_args['trigger_source'] ) ) {
			$args['ohm_scan_trigger'] = $scan_args['trigger_source'];
		}
		if ( ! empty( $issue_args['state'] ) ) {
			$args['ohm_issue_state'] = $issue_args['state'];
		}
		if ( ! empty( $issue_args['issue_type'] ) ) {
			$args['ohm_issue_type'] = $issue_args['issue_type'];
		}
		if ( ! empty( $issue_args['order_id'] ) ) {
			$args['ohm_order_id'] = (int) $issue_args['order_id'];
		}

		return $args;
	}

	/**
	 * Validate Y-m-d.
	 *
	 * @param string $ymd Date string.
	 * @return bool
	 */
	public static function is_valid_ymd( $ymd ) {
		$dt = DateTime::createFromFormat( 'Y-m-d', $ymd );
		return $dt && $dt->format( 'Y-m-d' ) === $ymd;
	}

	/**
	 * Convert a site-local calendar day to a GMT MySQL datetime bound.
	 *
	 * @param string $ymd   Local Y-m-d.
	 * @param bool   $start True for 00:00:00, false for 23:59:59.
	 * @return string
	 */
	public static function local_ymd_to_gmt( $ymd, $start = true ) {
		$time = $start ? '00:00:00' : '23:59:59';
		$tz   = wp_timezone();
		$dt   = date_create_from_format( 'Y-m-d H:i:s', $ymd . ' ' . $time, $tz );

		if ( ! $dt ) {
			return $start ? '1970-01-01 00:00:00' : gmdate( 'Y-m-d H:i:s' );
		}

		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
