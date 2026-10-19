<?php
/**
 * Secure CSV exports for Reports.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams scan-history and issue-report CSV downloads.
 */
class OHM_Csv_Exporter {

	/**
	 * Handle export request if present. Exits on success.
	 *
	 * @param OHM_Admin $admin Admin instance for capability checks.
	 */
	public static function maybe_export( $admin ) {
		if ( empty( $_GET['ohm_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! $admin->current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to export reports.', 'woo-order-health-monitor' ), 403 );
		}

		$export = sanitize_key( wp_unslash( $_GET['ohm_export'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $export, array( 'scans', 'issues' ), true ) ) {
			wp_die( esc_html__( 'Invalid export type.', 'woo-order-health-monitor' ), 400 );
		}

		check_admin_referer( 'ohm_export_' . $export );

		$period     = OHM_Report_Filters::parse_period_from_request();
		$scan_args  = OHM_Report_Filters::scan_filter_args(
			OHM_Report_Filters::scan_args_from_request( $period )
		);
		$issue_args = OHM_Report_Filters::issue_filter_args(
			OHM_Report_Filters::issue_args_from_request( $period )
		);

		if ( 'scans' === $export ) {
			self::export_scans( $scan_args );
		}

		self::export_issues( $issue_args );
	}

	/**
	 * Stream scan-history CSV.
	 *
	 * @param array $args Scan filters.
	 */
	private static function export_scans( $args ) {
		$filename = 'ohm-scan-history-' . gmdate( 'Y-m-d-His' ) . '.csv';
		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			exit;
		}

		// UTF-8 BOM for spreadsheet compatibility.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$headers = array(
			'Scan ID',
			'Started (GMT)',
			'Completed (GMT)',
			'Trigger',
			'Status',
			'Monitored orders processed',
			'Issues created/reopened',
			'Issues updated',
			'Issues resolved',
			'Errors',
			'Outcome',
		);
		self::write_row( $out, $headers );

		$repo     = new OHM_Scan_Repository();
		$after_id = 0;

		while ( true ) {
			$ids = $repo->get_ids_after( $args, $after_id, 100 );
			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$after_id = $id;
				$scan     = $repo->get( $id );
				if ( ! $scan ) {
					continue;
				}

				self::write_row(
					$out,
					array(
						(string) (int) $scan->id,
						(string) $scan->started_at,
						$scan->completed_at ? (string) $scan->completed_at : '',
						(string) $scan->trigger_source,
						(string) $scan->status,
						(string) (int) $scan->orders_processed,
						(string) (int) $scan->issues_created,
						(string) (int) $scan->issues_updated,
						(string) (int) $scan->issues_resolved,
						(string) (int) $scan->errors_count,
						$scan->message ? (string) $scan->message : '',
					)
				);
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Stream issue-report CSV with order enrichment.
	 *
	 * @param array $args Issue filters.
	 */
	private static function export_issues( $args ) {
		$filename = 'ohm-issue-report-' . gmdate( 'Y-m-d-His' ) . '.csv';
		self::send_headers( $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			exit;
		}

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$headers = array(
			'Issue ID',
			'Order ID',
			'Order number',
			'Order available',
			'Order status',
			'Issue type',
			'Description',
			'State',
			'First detected (GMT)',
			'Last checked (GMT)',
			'Resolved at (GMT)',
			'Dismissed at (GMT)',
			'Order total',
			'Currency',
			'Products',
			'Payment method',
			'Order created (GMT)',
			'Time in current status',
		);
		self::write_row( $out, $headers );

		$issues_repo = new OHM_Issue_Repository();
		$enricher    = new OHM_Order_Enricher();
		$after_id    = 0;

		while ( true ) {
			$ids = $issues_repo->get_ids_after( $args, $after_id, 50 );
			if ( empty( $ids ) ) {
				break;
			}

			$batch = array();
			foreach ( $ids as $id ) {
				$after_id = $id;
				$issue    = $issues_repo->get_by_id( $id );
				if ( $issue ) {
					$batch[] = $issue;
				}
			}

			$orders = $enricher->load_orders_for_issues( $batch );

			foreach ( $batch as $issue ) {
				$order = isset( $orders[ (int) $issue->order_id ] ) ? $orders[ (int) $issue->order_id ] : null;
				$row   = $enricher->enrich_issue( $issue, $order );

				self::write_row(
					$out,
					array(
						$row['issue_id'],
						$row['order_id'],
						$row['order_number'],
						$row['order_available'],
						$row['order_status'],
						$row['issue_type'],
						$row['description'],
						$row['state'],
						$row['first_detected'],
						$row['last_checked'],
						$row['resolved_at'],
						$row['dismissed_at'],
						$row['order_total'],
						$row['order_currency'],
						$row['products'],
						$row['payment_method'],
						$row['order_created'],
						$row['time_in_status'],
					)
				);
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Send download headers.
	 *
	 * @param string $filename Safe filename.
	 */
	private static function send_headers( $filename ) {
		$filename = preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
		if ( ! $filename ) {
			$filename = 'ohm-report.csv';
		}

		// Drop any prior buffered HTML/notices so the download is pure CSV.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	/**
	 * Write one CSV row with formula-injection protection.
	 *
	 * @param resource $out  Output stream.
	 * @param array    $cols Column values.
	 */
	private static function write_row( $out, $cols ) {
		$safe = array();
		foreach ( $cols as $col ) {
			$safe[] = self::escape_csv_value( (string) $col );
		}
		fputcsv( $out, $safe );
	}

	/**
	 * Neutralize spreadsheet formula injection.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape_csv_value( $value ) {
		$value = str_replace( array( "\r", "\n" ), ' ', $value );

		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
