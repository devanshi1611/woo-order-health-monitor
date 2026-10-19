<?php
/**
 * Persistence for issue records.
 *
 * @package WooOrderHealthMonitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD helpers for the ohm_issues table.
 */
class OHM_Issue_Repository {

	const STATES = array( 'open', 'resolved', 'dismissed' );
	const TYPES  = array( 'failed', 'pending', 'processing', 'on_hold' );

	/**
	 * Build a stable issue key.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $type     Issue type.
	 * @return string
	 */
	public static function make_key( $order_id, $type ) {
		return absint( $order_id ) . ':' . sanitize_key( $type );
	}

	/**
	 * Upsert an open issue for a detected condition.
	 *
	 * Dismissed issues are refreshed (last_checked / description) but not reopened.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $type        Issue type.
	 * @param string $description Human-readable description.
	 * @param array  $meta        Optional diagnostic meta.
	 * @return array{action:string,issue:?object} Result summary.
	 */
	public function upsert_open( $order_id, $type, $description, $meta = array() ) {
		global $wpdb;

		$table     = OHM_Installer::issues_table();
		$issue_key = self::make_key( $order_id, $type );
		$now       = current_time( 'mysql', true );
		$existing  = $this->get_by_key( $issue_key );

		$meta_json = ! empty( $meta ) ? wp_json_encode( $meta ) : null;

		if ( ! $existing ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'issue_key'      => $issue_key,
					'order_id'       => absint( $order_id ),
					'issue_type'     => sanitize_key( $type ),
					'description'    => sanitize_textarea_field( $description ),
					'state'          => 'open',
					'first_detected' => $now,
					'last_checked'   => $now,
					'meta'           => $meta_json,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return array( 'action' => 'error', 'issue' => null );
			}

			return array(
				'action' => 'created',
				'issue'  => $this->get_by_key( $issue_key ),
			);
		}

		// Dismissed: keep history and dismissed state; refresh check metadata only.
		if ( 'dismissed' === $existing->state ) {
			$wpdb->update(
				$table,
				array(
					'description'  => sanitize_textarea_field( $description ),
					'last_checked' => $now,
					'meta'         => $meta_json,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return array(
				'action' => 'dismissed_seen',
				'issue'  => $this->get_by_id( (int) $existing->id ),
			);
		}

		$data = array(
			'description'  => sanitize_textarea_field( $description ),
			'last_checked' => $now,
			'meta'         => $meta_json,
		);
		$formats = array( '%s', '%s', '%s' );

		$action = 'updated';

		if ( 'resolved' === $existing->state ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET description = %s, last_checked = %s, meta = %s, state = %s, resolved_at = NULL
					WHERE id = %d",
					sanitize_textarea_field( $description ),
					$now,
					$meta_json,
					'open',
					(int) $existing->id
				)
			);

			return array(
				'action' => 'reopened',
				'issue'  => $this->get_by_id( (int) $existing->id ),
			);
		}

