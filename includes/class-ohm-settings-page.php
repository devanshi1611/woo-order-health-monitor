<?php
/**
 * Admin settings page.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders plugin settings form.
 */
class OHM_Settings_Page {

	/**
	 * Render the page.
	 */
	public function render() {
		$settings = OHM_Settings::get_all();
		?>
		<div class="wrap ohm-wrap">
			<h1><?php esc_html_e( 'Order Health Settings', 'woo-order-health-monitor' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ohm-settings' ) ); ?>">
				<?php wp_nonce_field( 'ohm_save_settings' ); ?>

				<h2><?php esc_html_e( 'Detection rules', 'woo-order-health-monitor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Failed orders', 'woo-order-health-monitor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ohm_settings[rules][failed][enabled]" value="1" <?php checked( ! empty( $settings['rules']['failed']['enabled'] ) ); ?> />
								<?php esc_html_e( 'Flag orders currently in failed status', 'woo-order-health-monitor' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pending payment', 'woo-order-health-monitor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ohm_settings[rules][pending][enabled]" value="1" <?php checked( ! empty( $settings['rules']['pending']['enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable', 'woo-order-health-monitor' ); ?>
							</label>
							<p>
								<label for="ohm-pending-hours">
									<?php esc_html_e( 'Flag when pending longer than (hours)', 'woo-order-health-monitor' ); ?>
								</label>
								<input
									type="number"
									min="1"
									max="8760"
									id="ohm-pending-hours"
									name="ohm_settings[rules][pending][threshold_hours]"
									value="<?php echo esc_attr( (string) (int) $settings['rules']['pending']['threshold_hours'] ); ?>"
									class="small-text"
								/>
							</p>
							<p class="description">
								<?php esc_html_e( 'Age is measured from the order creation time (date_created), which is when pending payment typically begins.', 'woo-order-health-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Processing', 'woo-order-health-monitor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ohm_settings[rules][processing][enabled]" value="1" <?php checked( ! empty( $settings['rules']['processing']['enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable', 'woo-order-health-monitor' ); ?>
							</label>
							<p>
								<label for="ohm-processing-hours">
									<?php esc_html_e( 'Flag when processing longer than (hours)', 'woo-order-health-monitor' ); ?>
								</label>
								<input
									type="number"
									min="1"
									max="8760"
									id="ohm-processing-hours"
									name="ohm_settings[rules][processing][threshold_hours]"
									value="<?php echo esc_attr( (string) (int) $settings['rules']['processing']['threshold_hours'] ); ?>"
									class="small-text"
								/>
							</p>
							<p class="description">
								<?php esc_html_e( 'Prefers the plugin status-entry timestamp recorded on status change; falls back to date_modified when that meta is unavailable. Edits can reset date_modified and under-report age.', 'woo-order-health-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On hold', 'woo-order-health-monitor' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ohm_settings[rules][on_hold][enabled]" value="1" <?php checked( ! empty( $settings['rules']['on_hold']['enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable', 'woo-order-health-monitor' ); ?>
							</label>
							<p>
								<label for="ohm-onhold-hours">
									<?php esc_html_e( 'Flag when on hold longer than (hours)', 'woo-order-health-monitor' ); ?>
								</label>
								<input
									type="number"
									min="1"
									max="8760"
									id="ohm-onhold-hours"
									name="ohm_settings[rules][on_hold][threshold_hours]"
									value="<?php echo esc_attr( (string) (int) $settings['rules']['on_hold']['threshold_hours'] ); ?>"
									class="small-text"
								/>
							</p>
							<p class="description">
								<?php esc_html_e( 'On-hold orders are flagged for review after the threshold; the plugin does not assume every on-hold order is an error. Timestamp strategy matches the processing rule.', 'woo-order-health-monitor' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Scanning', 'woo-order-health-monitor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ohm-scan-frequency"><?php esc_html_e( 'Scan frequency', 'woo-order-health-monitor' ); ?></label>
						</th>
						<td>
							<select name="ohm_settings[scan_frequency]" id="ohm-scan-frequency">
								<option value="hourly" <?php selected( $settings['scan_frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'woo-order-health-monitor' ); ?></option>
								<option value="twicedaily" <?php selected( $settings['scan_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'woo-order-health-monitor' ); ?></option>
								<option value="daily" <?php selected( $settings['scan_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'woo-order-health-monitor' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'WP-Cron is traffic-dependent: scheduled scans run when someone visits the site after the due time, not necessarily at the exact scheduled second.', 'woo-order-health-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ohm-batch-size"><?php esc_html_e( 'Batch size', 'woo-order-health-monitor' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="10"
								max="200"
								id="ohm-batch-size"
								name="ohm_settings[batch_size]"
								value="<?php echo esc_attr( (string) (int) $settings['batch_size'] ); ?>"
								class="small-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Orders processed per batch (10–200). Larger stores are scanned across multiple batches.', 'woo-order-health-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ohm-retention"><?php esc_html_e( 'Scan history retention (days)', 'woo-order-health-monitor' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="1"
								max="365"
								id="ohm-retention"
								name="ohm_settings[scan_history_retention]"
								value="<?php echo esc_attr( (string) (int) $settings['scan_history_retention'] ); ?>"
								class="small-text"
							/>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'woo-order-health-monitor' ), 'primary', 'ohm_save_settings' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Reset settings', 'woo-order-health-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Restore all settings to plugin defaults. Issue records and scan history are not deleted.', 'woo-order-health-monitor' ); ?></p>
			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin.php?page=ohm-settings' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'Reset all settings to defaults?', 'woo-order-health-monitor' ) ); ?>');"
			>
				<?php wp_nonce_field( 'ohm_reset_settings' ); ?>
				<?php submit_button( __( 'Reset to defaults', 'woo-order-health-monitor' ), 'delete', 'ohm_reset_settings', false ); ?>
			</form>
		</div>
		<?php
	}
}
