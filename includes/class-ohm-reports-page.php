<?php
/**
 * Admin Reports page: scan history and detailed issue report.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders Order Health → Reports.
 */
class OHM_Reports_Page {

	/**
	 * Render the page.
	 */
	public function render() {
		$period     = OHM_Report_Filters::parse_period_from_request();
		$scan_args  = OHM_Report_Filters::scan_args_from_request( $period );
		$issue_args = OHM_Report_Filters::issue_args_from_request( $period );
		$query_base = OHM_Report_Filters::request_query_args( $period, $scan_args, $issue_args );

		$scan_repo  = new OHM_Scan_Repository();
		$issue_repo = new OHM_Issue_Repository();

		$scan_filters  = OHM_Report_Filters::scan_filter_args( $scan_args );
		$issue_filters = OHM_Report_Filters::issue_filter_args( $issue_args );

		$summary = $scan_repo->summarize( $scan_filters );

		// Issue timestamp counts (not a separate event log). Type/order filters apply;
		// state filter applies only to “first detected” (current row state), not to
		// resolved_at / dismissed_at activity counts.
		$issue_extra_typed = array(
			'issue_type' => $issue_filters['issue_type'],
			'order_id'   => $issue_filters['order_id'],
		);
		$first_detected_extra = $issue_extra_typed;
		if ( ! empty( $issue_filters['state'] ) ) {
			$first_detected_extra['state'] = $issue_filters['state'];
		}

		$issues_first_detected = $issue_repo->count_for_timestamp_range(
			'first_detected',
			$period['date_from_gmt'],
			$period['date_to_gmt'],
			$first_detected_extra
		);
		$issues_resolved_at = $issue_repo->count_for_timestamp_range(
			'resolved_at',
			$period['date_from_gmt'],
			$period['date_to_gmt'],
			$issue_extra_typed
		);
		$issues_dismissed_at = $issue_repo->count_for_timestamp_range(
			'dismissed_at',
			$period['date_from_gmt'],
			$period['date_to_gmt'],
			$issue_extra_typed
		);

		$scans       = $scan_repo->query( $scan_args );
		$scan_total  = $scan_repo->count( $scan_filters );
		$scan_pages  = max( 1, (int) ceil( $scan_total / 20 ) );
		$latest      = $scan_repo->get_latest();
		$issues      = $issue_repo->query( $issue_args );
		$issue_total = $issue_repo->count( $issue_filters );
		$issue_pages = max( 1, (int) ceil( $issue_total / 20 ) );

		$enricher = new OHM_Order_Enricher();
		$orders   = $enricher->load_orders_for_issues( $issues );

		$scan_export_url  = wp_nonce_url( add_query_arg( array_merge( $query_base, array( 'ohm_export' => 'scans' ) ), admin_url( 'admin.php' ) ), 'ohm_export_scans' );
		$issue_export_url = wp_nonce_url( add_query_arg( array_merge( $query_base, array( 'ohm_export' => 'issues' ) ), admin_url( 'admin.php' ) ), 'ohm_export_issues' );
		$reset_url        = admin_url( 'admin.php?page=ohm-reports' );
		?>
		<div class="wrap ohm-wrap ohm-reports">
			<h1><?php esc_html_e( 'Order Health Reports', 'woo-order-health-monitor' ); ?></h1>

			<?php if ( ! empty( $period['error'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $period['error'] ); ?></p></div>
			<?php endif; ?>

			<form method="get" class="ohm-filters ohm-filters--reports">
				<input type="hidden" name="page" value="ohm-reports" />

				<p class="ohm-filters__group-label"><strong><?php esc_html_e( 'Period', 'woo-order-health-monitor' ); ?></strong></p>
				<label for="ohm-date-from"><?php esc_html_e( 'From', 'woo-order-health-monitor' ); ?></label>
				<input type="date" id="ohm-date-from" name="ohm_date_from" value="<?php echo esc_attr( $period['date_from_local'] ); ?>" />

				<label for="ohm-date-to"><?php esc_html_e( 'To', 'woo-order-health-monitor' ); ?></label>
				<input type="date" id="ohm-date-to" name="ohm_date_to" value="<?php echo esc_attr( $period['date_to_local'] ); ?>" />
				<span class="description ohm-filters__hint"><?php esc_html_e( 'Scans: started_at. Issue table: first_detected.', 'woo-order-health-monitor' ); ?></span>

				<p class="ohm-filters__group-label"><strong><?php esc_html_e( 'Scan history filters', 'woo-order-health-monitor' ); ?></strong></p>
				<label for="ohm-scan-status" class="screen-reader-text"><?php esc_html_e( 'Scan status', 'woo-order-health-monitor' ); ?></label>
				<select name="ohm_scan_status" id="ohm-scan-status">
					<option value=""><?php esc_html_e( 'All scan statuses', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Scan_Repository::statuses() as $status ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $scan_args['status'], $status ); ?>>
							<?php echo esc_html( OHM_Helpers::scan_status_label( $status ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="ohm-scan-trigger" class="screen-reader-text"><?php esc_html_e( 'Trigger', 'woo-order-health-monitor' ); ?></label>
				<select name="ohm_scan_trigger" id="ohm-scan-trigger">
					<option value=""><?php esc_html_e( 'All triggers', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Scan_Repository::triggers() as $trigger ) : ?>
						<option value="<?php echo esc_attr( $trigger ); ?>" <?php selected( $scan_args['trigger_source'], $trigger ); ?>>
							<?php echo esc_html( $trigger ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<p class="ohm-filters__group-label"><strong><?php esc_html_e( 'Issue report filters', 'woo-order-health-monitor' ); ?></strong></p>
				<label for="ohm-issue-state" class="screen-reader-text"><?php esc_html_e( 'Issue state', 'woo-order-health-monitor' ); ?></label>
				<select name="ohm_issue_state" id="ohm-issue-state">
					<option value=""><?php esc_html_e( 'All issue states', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Issue_Repository::STATES as $state ) : ?>
						<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $issue_args['state'], $state ); ?>>
							<?php echo esc_html( OHM_Helpers::issue_state_label( $state ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="ohm-issue-type" class="screen-reader-text"><?php esc_html_e( 'Issue type', 'woo-order-health-monitor' ); ?></label>
				<select name="ohm_issue_type" id="ohm-issue-type">
					<option value=""><?php esc_html_e( 'All issue types', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Issue_Repository::TYPES as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $issue_args['issue_type'], $type ); ?>>
							<?php echo esc_html( OHM_Helpers::issue_type_label( $type ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="ohm-order-id" class="screen-reader-text"><?php esc_html_e( 'Order ID', 'woo-order-health-monitor' ); ?></label>
				<input type="number" min="1" name="ohm_order_id" id="ohm-order-id" value="<?php echo ! empty( $issue_args['order_id'] ) ? esc_attr( (string) $issue_args['order_id'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Order ID', 'woo-order-health-monitor' ); ?>" />

				<?php submit_button( __( 'Apply filters', 'woo-order-health-monitor' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Reset filters', 'woo-order-health-monitor' ); ?></a>
			</form>

			<div class="ohm-panel">
				<h2><?php esc_html_e( 'Period summary', 'woo-order-health-monitor' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: 1: from date, 2: to date */
						esc_html__( 'Period %1$s – %2$s (site timezone). Metrics below come only from stored scan counters and issue timestamps—there is no separate event history table.', 'woo-order-health-monitor' ),
						esc_html( $period['date_from_local'] ),
						esc_html( $period['date_to_local'] )
					);
					?>
				</p>

				<h3 class="ohm-subheading"><?php esc_html_e( 'From scan history', 'woo-order-health-monitor' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Filtered by scan start time, scan status, and trigger. Counters are summed from each matching scan row.', 'woo-order-health-monitor' ); ?></p>
				<div class="ohm-cards ohm-cards--compact">
					<?php
					$this->summary_card( __( 'Total scans', 'woo-order-health-monitor' ), (int) $summary['total_scans'] );
					$this->summary_card( __( 'Successful scans', 'woo-order-health-monitor' ), (int) $summary['successful_scans'] );
					$this->summary_card( __( 'Failed scans', 'woo-order-health-monitor' ), (int) $summary['failed_scans'] );
					$this->summary_card( __( 'Scans with errors', 'woo-order-health-monitor' ), (int) $summary['scans_with_errors'] );
					$this->summary_card( __( 'Monitored orders processed', 'woo-order-health-monitor' ), (int) $summary['orders_processed'] );
					$this->summary_card( __( 'Scan-reported created/reopened', 'woo-order-health-monitor' ), (int) $summary['issues_created'] );
					$this->summary_card( __( 'Scan-reported updates', 'woo-order-health-monitor' ), (int) $summary['issues_updated'] );
					$this->summary_card( __( 'Scan-reported resolutions', 'woo-order-health-monitor' ), (int) $summary['issues_resolved'] );
					?>
				</div>
				<p class="description">
					<?php esc_html_e( 'Created and reopened are stored as one scan counter and cannot be separated for past scans.', 'woo-order-health-monitor' ); ?>
				</p>

				<h3 class="ohm-subheading"><?php esc_html_e( 'From issue records', 'woo-order-health-monitor' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Counted from issue timestamps (not scan rows). Type and order ID filters apply. State filter applies only to “first detected”.', 'woo-order-health-monitor' ); ?></p>
				<div class="ohm-cards ohm-cards--compact">
					<?php
					$this->summary_card( __( 'First detected in period', 'woo-order-health-monitor' ), (int) $issues_first_detected );
					$this->summary_card( __( 'Resolved at in period', 'woo-order-health-monitor' ), (int) $issues_resolved_at );
					$this->summary_card( __( 'Dismissed at in period', 'woo-order-health-monitor' ), (int) $issues_dismissed_at );
					?>
				</div>
			</div>

			<div class="ohm-panel">
				<h2><?php esc_html_e( 'Most recent scan', 'woo-order-health-monitor' ); ?></h2>
				<?php if ( ! $latest ) : ?>
					<p><?php esc_html_e( 'No scan history is available yet.', 'woo-order-health-monitor' ); ?></p>
				<?php else : ?>
					<table class="widefat striped ohm-meta-table">
						<tbody>
							<tr><th><?php esc_html_e( 'Scan ID', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( (string) (int) $latest->id ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Started', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( OHM_Helpers::format_datetime( $latest->started_at ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Completed', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( OHM_Helpers::format_datetime( $latest->completed_at ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Trigger', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( (string) $latest->trigger_source ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Status', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( OHM_Helpers::scan_status_label( $latest->status ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Monitored orders processed', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( (string) (int) $latest->orders_processed ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Created/reopened / updated / resolved', 'woo-order-health-monitor' ); ?></th>
								<td><?php echo esc_html( sprintf( '%d / %d / %d', (int) $latest->issues_created, (int) $latest->issues_updated, (int) $latest->issues_resolved ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Errors', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( (string) (int) $latest->errors_count ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Outcome', 'woo-order-health-monitor' ); ?></th><td><?php echo esc_html( $latest->message ? $latest->message : '—' ); ?></td></tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'This block shows the newest scan overall, not necessarily one inside the selected period filters.', 'woo-order-health-monitor' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="ohm-panel">
				<div class="ohm-panel__header">
					<h2><?php esc_html_e( 'Scan history', 'woo-order-health-monitor' ); ?></h2>
					<a class="button button-secondary" href="<?php echo esc_url( $scan_export_url ); ?>"><?php esc_html_e( 'Export scan history CSV', 'woo-order-health-monitor' ); ?></a>
				</div>
				<p class="description">
					<?php esc_html_e( 'Issues are not linked to individual scan IDs in storage, so per-scan issue lists are not available. CSV export uses the same period, status, and trigger filters as this table (all matching rows, not only this page).', 'woo-order-health-monitor' ); ?>
				</p>

				<?php if ( empty( $scans ) ) : ?>
					<p><?php esc_html_e( 'No scan history rows match the current period, status, and trigger filters.', 'woo-order-health-monitor' ); ?></p>
				<?php else : ?>
					<div class="ohm-table-scroll">
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Scan ID', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Started', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Completed', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Trigger', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Status', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Monitored orders', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Created/reopened', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Resolved', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Errors', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Outcome', 'woo-order-health-monitor' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $scans as $scan ) : ?>
									<tr>
										<td><?php echo esc_html( (string) (int) $scan->id ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::format_datetime( $scan->started_at ) ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::format_datetime( $scan->completed_at ) ); ?></td>
										<td><?php echo esc_html( (string) $scan->trigger_source ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::scan_status_label( $scan->status ) ); ?></td>
										<td><?php echo esc_html( (string) (int) $scan->orders_processed ); ?></td>
										<td><?php echo esc_html( (string) (int) $scan->issues_created ); ?></td>
										<td><?php echo esc_html( (string) (int) $scan->issues_resolved ); ?></td>
										<td><?php echo esc_html( (string) (int) $scan->errors_count ); ?></td>
										<td><?php echo esc_html( $scan->message ? wp_html_excerpt( $scan->message, 80, '…' ) : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php $this->render_pagination( 'ohm_scan_paged', (int) $scan_args['page'], $scan_pages, $scan_total, $query_base ); ?>
				<?php endif; ?>
			</div>

			<div class="ohm-panel">
				<div class="ohm-panel__header">
					<h2><?php esc_html_e( 'Detailed issue report', 'woo-order-health-monitor' ); ?></h2>
					<a class="button button-secondary" href="<?php echo esc_url( $issue_export_url ); ?>"><?php esc_html_e( 'Export issue report CSV', 'woo-order-health-monitor' ); ?></a>
				</div>
				<p class="description">
					<?php esc_html_e( 'Issue rows come from plugin storage (filtered by first_detected in the period, plus state/type/order ID). Order fields are loaded live via WooCommerce APIs. Billing addresses, emails, phones, and payment tokens are never included. CSV export uses the same filters and includes every matching row.', 'woo-order-health-monitor' ); ?>
				</p>

				<?php if ( empty( $issues ) ) : ?>
					<p><?php esc_html_e( 'No issue records match the current period and issue filters. Try widening the dates or choosing “All issue states”.', 'woo-order-health-monitor' ); ?></p>
				<?php else : ?>
					<div class="ohm-table-scroll">
						<table class="widefat striped ohm-issues-report-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Issue', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Order', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'WC status', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Type', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'State', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Description', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Detected', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Last checked', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Resolved / dismissed', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Total', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Products', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Payment', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Order created', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Time in status', 'woo-order-health-monitor' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $issues as $issue ) : ?>
									<?php
									$order = isset( $orders[ (int) $issue->order_id ] ) ? $orders[ (int) $issue->order_id ] : null;
									$row   = $enricher->enrich_issue( $issue, $order );
									$url   = ( 'yes' === $row['order_available'] ) ? OHM_Helpers::order_edit_url( (int) $issue->order_id ) : '';
									$resolved_dismissed = '—';
									if ( ! empty( $issue->resolved_at ) ) {
										$resolved_dismissed = OHM_Helpers::format_datetime( $issue->resolved_at );
									} elseif ( ! empty( $issue->dismissed_at ) ) {
										$resolved_dismissed = OHM_Helpers::format_datetime( $issue->dismissed_at );
									}
									?>
									<tr>
										<td><?php echo esc_html( '#' . $row['issue_id'] ); ?></td>
										<td>
											<?php if ( $url ) : ?>
												<a href="<?php echo esc_url( $url ); ?>">
													#<?php echo esc_html( $row['order_id'] ); ?>
													<?php if ( $row['order_number'] && $row['order_number'] !== $row['order_id'] ) : ?>
														(<?php echo esc_html( $row['order_number'] ); ?>)
													<?php endif; ?>
												</a>
											<?php else : ?>
												#<?php echo esc_html( $row['order_id'] ); ?>
												<span class="description"><?php esc_html_e( '(order unavailable)', 'woo-order-health-monitor' ); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $row['order_status'] ? $row['order_status'] : '—' ); ?></td>
										<td><?php echo esc_html( $row['issue_type_label'] ); ?></td>
										<td><span class="ohm-state ohm-state--<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $row['state_label'] ); ?></span></td>
										<td><?php echo esc_html( $row['description'] ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::format_datetime( $row['first_detected'] ) ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::format_datetime( $row['last_checked'] ) ); ?></td>
										<td><?php echo esc_html( $resolved_dismissed ); ?></td>
										<td>
											<?php
											if ( 'yes' === $row['order_available'] && '' !== $row['order_total'] ) {
												echo esc_html( $row['order_total'] . ' ' . $row['order_currency'] );
											} else {
												echo '—';
											}
											?>
										</td>
										<td><?php echo esc_html( $row['products'] ? $row['products'] : '—' ); ?></td>
										<td><?php echo esc_html( $row['payment_method'] ? $row['payment_method'] : '—' ); ?></td>
										<td><?php echo esc_html( ! empty( $row['order_created'] ) ? OHM_Helpers::format_datetime( $row['order_created'] ) : '—' ); ?></td>
										<td>
											<?php
											if ( 'ohm_status_meta' === $row['time_in_status_note'] && $row['time_in_status'] ) {
												echo esc_html( $row['time_in_status'] );
											} else {
												echo esc_html__( '— (no reliable status-entry time)', 'woo-order-health-monitor' );
											}
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php $this->render_pagination( 'ohm_issue_paged', (int) $issue_args['page'], $issue_pages, $issue_total, $query_base ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Compact summary card (not a link).
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 */
	private function summary_card( $label, $count ) {
		?>
		<div class="ohm-card ohm-card--static">
			<span class="ohm-card__count"><?php echo esc_html( (string) (int) $count ); ?></span>
			<span class="ohm-card__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render pagination that preserves filters.
	 *
	 * @param string $page_key Query arg for page number.
	 * @param int    $current  Current page.
	 * @param int    $total_pages Total pages.
	 * @param int    $total_items Total items.
	 * @param array  $query_base Base query args.
	 */
	private function render_pagination( $page_key, $current, $total_pages, $total_items, $query_base ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base = add_query_arg( $query_base, admin_url( 'admin.php' ) );
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html( _n( '%d item', '%d items', $total_items, 'woo-order-health-monitor' ) ),
						(int) $total_items
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( $page_key, '%#%', $base ),
							'format'    => '',
							'current'   => $current,
							'total'     => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div>
		</div>
		<?php
	}
}
