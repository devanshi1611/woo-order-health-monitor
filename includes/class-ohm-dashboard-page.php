<?php
/**
 * Admin dashboard page.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Order Health dashboard.
 */
class OHM_Dashboard_Page {

	/**
	 * Render the page.
	 */
	public function render() {
		$wc_active = OHM_Plugin::is_woocommerce_active();
		?>
		<div class="wrap ohm-wrap">
			<h1><?php esc_html_e( 'Order Health Monitor', 'woo-order-health-monitor' ); ?></h1>

			<?php if ( ! $wc_active ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php esc_html_e( 'WooCommerce is not active. Activate it to enable order monitoring.', 'woo-order-health-monitor' ); ?>
					</p>
				</div>
				<?php
				return;
			endif;

			$issues_repo = new OHM_Issue_Repository();
			$scan_repo   = new OHM_Scan_Repository();
			$counts      = $issues_repo->count_open_by_type();
			$latest      = $scan_repo->get_latest();
			$running     = OHM_Scanner::is_running();
			$state       = OHM_Scanner::get_state();
			$recent      = $issues_repo->query(
				array(
					'state'    => 'open',
					'per_page' => 10,
					'page'     => 1,
					'orderby'  => 'last_checked',
					'order'    => 'DESC',
				)
			);
			$history     = $scan_repo->get_recent( 5 );
			$next_cron   = OHM_Scheduler::next_scheduled();
			?>

			<div class="ohm-toolbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=woo-order-health-monitor' ) ); ?>">
					<?php wp_nonce_field( 'ohm_scan_now' ); ?>
					<?php
					submit_button(
						$running
							? __( 'Scan in progress…', 'woo-order-health-monitor' )
							: __( 'Scan Now', 'woo-order-health-monitor' ),
						'primary',
						'ohm_scan_now',
						false,
						$running ? array( 'disabled' => 'disabled' ) : array()
					);
					?>
				</form>
				<p class="ohm-toolbar__meta description">
					<?php
					if ( $running && $state ) {
						printf(
							/* translators: %d: orders processed so far */
							esc_html__( 'Scan running — %d orders processed so far.', 'woo-order-health-monitor' ),
							isset( $state['orders_processed'] ) ? (int) $state['orders_processed'] : 0
						);
					} elseif ( $next_cron ) {
						printf(
							/* translators: %s: next scheduled datetime */
							esc_html__( 'Next scheduled scan: %s (WP-Cron is traffic-dependent).', 'woo-order-health-monitor' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron ) )
						);
					} else {
						esc_html_e( 'No recurring scan is currently scheduled.', 'woo-order-health-monitor' );
					}
					?>
				</p>
			</div>

			<div class="ohm-cards">
				<?php
				$this->render_card(
					__( 'Open issues', 'woo-order-health-monitor' ),
					(int) $counts['total'],
					OHM_Helpers::issues_url( array( 'state' => 'open' ) )
				);
				$this->render_card(
					__( 'Failed orders', 'woo-order-health-monitor' ),
					(int) $counts['failed'],
					OHM_Helpers::issues_url( array( 'state' => 'open', 'issue_type' => 'failed' ) )
				);
				$this->render_card(
					__( 'Aged pending payment', 'woo-order-health-monitor' ),
					(int) $counts['pending'],
					OHM_Helpers::issues_url( array( 'state' => 'open', 'issue_type' => 'pending' ) )
				);
				$this->render_card(
					__( 'Aged processing', 'woo-order-health-monitor' ),
					(int) $counts['processing'],
					OHM_Helpers::issues_url( array( 'state' => 'open', 'issue_type' => 'processing' ) )
				);
				$this->render_card(
					__( 'Aged on hold', 'woo-order-health-monitor' ),
					(int) $counts['on_hold'],
					OHM_Helpers::issues_url( array( 'state' => 'open', 'issue_type' => 'on_hold' ) )
				);
				?>
			</div>

			<div class="ohm-panels">
				<div class="ohm-panel">
					<h2><?php esc_html_e( 'Last scan', 'woo-order-health-monitor' ); ?></h2>
					<?php if ( ! $latest ) : ?>
						<p><?php esc_html_e( 'No scans have run yet. Click “Scan Now” to check your orders.', 'woo-order-health-monitor' ); ?></p>
					<?php else : ?>
						<table class="widefat striped ohm-meta-table">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'Started', 'woo-order-health-monitor' ); ?></th>
									<td><?php echo esc_html( OHM_Helpers::format_datetime( $latest->started_at ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Completed', 'woo-order-health-monitor' ); ?></th>
									<td><?php echo esc_html( OHM_Helpers::format_datetime( $latest->completed_at ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Status', 'woo-order-health-monitor' ); ?></th>
									<td><?php echo esc_html( OHM_Helpers::scan_status_label( $latest->status ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Monitored orders processed', 'woo-order-health-monitor' ); ?></th>
									<td>
										<?php echo esc_html( (string) (int) $latest->orders_processed ); ?>
										<p class="description" style="margin: 6px 0 0;">
											<?php esc_html_e( 'Only orders matching the enabled monitoring statuses are included. Completed and cancelled orders are excluded unless explicitly supported by the configured rules.', 'woo-order-health-monitor' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Issues created / updated / resolved', 'woo-order-health-monitor' ); ?></th>
									<td>
										<?php
										echo esc_html(
											sprintf(
												'%d / %d / %d',
												(int) $latest->issues_created,
												(int) $latest->issues_updated,
												(int) $latest->issues_resolved
											)
										);
										?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Errors', 'woo-order-health-monitor' ); ?></th>
									<td><?php echo esc_html( (string) (int) $latest->errors_count ); ?></td>
								</tr>
								<?php if ( ! empty( $latest->message ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Outcome', 'woo-order-health-monitor' ); ?></th>
										<td><?php echo esc_html( $latest->message ); ?></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<div class="ohm-panel">
					<h2><?php esc_html_e( 'Recent unresolved issues', 'woo-order-health-monitor' ); ?></h2>
					<?php if ( empty( $recent ) ) : ?>
						<p><?php esc_html_e( 'No open issues currently match your monitoring rules.', 'woo-order-health-monitor' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'If you expected issues to appear, review your enabled rules and age thresholds in Settings, then run another scan.', 'woo-order-health-monitor' ); ?>
						</p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Type', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Order', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Description', 'woo-order-health-monitor' ); ?></th>
									<th><?php esc_html_e( 'Last checked', 'woo-order-health-monitor' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent as $issue ) : ?>
									<tr>
										<td><?php echo esc_html( OHM_Helpers::issue_type_label( $issue->issue_type ) ); ?></td>
										<td>
											<?php
											$url = OHM_Helpers::order_edit_url( (int) $issue->order_id );
											if ( $url ) :
												?>
												<a href="<?php echo esc_url( $url ); ?>">
													#<?php echo esc_html( (string) (int) $issue->order_id ); ?>
												</a>
											<?php else : ?>
												#<?php echo esc_html( (string) (int) $issue->order_id ); ?>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $issue->description ); ?></td>
										<td><?php echo esc_html( OHM_Helpers::format_datetime( $issue->last_checked ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p>
							<a href="<?php echo esc_url( OHM_Helpers::issues_url( array( 'state' => 'open' ) ) ); ?>">
								<?php esc_html_e( 'View all open issues', 'woo-order-health-monitor' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="ohm-panel">
				<h2><?php esc_html_e( 'Recent scan history', 'woo-order-health-monitor' ); ?></h2>
				<?php if ( empty( $history ) ) : ?>
					<p><?php esc_html_e( 'Scan history will appear here after the first scan.', 'woo-order-health-monitor' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Started', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Status', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Trigger', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Orders', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Created', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Resolved', 'woo-order-health-monitor' ); ?></th>
								<th><?php esc_html_e( 'Errors', 'woo-order-health-monitor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $row ) : ?>
								<tr>
									<td><?php echo esc_html( OHM_Helpers::format_datetime( $row->started_at ) ); ?></td>
									<td><?php echo esc_html( OHM_Helpers::scan_status_label( $row->status ) ); ?></td>
									<td><?php echo esc_html( $row->trigger_source ); ?></td>
									<td><?php echo esc_html( (string) (int) $row->orders_processed ); ?></td>
									<td><?php echo esc_html( (string) (int) $row->issues_created ); ?></td>
									<td><?php echo esc_html( (string) (int) $row->issues_resolved ); ?></td>
									<td><?php echo esc_html( (string) (int) $row->errors_count ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a summary card.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param string $url   Link URL.
	 */
	private function render_card( $label, $count, $url ) {
		?>
		<a class="ohm-card" href="<?php echo esc_url( $url ); ?>">
			<span class="ohm-card__count"><?php echo esc_html( (string) (int) $count ); ?></span>
			<span class="ohm-card__label"><?php echo esc_html( $label ); ?></span>
		</a>
		<?php
	}
}
