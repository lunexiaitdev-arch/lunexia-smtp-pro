<?php
/**
 * Gmail OAuth2 authentication class
 *
 * @package Lunexia_SMTP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunexia_SMTP_GmailOAuth2 {

	private $client_id;
	private $client_secret;
	private $redirect_uri;
	private $oauth_endpoint     = 'https://oauth2.googleapis.com/token';
	private $authorize_endpoint = 'https://accounts.google.com/o/oauth2/v2/auth';
	private $gmail_api_endpoint = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	public function __construct() {
		$this->setup_hooks();
		$this->load_credentials();
	}

	/**
	 * Retrieve plugin settings helper.
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = get_option( 'lunexia_smtp_settings', null );
		if ( null === $settings ) {
			$settings = get_option( 'lite_smtp_settings', array() );
		}
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Update plugin settings helper.
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	private function update_settings( $settings ) {
		return update_option( 'lunexia_smtp_settings', $settings );
	}

	/**
	 * Load OAuth credentials from WordPress options
	 */
	private function load_credentials() {
		$settings = $this->get_settings();

		$this->client_id     = isset( $settings['gmail_client_id'] ) ? sanitize_text_field( $settings['gmail_client_id'] ) : '';
		$this->client_secret = isset( $settings['gmail_client_secret'] ) ? sanitize_text_field( $settings['gmail_client_secret'] ) : '';
		$this->redirect_uri  = $this->get_redirect_uri();
	}

	/**
	 * Setup WordPress hooks
	 */
	private function setup_hooks() {
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'wp_ajax_lunexia_smtp_generate_auth_url', array( $this, 'ajax_generate_auth_url' ) );
		add_action( 'wp_ajax_lunexia_smtp_disconnect', array( $this, 'ajax_disconnect' ) );

		// Backwards compatibility AJAX hooks
		add_action( 'wp_ajax_lite_smtp_generate_auth_url', array( $this, 'ajax_generate_auth_url' ) );
		add_action( 'wp_ajax_lite_smtp_disconnect', array( $this, 'ajax_disconnect' ) );
	}

	/**
	 * Get the redirect URI for OAuth
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return admin_url( 'options-general.php?page=lunexia-smtp-pro' );
	}

	/**
	 * Generate the authorization URL
	 *
	 * @return string|false
	 */
	public function generate_auth_url() {
		if ( ! $this->client_id || ! $this->client_secret ) {
			return false;
		}

		$params = array(
			'client_id'     => $this->client_id,
			'redirect_uri'  => $this->redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'lunexia_smtp_oauth_state' ),
		);

		return add_query_arg( $params, $this->authorize_endpoint );
	}

	/**
	 * AJAX handler for generating auth URL
	 */
	public function ajax_generate_auth_url() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please refresh the page.', 'lunexia-smtp-pro' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have sufficient permissions to perform this action.', 'lunexia-smtp-pro' ) ) );
		}

		$this->load_credentials();
		$auth_url = $this->generate_auth_url();

		if ( ! $auth_url ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Please enter your Gmail Client ID and Client Secret in the settings above and save the settings before connecting.', 'lunexia-smtp-pro' ) ) );
		}

		wp_send_json_success( array( 'auth_url' => esc_url_raw( $auth_url ) ) );
	}

	/**
	 * Handle OAuth callback from Google
	 */
	public function handle_oauth_callback() {
		// Check if we're on the oauth callback page
		if ( ! isset( $_GET['page'] ) || 'lunexia-smtp-pro' !== $_GET['page'] ) {
			return;
		}

		// Only handle when OAuth return params are present
		if ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) {
			return;
		}

		// Verify user capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage SMTP options.', 'lunexia-smtp-pro' ) );
		}

		// Verify state nonce for CSRF protection
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( empty( $state ) || ! wp_verify_nonce( $state, 'lunexia_smtp_oauth_state' ) ) {
			wp_die( esc_html__( 'Invalid OAuth state or session expired. Please return to the settings page and try connecting again.', 'lunexia-smtp-pro' ) );
		}

		// Handle error responses from Google
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			wp_die( esc_html__( 'OAuth Error: ', 'lunexia-smtp-pro' ) . esc_html( $error ) );
		}

		// Exchange code for tokens
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$this->exchange_code_for_tokens( $code );
	}

	/**
	 * Exchange authorization code for access and refresh tokens
	 *
	 * @param string $code Authorization code from Google.
	 */
	private function exchange_code_for_tokens( $code ) {
		$body = array(
			'code'          => $code,
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri'  => $this->redirect_uri,
			'grant_type'    => 'authorization_code',
		);

		$response = wp_remote_post(
			$this->oauth_endpoint,
			array(
				'body'      => $body,
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html__( 'Error connecting to OAuth server: ', 'lunexia-smtp-pro' ) . esc_html( $response->get_error_message() ) );
		}

		$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $tokens['access_token'] ) ) {
			$this->store_tokens( $tokens );
			$this->store_user_email( sanitize_text_field( $tokens['access_token'] ) );

			// Redirect back to settings with success message
			wp_safe_redirect( admin_url( 'options-general.php?page=lunexia-smtp-pro&oauth=success' ) );
			exit;
		} else {
			$error = isset( $tokens['error_description'] ) ? $tokens['error_description'] : ( $tokens['error'] ?? esc_html__( 'Unknown error', 'lunexia-smtp-pro' ) );
			wp_die( esc_html__( 'OAuth Error: ', 'lunexia-smtp-pro' ) . esc_html( $error ) );
		}
	}

	/**
	 * Store tokens in WordPress options
	 *
	 * @param array $tokens Token data from Google.
	 */
	private function store_tokens( $tokens ) {
		$stored_tokens = array(
			'access_token'  => isset( $tokens['access_token'] ) ? sanitize_text_field( $tokens['access_token'] ) : '',
			'refresh_token' => isset( $tokens['refresh_token'] ) ? sanitize_text_field( $tokens['refresh_token'] ) : '',
			'expires_in'    => isset( $tokens['expires_in'] ) ? absint( $tokens['expires_in'] ) : 3600,
			'token_type'    => isset( $tokens['token_type'] ) ? sanitize_text_field( $tokens['token_type'] ) : 'Bearer',
			'created_at'    => time(),
		);

		update_option( 'lunexia_smtp_tokens', $stored_tokens );

		// Update settings to mark as authenticated
		$settings = $this->get_settings();
		$settings['google_authenticated']    = true;
		$settings['google_authenticated_at'] = current_time( 'mysql' );
		$this->update_settings( $settings );
	}

	/**
	 * Store the authenticated user's email
	 *
	 * @param string $access_token Access token.
	 */
	private function store_user_email( $access_token ) {
		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v1/userinfo?alt=json',
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$user_info = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $user_info['email'] ) && is_email( $user_info['email'] ) ) {
				update_option( 'lunexia_smtp_gmail_email', sanitize_email( $user_info['email'] ) );
			}
		}
	}

	/**
	 * Get stored tokens
	 *
	 * @return array
	 */
	public function get_tokens() {
		$tokens = get_option( 'lunexia_smtp_tokens', null );
		if ( null === $tokens ) {
			$tokens = get_option( 'lite_smtp_tokens', array() );
		}
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Get the authenticated Gmail email
	 *
	 * @return string
	 */
	public function get_gmail_email() {
		$email = get_option( 'lunexia_smtp_gmail_email', null );
		if ( null === $email ) {
			$email = get_option( 'lite_smtp_gmail_email', '' );
		}
		return is_string( $email ) ? $email : '';
	}

	/**
	 * Check if tokens are stored and valid
	 *
	 * @return bool
	 */
	public function is_authenticated() {
		$tokens = $this->get_tokens();
		return ! empty( $tokens['access_token'] );
	}

	/**
	 * Get a valid access token, refreshing if necessary
	 *
	 * @return string|false Access token or false if not authenticated
	 */
	public function get_valid_access_token() {
		$tokens = $this->get_tokens();

		if ( empty( $tokens['access_token'] ) ) {
			return false;
		}

		// Check if token is expired
		if ( isset( $tokens['created_at'], $tokens['expires_in'] ) ) {
			$expired_at = (int) $tokens['created_at'] + (int) $tokens['expires_in'] - 300; // Refresh 5 minutes before expiry
			if ( time() > $expired_at ) {
				// Refresh the token
				if ( $this->refresh_access_token() ) {
					$tokens = $this->get_tokens();
				}
			}
		}

		return $tokens['access_token'] ?? false;
	}

	/**
	 * Refresh the access token using the refresh token
	 *
	 * @return bool True if successful
	 */
	public function refresh_access_token() {
		$tokens = $this->get_tokens();

		if ( empty( $tokens['refresh_token'] ) ) {
			return false;
		}

		$body = array(
			'client_id'     => $this->client_id,
			'client_secret' => $this->client_secret,
			'refresh_token' => $tokens['refresh_token'],
			'grant_type'    => 'refresh_token',
		);

		$response = wp_remote_post(
			$this->oauth_endpoint,
			array(
				'body'      => $body,
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$new_tokens = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $new_tokens['access_token'] ) ) {
			$updated_tokens = array_merge(
				$tokens,
				array(
					'access_token' => sanitize_text_field( $new_tokens['access_token'] ),
					'expires_in'   => isset( $new_tokens['expires_in'] ) ? absint( $new_tokens['expires_in'] ) : 3600,
					'created_at'   => time(),
				)
			);

			if ( isset( $new_tokens['refresh_token'] ) ) {
				$updated_tokens['refresh_token'] = sanitize_text_field( $new_tokens['refresh_token'] );
			}

			$this->store_tokens( $updated_tokens );
			return true;
		}

		return false;
	}

	/**
	 * Send an email via Gmail API
	 *
	 * @param array $email_data Email payload.
	 * @return array|WP_Error Response from API or error
	 */
	public function send_via_gmail_api( $email_data ) {
		$access_token = $this->get_valid_access_token();

		if ( ! $access_token ) {
			return new WP_Error( 'auth_failed', esc_html__( 'Gmail authentication not configured or expired.', 'lunexia-smtp-pro' ) );
		}

		// Create MIME email
		$message = $this->create_mime_message( $email_data );

		// Encode message as base64url (RFC 4648 URL and filename safe encoding)
		$encoded_message = rtrim( strtr( base64_encode( $message ), '+/', '-_' ), '=' );

		$body = array(
			'raw' => $encoded_message,
		);

		$response = wp_remote_post(
			$this->gmail_api_endpoint,
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );

		if ( 200 === $http_code || 201 === $http_code ) {
			return array(
				'success'    => true,
				'message_id' => $result['id'] ?? '',
			);
		}

		$error_message = esc_html__( 'Unknown Gmail API error', 'lunexia-smtp-pro' );
		if ( is_array( $result ) && isset( $result['error'] ) ) {
			if ( is_array( $result['error'] ) && isset( $result['error']['message'] ) ) {
				$error_message = sanitize_text_field( $result['error']['message'] );
			} elseif ( is_string( $result['error'] ) ) {
				$error_message = sanitize_text_field( $result['error'] );
			}
		} elseif ( is_array( $result ) && isset( $result['error_description'] ) ) {
			$error_message = sanitize_text_field( $result['error_description'] );
		}

		return new WP_Error(
			'api_error',
			$error_message . ' (HTTP ' . absint( $http_code ) . ')'
		);
	}

	/**
	 * Create MIME formatted email message
	 *
	 * @param array $email_data Email data
	 * @return string MIME formatted message
	 */
	private function create_mime_message( $email_data ) {
		$from_email = sanitize_email( $email_data['from_email'] ?? '' );
		$to_email   = sanitize_text_field( $email_data['to'] ?? '' );
		$subject    = sanitize_text_field( $email_data['subject'] ?? '' );
		$body       = $email_data['body'] ?? '';

		$message  = 'From: ' . $from_email . "\r\n";
		$message .= 'To: ' . $to_email . "\r\n";
		$message .= 'Subject: =?UTF-8?B?' . base64_encode( $subject ) . "?=\r\n";
		$message .= "MIME-Version: 1.0\r\n";
		$message .= "Content-Type: text/html; charset=utf-8\r\n";
		$message .= "Content-Transfer-Encoding: base64\r\n\r\n";
		$message .= chunk_split( base64_encode( $body ) );

		return $message;
	}

	/**
	 * AJAX handler for disconnecting Gmail
	 */
	public function ajax_disconnect() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_nonce' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_nonce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed. Please refresh the page.', 'lunexia-smtp-pro' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have sufficient permissions to perform this action.', 'lunexia-smtp-pro' ) ) );
		}

		delete_option( 'lunexia_smtp_tokens' );
		delete_option( 'lunexia_smtp_gmail_email' );
		delete_option( 'lite_smtp_tokens' );
		delete_option( 'lite_smtp_gmail_email' );

		// Update settings to mark as not authenticated
		$settings = $this->get_settings();
		$settings['google_authenticated'] = false;
		unset( $settings['google_auth_code'], $settings['google_authenticated_at'] );
		$this->update_settings( $settings );

		wp_send_json_success( array( 'message' => esc_html__( 'Disconnected successfully from Google.', 'lunexia-smtp-pro' ) ) );
	}
}

// Backwards compatibility class alias
if ( ! class_exists( 'Lite_SMTP_GmailOAuth2' ) ) {
	class_alias( 'Lunexia_SMTP_GmailOAuth2', 'Lite_SMTP_GmailOAuth2' );
}
