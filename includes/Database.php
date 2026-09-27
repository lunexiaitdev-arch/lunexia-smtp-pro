<?php
/**
 * Database management class for lunexia SMTP
 *
 * @package Lunexia_SMTP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunexia_SMTP_Database {

	protected $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'lunexia_smtp_logs';
	}

	/**
	 * Create custom logs table on plugin activation
	 */
	public function create_logs_table() {
		global $wpdb;

		$table_name      = $this->table_name;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			to_email VARCHAR(255) NOT NULL,
			subject VARCHAR(255),
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_status (status),
			INDEX idx_email (to_email),
			INDEX idx_created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'lunexia_smtp_db_version', LUNEXIA_SMTP_VERSION );
	}

	/**
	 * Insert a new log entry
	 *
	 * @param array $data {
	 *     @type string $to_email      Recipient email address
	 *     @type string $subject       Email subject
	 *     @type string $status        'sent' or 'failed'
	 *     @type string $error_message Error details if failed
	 * }
	 * @return int|false The number of rows inserted, or false on error
	 */
	public function insert_log( $data ) {
		global $wpdb;

		$insert_data = array(
			'to_email'      => sanitize_email( $data['to_email'] ?? '' ),
			'subject'       => sanitize_text_field( $data['subject'] ?? '' ),
			'status'        => in_array( $data['status'] ?? '', array( 'sent', 'failed' ), true ) ? $data['status'] : 'pending',
			'error_message' => isset( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : null,
			'created_at'    => current_time( 'mysql' ),
		);

		return $wpdb->insert(
			$this->table_name,
			$insert_data,
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get logs with optional filtering
	 *
	 * @param array $args Filter arguments.
	 * @return array Array of log entries
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;

		$limit   = absint( $args['limit'] ?? 50 );
		$offset  = absint( $args['offset'] ?? 0 );
		$status  = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'id', 'to_email', 'subject', 'status', 'created_at' ), true ) ? $args['orderby'] : 'created_at';
		$order   = isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql    = "SELECT * FROM $this->table_name WHERE 1=1";
		$params = array();

		if ( ! empty( $status ) ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		$sql     .= " ORDER BY $orderby $order LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$prepared_sql = $wpdb->prepare( $sql, $params );

		return $wpdb->get_results( $prepared_sql );
	}

	/**
	 * Get total count of logs
	 *
	 * @param string $status Optional status filter.
	 * @return int Count of logs
	 */
	public function get_logs_count( $status = '' ) {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM $this->table_name WHERE 1=1";

		if ( ! empty( $status ) ) {
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM $this->table_name WHERE status = %s", $status );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get the table name
	 *
	 * @return string
	 */
	public function get_table_name() {
		return $this->table_name;
	}
}

// Backwards compatibility alias
if ( ! class_exists( 'Lite_SMTP_Database' ) ) {
	class_alias( 'Lunexia_SMTP_Database', 'Lite_SMTP_Database' );
}
