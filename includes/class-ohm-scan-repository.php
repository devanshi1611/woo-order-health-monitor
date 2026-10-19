<?php
/**
 * Persistence for scan history records.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD helpers for the ohm_scan_history table.
 */
class OHM_Scan_Repository {

	/**
	 * Start a new scan history row.
	 *
	 * @param string $trigger_source manual|scheduled|resume.
	 * @return int|false Insert ID or false.
	 */
	public function start( $trigger_source = 'manual' ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			OHM_Installer::scans_table(),
			array(
				'started_at'       => current_time( 'mysql', true ),
				'status'           => 'running',
				'trigger_source'   => sanitize_key( $trigger_source ),
				'orders_processed' => 0,
				'issues_created'   => 0,
				'issues_updated'   => 0,
				'issues_resolved'  => 0,
				'errors_count'     => 0,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update counters on a running scan.
	 *
	 * @param int   $id   Scan ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$allowed = array(
			'orders_processed' => '%d',
			'issues_created'   => '%d',
			'issues_updated'   => '%d',
			'issues_resolved'  => '%d',
			'errors_count'     => '%d',
			'message'          => '%s',
			'status'           => '%s',
			'completed_at'     => '%s',
			'meta'             => '%s',
		);

		$update  = array();
		$formats = array();

		foreach ( $allowed as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$update[ $key ] = $data[ $key ];
				$formats[]      = $format;
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update(
			OHM_Installer::scans_table(),
			$update,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Mark a scan completed.
	 *
	 * @param int    $id      Scan ID.
	 * @param string $status  completed|failed|interrupted.
	 * @param string $message Optional message.
	 * @return bool
	 */
	public function finish( $id, $status = 'completed', $message = '' ) {
		$data = array(
			'status'       => sanitize_key( $status ),
			'completed_at' => current_time( 'mysql', true ),
		);

		if ( '' !== $message ) {
			$data['message'] = sanitize_textarea_field( $message );
		}

		return $this->update( $id, $data );
	}

	/**
	 * Get a scan by ID.
	 *
	 * @param int $id Scan ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;

		$table = OHM_Installer::scans_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) )
		);

		return $row ? $row : null;
	}

	/**
	 * Most recent finished or running scan.
	 *
	 * @return object|null
	 */
	public function get_latest() {
		global $wpdb;

		$table = OHM_Installer::scans_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
	}

	/**
	 * Recent scan history.
	 *
	 * @param int $limit Number of rows.
	 * @return array
	 */
	public function get_recent( $limit = 10 ) {
		global $wpdb;

		$table = OHM_Installer::scans_table();
		$limit = max( 1, min( 50, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Mark stale running scans as interrupted.
	 *
	 * @param int $max_age_seconds Age threshold.
	 * @return int Rows updated.
	 */
	public function interrupt_stale( $max_age_seconds = 3600 ) {
		global $wpdb;

		$table     = OHM_Installer::scans_table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - absint( $max_age_seconds ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, completed_at = %s, message = %s
				WHERE status = %s AND started_at < %s",
				'interrupted',
				current_time( 'mysql', true ),
				__( 'Scan interrupted after exceeding the maximum runtime.', 'woo-order-health-monitor' ),
				'running',
				$threshold
			)
		);
	}

	/**
	 * Delete old scan history rows.
	 *
	 * @param int $days Retention days.
	 * @return int Rows deleted.
	 */
	public function prune( $days ) {
		global $wpdb;

		$table     = OHM_Installer::scans_table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE started_at < %s AND status != %s",
				$threshold,
				'running'
			)
		);
	}

	/**
	 * Allowed scan statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'running', 'completed', 'failed', 'interrupted' );
	}

	/**
	 * Allowed trigger sources.
	 *
	 * @return string[]
	 */
	public static function triggers() {
		return array( 'manual', 'scheduled', 'resume' );
	}

	/**
	 * Count scans matching filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public function count( $args = array() ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::scans_table();
		$sql                     = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Query scans with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'         => '',
			'trigger_source' => '',
			'date_from'      => '',
			'date_to'        => '',
			'per_page'       => 20,
			'page'           => 1,
			'orderby'        => 'started_at',
			'order'          => 'DESC',
		);

		$args                    = wp_parse_args( $args, $defaults );
		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::scans_table();

		$allowed_orderby = array(
			'id'         => 'id',
			'started_at' => 'started_at',
			'status'     => 'status',
		);

		$orderby = isset( $allowed_orderby[ $args['orderby'] ] ) ? $allowed_orderby[ $args['orderby'] ] : 'started_at';
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per     = max( 1, min( 100, (int) $args['per_page'] ) );
		$page    = max( 1, (int) $args['page'] );
		$offset  = ( $page - 1 ) * $per;

		$sql      = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values[] = $per;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Aggregate scan metrics for a filter set.
	 *
	 * @param array $args Filters (date/status/trigger).
	 * @return array<string,int|float>
	 */
	public function summarize( $args = array() ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::scans_table();

		$sql = "SELECT
			COUNT(*) AS total_scans,
			SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS successful_scans,
			SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_scans,
			SUM(CASE WHEN errors_count > 0 THEN 1 ELSE 0 END) AS scans_with_errors,
			COALESCE(SUM(orders_processed), 0) AS orders_processed,
			COALESCE(SUM(issues_created), 0) AS issues_created,
			COALESCE(SUM(issues_updated), 0) AS issues_updated,
			COALESCE(SUM(issues_resolved), 0) AS issues_resolved,
			COALESCE(SUM(errors_count), 0) AS errors_count
			FROM {$table} {$where}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $sql );
		}

		if ( ! $row ) {
			return array(
				'total_scans'       => 0,
				'successful_scans'  => 0,
				'failed_scans'      => 0,
				'scans_with_errors' => 0,
				'orders_processed'  => 0,
				'issues_created'    => 0,
				'issues_updated'    => 0,
				'issues_resolved'   => 0,
				'errors_count'      => 0,
			);
		}

		return array(
			'total_scans'       => (int) $row->total_scans,
			'successful_scans'  => (int) $row->successful_scans,
			'failed_scans'      => (int) $row->failed_scans,
			'scans_with_errors' => (int) $row->scans_with_errors,
			'orders_processed'  => (int) $row->orders_processed,
			'issues_created'    => (int) $row->issues_created,
			'issues_updated'    => (int) $row->issues_updated,
			'issues_resolved'   => (int) $row->issues_resolved,
			'errors_count'      => (int) $row->errors_count,
		);
	}

	/**
	 * Iterate scan IDs for streaming exports.
	 *
	 * @param array $args     Filters.
	 * @param int   $after_id Exclusive lower bound.
	 * @param int   $limit    Batch size.
	 * @return int[]
	 */
	public function get_ids_after( $args, $after_id, $limit = 100 ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::scans_table();
		$after_id                = absint( $after_id );
		$limit                   = max( 1, min( 500, (int) $limit ) );

		$id_clause = 'id > %d';
		if ( $where ) {
			$where .= ' AND ' . $id_clause;
		} else {
			$where = 'WHERE ' . $id_clause;
		}
		$values[] = $after_id;
		$values[] = $limit;

		$sql = "SELECT id FROM {$table} {$where} ORDER BY id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );

		return array_map( 'absint', $ids ? $ids : array() );
	}

	/**
	 * Build WHERE clause for scan filters.
	 *
	 * @param array $args Filters.
	 * @return array{0:string,1:array}
	 */
	private function build_where( $args ) {
		$clauses = array();
		$values  = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::statuses(), true ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $args['status'];
		}

		if ( ! empty( $args['trigger_source'] ) && in_array( $args['trigger_source'], self::triggers(), true ) ) {
			$clauses[] = 'trigger_source = %s';
			$values[]  = $args['trigger_source'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = 'started_at >= %s';
			$values[]  = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = 'started_at <= %s';
			$values[]  = $args['date_to'];
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

		return array( $where, $values );
	}
}