		$wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $existing->id ),
			$formats,
			array( '%d' )
		);

		return array(
			'action' => $action,
			'issue'  => $this->get_by_id( (int) $existing->id ),
		);
	}

	/**
	 * Mark an issue resolved when the condition no longer applies.
	 *
	 * Dismissed issues are left dismissed; only last_checked is updated.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $type     Issue type.
	 * @return string Action taken: resolved|dismissed_cleared|none|error.
	 */
	public function resolve_if_open( $order_id, $type ) {
		global $wpdb;

		$issue = $this->get_by_key( self::make_key( $order_id, $type ) );

		if ( ! $issue ) {
			return 'none';
		}

		$now = current_time( 'mysql', true );

		if ( 'dismissed' === $issue->state ) {
			$wpdb->update(
				OHM_Installer::issues_table(),
				array( 'last_checked' => $now ),
				array( 'id' => (int) $issue->id ),
				array( '%s' ),
				array( '%d' )
			);
			return 'dismissed_cleared';
		}

		if ( 'resolved' === $issue->state ) {
			$wpdb->update(
				OHM_Installer::issues_table(),
				array( 'last_checked' => $now ),
				array( 'id' => (int) $issue->id ),
				array( '%s' ),
				array( '%d' )
			);
			return 'none';
		}

		$updated = $wpdb->update(
			OHM_Installer::issues_table(),
			array(
				'state'        => 'resolved',
				'last_checked' => $now,
				'resolved_at'  => $now,
			),
			array( 'id' => (int) $issue->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? 'error' : 'resolved';
	}

	/**
	 * Dismiss an issue by ID.
	 *
	 * @param int $id Issue ID.
	 * @return bool
	 */
	public function dismiss( $id ) {
		global $wpdb;

		$issue = $this->get_by_id( $id );
		if ( ! $issue || 'dismissed' === $issue->state ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		$result = $wpdb->update(
			OHM_Installer::issues_table(),
			array(
				'state'        => 'dismissed',
				'dismissed_at' => $now,
				'last_checked' => $now,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Reopen a dismissed or resolved issue (admin action).
	 *
	 * @param int $id Issue ID.
	 * @return bool
	 */
	public function reopen( $id ) {
		global $wpdb;

		$issue = $this->get_by_id( $id );
		if ( ! $issue || 'open' === $issue->state ) {
			return false;
		}

		$table = OHM_Installer::issues_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET state = %s, resolved_at = NULL, dismissed_at = NULL, last_checked = %s
				WHERE id = %d",
				'open',
				$now,
				absint( $id )
			)
		);

		return false !== $result;
	}

	/**
	 * Get all open issue IDs (for end-of-scan reconciliation).
	 *
	 * @return int[]
	 */
	public function get_open_ids() {
		global $wpdb;

		$table = OHM_Installer::issues_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE state = %s ORDER BY id ASC",
				'open'
			)
		);

		return array_map( 'absint', $ids ? $ids : array() );
	}

	/**
	 * Get issue by primary key.
	 *
	 * @param int $id Issue ID.
	 * @return object|null
	 */
	public function get_by_id( $id ) {
		global $wpdb;

		$table = OHM_Installer::issues_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) )
		);

		return $row ? $row : null;
	}

	/**
	 * Get issue by stable key.
	 *
	 * @param string $issue_key Issue key.
	 * @return object|null
	 */
	public function get_by_key( $issue_key ) {
		global $wpdb;

		$table = OHM_Installer::issues_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE issue_key = %s", $issue_key )
		);

		return $row ? $row : null;
	}

	/**
	 * Count issues matching filters.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count( $args = array() ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::issues_table();

		$sql = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Query issues with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'state'      => '',
			'issue_type' => '',
			'order_id'   => 0,
			'date_from'  => '',
			'date_to'    => '',
			'date_field' => 'first_detected',
			'per_page'   => 20,
			'page'       => 1,
			'orderby'    => 'last_checked',
			'order'      => 'DESC',
		);

		$args                    = wp_parse_args( $args, $defaults );
		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::issues_table();

		$allowed_orderby = array(
			'id'             => 'id',
			'last_checked'   => 'last_checked',
			'first_detected' => 'first_detected',
			'order_id'       => 'order_id',
			'issue_type'     => 'issue_type',
			'state'          => 'state',
		);

		$orderby = isset( $allowed_orderby[ $args['orderby'] ] ) ? $allowed_orderby[ $args['orderby'] ] : 'last_checked';
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per     = max( 1, min( 100, (int) $args['per_page'] ) );
		$page    = max( 1, (int) $args['page'] );
		$offset  = ( $page - 1 ) * $per;

		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values[] = $per;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count open issues grouped by type.
	 *
	 * @return array<string,int>
	 */
	public function count_open_by_type() {
		global $wpdb;

		$table = OHM_Installer::issues_table();
		$out   = array(
			'failed'     => 0,
			'pending'    => 0,
			'processing' => 0,
			'on_hold'    => 0,
			'total'      => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT issue_type, COUNT(*) AS cnt FROM {$table} WHERE state = %s GROUP BY issue_type",
				'open'
			)
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$type = $row->issue_type;
				if ( isset( $out[ $type ] ) ) {
					$out[ $type ] = (int) $row->cnt;
					$out['total'] += (int) $row->cnt;
				}
			}
		}

		return $out;
	}

	/**
	 * Count issues whose timestamp field falls in a GMT range.
	 *
	 * Uses the same filters as query()/count() (type, state, order_id, date_field).
	 *
	 * @param string $date_field first_detected|last_checked|resolved_at|dismissed_at.
	 * @param string $gmt_from   Inclusive start.
	 * @param string $gmt_to     Inclusive end.
	 * @param array  $extra      Optional filters: issue_type, state, order_id.
	 * @return int
	 */
	public function count_for_timestamp_range( $date_field, $gmt_from, $gmt_to, $extra = array() ) {
		$allowed = array( 'first_detected', 'last_checked', 'resolved_at', 'dismissed_at' );
		if ( ! in_array( $date_field, $allowed, true ) ) {
			return 0;
		}

		$args = array(
			'date_field' => $date_field,
			'date_from'  => $gmt_from,
			'date_to'    => $gmt_to,
		);

		if ( ! empty( $extra['issue_type'] ) ) {
			$args['issue_type'] = $extra['issue_type'];
		}
		if ( ! empty( $extra['state'] ) ) {
			$args['state'] = $extra['state'];
		}
		if ( ! empty( $extra['order_id'] ) ) {
			$args['order_id'] = absint( $extra['order_id'] );
		}

		return $this->count( $args );
	}

	/**
	 * Iterate matching issue IDs in stable order for streaming exports.
	 *
	 * @param array $args   Filters (same as query()).
	 * @param int   $after_id Return IDs greater than this (exclusive).
	 * @param int   $limit  Batch size.
	 * @return int[]
	 */
	public function get_ids_after( $args, $after_id, $limit = 100 ) {
		global $wpdb;

		list( $where, $values ) = $this->build_where( $args );
		$table                   = OHM_Installer::issues_table();
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
	 * Build WHERE clause fragments.
	 *
	 * @param array $args Filters.
	 * @return array{0:string,1:array}
	 */
	private function build_where( $args ) {
		$clauses = array();
		$values  = array();

		if ( ! empty( $args['state'] ) && in_array( $args['state'], self::STATES, true ) ) {
			$clauses[] = 'state = %s';
			$values[]  = $args['state'];
		}

		if ( ! empty( $args['issue_type'] ) && in_array( $args['issue_type'], self::TYPES, true ) ) {
			$clauses[] = 'issue_type = %s';
			$values[]  = $args['issue_type'];
		}

		if ( ! empty( $args['order_id'] ) ) {
			$clauses[] = 'order_id = %d';
			$values[]  = absint( $args['order_id'] );
		}

		$date_field = isset( $args['date_field'] ) ? $args['date_field'] : 'first_detected';
		$allowed_date_fields = array( 'first_detected', 'last_checked', 'resolved_at', 'dismissed_at' );
		if ( ! in_array( $date_field, $allowed_date_fields, true ) ) {
			$date_field = 'first_detected';
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = "{$date_field} >= %s";
			$values[]  = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = "{$date_field} <= %s";
			$values[]  = $args['date_to'];
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

		return array( $where, $values );
	}
}
