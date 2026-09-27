<?php
/**
 * Settings Page Handler for Lunexia SMTP Pro
 *
 * @package Lunexia_SMTP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunexia_SMTP_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_lunexia_smtp_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_lunexia_smtp_authenticate_google', array( $this, 'ajax_authenticate_google' ) );
		add_action( 'wp_ajax_lunexia_smtp_disconnect_google', array( $this, 'ajax_disconnect_google' ) );
		add_action( 'wp_ajax_lunexia_smtp_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_lunexia_smtp_regenerate_keys', array( $this, 'ajax_regenerate_keys' ) );
		add_action( 'wp_ajax_lunexia_smtp_save_preferences', array( $this, 'ajax_save_preferences' ) );
		add_action( 'wp_ajax_lunexia_smtp_export_logs', array( $this, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_lunexia_smtp_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_lunexia_smtp_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'wp_ajax_lunexia_smtp_notice_action', array( $this, 'handle_notice_action' ) );

		// Backwards compatibility AJAX endpoints
		add_action( 'wp_ajax_lite_smtp_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_lite_smtp_authenticate_google', array( $this, 'ajax_authenticate_google' ) );
		add_action( 'wp_ajax_lite_smtp_disconnect_google', array( $this, 'ajax_disconnect_google' ) );
		add_action( 'wp_ajax_lite_smtp_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_lite_smtp_regenerate_keys', array( $this, 'ajax_regenerate_keys' ) );
		add_action( 'wp_ajax_lite_smtp_save_preferences', array( $this, 'ajax_save_preferences' ) );
		add_action( 'wp_ajax_lite_smtp_export_logs', array( $this, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_lite_smtp_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_lite_smtp_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'wp_ajax_lite_smtp_notice_action', array( $this, 'handle_notice_action' ) );

		// Admin notices
		add_action( 'admin_notices', array( $this, 'display_notices' ), 20 );
		add_action( 'wp_login', array( $this, 'clear_temporary_dismissals' ), 10, 2 );
	}

	public function add_admin_menu() {
		add_options_page(
			__( 'Lunexia SMTP Settings', 'lunexia-smtp-pro' ),
			__( 'Lunexia SMTP', 'lunexia-smtp-pro' ),
			'manage_options',
			'lunexia-smtp-pro',
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_admin_scripts( $hook ) {
		// Enqueue notice assets globally for admin users with proper capability
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_script(
				'lunexia-smtp-notices-js',
				LUNEXIA_SMTP_ASSETS . 'admin.js',
				array( 'jquery' ),
				LUNEXIA_SMTP_VERSION,
				true
			);

			wp_localize_script(
				'lunexia-smtp-notices-js',
				'liteSMTPNoticeData',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'noticeNonce' => wp_create_nonce( 'lunexia_smtp_notice' ),
				)
			);
		}

		if ( 'settings_page_lunexia-smtp-pro' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lunexia-smtp-admin',
			LUNEXIA_SMTP_ASSETS . 'admin.css',
			array(),
			LUNEXIA_SMTP_VERSION
		);

		wp_enqueue_script(
			'lunexia-smtp-admin',
			LUNEXIA_SMTP_ASSETS . 'admin.js',
			array( 'jquery' ),
			LUNEXIA_SMTP_VERSION,
			true
		);

		wp_localize_script(
			'lunexia-smtp-admin',
			'lunexiaSMTPData',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lunexia_smtp_nonce' ),
				'security'        => wp_create_nonce( 'lunexia_smtp_test_email' ),
				'settingsPageUrl' => admin_url( 'options-general.php?page=lunexia-smtp-pro' ),
			)
		);
	}

	public function get_settings() {
		$settings = get_option( 'lunexia_smtp_settings', null );
		if ( null === $settings ) {
			$settings = get_option( 'lite_smtp_settings', array() );
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Site Key & Secret Key generation if empty
		if ( empty( $settings['site_key'] ) ) {
			$settings['site_key'] = 'sk_test_' . wp_generate_uuid4();
		}
		if ( empty( $settings['secret_key'] ) ) {
			$settings['secret_key'] = 'sk_secret_' . wp_generate_uuid4();
		}

		return $settings;
	}

	public function update_settings( $settings ) {
		return update_option( 'lunexia_smtp_settings', $settings );
	}

	public function ajax_save_settings() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$tab      = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : '';
		$settings = $this->get_settings();

		if ( 'gmail' === $tab ) {
			$settings['gmail_client_id']     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
			$settings['gmail_client_secret'] = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		} elseif ( 'smtp' === $tab ) {
			$settings['smtp_host']       = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
			$settings['smtp_port']       = isset( $_POST['smtp_port'] ) ? absint( wp_unslash( $_POST['smtp_port'] ) ) : 587;
			$settings['smtp_username']   = isset( $_POST['smtp_username'] ) ? sanitize_email( wp_unslash( $_POST['smtp_username'] ) ) : '';
			$settings['smtp_password']   = isset( $_POST['smtp_password'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_password'] ) ) : '';
			$settings['smtp_encryption'] = isset( $_POST['smtp_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ) ) : 'tls';
			$settings['from_email']      = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
			$settings['from_name']       = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : get_bloginfo( 'name' );
		}

		$this->update_settings( $settings );
		wp_send_json_success( esc_html__( 'Settings saved successfully', 'lunexia-smtp-pro' ) );
	}

	public function ajax_authenticate_google() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$settings  = $this->get_settings();
		$auth_code = isset( $_POST['auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';

		if ( empty( $auth_code ) ) {
			wp_send_json_error( esc_html__( 'Authorization code is required', 'lunexia-smtp-pro' ) );
		}

		$settings['google_auth_code']        = $auth_code;
		$settings['google_authenticated']    = true;
		$settings['google_authenticated_at'] = current_time( 'mysql' );

		$this->update_settings( $settings );

		wp_send_json_success(
			array(
				'message'       => esc_html__( 'Successfully authenticated with Google!', 'lunexia-smtp-pro' ),
				'authenticated' => true,
			)
		);
	}

	public function ajax_disconnect_google() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$settings = $this->get_settings();
		$settings['google_authenticated'] = false;
		unset( $settings['google_auth_code'], $settings['google_authenticated_at'] );

		$this->update_settings( $settings );
		delete_option( 'lunexia_smtp_tokens' );
		delete_option( 'lunexia_smtp_gmail_email' );
		delete_option( 'lite_smtp_tokens' );
		delete_option( 'lite_smtp_gmail_email' );

		wp_send_json_success(
			array(
				'message'       => esc_html__( 'Successfully disconnected from Google', 'lunexia-smtp-pro' ),
				'authenticated' => false,
			)
		);
	}

	public function ajax_get_logs() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$database = new Lunexia_SMTP_Database();
		$logs     = $database->get_logs(
			array(
				'limit'   => 100,
				'orderby' => 'id',
				'order'   => 'DESC',
			)
		);

		$formatted_logs = array();
		if ( ! empty( $logs ) ) {
			foreach ( $logs as $log ) {
				$formatted_logs[] = array(
					'id'            => absint( $log->id ),
					'recipient'     => sanitize_email( $log->to_email ),
					'subject'       => sanitize_text_field( $log->subject ),
					'status'        => sanitize_text_field( $log->status ),
					'error_message' => sanitize_text_field( $log->error_message ),
					'timestamp'     => sanitize_text_field( $log->created_at ),
				);
			}
		}

		wp_send_json_success( $formatted_logs );
	}

	public function ajax_regenerate_keys() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$settings               = $this->get_settings();
		$settings['site_key']   = 'sk_test_' . wp_generate_uuid4();
		$settings['secret_key'] = 'sk_secret_' . wp_generate_uuid4();

		$this->update_settings( $settings );

		wp_send_json_success(
			array(
				'site_key'   => sanitize_text_field( $settings['site_key'] ),
				'secret_key' => sanitize_text_field( $settings['secret_key'] ),
				'message'    => esc_html__( 'Keys regenerated successfully', 'lunexia-smtp-pro' ),
			)
		);
	}

	public function ajax_save_preferences() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$preferences = isset( $_POST['preferences'] ) && is_array( $_POST['preferences'] ) ? $_POST['preferences'] : array();

		$settings                           = $this->get_settings();
		$settings['enable_lite_smtp']       = ! empty( $preferences['enable_lite_smtp'] );
		$settings['enable_logging']         = ! empty( $preferences['enable_email_logging'] );
		$settings['delivery_notifications'] = ! empty( $preferences['enable_delivery_notifications'] );

		$this->update_settings( $settings );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Preferences saved successfully', 'lunexia-smtp-pro' ),
			)
		);
	}

	public function ajax_export_logs() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$format   = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';
		$database = new Lunexia_SMTP_Database();
		$logs     = $database->get_logs( array( 'limit' => 10000 ) );

		if ( empty( $logs ) ) {
			wp_die( esc_html__( 'No logs found to export', 'lunexia-smtp-pro' ) );
		}

		$filename = 'lunexia-smtp-logs-' . gmdate( 'Y-m-d-H-i-s' );

		if ( 'csv' === $format ) {
			$this->export_csv( $logs, $filename );
		} else {
			$this->export_excel( $logs, $filename );
		}
	}

	public function ajax_clear_logs() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		global $wpdb;
		$database   = new Lunexia_SMTP_Database();
		$table_name = $database->get_table_name();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_send_json_success(
			array(
				'message' => esc_html__( 'All logs cleared successfully', 'lunexia-smtp-pro' ),
			)
		);
	}

	private function export_csv( $logs, $filename ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'ID', 'Recipient', 'Subject', 'Status', 'Error', 'Sent At' ) );

		foreach ( $logs as $log ) {
			fputcsv(
				$output,
				array(
					$log->id,
					$log->to_email,
					$log->subject,
					$log->status,
					$log->error_message,
					$log->created_at,
				)
			);
		}

		fclose( $output );
		exit;
	}

	private function export_excel( $logs, $filename ) {
		header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) . '.xls' );

		echo '<table border="1">';
		echo '<tr><th>ID</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Error</th><th>Sent At</th></tr>';

		foreach ( $logs as $log ) {
			echo '<tr>';
			echo '<td>' . esc_html( $log->id ) . '</td>';
			echo '<td>' . esc_html( $log->to_email ) . '</td>';
			echo '<td>' . esc_html( $log->subject ) . '</td>';
			echo '<td>' . esc_html( $log->status ) . '</td>';
			echo '<td>' . esc_html( $log->error_message ) . '</td>';
			echo '<td>' . esc_html( $log->created_at ) . '</td>';
			echo '</tr>';
		}

		echo '</table>';
		exit;
	}

	/**
	 * Display admin notices.
	 */
	public function display_notices() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return;
		}

		if ( isset( $_GET['action'] ) && 'logout' === $_GET['action'] ) {
			return;
		}

		$this->display_review_notice();
		$this->display_donate_notice();
	}

	/**
	 * Display review notice.
	 */
	private function display_review_notice() {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'lunexia_smtp_review_notice_dismissed', true );

		if ( $dismissed ) {
			if ( 'permanent' === $dismissed ) {
				return;
			}
			$dismiss_time = (int) $dismissed;
			$current_time = time();

			if ( $current_time < $dismiss_time + ( 7 * 24 * 60 * 60 ) ) {
				return;
			}
		}
		?>
		<div class="notice notice-info lite-smtp-notice-box lite-smtp-review-notice" data-notice-type="review">
			<div class="lite-smtp-notice-header">
				<div class="lite-smtp-notice-title">
					<strong><?php esc_html_e( '⭐ Enjoying Lunexia SMTP Pro?', 'lunexia-smtp-pro' ); ?></strong>
					<p><?php esc_html_e( 'We would love to hear your feedback! Leaving a review helps us keep improving the plugin.', 'lunexia-smtp-pro' ); ?></p>
				</div>
				<button type="button" class="lite-smtp-notice-close" data-notice-type="review" aria-label="<?php esc_attr_e( 'Dismiss notice', 'lunexia-smtp-pro' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<div class="lite-smtp-notice-actions">
				<a href="https://lunexiait.com" target="_blank" rel="noopener noreferrer" class="button button-primary lite-smtp-notice-btn" data-action="review_now">
					<?php esc_html_e( '⭐ Leave a Review', 'lunexia-smtp-pro' ); ?>
				</a>
				<button type="button" class="button lite-smtp-notice-btn" data-action="review_later">
					<?php esc_html_e( 'Maybe Later', 'lunexia-smtp-pro' ); ?>
				</button>
				<button type="button" class="button lite-smtp-notice-btn" data-action="review_done">
					<?php esc_html_e( 'Already Done', 'lunexia-smtp-pro' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Display donate notice.
	 */
	private function display_donate_notice() {
		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, 'lunexia_smtp_donate_notice_dismissed', true );

		if ( $dismissed ) {
			if ( 'permanent' === $dismissed ) {
				return;
			}
			$dismiss_time = (int) $dismissed;
			$current_time = time();

			if ( $current_time < $dismiss_time + ( 7 * 24 * 60 * 60 ) ) {
				return;
			}
		}
		?>
		<div class="notice notice-warning lite-smtp-notice-box lite-smtp-donate-notice" data-notice-type="donate">
			<div class="lite-smtp-notice-header">
				<div class="lite-smtp-notice-title">
					<strong><?php esc_html_e( '☕ Support Our Development', 'lunexia-smtp-pro' ); ?></strong>
					<p><?php esc_html_e( 'If you find this plugin valuable, consider supporting our open-source development.', 'lunexia-smtp-pro' ); ?></p>
				</div>
				<button type="button" class="lite-smtp-notice-close" data-notice-type="donate" aria-label="<?php esc_attr_e( 'Dismiss notice', 'lunexia-smtp-pro' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<div class="lite-smtp-notice-actions">
				<a href="https://lunexiait.com" target="_blank" rel="noopener noreferrer" class="button button-primary lite-smtp-notice-btn" data-action="donate_now">
					<?php esc_html_e( '❤️ Support Us', 'lunexia-smtp-pro' ); ?>
				</a>
				<button type="button" class="button lite-smtp-notice-btn" data-action="donate_not_interested">
					<?php esc_html_e( 'Not Interested', 'lunexia-smtp-pro' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the notice dismiss AJAX.
	 */
	public function handle_dismiss_notice() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_notice' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_notice' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$notice_type = isset( $_POST['notice_type'] ) ? sanitize_key( wp_unslash( $_POST['notice_type'] ) ) : '';

		if ( ! $notice_type ) {
			wp_send_json_error( esc_html__( 'Invalid notice type', 'lunexia-smtp-pro' ) );
		}

		$user_id  = get_current_user_id();
		$meta_key = 'lunexia_smtp_' . $notice_type . '_notice_dismissed';

		update_user_meta( $user_id, $meta_key, time() );

		wp_send_json_success( esc_html__( 'Notice dismissed', 'lunexia-smtp-pro' ) );
	}

	/**
	 * Handle the notice action AJAX.
	 */
	public function handle_notice_action() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_notice' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_notice' ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'lunexia-smtp-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Unauthorized access.', 'lunexia-smtp-pro' ) );
		}

		$action      = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		$notice_type = isset( $_POST['notice_type'] ) ? sanitize_key( wp_unslash( $_POST['notice_type'] ) ) : '';
		$user_id     = get_current_user_id();

		$redirect_url = '';

		switch ( $action ) {
			case 'review_now':
				$redirect_url = 'https://lunexiait.com';
				update_user_meta( $user_id, 'lunexia_smtp_review_notice_dismissed', time() );
				break;

			case 'review_later':
				update_user_meta( $user_id, 'lunexia_smtp_review_notice_dismissed', time() );
				break;

			case 'review_done':
				update_user_meta( $user_id, 'lunexia_smtp_review_notice_dismissed', 'permanent' );
				break;

			case 'donate_now':
				$redirect_url = 'https://lunexiait.com';
				update_user_meta( $user_id, 'lunexia_smtp_donate_notice_dismissed', time() );
				break;

			case 'donate_not_interested':
				update_user_meta( $user_id, 'lunexia_smtp_donate_notice_dismissed', 'permanent' );
				break;
		}

		wp_send_json_success(
			array(
				'redirect_url' => esc_url_raw( $redirect_url ),
			)
		);
	}

	public function clear_temporary_dismissals( $user_login, $user ) {
		$review_dismissed = get_user_meta( $user->ID, 'lunexia_smtp_review_notice_dismissed', true );
		if ( 'temporary' === $review_dismissed ) {
			delete_user_meta( $user->ID, 'lunexia_smtp_review_notice_dismissed' );
		}

		$donate_dismissed = get_user_meta( $user->ID, 'lunexia_smtp_donate_notice_dismissed', true );
		if ( 'temporary' === $donate_dismissed ) {
			delete_user_meta( $user->ID, 'lunexia_smtp_donate_notice_dismissed' );
		}
	}

	public function render_settings_page() {
		render_lunexia_smtp_settings_page();
	}
}

