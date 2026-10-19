<?php
/**
 * Order scan orchestration with locking and batching.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs batched order health scans.
 */
class OHM_Scanner {

	const LOCK_KEY            = 'ohm_scan_lock';
	const STATE_KEY           = 'ohm_scan_state';
	const LOCK_TTL            = 900; // 15 minutes.
	const BATCH_HOOK          = 'ohm_process_scan_batch';
	const TIME_BUDGET_SECONDS = 8;

	/**
	 * Register cron/batch hooks.
	 */
	public function register_hooks() {
		add_action( self::BATCH_HOOK, array( $this, 'process_continuation' ) );
	}

	/**
	 * Start a new scan if none is running.
	 *
	 * @param string $trigger manual|scheduled.
	 * @return array{success:bool,message:string,scan_id?:int}
	 */
	public function start( $trigger = 'manual' ) {
		if ( ! OHM_Plugin::is_woocommerce_active() ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce is not active. Scanning is unavailable.', 'woo-order-health-monitor' ),
			);
		}

		$rules    = new OHM_Rules();
		$statuses = $rules->get_statuses_to_scan();

		if ( empty( $statuses ) ) {
			return array(
				'success' => false,
				'message' => __( 'No detection rules are enabled. Enable at least one rule in Settings.', 'woo-order-health-monitor' ),
			);
		}

		$this->recover_stale_state();

		if ( ! $this->acquire_lock() ) {
			return array(
				'success' => false,
				'message' => __( 'A scan is already in progress. Please wait for it to finish.', 'woo-order-health-monitor' ),
			);
		}

		$scan_repo = new OHM_Scan_Repository();
		$scan_id   = $scan_repo->start( $trigger );

		if ( ! $scan_id ) {
			self::release_lock();
			return array(
				'success' => false,
				'message' => __( 'Could not create a scan history record. Check the database and try again.', 'woo-order-health-monitor' ),
			);
		}

		$state = array(
			'scan_id'           => $scan_id,
			'page'              => 1,
			'statuses'          => $statuses,
			'orders_processed'  => 0,
			'issues_created'    => 0,
			'issues_updated'    => 0,
			'issues_resolved'   => 0,
			'errors_count'      => 0,
			'trigger'           => $trigger,
			'started_at'        => time(),
		);

		update_option( self::STATE_KEY, $state, false );

		$result = $this->run_batches( $state, true );

