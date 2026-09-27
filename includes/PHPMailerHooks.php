<?php
/**
 * PHPMailer integration for lunexia SMTP
 *
 * @package Lunexia_SMTP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunexia_SMTP_PHPMailerHooks {

	private $database;
	private $oauth;

	public function __construct() {
		$this->database = new Lunexia_SMTP_Database();
		$this->oauth    = new Lunexia_SMTP_GmailOAuth2();

		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks
	 */
	private function setup_hooks() {
		// Intercept wp_mail before WordPress sends it so Gmail API can replace it
		add_filter( 'pre_wp_mail', array( $this, 'handle_gmail_email' ), 10, 2 );
		add_filter( 'wp_mail_from', array( $this, 'filter_mail_from' ), 999, 1 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ), 999, 1 );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 10, 1 );

		add_action( 'wp_ajax_lunexia_smtp_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'wp_ajax_lite_smtp_test_email', array( $this, 'handle_test_email' ) );
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
	 * Determine the active mail provider.
	 *
	 * @param array|null $settings Plugin settings.
	 * @return string 'gmail' or 'other'.
	 */
	private function get_active_provider( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->get_settings();
		}

		if ( $this->is_gmail_available( $settings ) ) {
			return 'gmail';
		}

		if ( $this->is_smtp_available( $settings ) ) {
			return 'other';
		}

		return $settings['mail_provider'] ?? 'other';
	}

	/**
	 * Check if Gmail OAuth2 is ready to send.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	private function is_gmail_available( $settings ) {
		if ( ! $this->oauth->is_authenticated() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if standard SMTP is configured.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	private function is_smtp_available( $settings ) {
		$smtp_host     = sanitize_text_field( $settings['smtp_host'] ?? '' );
		$smtp_username = sanitize_email( $settings['smtp_username'] ?? '' );
		$smtp_password = sanitize_text_field( $settings['smtp_password'] ?? '' );

		return ! empty( $smtp_host ) && ! empty( $smtp_username ) && ! empty( $smtp_password );
	}

	/**
	 * Handle Gmail email sending before WordPress sends it.
	 * This filter is called during wp_mail before PHPMailer is configured.
	 *
	 * @param null|bool $pre  Pre-send value for wp_mail.
	 * @param array     $args Array containing email arguments.
	 * @return null|bool True if Gmail API sent the email, null to continue normal processing
	 */
	public function handle_gmail_email( $pre, $args ) {
		$settings = $this->get_settings();

		// Check if plugin sending is enabled
		if ( isset( $settings['enable_lite_smtp'] ) && ! $settings['enable_lite_smtp'] ) {
			return null;
		}

		$provider = $this->get_active_provider( $settings );

		// Only intercept if Gmail is the active provider
		if ( 'gmail' !== $provider ) {
			return null;
		}

		// Check if Gmail is authenticated
		if ( ! $this->oauth->is_authenticated() ) {
			$this->database->insert_log(
				array(
					'to_email'      => is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'],
					'subject'       => $args['subject'] ?? '',
					'status'        => 'failed',
					'error_message' => esc_html__( 'Gmail authentication not configured. Please connect your Gmail account in lunexia SMTP settings.', 'lunexia-smtp-pro' ),
				)
			);
			return null;
		}

		// Send via Gmail API
		$to_email   = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
		$from_email = $this->oauth->get_gmail_email() ?: get_option( 'admin_email' );

		$email_data = array(
			'to'         => $to_email,
			'subject'    => $args['subject'] ?? '',
			'body'       => $args['message'] ?? '',
			'from_email' => $from_email,
			'headers'    => $args['headers'] ?? array(),
		);

		$result = $this->oauth->send_via_gmail_api( $email_data );

		$status = is_wp_error( $result ) ? 'failed' : 'sent';
		$error  = is_wp_error( $result ) ? $result->get_error_message() : '';

		$this->database->insert_log(
			array(
				'to_email'      => $email_data['to'],
				'subject'       => $email_data['subject'],
				'status'        => $status,
				'error_message' => $error,
			)
		);

		// If Gmail API succeeded, short-circuit wp_mail and report success.
		if ( ! is_wp_error( $result ) ) {
			return true;
		}

		// If Gmail failed, let wp_mail continue with normal processing
		return null;
	}

	/**
	 * Configure PHPMailer based on settings
	 *
	 * @param PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_phpmailer( $phpmailer ) {
		$settings = $this->get_settings();

		// Check if plugin sending is enabled
		if ( isset( $settings['enable_lite_smtp'] ) && ! $settings['enable_lite_smtp'] ) {
			return;
		}

		$provider = $this->get_active_provider( $settings );

		if ( 'gmail' === $provider ) {
			// Gmail emails are handled by the pre_wp_mail filter
			$phpmailer->isSMTP();
			$phpmailer->Host       = 'smtp.gmail.com';
			$phpmailer->Port       = 587;
			$phpmailer->SMTPAuth   = true;
			$phpmailer->SMTPSecure = 'tls';
			$phpmailer->Username   = $this->oauth->get_gmail_email();
			$phpmailer->Password   = 'dummy';

			$phpmailer->Host = 'localhost';
			$phpmailer->Port = 25;
			return;
		}

		// Configure for standard SMTP
		if ( 'other' === $provider ) {
			$this->configure_standard_smtp( $phpmailer, $settings );
		}
	}

	/**
	 * Configure standard SMTP settings
	 *
	 * @param PHPMailer $phpmailer PHPMailer instance.
	 * @param array     $settings  Plugin settings.
	 */
	private function configure_standard_smtp( $phpmailer, $settings ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = sanitize_text_field( $settings['smtp_host'] ?? '' );
		$phpmailer->Port       = absint( $settings['smtp_port'] ?? 587 );
		$phpmailer->SMTPSecure = sanitize_text_field( $settings['smtp_encryption'] ?? 'tls' );

		// Configure authentication if credentials are provided
		$smtp_username = sanitize_email( $settings['smtp_username'] ?? '' );
		$smtp_password = sanitize_text_field( $settings['smtp_password'] ?? '' );
		if ( ! empty( $smtp_username ) && ! empty( $smtp_password ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $smtp_username;
			$phpmailer->Password = $smtp_password;
		} else {
			$phpmailer->SMTPAuth = false;
		}

		// Set From email and sender name if configured
		$from_email = sanitize_email( $settings['from_email'] ?? '' );
		if ( empty( $from_email ) && ! empty( $smtp_username ) && is_email( $smtp_username ) ) {
			$from_email = $smtp_username;
		}

		$from_name = sanitize_text_field( $settings['from_name'] ?? get_bloginfo( 'name' ) );

		if ( ! empty( $from_email ) && is_email( $from_email ) ) {
			$phpmailer->setFrom( $from_email, $from_name );
			$phpmailer->Sender = $from_email;
		}
	}

	/**
	 * Filter the outgoing wp_mail from address
	 *
	 * @param string $email The original from email.
	 * @return string Modified from email
	 */
	public function filter_mail_from( $email ) {
		$settings = $this->get_settings();

		if ( isset( $settings['enable_lite_smtp'] ) && ! $settings['enable_lite_smtp'] ) {
			return $email;
		}

		$from_email    = sanitize_email( $settings['from_email'] ?? '' );
		$smtp_username = sanitize_email( $settings['smtp_username'] ?? '' );

		if ( ! empty( $from_email ) && is_email( $from_email ) ) {
			return $from_email;
		}

		if ( empty( $from_email ) && ! empty( $smtp_username ) && is_email( $smtp_username ) ) {
			return $smtp_username;
		}

		if ( 'gmail' === $this->get_active_provider( $settings ) ) {
			return $this->oauth->get_gmail_email() ?: $email;
		}

		return $email;
	}

	/**
	 * Filter the outgoing wp_mail from name
	 *
	 * @param string $name The original from name.
	 * @return string Modified from name
	 */
	public function filter_mail_from_name( $name ) {
		$settings = $this->get_settings();

		if ( isset( $settings['enable_lite_smtp'] ) && ! $settings['enable_lite_smtp'] ) {
			return $name;
		}

		$from_name = sanitize_text_field( $settings['from_name'] ?? '' );

		if ( ! empty( $from_name ) ) {
			return $from_name;
		}

		return $name;
	}

	/**
	 * Generate professional HTML test email template
	 *
	 * @param string $recipient Email recipient address.
	 * @return string HTML email content
	 */
	private function generate_test_email_template( $recipient ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$sent_at   = esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), current_time( 'timestamp' ) ) );
		$logo_url  = esc_url( LUNEXIA_SMTP_PLUGIN_URL . 'assets/img/lunexiait.webp' );

		return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