/**
 * Settings Page Template for Lunexia SMTP Pro
 */
function render_lunexia_smtp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Check for OAuth success
	if ( isset( $_GET['oauth'] ) && 'success' === $_GET['oauth'] ) {
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'lunexia-smtp-pro' ) . '</strong> ' . esc_html__( 'You have been successfully authenticated with Google. Your Gmail account is now connected.', 'lunexia-smtp-pro' ) . '</p></div>';
	}

	$settings_obj     = new Lunexia_SMTP_Settings();
	$settings         = $settings_obj->get_settings();
	$oauth            = new Lunexia_SMTP_GmailOAuth2();
	$is_authenticated = $oauth->is_authenticated();

	$client_id     = $settings['gmail_client_id'] ?? '';
	$client_secret = $settings['gmail_client_secret'] ?? '';
	$smtp_host     = $settings['smtp_host'] ?? '';
	$smtp_port     = $settings['smtp_port'] ?? 587;
	$smtp_username = $settings['smtp_username'] ?? '';
	$smtp_password = $settings['smtp_password'] ?? '';
	$from_email    = $settings['from_email'] ?? '';
	$from_name     = $settings['from_name'] ?? get_bloginfo( 'name' );

	$is_smtp_configured = ! empty( $smtp_host ) && ! empty( $smtp_username ) && ! empty( $smtp_password );
	$is_smtp_active     = $is_authenticated || $is_smtp_configured;
	$smtp_encryption    = $settings['smtp_encryption'] ?? 'tls';
	$site_key           = $settings['site_key'] ?? '';
	$secret_key         = $settings['secret_key'] ?? '';

	$database = new Lunexia_SMTP_Database();

	global $wpdb;
	$table_name   = $database->get_table_name();
	$today_start  = gmdate( 'Y-m-d 00:00:00' );
	$today_end    = gmdate( 'Y-m-d 23:59:59' );

	$sent_today   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE status = %s AND created_at BETWEEN %s AND %s", 'sent', $today_start, $today_end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total_logs   = $database->get_logs_count();
	$sent_logs    = $database->get_logs_count( 'sent' );
	$failed_count = $database->get_logs_count( 'failed' );

	$total_sent_today    = $sent_today;
	$total_sent_all_time = $sent_logs;
	$success_rate        = $total_logs > 0 ? round( ( $sent_logs / $total_logs ) * 100, 1 ) : 0;
	?>
	<div class="lite-smtp-settings-wrap">
		<!-- Sidebar -->
		<aside class="lite-smtp-sidebar">
			<div class="lite-smtp-logo">
				<div class="lite-smtp-logo-icon">L</div>
				<div class="lite-smtp-logo-text"><?php esc_html_e( 'Lunexia SMTP Pro', 'lunexia-smtp-pro' ); ?></div>
			</div>

			<nav class="lite-smtp-nav">
				<a href="#general" class="lite-smtp-nav-item active" data-section="general">
					<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'General Settings', 'lunexia-smtp-pro' ); ?>
				</a>
				<a href="#connection" class="lite-smtp-nav-item" data-section="connection">
					<span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Connection', 'lunexia-smtp-pro' ); ?>
				</a>
				<a href="#test-email" class="lite-smtp-nav-item" data-section="test-email">
					<span class="dashicons dashicons-testimonial"></span> <?php esc_html_e( 'Test Email', 'lunexia-smtp-pro' ); ?>
				</a>
				<a href="#logs" class="lite-smtp-nav-item" data-section="logs">
					<span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Email Logs', 'lunexia-smtp-pro' ); ?>
				</a>
				<a href="#security" class="lite-smtp-nav-item" data-section="security">
					<span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Security', 'lunexia-smtp-pro' ); ?>
				</a>
			</nav>

			<div style="margin-top: auto; padding-top: 20px;">
				<a href="https://lunexiait.com" target="_blank" rel="noopener noreferrer" class="lite-smtp-nav-item" style="text-decoration: none;">
					<span class="dashicons dashicons-editor-help"></span> <?php esc_html_e( 'Documentation', 'lunexia-smtp-pro' ); ?>
				</a>
			</div>
		</aside>

		<!-- Main Content -->
		<main class="lite-smtp-main">
			<!-- General Settings Section -->
			<section id="general" class="lite-smtp-section active">
				<header class="lite-smtp-header">
					<div>
						<h1><?php esc_html_e( 'General Settings', 'lunexia-smtp-pro' ); ?></h1>
						<p><?php esc_html_e( 'Configure your email delivery preferences.', 'lunexia-smtp-pro' ); ?></p>
					</div>
					<div class="lite-smtp-overall-status <?php echo $is_smtp_active ? 'active' : 'inactive'; ?>">
						<div class="lite-smtp-status-light"></div>
						<span>
							<?php
							echo esc_html(
								$is_smtp_active
									? __( 'Lunexia SMTP is active', 'lunexia-smtp-pro' )
									: __( 'Lunexia SMTP is not active', 'lunexia-smtp-pro' )
							);
							?>
						</span>
					</div>
				</header>

				<!-- Authentication Status Card -->
				<div class="lite-smtp-notice-card" id="auth-status-card">
					<div class="lite-smtp-notice-content">
						<h4>
							<?php
							echo esc_html(
								$is_authenticated
									? __( 'Gmail Connected', 'lunexia-smtp-pro' )
									: __( 'Gmail Not Connected', 'lunexia-smtp-pro' )
							);
							?>
						</h4>
						<p>
							<?php
							echo esc_html(
								$is_authenticated
									? __( 'Your Gmail account is successfully connected and ready to send emails.', 'lunexia-smtp-pro' )
									: __( 'Connect your Gmail account to start sending emails securely through OAuth 2.0.', 'lunexia-smtp-pro' )
							);
							?>
						</p>
					</div>
					<div class="lite-smtp-auth-pill <?php echo $is_authenticated ? 'authenticated' : 'not-authenticated'; ?>">
						<div class="lite-smtp-auth-glow"></div>
						<span>
							<?php
							echo esc_html(
								$is_authenticated
									? __( '✓ Connected', 'lunexia-smtp-pro' )
									: __( '⚠ Not Connected', 'lunexia-smtp-pro' )
							);
							?>
						</span>
					</div>
				</div>

				<!-- Custom SMTP Status Card -->
				<div class="lite-smtp-notice-card" id="smtp-status-card">
					<div class="lite-smtp-notice-content">
						<h4>
							<?php
							echo esc_html(
								$is_smtp_configured
									? __( 'Custom SMTP Configured', 'lunexia-smtp-pro' )
									: __( 'Custom SMTP Not Configured', 'lunexia-smtp-pro' )
							);
							?>
						</h4>
						<p>
							<?php
							echo esc_html(
								$is_smtp_configured
									? __( 'Your custom SMTP settings are configured and ready to send emails.', 'lunexia-smtp-pro' )
									: __( 'Configure your custom SMTP settings to start sending emails.', 'lunexia-smtp-pro' )
							);
							?>
						</p>
					</div>
					<div class="lite-smtp-auth-pill <?php echo $is_smtp_configured ? 'authenticated' : 'not-authenticated'; ?>">
						<div class="lite-smtp-auth-glow"></div>
						<span>
							<?php
							echo esc_html(
								$is_smtp_configured
									? __( '✓ Configured', 'lunexia-smtp-pro' )
									: __( '⚠ Not Configured', 'lunexia-smtp-pro' )
							);
							?>
						</span>
					</div>
				</div>

				<div class="lite-smtp-card">
					<h3 class="lite-smtp-card-title"><?php esc_html_e( 'Email Preferences', 'lunexia-smtp-pro' ); ?></h3>
					<div class="lite-smtp-form-group">
						<label>
							<input type="checkbox" id="enable_lite_smtp" <?php checked( $settings['enable_lite_smtp'] ?? true ); ?>>
							<?php esc_html_e( 'Send emails through Lunexia SMTP Pro', 'lunexia-smtp-pro' ); ?>
						</label>
					</div>
					<div class="lite-smtp-form-group">
						<label>
							<input type="checkbox" id="enable_logging" <?php checked( $settings['enable_logging'] ?? true ); ?>>
							<?php esc_html_e( 'Enable email logging', 'lunexia-smtp-pro' ); ?>
						</label>
					</div>
					<div class="lite-smtp-form-group">
						<label>
							<input type="checkbox" id="delivery_notifications" <?php checked( $settings['delivery_notifications'] ?? false ); ?>>
							<?php esc_html_e( 'Send delivery notifications', 'lunexia-smtp-pro' ); ?>
						</label>
					</div>
					<button type="button" class="lite-smtp-btn-primary" id="save-preferences-btn">
						<?php esc_html_e( 'Save Preferences', 'lunexia-smtp-pro' ); ?>
					</button>
				</div>

				<!-- Quick Stats -->
				<div class="lite-smtp-card">
					<h3 class="lite-smtp-card-title"><?php esc_html_e( 'Quick Stats', 'lunexia-smtp-pro' ); ?></h3>
					<div class="lite-smtp-stats-grid">
						<div class="lite-smtp-stat-item">
							<div class="lite-smtp-stat-number" id="sent-today-count"><?php echo esc_html( number_format_i18n( $total_sent_today ) ); ?></div>
							<div class="lite-smtp-stat-label"><?php esc_html_e( 'Sent Today', 'lunexia-smtp-pro' ); ?></div>
						</div>
						<div class="lite-smtp-stat-item">
							<div class="lite-smtp-stat-number"><?php echo esc_html( number_format_i18n( $total_sent_all_time ) ); ?></div>
							<div class="lite-smtp-stat-label"><?php esc_html_e( 'Sent All Time', 'lunexia-smtp-pro' ); ?></div>
						</div>
						<div class="lite-smtp-stat-item">
							<div class="lite-smtp-stat-number"><?php echo esc_html( number_format_i18n( $failed_count ) ); ?></div>
							<div class="lite-smtp-stat-label"><?php esc_html_e( 'Failed Emails', 'lunexia-smtp-pro' ); ?></div>
						</div>
						<div class="lite-smtp-stat-item">
							<div class="lite-smtp-stat-number" id="success-rate"><?php echo esc_html( number_format_i18n( $success_rate, 1 ) ); ?>%</div>
							<div class="lite-smtp-stat-label"><?php esc_html_e( 'Success Rate', 'lunexia-smtp-pro' ); ?></div>
						</div>
					</div>
				</div>
			</section>

			<!-- Connection Section -->
			<section id="connection" class="lite-smtp-section">
				<header class="lite-smtp-header">
					<div>
						<h1><?php esc_html_e( 'Connection Settings', 'lunexia-smtp-pro' ); ?></h1>
						<p><?php esc_html_e( 'Configure Gmail OAuth2 and Custom SMTP.', 'lunexia-smtp-pro' ); ?></p>
					</div>
				</header>

				<div class="lite-smtp-card">
					<h3 class="lite-smtp-card-title"><?php esc_html_e( 'Email Provider', 'lunexia-smtp-pro' ); ?></h3>

					<div class="lite-smtp-tabs">
						<div class="lite-smtp-tab active" data-tab="gmail"><?php esc_html_e( 'Gmail OAuth2', 'lunexia-smtp-pro' ); ?></div>
						<div class="lite-smtp-tab" data-tab="smtp"><?php esc_html_e( 'Custom SMTP', 'lunexia-smtp-pro' ); ?></div>
					</div>

					<!-- Gmail Settings -->
					<div id="lite-smtp-gmail-settings" class="lite-smtp-tab-content active">
						<div class="lite-smtp-form-group">
							<label><?php esc_html_e( 'Authentication Status', 'lunexia-smtp-pro' ); ?></label>
							<div class="lite-smtp-auth-status <?php echo $is_authenticated ? 'authenticated' : 'not-authenticated'; ?>">
								<div class="lite-smtp-auth-glow"></div>
								<div class="lite-smtp-auth-content">
									<strong>
										<?php
										echo esc_html(
											$is_authenticated
												? __( '✓ Connected to Gmail', 'lunexia-smtp-pro' )
												: __( '⚠ Not Connected', 'lunexia-smtp-pro' )
										);
										?>
									</strong>
									<p>
										<?php
										echo esc_html(
											$is_authenticated
												? __( 'Your Gmail account is successfully connected and ready to send emails.', 'lunexia-smtp-pro' )
												: __( 'Please connect your Gmail account to start sending emails through OAuth2.', 'lunexia-smtp-pro' )
										);
										?>
									</p>
								</div>
							</div>
						</div>

						<div class="lite-smtp-form-group">
							<label><?php esc_html_e( 'Authorized Redirect URI', 'lunexia-smtp-pro' ); ?></label>
							<div class="lite-smtp-key-display">
								<span><?php echo esc_html( admin_url( 'options-general.php?page=lunexia-smtp-pro' ) ); ?></span>
								<span class="lite-smtp-link copy-btn"><?php esc_html_e( 'Copy', 'lunexia-smtp-pro' ); ?></span>
							</div>
						</div>

						<div class="lite-smtp-form-group">
							<label for="gmail_client_id"><?php esc_html_e( 'Client ID', 'lunexia-smtp-pro' ); ?></label>
							<input type="text" class="lite-smtp-input" id="gmail_client_id" value="<?php echo esc_attr( $client_id ); ?>" placeholder="<?php esc_attr_e( 'Your Gmail Client ID', 'lunexia-smtp-pro' ); ?>">
						</div>

						<div class="lite-smtp-form-group">
							<label for="gmail_client_secret"><?php esc_html_e( 'Client Secret', 'lunexia-smtp-pro' ); ?></label>
							<input type="password" class="lite-smtp-input" id="gmail_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" placeholder="<?php esc_attr_e( 'Your Gmail Client Secret', 'lunexia-smtp-pro' ); ?>">
						</div>

						<div style="margin-top: 32px; display: flex; gap: 12px;">
							<button type="button" class="lite-smtp-btn-primary" id="authenticate-google-btn">
								<?php
								echo esc_html(
									$is_authenticated
										? __( 'Re-authenticate', 'lunexia-smtp-pro' )
										: __( 'Authenticate', 'lunexia-smtp-pro' )
								);
								?>
							</button>
							<?php if ( $is_authenticated ) : ?>
								<button type="button" class="lite-smtp-btn-secondary" id="disconnect-google-btn">
									<?php esc_html_e( 'Disconnect from Google', 'lunexia-smtp-pro' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>

					<!-- SMTP Settings -->
					<div id="lite-smtp-smtp-settings" class="lite-smtp-tab-content">
						<div class="lite-smtp-form-group">
							<label for="smtp_host"><?php esc_html_e( 'SMTP Host', 'lunexia-smtp-pro' ); ?></label>
							<input type="text" class="lite-smtp-input" id="smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" placeholder="smtp.example.com">
						</div>

						<div class="lite-smtp-form-group">
							<label for="smtp_port"><?php esc_html_e( 'SMTP Port', 'lunexia-smtp-pro' ); ?></label>
							<input type="number" class="lite-smtp-input" id="smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" placeholder="587">
						</div>

						<div class="lite-smtp-form-group">
							<label for="smtp_username"><?php esc_html_e( 'Username', 'lunexia-smtp-pro' ); ?></label>
							<input type="email" class="lite-smtp-input" id="smtp_username" value="<?php echo esc_attr( $smtp_username ); ?>" placeholder="your-email@example.com">
						</div>

						<div class="lite-smtp-form-group">
							<label for="smtp_password"><?php esc_html_e( 'Password', 'lunexia-smtp-pro' ); ?></label>
							<input type="password" class="lite-smtp-input" id="smtp_password" value="<?php echo esc_attr( $smtp_password ); ?>" placeholder="<?php esc_attr_e( 'Enter your SMTP password', 'lunexia-smtp-pro' ); ?>">
						</div>

						<div class="lite-smtp-form-group">
							<label for="from_email"><?php esc_html_e( 'From Email', 'lunexia-smtp-pro' ); ?></label>
							<input type="email" class="lite-smtp-input" id="from_email" value="<?php echo esc_attr( $from_email ); ?>" placeholder="noreply@example.com">
							<p class="description"><?php esc_html_e( 'Use this email as the sender address for outgoing messages.', 'lunexia-smtp-pro' ); ?></p>
						</div>

						<div class="lite-smtp-form-group">
							<label for="from_name"><?php esc_html_e( 'From Name', 'lunexia-smtp-pro' ); ?></label>
							<input type="text" class="lite-smtp-input" id="from_name" value="<?php echo esc_attr( $from_name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						</div>

						<div class="lite-smtp-form-group">
							<label for="smtp_encryption"><?php esc_html_e( 'Encryption', 'lunexia-smtp-pro' ); ?></label>
							<select class="lite-smtp-input" id="smtp_encryption">
								<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>>TLS</option>
								<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>>SSL</option>
								<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'lunexia-smtp-pro' ); ?></option>
							</select>
						</div>

						<div style="margin-top: 32px;">
							<button type="button" class="lite-smtp-btn-primary" id="save-smtp-btn">
								<?php esc_html_e( 'Save SMTP Settings', 'lunexia-smtp-pro' ); ?>
							</button>
						</div>
					</div>
				</div>
			</section>

			<!-- Test Email Section -->
			<section id="test-email" class="lite-smtp-section">
				<header class="lite-smtp-header">
					<div>
						<h1><?php esc_html_e( 'Test Email', 'lunexia-smtp-pro' ); ?></h1>
						<p><?php esc_html_e( 'Send test emails to verify your configuration.', 'lunexia-smtp-pro' ); ?></p>
					</div>
				</header>

				<div class="lite-smtp-card">
					<h3 class="lite-smtp-card-title"><?php esc_html_e( 'Send Test Email', 'lunexia-smtp-pro' ); ?></h3>
					<div class="lite-smtp-form-group">
						<label for="test-email-address"><?php esc_html_e( 'Test Email Address', 'lunexia-smtp-pro' ); ?></label>
						<input type="email" id="test-email-address" class="lite-smtp-input" placeholder="recipient@example.com" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<small style="color: #64748B; margin-top: 8px; display: block;"><?php esc_html_e( 'Enter the email address where you want to receive the test email.', 'lunexia-smtp-pro' ); ?></small>
					</div>
					<div class="lite-smtp-form-group">
						<label for="test-email-subject"><?php esc_html_e( 'Test Email Subject', 'lunexia-smtp-pro' ); ?></label>
						<input type="text" id="test-email-subject" class="lite-smtp-input" value="<?php esc_attr_e( 'Lunexia SMTP Test Notification', 'lunexia-smtp-pro' ); ?>">
					</div>
					<div class="lite-smtp-form-group">
						<label for="test-email-message"><?php esc_html_e( 'Test Email Message', 'lunexia-smtp-pro' ); ?></label>
						<textarea id="test-email-message" class="lite-smtp-input" rows="4"><?php
							echo esc_textarea(
								__( 'Confirm your SMTP setup by reviewing this transactional test email and validating receipt.', 'lunexia-smtp-pro' ) . "\n\n" .
								__( 'Sent at: ', 'lunexia-smtp-pro' ) . date_i18n( 'F j, Y \a\t g:i A', current_time( 'timestamp' ) ) . "\n" .
								__( 'WordPress Site: ', 'lunexia-smtp-pro' ) . get_bloginfo( 'name' ) . "\n" .
								__( 'Site URL: ', 'lunexia-smtp-pro' ) . home_url()
							);
						?></textarea>
					</div>
					<button type="button" id="send-test-email-btn" class="lite-smtp-btn-primary">
						<?php esc_html_e( 'Send Test Email', 'lunexia-smtp-pro' ); ?>
					</button>
					<div id="test-email-response" style="margin-top: 16px; padding: 12px; border-radius: 8px; font-size: 13px; display: none;"></div>
				</div>
			</section>

			<!-- Email Logs Section -->
			<section id="logs" class="lite-smtp-section">
				<header class="lite-smtp-header">
					<div>
						<h1><?php esc_html_e( 'Email Logs', 'lunexia-smtp-pro' ); ?></h1>
						<p><?php esc_html_e( 'View and manage sent emails.', 'lunexia-smtp-pro' ); ?></p>
					</div>
				</header>

				<div class="lite-smtp-card">
					<div class="lite-smtp-logs-header">
						<h3 class="lite-smtp-card-title"><?php esc_html_e( 'Recent Email Logs', 'lunexia-smtp-pro' ); ?></h3>
						<div class="lite-smtp-log-actions">
							<button type="button" class="lite-smtp-btn-secondary lite-smtp-btn-small" id="export-csv-btn"><?php esc_html_e( 'CSV', 'lunexia-smtp-pro' ); ?></button>
							<button type="button" class="lite-smtp-btn-secondary lite-smtp-btn-small" id="export-excel-btn"><?php esc_html_e( 'Excel', 'lunexia-smtp-pro' ); ?></button>
						</div>
					</div>
					<table class="lite-smtp-logs-table" style="width: 100%; border-collapse: collapse;">
						<thead>
							<tr style="border-bottom: 1px solid var(--lite-smtp-border);">
								<th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e( 'Recipient', 'lunexia-smtp-pro' ); ?></th>
								<th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e( 'Subject', 'lunexia-smtp-pro' ); ?></th>
								<th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e( 'Status', 'lunexia-smtp-pro' ); ?></th>
								<th style="padding: 12px; text-align: left; font-weight: 600;"><?php esc_html_e( 'Date', 'lunexia-smtp-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="logs-tbody">
							<tr>
								<td colspan="4" style="padding: 20px; text-align: center;"><?php esc_html_e( 'Loading logs...', 'lunexia-smtp-pro' ); ?></td>
							</tr>
						</tbody>
					</table>
					<div class="lite-smtp-logs-footer">
						<button type="button" class="lite-smtp-btn-danger lite-smtp-btn-small" id="clear-logs-btn">
							<?php esc_html_e( 'Clear All Logs', 'lunexia-smtp-pro' ); ?>
						</button>
					</div>
				</div>
			</section>

			<!-- Security Section -->
			<section id="security" class="lite-smtp-section">
				<header class="lite-smtp-header">
					<div>
						<h1><?php esc_html_e( 'Security Settings', 'lunexia-smtp-pro' ); ?></h1>
						<p><?php esc_html_e( 'Manage API keys and security options.', 'lunexia-smtp-pro' ); ?></p>
					</div>
				</header>

				<div class="lite-smtp-card">
					<h3 class="lite-smtp-card-title"><?php esc_html_e( 'API Keys', 'lunexia-smtp-pro' ); ?></h3>
					<div class="lite-smtp-form-group">
						<label><?php esc_html_e( 'Site Key', 'lunexia-smtp-pro' ); ?></label>
						<div class="lite-smtp-key-display">
							<span id="site-key" style="word-break: break-all;"><?php echo esc_html( $site_key ); ?></span>
							<span class="lite-smtp-link copy-btn"><?php esc_html_e( 'Copy', 'lunexia-smtp-pro' ); ?></span>
						</div>
					</div>

					<div class="lite-smtp-form-group">
						<label><?php esc_html_e( 'Secret Key', 'lunexia-smtp-pro' ); ?></label>
						<div class="lite-smtp-key-display">
							<span id="secret-key" style="word-break: break-all;"><?php echo esc_html( $secret_key ); ?></span>
							<span class="lite-smtp-link copy-btn"><?php esc_html_e( 'Copy', 'lunexia-smtp-pro' ); ?></span>
						</div>
					</div>

					<button type="button" class="lite-smtp-btn-secondary" id="regenerate-keys-btn">
						<?php esc_html_e( 'Regenerate Keys', 'lunexia-smtp-pro' ); ?>
					</button>
				</div>
			</section>
		</main>
	</div>
	<?php
}

// Backwards compatibility alias
if ( ! class_exists( 'Lite_SMTP_Settings' ) ) {
	class_alias( 'Lunexia_SMTP_Settings', 'Lite_SMTP_Settings' );
}
