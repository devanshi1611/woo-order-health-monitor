<?php
/**
 * Admin menus, assets, and request handling.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin pages and routes actions.
 */
class OHM_Admin {

	/**
	 * Register admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Capability required for Order Health pages.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return OHM_Plugin::is_woocommerce_active()
			? 'manage_woocommerce'
			: 'manage_options';
	}

	/**
	 * Whether the current user may manage the plugin.
	 *
	 * @return bool
	 */
	public function current_user_can_manage() {
		return current_user_can( $this->get_required_capability() );
	}

	/**
	 * Register top-level menu and submenus.
	 */
	public function register_menu() {
		$cap = $this->get_required_capability();

		add_menu_page(
			__( 'Order Health Monitor', 'woo-order-health-monitor' ),
			__( 'Order Health', 'woo-order-health-monitor' ),
			$cap,
			'woo-order-health-monitor',
			array( $this, 'render_dashboard' ),
			'dashicons-analytics',
			56
		);

		add_submenu_page(
			'woo-order-health-monitor',
			__( 'Dashboard', 'woo-order-health-monitor' ),
			__( 'Dashboard', 'woo-order-health-monitor' ),
			$cap,
			'woo-order-health-monitor',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'woo-order-health-monitor',
			__( 'Issues', 'woo-order-health-monitor' ),
			__( 'Issues', 'woo-order-health-monitor' ),
			$cap,
			'ohm-issues',
			array( $this, 'render_issues' )
		);

		add_submenu_page(
			'woo-order-health-monitor',
			__( 'Reports', 'woo-order-health-monitor' ),
			__( 'Reports', 'woo-order-health-monitor' ),
			$cap,
			'ohm-reports',
			array( $this, 'render_reports' )
		);

		add_submenu_page(
			'woo-order-health-monitor',
			__( 'Settings', 'woo-order-health-monitor' ),
			__( 'Settings', 'woo-order-health-monitor' ),
			$cap,
			'ohm-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS on plugin pages only.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'woo-order-health-monitor' ) && false === strpos( $hook, 'ohm-' ) ) {
			return;
		}

		wp_enqueue_style(
			'ohm-admin',
			OHM_URL . 'assets/css/admin.css',
			array(),
			OHM_VERSION
		);
	}

	/**
	 * Display a warning when WooCommerce is unavailable.
	 */
	public function woocommerce_notice() {
		if ( OHM_Plugin::is_woocommerce_active() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'WooCommerce Order Health Monitor:', 'woo-order-health-monitor' ); ?></strong>
				<?php echo esc_html__( 'Please install and activate WooCommerce to use order monitoring features.', 'woo-order-health-monitor' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle POST/GET admin actions before output.
	 */
	public function handle_actions() {
		// CSV exports check capability/nonce themselves and must run before HTML.
		if ( ! empty( $_GET['ohm_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			OHM_Csv_Exporter::maybe_export( $this );
		}

		if ( ! $this->current_user_can_manage() ) {
			return;
		}

		// Manual scan.
		if ( isset( $_POST['ohm_scan_now'] ) ) {
			check_admin_referer( 'ohm_scan_now' );

			if ( ! OHM_Plugin::is_woocommerce_active() ) {
				$this->redirect_with_notice( 'dashboard', 'error', 'wc_inactive' );
			}

			$scanner = new OHM_Scanner();
			$result  = $scanner->start( 'manual' );

			$this->redirect_with_notice(
				'dashboard',
				$result['success'] ? 'success' : 'error',
				$result['success'] ? 'scan_started' : 'scan_failed',
				array( 'ohm_msg' => rawurlencode( $result['message'] ) )
			);
		}

		// Settings save.
		if ( isset( $_POST['ohm_save_settings'] ) ) {
			check_admin_referer( 'ohm_save_settings' );

			$input = isset( $_POST['ohm_settings'] ) && is_array( $_POST['ohm_settings'] )
				? wp_unslash( $_POST['ohm_settings'] )
				: array();

			$result = OHM_Settings::update( $input );

			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice(
					'settings',
					'error',
					'settings_invalid',
					array( 'ohm_msg' => rawurlencode( $result->get_error_message() ) )
				);
			}

			OHM_Scheduler::schedule_recurring();
			$this->redirect_with_notice( 'settings', 'success', 'settings_saved' );
		}

		// Settings reset.
		if ( isset( $_POST['ohm_reset_settings'] ) ) {
			check_admin_referer( 'ohm_reset_settings' );
			OHM_Settings::reset();
			OHM_Scheduler::schedule_recurring();
			$this->redirect_with_notice( 'settings', 'success', 'settings_reset' );
		}

		// Issue dismiss / reopen.
		if ( isset( $_GET['ohm_action'], $_GET['issue_id'] ) ) {
			$action   = sanitize_key( wp_unslash( $_GET['ohm_action'] ) );
			$issue_id = absint( $_GET['issue_id'] );

			if ( ! in_array( $action, array( 'dismiss', 'reopen' ), true ) || ! $issue_id ) {
				return;
			}

			check_admin_referer( 'ohm_issue_' . $action . '_' . $issue_id );

			$repo   = new OHM_Issue_Repository();
			$ok     = ( 'dismiss' === $action ) ? $repo->dismiss( $issue_id ) : $repo->reopen( $issue_id );
			$notice = $ok
				? ( 'dismiss' === $action ? 'issue_dismissed' : 'issue_reopened' )
				: 'issue_action_failed';

			$this->redirect_with_notice( 'issues', $ok ? 'success' : 'error', $notice );
		}
	}

	/**
	 * Render dashboard page.
	 */
	public function render_dashboard() {
		$this->ensure_cap();
		OHM_Admin_Notices::render_from_query();

		$page = new OHM_Dashboard_Page();
		$page->render();
	}

	/**
	 * Render issues page.
	 */
	public function render_issues() {
		$this->ensure_cap();
		OHM_Admin_Notices::render_from_query();

		$page = new OHM_Issues_Page();
		$page->render();
	}

	/**
	 * Render reports page.
	 */
	public function render_reports() {
		$this->ensure_cap();
		OHM_Admin_Notices::render_from_query();

		$page = new OHM_Reports_Page();
		$page->render();
	}

	/**
	 * Render settings page.
	 */
	public function render_settings() {
		$this->ensure_cap();
		OHM_Admin_Notices::render_from_query();

		$page = new OHM_Settings_Page();
		$page->render();
	}

	/**
	 * Capability gate for page callbacks.
	 */
	private function ensure_cap() {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'woo-order-health-monitor' ) );
		}
	}

	/**
	 * Redirect back to a plugin screen with a notice flag.
	 *
	 * @param string $page    dashboard|issues|settings.
	 * @param string $type    success|error.
	 * @param string $code    Notice code.
	 * @param array  $extra   Extra query args.
	 */
	private function redirect_with_notice( $page, $type, $code, $extra = array() ) {
		$slugs = array(
			'dashboard' => 'woo-order-health-monitor',
			'issues'    => 'ohm-issues',
			'settings'  => 'ohm-settings',
		);

		$slug = isset( $slugs[ $page ] ) ? $slugs[ $page ] : 'woo-order-health-monitor';

		$args = array_merge(
			array(
				'page'           => $slug,
				'ohm_notice'     => sanitize_key( $code ),
				'ohm_notice_type'=> sanitize_key( $type ),
			),
			$extra
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