</head>
<body style="margin:0;padding:0;background:#f3f5f7;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#0f172a;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f5f7;padding:24px 0;">
<tr>
<td align="center">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 24px 80px rgba(15,23,42,0.08);">
<tr>
<td style="background:#0f172a;padding:32px;text-align:center;color:#ffffff;">
<img src="' . $logo_url . '" width="108" height="auto" alt="Lunexia" style="display:block;margin:0 auto 20px;max-width:108px;" />
<h1 style="margin:0;font-size:28px;line-height:1.2;font-weight:700;color:#ffffff;">' . esc_html__( 'Lunexia SMTP Test Notification', 'lunexia-smtp-pro' ) . '</h1>
<p style="margin:16px auto 0;max-width:520px;font-size:16px;line-height:1.7;color:#cbd5e1;">' . esc_html__( 'Confirm that the SMTP configuration is working by receiving this notification.', 'lunexia-smtp-pro' ) . '</p>
</td>
</tr>
<tr>
<td style="background:#f8fafc;padding:28px 32px;">
<p style="margin:0 0 14px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#64748b;">' . esc_html__( 'Event details', 'lunexia-smtp-pro' ) . '</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
<tr>
<td style="padding:10px 0;font-size:15px;color:#334155;width:42%;vertical-align:top;"><strong>' . esc_html__( 'Event', 'lunexia-smtp-pro' ) . '</strong></td>
<td style="padding:10px 0;font-size:15px;color:#0f172a;vertical-align:top;">' . esc_html__( 'Test email verification', 'lunexia-smtp-pro' ) . '</td>
</tr>
<tr>
<td style="padding:10px 0;font-size:15px;color:#334155;vertical-align:top;"><strong>' . esc_html__( 'Recipient', 'lunexia-smtp-pro' ) . '</strong></td>
<td style="padding:10px 0;font-size:15px;color:#0f172a;vertical-align:top;">' . esc_html( $recipient ) . '</td>
</tr>
<tr>
<td style="padding:10px 0;font-size:15px;color:#334155;vertical-align:top;"><strong>' . esc_html__( 'Website', 'lunexia-smtp-pro' ) . '</strong></td>
<td style="padding:10px 0;font-size:15px;color:#0f172a;vertical-align:top;">' . $site_name . '</td>
</tr>
<tr>
<td style="padding:10px 0;font-size:15px;color:#334155;vertical-align:top;"><strong>' . esc_html__( 'Sent', 'lunexia-smtp-pro' ) . '</strong></td>
<td style="padding:10px 0;font-size:15px;color:#0f172a;vertical-align:top;">' . $sent_at . '</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="background:#fffbeb;border-top:1px solid #fcd34d;border-bottom:1px solid #fcd34d;padding:24px 32px;color:#92400e;text-align:center;font-size:15px;font-weight:700;">
' . esc_html__( 'Please confirm receipt of this email to verify your SMTP configuration is working correctly.', 'lunexia-smtp-pro' ) . '
</td>
</tr>
<tr>
<td style="background:#f8fafc;padding:28px 32px;">
<p style="margin:0 0 14px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#64748b;">' . esc_html__( 'Recommended actions', 'lunexia-smtp-pro' ) . '</p>
<ul style="margin:0;padding-left:18px;color:#0f172a;font-size:15px;line-height:1.7;">
<li style="margin-bottom:10px;">' . esc_html__( 'Confirm receipt of this message.', 'lunexia-smtp-pro' ) . '</li>
<li style="margin-bottom:10px;">' . esc_html__( 'Review your SMTP settings in the Lunexia SMTP dashboard.', 'lunexia-smtp-pro' ) . '</li>
<li style="margin-bottom:0;">' . esc_html__( 'Send another test email if any changes were made.', 'lunexia-smtp-pro' ) . '</li>
</ul>
</td>
</tr>
<tr>
<td style="background:#f1f5f9;padding:22px 32px;text-align:center;color:#64748b;font-size:13px;">
<p style="margin:0 0 6px;">' . esc_html__( 'This message was generated automatically by Lunexia SMTP Pro.', 'lunexia-smtp-pro' ) . '</p>
<p style="margin:0 0 8px;">' . esc_html__( 'Developed by Lunexia IT', 'lunexia-smtp-pro' ) . '</p>
<a href="https://lunexiait.com" style="color:#2563eb;text-decoration:none;">https://lunexiait.com</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';
	}

	/**
	 * AJAX handler for test email
	 */
	public function handle_test_email() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'lunexia_smtp_test_email' ) && ! wp_verify_nonce( $nonce, 'lite_smtp_test_email' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Security verification failed. Please refresh the page.', 'lunexia-smtp-pro' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to send test emails.', 'lunexia-smtp-pro' ) ) );
		}

		$to = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid recipient email address.', 'lunexia-smtp-pro' ) ) );
		}

		$settings = $this->get_settings();
		$provider = $this->get_active_provider( $settings );

		// Validate configuration based on the active provider
		if ( 'gmail' === $provider ) {
			if ( ! $this->oauth->is_authenticated() ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Gmail is not authenticated. Please connect your Gmail account first.', 'lunexia-smtp-pro' ) ) );
			}
		} else {
			if ( empty( $settings['smtp_host'] ) || empty( $settings['smtp_username'] ) || empty( $settings['smtp_password'] ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Please configure your SMTP credentials (Host, Username, and Password).', 'lunexia-smtp-pro' ) ) );
			}
		}

		// Send test email with professional HTML template
		$subject = esc_html__( 'Lunexia SMTP Test Notification', 'lunexia-smtp-pro' );
		$message = $this->generate_test_email_template( $to );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$result = wp_mail( $to, $subject, $message, $headers );

		// For Gmail provider, check if the email was sent via API
		if ( 'gmail' === $provider && $this->oauth->is_authenticated() ) {
			$latest_log = $this->database->get_logs( array( 'limit' => 1 ) );
			if ( ! empty( $latest_log ) && 'sent' === $latest_log[0]->status ) {
				$result = true;
			}
		}

		if ( $result ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Test email sent successfully!', 'lunexia-smtp-pro' ) ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to send test email. Check your SMTP settings or see email logs for details.', 'lunexia-smtp-pro' ) ) );
		}
	}
}

// Backwards compatibility alias
if ( ! class_exists( 'Lite_SMTP_PHPMailerHooks' ) ) {
	class_alias( 'Lunexia_SMTP_PHPMailerHooks', 'Lite_SMTP_PHPMailerHooks' );
}