		return array(
			'success' => true,
			'scan_id' => $scan_id,
			'message' => $result['message'],
			'done'    => $result['done'],
		);
	}

	/**
	 * Continue a paused/incomplete scan (WP-Cron callback).
	 */
	public function process_continuation() {
		$state = get_option( self::STATE_KEY, null );

		if ( empty( $state ) || empty( $state['scan_id'] ) ) {
			self::release_lock();
			return;
		}

		if ( ! $this->acquire_lock( true ) ) {
			return;
		}

		$this->run_batches( $state, false );
	}

	/**
	 * Whether a scan is currently active.
	 *
	 * @return bool
	 */
	public static function is_running() {
		$lock  = get_transient( self::LOCK_KEY );
		$state = get_option( self::STATE_KEY, null );

		return (bool) $lock || ( ! empty( $state ) && ! empty( $state['scan_id'] ) );
	}

	/**
	 * Get current scan progress state.
	 *
	 * @return array|null
	 */
	public static function get_state() {
		$state = get_option( self::STATE_KEY, null );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Release the scan lock.
	 */
	public static function release_lock() {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Process as many batches as the time budget allows.
	 *
	 * @param array $state          Scan state.
	 * @param bool  $allow_schedule Whether to schedule continuation.
	 * @return array{done:bool,message:string}
	 */
	private function run_batches( $state, $allow_schedule ) {
		$deadline = time() + self::TIME_BUDGET_SECONDS;
		$done     = false;
		$message  = '';

		try {
			while ( time() < $deadline ) {
				$batch = $this->process_one_batch( $state );
				$state = $batch['state'];

				if ( $batch['done'] ) {
					$done    = true;
					$message = $batch['message'];
					break;
				}

				update_option( self::STATE_KEY, $state, false );
			}

			if ( ! $done ) {
				update_option( self::STATE_KEY, $state, false );
				$this->persist_counters( $state );

				if ( $allow_schedule ) {
					if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
						wp_schedule_single_event( time() + 5, self::BATCH_HOOK );
					}
					$message = __( 'Scan started. Processing continues in the background.', 'woo-order-health-monitor' );
				} else {
					if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
						wp_schedule_single_event( time() + 5, self::BATCH_HOOK );
					}
					$message = __( 'Scan batch processed; continuation scheduled.', 'woo-order-health-monitor' );
				}

				self::release_lock();
				// Keep lock released between batches so overlapping requests fail cleanly;
				// continuation re-acquires. Soft lock via state option indicates in-progress.
				set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
			}
		} catch ( Exception $e ) {
			$this->fail_scan( $state, __( 'Scan failed due to an unexpected error.', 'woo-order-health-monitor' ) );
			return array(
				'done'    => true,
				'message' => __( 'Scan failed due to an unexpected error.', 'woo-order-health-monitor' ),
			);
		} catch ( Error $e ) {
			$this->fail_scan( $state, __( 'Scan failed due to an unexpected error.', 'woo-order-health-monitor' ) );
			return array(
				'done'    => true,
				'message' => __( 'Scan failed due to an unexpected error.', 'woo-order-health-monitor' ),
			);
		}

		return array(
			'done'    => $done,
			'message' => $message,
		);
	}

	/**
	 * Process a single page of orders.
	 *
	 * @param array $state Scan state.
	 * @return array{done:bool,state:array,message:string}
	 */
	private function process_one_batch( $state ) {
		$rules      = new OHM_Rules();
		$issues     = new OHM_Issue_Repository();
		$batch_size = (int) OHM_Settings::get( 'batch_size', 50 );
		$page       = max( 1, (int) $state['page'] );
		$statuses   = isset( $state['statuses'] ) ? $state['statuses'] : $rules->get_statuses_to_scan();

		if ( empty( $statuses ) ) {
			return $this->complete_scan( $state, __( 'No statuses to scan.', 'woo-order-health-monitor' ) );
		}

		$query_args = array(
			'limit'    => $batch_size,
			'page'     => $page,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'ids',
			'status'   => $statuses,
			'paginate' => true,
		);

		$result = wc_get_orders( $query_args );

		if ( is_wp_error( $result ) ) {
			$this->fail_scan( $state, __( 'Order query failed.', 'woo-order-health-monitor' ) );
			return array(
				'done'    => true,
				'state'   => $state,
				'message' => __( 'Order query failed.', 'woo-order-health-monitor' ),
			);
		}

		$order_ids = isset( $result->orders ) ? $result->orders : array();
		$max_pages = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

		if ( empty( $order_ids ) && 1 === $page ) {
			$resolved = $this->reconcile_open_issues( $issues );
			$state['issues_resolved'] += $resolved;
			return $this->complete_scan(
				$state,
				__( 'Scan completed. No matching orders found.', 'woo-order-health-monitor' )
			);
		}

		foreach ( $order_ids as $order_id ) {
			$order_id = absint( $order_id );

			try {
				$order = wc_get_order( $order_id );

				if ( ! $order ) {
					++$state['errors_count'];
					continue;
				}

				++$state['orders_processed'];

				$matches       = $rules->evaluate_order( $order );
				$matched_types = array();

				foreach ( $matches as $match ) {
					$matched_types[] = $match['type'];

					$result_upsert = $issues->upsert_open(
						$order_id,
						$match['type'],
						$match['description'],
						isset( $match['meta'] ) ? $match['meta'] : array()
					);

					if ( 'created' === $result_upsert['action'] || 'reopened' === $result_upsert['action'] ) {
						++$state['issues_created'];
					} elseif ( 'updated' === $result_upsert['action'] || 'dismissed_seen' === $result_upsert['action'] ) {
						++$state['issues_updated'];
					} elseif ( 'error' === $result_upsert['action'] ) {
						++$state['errors_count'];
					}
				}

				// Resolve any open issues for this order whose rules no longer match.
				foreach ( OHM_Rules::all_types() as $type ) {
					if ( ! OHM_Settings::is_rule_enabled( $type ) ) {
						continue;
					}

					if ( in_array( $type, $matched_types, true ) ) {
						continue;
					}

					$resolve_action = $issues->resolve_if_open( $order_id, $type );
					if ( 'resolved' === $resolve_action ) {
						++$state['issues_resolved'];
					}
				}
			} catch ( Exception $e ) {
				++$state['errors_count'];
			} catch ( Error $e ) {
				++$state['errors_count'];
			}
		}

		if ( $page >= $max_pages ) {
			$resolved = $this->reconcile_open_issues( $issues );
			$state['issues_resolved'] += $resolved;

			return $this->complete_scan(
				$state,
				sprintf(
					/* translators: 1: orders processed, 2: issues created, 3: resolved */
					__( 'Scan completed. Processed %1$d orders; %2$d issues created/reopened; %3$d resolved.', 'woo-order-health-monitor' ),
					(int) $state['orders_processed'],
					(int) $state['issues_created'],
					(int) $state['issues_resolved']
				)
			);
		}

		$state['page'] = $page + 1;
		$this->persist_counters( $state );

		return array(
			'done'    => false,
			'state'   => $state,
			'message' => '',
		);
	}

	/**
	 * Re-check all open issues so orders that left scanned statuses are resolved.
	 *
	 * @param OHM_Issue_Repository $issues Repository.
	 * @return int Number newly resolved.
	 */
	private function reconcile_open_issues( $issues ) {
		$rules = new OHM_Rules();
		$ids   = $issues->get_open_ids();
		$total = 0;

		foreach ( $ids as $issue_id ) {
			$issue = $issues->get_by_id( $issue_id );

			if ( ! $issue || 'open' !== $issue->state ) {
				continue;
			}

			$order = wc_get_order( (int) $issue->order_id );

			if ( ! $order ) {
				if ( 'resolved' === $issues->resolve_if_open( (int) $issue->order_id, $issue->issue_type ) ) {
					++$total;
				}
				continue;
			}

			if ( ! OHM_Settings::is_rule_enabled( $issue->issue_type ) ) {
				if ( 'resolved' === $issues->resolve_if_open( (int) $issue->order_id, $issue->issue_type ) ) {
					++$total;
				}
				continue;
			}

			$matches       = $rules->evaluate_order( $order );
			$matched_types = wp_list_pluck( $matches, 'type' );

			if ( ! in_array( $issue->issue_type, $matched_types, true ) ) {
				if ( 'resolved' === $issues->resolve_if_open( (int) $issue->order_id, $issue->issue_type ) ) {
					++$total;
				}
			}
		}

		$retention = (int) OHM_Settings::get( 'scan_history_retention', 30 );
		$scan_repo = new OHM_Scan_Repository();
		$scan_repo->prune( $retention );

		return $total;
	}

	/**
	 * Complete a successful scan.
	 *
	 * @param array  $state   State.
	 * @param string $message Outcome message.
	 * @return array
	 */
	private function complete_scan( $state, $message ) {
		$this->persist_counters( $state );

		$scan_repo = new OHM_Scan_Repository();
		$scan_repo->finish( (int) $state['scan_id'], 'completed', $message );

		delete_option( self::STATE_KEY );
		self::release_lock();
		wp_clear_scheduled_hook( self::BATCH_HOOK );

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => $message,
		);
	}

	/**
	 * Fail the current scan.
	 *
	 * @param array  $state   State.
	 * @param string $message Error message.
	 */
	private function fail_scan( $state, $message ) {
		if ( ! empty( $state['scan_id'] ) ) {
			$this->persist_counters( $state );
			$scan_repo = new OHM_Scan_Repository();
			$scan_repo->finish( (int) $state['scan_id'], 'failed', $message );
		}

		delete_option( self::STATE_KEY );
		self::release_lock();
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	/**
	 * Persist counters to scan history.
	 *
	 * @param array $state State.
	 */
	private function persist_counters( $state ) {
		if ( empty( $state['scan_id'] ) ) {
			return;
		}

		$scan_repo = new OHM_Scan_Repository();
		$scan_repo->update(
			(int) $state['scan_id'],
			array(
				'orders_processed' => (int) $state['orders_processed'],
				'issues_created'   => (int) $state['issues_created'],
				'issues_updated'   => (int) $state['issues_updated'],
				'issues_resolved'  => (int) $state['issues_resolved'],
				'errors_count'     => (int) $state['errors_count'],
				'meta'             => wp_json_encode(
					array(
						'page' => isset( $state['page'] ) ? (int) $state['page'] : 1,
					)
				),
			)
		);
	}

	/**
	 * Acquire an exclusive scan lock.
	 *
	 * @param bool $renew Renew existing lock for continuation.
	 * @return bool
	 */
	private function acquire_lock( $renew = false ) {
		$existing = get_transient( self::LOCK_KEY );

		if ( $existing && ! $renew ) {
			return false;
		}

		set_transient( self::LOCK_KEY, get_current_user_id() ? get_current_user_id() : 1, self::LOCK_TTL );
		return true;
	}

	/**
	 * Recover interrupted scans whose lock expired.
	 */
	private function recover_stale_state() {
		$scan_repo = new OHM_Scan_Repository();
		$scan_repo->interrupt_stale( self::LOCK_TTL + 60 );

		$state = get_option( self::STATE_KEY, null );
		$lock  = get_transient( self::LOCK_KEY );

		if ( ! empty( $state ) && ! $lock ) {
			// Stale state without lock — mark interrupted and clear.
			if ( ! empty( $state['scan_id'] ) ) {
				$scan_repo->finish(
					(int) $state['scan_id'],
					'interrupted',
					__( 'Previous scan was interrupted and cleaned up.', 'woo-order-health-monitor' )
				);
			}
			delete_option( self::STATE_KEY );
			wp_clear_scheduled_hook( self::BATCH_HOOK );
		}
	}
}
