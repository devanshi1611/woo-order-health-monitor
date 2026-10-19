<?php
/**
 * Admin issues list page.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the issues table with filters and actions.
 */
class OHM_Issues_Page {

	/**
	 * Render the page.
	 */
	public function render() {
		$repo = new OHM_Issue_Repository();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : 'open';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( $_GET['issue_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		if ( ! in_array( $state, array( 'open', 'resolved', 'dismissed', '' ), true ) ) {
			$state = 'open';
		}
		if ( $type && ! in_array( $type, OHM_Issue_Repository::TYPES, true ) ) {
			$type = '';
		}

		$args = array(
			'state'      => $state,
			'issue_type' => $type,
			'order_id'   => $order_id,
			'per_page'   => 20,
			'page'       => $paged,
			'orderby'    => 'last_checked',
			'order'      => 'DESC',
		);

		$total   = $repo->count( $args );
		$items   = $repo->query( $args );
		$pages   = max( 1, (int) ceil( $total / 20 ) );
		?>
		<div class="wrap ohm-wrap">
			<h1><?php esc_html_e( 'Order Health Issues', 'woo-order-health-monitor' ); ?></h1>

			<form method="get" class="ohm-filters">
				<input type="hidden" name="page" value="ohm-issues" />

				<label for="ohm-filter-state" class="screen-reader-text"><?php esc_html_e( 'State', 'woo-order-health-monitor' ); ?></label>
				<select name="state" id="ohm-filter-state">
					<option value=""><?php esc_html_e( 'All states', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Issue_Repository::STATES as $state_key ) : ?>
						<option value="<?php echo esc_attr( $state_key ); ?>" <?php selected( $state, $state_key ); ?>>
							<?php echo esc_html( OHM_Helpers::issue_state_label( $state_key ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="ohm-filter-type" class="screen-reader-text"><?php esc_html_e( 'Type', 'woo-order-health-monitor' ); ?></label>
				<select name="issue_type" id="ohm-filter-type">
					<option value=""><?php esc_html_e( 'All types', 'woo-order-health-monitor' ); ?></option>
					<?php foreach ( OHM_Issue_Repository::TYPES as $type_key ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>>
							<?php echo esc_html( OHM_Helpers::issue_type_label( $type_key ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="ohm-filter-order" class="screen-reader-text"><?php esc_html_e( 'Order ID', 'woo-order-health-monitor' ); ?></label>
				<input
					type="number"
					min="1"
					name="order_id"
					id="ohm-filter-order"
					value="<?php echo $order_id ? esc_attr( (string) $order_id ) : ''; ?>"
					placeholder="<?php esc_attr_e( 'Order ID', 'woo-order-health-monitor' ); ?>"
				/>

				<?php submit_button( __( 'Filter', 'woo-order-health-monitor' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( empty( $items ) ) : ?>
				<p><?php esc_html_e( 'No issues match the current filters.', 'woo-order-health-monitor' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ohm-issues-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'Order', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'Description', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'Detected', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'Last checked', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'State', 'woo-order-health-monitor' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'woo-order-health-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $issue ) : ?>
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
								<td><?php echo esc_html( OHM_Helpers::format_datetime( $issue->first_detected ) ); ?></td>
								<td><?php echo esc_html( OHM_Helpers::format_datetime( $issue->last_checked ) ); ?></td>
								<td>
									<span class="ohm-state ohm-state--<?php echo esc_attr( $issue->state ); ?>">
										<?php echo esc_html( OHM_Helpers::issue_state_label( $issue->state ) ); ?>
									</span>
								</td>
								<td class="ohm-actions">
									<?php if ( $url ) : ?>
										<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View order', 'woo-order-health-monitor' ); ?></a>
									<?php endif; ?>

									<?php if ( 'dismissed' !== $issue->state ) : ?>
										|
										<a href="<?php echo esc_url( $this->action_url( 'dismiss', (int) $issue->id ) ); ?>">
											<?php esc_html_e( 'Dismiss', 'woo-order-health-monitor' ); ?>
										</a>
									<?php endif; ?>

									<?php if ( 'open' !== $issue->state ) : ?>
										|
										<a href="<?php echo esc_url( $this->action_url( 'reopen', (int) $issue->id ) ); ?>">
											<?php esc_html_e( 'Reopen', 'woo-order-health-monitor' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %d: number of items */
									esc_html( _n( '%d item', '%d items', $total, 'woo-order-health-monitor' ) ),
									(int) $total
								);
								?>
							</span>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build a nonce-protected action URL.
	 *
	 * @param string $action dismiss|reopen.
	 * @param int    $issue_id Issue ID.
	 * @return string
	 */
	private function action_url( $action, $issue_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'ohm-issues',
					'ohm_action' => $action,
					'issue_id'   => $issue_id,
				),
				admin_url( 'admin.php' )
			),
			'ohm_issue_' . $action . '_' . $issue_id
		);
	}
}
